<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Support\BootFailedException;
use HotRadar\Support\EnvironmentValidator;

T::group('Blindagem de persistência — validação central de ambiente (E1/E2/E3/E4)');

// Helper: monta um array de config no mesmo formato de config/config.php.
$mkConfig = static function (array $overrides = []): array {
    $base = [
        'env' => 'production',
        'db' => [
            'driver' => 'mysql',
            'sqlite_path' => 'storage/should_never_be_created.sqlite',
            'host' => 'db.example.invalid',
            'port' => '3306',
            'name' => 'hotradar',
            'user' => 'hotradar',
            'password' => 'senha-super-secreta-nao-pode-vazar',
        ],
    ];
    return array_replace_recursive($base, $overrides);
};

// ---------------------------------------------------------------- 1) produção sem HR_DB_DRIVER
$cfg = $mkConfig(['db' => ['driver' => '']]);
$problems = EnvironmentValidator::validate($cfg);
T::ok($problems !== [], 'produção sem HR_DB_DRIVER -> validate() acusa problema');
T::ok(str_contains(implode(' ', $problems), 'HR_DB_DRIVER'), 'problema menciona HR_DB_DRIVER especificamente');

// ---------------------------------------------------------------- 2) driver=mysql mas variável obrigatória ausente
foreach (['host', 'port', 'name', 'user', 'password'] as $missingKey) {
    $cfg = $mkConfig(['db' => [$missingKey => '']]);
    $problems = EnvironmentValidator::validate($cfg);
    T::ok($problems !== [], "produção com driver=mysql e '$missingKey' vazio -> validate() acusa problema");
}

// driver explicitamente sqlite em produção também é recusado (não só ausência)
$cfg = $mkConfig(['db' => ['driver' => 'sqlite']]);
$problems = EnvironmentValidator::validate($cfg);
T::ok($problems !== [], 'produção com HR_DB_DRIVER=sqlite explícito -> recusado (só mysql é suportado)');

// driver totalmente desconhecido também é recusado
$cfg = $mkConfig(['db' => ['driver' => 'postgres']]);
$problems = EnvironmentValidator::validate($cfg);
T::ok($problems !== [], 'produção com driver desconhecido -> recusado');

// tudo presente e correto -> nenhum problema
$cfg = $mkConfig();
T::eq([], EnvironmentValidator::validate($cfg), 'produção com tudo presente -> sem problemas');

// ---------------------------------------------------------------- 5) local/dev ainda pode usar SQLite
$cfgLocal = ['env' => 'local', 'db' => ['driver' => 'sqlite', 'sqlite_path' => 'x', 'host' => '', 'port' => '', 'name' => '', 'user' => '', 'password' => '']];
T::eq([], EnvironmentValidator::validate($cfgLocal), 'ambiente local: SQLite e variáveis vazias são permitidos, sem problema');
T::ok(EnvironmentValidator::isLocal($cfgLocal), 'isLocal() reconhece "local"');
T::ok(!EnvironmentValidator::isLocal($mkConfig()), 'isLocal() não confunde "production" com local');

// ---------------------------------------------------------------- 4) produção jamais cria SQLite automaticamente
$tmpDir = sys_get_temp_dir() . '/hr_env_test_' . uniqid();
@mkdir($tmpDir, 0775, true);
$sqlitePath = $tmpDir . '/nunca_deveria_existir.sqlite';
$cfg = $mkConfig(['db' => ['driver' => '', 'sqlite_path' => $sqlitePath]]);
try {
    App::bootOrFail($cfg, 'test');
    T::ok(false, 'bootOrFail deveria ter lançado BootFailedException (driver ausente em produção)');
} catch (BootFailedException $e) {
    T::ok(true, 'bootOrFail lança BootFailedException quando driver ausente em produção');
}
T::ok(!is_file($sqlitePath), 'NENHUM arquivo .sqlite foi criado durante a tentativa de boot em produção');
@rmdir($tmpDir);

// ---------------------------------------------------------------- 3) produção com credenciais inválidas (falha de conexão real)
// host/porta que recusam conexão IMEDIATAMENTE (sem timeout de DNS) — rápido e determinístico.
$cfg = $mkConfig(['db' => ['host' => '127.0.0.1', 'port' => '1']]);
$threw = false;
try {
    App::bootOrFail($cfg, 'test');
} catch (BootFailedException $e) {
    $threw = true;
    T::ok(str_contains($e->getMessage(), '127.0.0.1'), 'mensagem de erro identifica o host que falhou');
    T::ok(!str_contains($e->getMessage(), 'senha-super-secreta-nao-pode-vazar'), 'mensagem de erro NUNCA contém a senha');
    T::ok(!isset($e->context['password']) && !isset($e->context['user']), 'contexto seguro não inclui password nem user');
}
T::ok($threw, 'produção com host/porta inválidos -> conexão falha -> BootFailedException');

// ---------------------------------------------------------------- 5b) local com SQLite continua funcionando de ponta a ponta
$db = TestDb::fresh('env_validation');
$localCfg = [
    'env' => 'local',
    'timezone' => 'America/Sao_Paulo',
    'panel' => ['user' => 'x', 'password_hash' => '', 'password' => ''],
    'db' => ['driver' => 'sqlite', 'sqlite_path' => HR_ROOT . '/storage/test_env_validation.sqlite', 'host' => '', 'port' => '', 'name' => '', 'user' => '', 'password' => ''],
];
$app = App::bootOrFail($localCfg, 'test');
T::eq('sqlite', $app->db->driver(), 'local com sqlite: bootOrFail() sobe normalmente e usa sqlite');
TestDb::cleanup('env_validation');

// ---------------------------------------------------------------- 8) nenhum segredo aparece no log
$logFile = sys_get_temp_dir() . '/hr_boot_log_test_' . uniqid() . '.log';
$prevLog = ini_get('error_log');
ini_set('error_log', $logFile);
try {
    App::bootOrFail($mkConfig(['db' => ['host' => '127.0.0.1', 'port' => '1']]), 'test');
} catch (BootFailedException) {
    // esperado
}
ini_set('error_log', $prevLog !== false ? $prevLog : '');
$logContent = is_file($logFile) ? file_get_contents($logFile) : '';
T::ok($logContent !== '', 'uma linha de log FOI escrita na falha de boot');
T::ok(!str_contains($logContent, 'senha-super-secreta-nao-pode-vazar'), 'a senha NUNCA aparece no log de erro');
T::ok(str_contains($logContent, 'mysql') && str_contains($logContent, '127.0.0.1') && str_contains($logContent, 'production'),
    'o log CONTÉM driver/host/environment (diagnóstico útil, sem segredo)');
@unlink($logFile);

// também confirma ausência de segredo na configuração inválida (sem tentativa de conexão)
$logFile2 = sys_get_temp_dir() . '/hr_boot_log_test2_' . uniqid() . '.log';
ini_set('error_log', $logFile2);
try {
    App::bootOrFail($mkConfig(['db' => ['driver' => '']]), 'test');
} catch (BootFailedException) {
}
ini_set('error_log', $prevLog !== false ? $prevLog : '');
$logContent2 = is_file($logFile2) ? file_get_contents($logFile2) : '';
T::ok(!str_contains($logContent2, 'senha-super-secreta-nao-pode-vazar'), 'config inválida: senha também não aparece no log');
@unlink($logFile2);

// ---------------------------------------------------------------- 6) CLI e web resolvem a MESMA configuração central (guarda estrutural)
$indexSrc = (string) file_get_contents(HR_ROOT . '/public/index.php');
$hrSrc = (string) file_get_contents(HR_ROOT . '/bin/hr.php');
$healthSrc = (string) file_get_contents(HR_ROOT . '/public/health.php');
T::ok(str_contains($indexSrc, 'App::bootOrFail($config'), 'public/index.php usa App::bootOrFail() (não App::boot() direto)');
T::ok(str_contains($hrSrc, 'App::bootOrFail(hr_config()'), 'bin/hr.php usa App::bootOrFail() (não App::boot() direto)');
T::ok(str_contains($healthSrc, 'HealthCheck::run(hr_config())'), 'public/health.php também parte de hr_config() central');

// Garante que NENHUM outro arquivo do projeto instancia Connection diretamente
// (o único lugar permitido é dentro de App::boot(), chamado só por App::bootOrFail()).
$appSrc = (string) file_get_contents(HR_ROOT . '/src/App.php');
T::ok(substr_count($appSrc, 'new Connection(') === 1, 'App.php é o ÚNICO lugar do projeto que faz "new Connection(" (1 ocorrência)');
$otherOffenders = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(HR_ROOT . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($rii as $fileInfo) {
    if ($fileInfo->getExtension() !== 'php' || $fileInfo->getFilename() === 'App.php') {
        continue;
    }
    $src = (string) file_get_contents($fileInfo->getPathname());
    if (str_contains($src, 'new Connection(')) {
        $otherOffenders[] = str_replace(HR_ROOT . '/', '', $fileInfo->getPathname());
    }
}
T::eq([], $otherOffenders, 'nenhum outro arquivo em src/ (fora App.php) instancia Connection diretamente (evita split-brain)');

echo "\n(nenhum comando de coleta, migration destrutiva, deploy ou ativação de V2 foi executado por este teste)\n";
