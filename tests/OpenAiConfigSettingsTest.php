<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Integration\OpenAi\OpenAiConfig;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Web\Actions;

T::group('OpenAiConfig — ativar/desativar e modelo via hr_settings (NÃO secreto)');

$db = TestDb::fresh('openai_settings');
$audit = new AuditRepository($db);
$settings = new SettingsRepository($db, $audit);

foreach (['OPENAI_API_KEY', 'OPENAI_MODEL'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}

// ---- sem settings salvo: comportamento default preservado (enabled=true) ----
$oc = new OpenAiConfig($settings);
T::ok($oc->enabled() === true, 'sem settings salvo: enabled() default true (preserva comportamento histórico)');
T::ok($oc->isConfigured() === false, 'sem chave: isConfigured() false mesmo com enabled=true');
T::eq('Não configurada', $oc->statusLabel(), 'sem chave: status da chave = "Não configurada"');

// ---- chave presente, nada desativado ainda: usa normalmente ----
putenv('OPENAI_API_KEY=sk-teste-fake-nao-usada-em-rede');
$oc2 = new OpenAiConfig($settings);
T::ok($oc2->isConfigured() === true, 'com chave e sem settings: isConfigured() true (default enabled)');
T::eq('Configurada · modelo gpt-4o-mini', $oc2->statusLabel(), 'status mostra chave configurada + modelo default');

// ---- desativa explicitamente via settings (não secreto) ----
$settings->set('openai', ['enabled' => false, 'model' => '']);
$oc3 = new OpenAiConfig($settings);
T::ok($oc3->enabled() === false, 'settings enabled=false é respeitado');
T::ok($oc3->isConfigured() === false, 'com chave MAS desativado: isConfigured() false (uso real fica bloqueado)');
T::eq('Configurada · modelo gpt-4o-mini', $oc3->statusLabel(), 'status da CHAVE continua "Configurada" mesmo desativado (conceitos não se confundem)');

// ---- reativa + escolhe modelo custom via settings ----
$settings->set('openai', ['enabled' => true, 'model' => 'gpt-4.1-mini']);
$oc4 = new OpenAiConfig($settings);
T::ok($oc4->isConfigured() === true, 'reativado: isConfigured() true de novo');
T::eq('gpt-4.1-mini', $oc4->model(), 'modelo salvo em hr_settings tem prioridade sobre OPENAI_MODEL/default');

// ---- hr_settings guarda SÓ enabled/model, nunca a chave ----
$raw = $db->first("SELECT svalue FROM hr_settings WHERE skey = 'openai'");
T::ok(!str_contains((string) $raw['svalue'], 'sk-teste'), 'hr_settings NUNCA contém a API key');

// ---- audit log da mudança de settings também não vaza a chave ----
$openaiAudit = array_values(array_filter($audit->recent('settings', 20), static fn ($r) => $r['ref'] === 'openai'));
T::ok($openaiAudit !== [], 'mudança de settings de IA foi auditada');
foreach ($openaiAudit as $a) {
    T::ok(!str_contains((string) $a['before_json'], 'sk-teste'), 'audit before_json não contém a chave');
    T::ok(!str_contains((string) $a['after_json'], 'sk-teste'), 'audit after_json não contém a chave');
}

// =====================================================================
T::group('Actions::settingsOpenAi() — salva via POST, nunca recebe/grava a chave');

$app = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $db);

$_POST = ['enabled' => '1', 'model' => 'gpt-4o'];
@Actions::settingsOpenAi($app); // @ só cala "headers already sent" do redirect() em CLI (mesmo padrão do SystemResetServiceTest)
$saved = $settings->get('openai');
T::eq(true, $saved['enabled'], 'Actions::settingsOpenAi() salvou enabled=true');
T::eq('gpt-4o', $saved['model'], 'Actions::settingsOpenAi() salvou o modelo escolhido');

$_POST = ['model' => 'gpt-4o']; // sem "enabled" no POST = checkbox desmarcado
@Actions::settingsOpenAi($app);
$saved2 = $settings->get('openai');
T::eq(false, $saved2['enabled'], 'checkbox desmarcado (ausente no POST) salva enabled=false');

// nenhum campo de API key existe no form — mesmo enviando um por engano/ataque, é ignorado
$_POST = ['enabled' => '1', 'model' => 'gpt-4o', 'api_key' => 'sk-nao-deveria-ir-a-lugar-nenhum'];
@Actions::settingsOpenAi($app);
$raw2 = $db->first("SELECT svalue FROM hr_settings WHERE skey = 'openai'");
T::ok(!str_contains((string) $raw2['svalue'], 'sk-nao-deveria'), 'campo extra "api_key" no POST é ignorado — Action só lê enabled/model');

$_POST = [];

// =====================================================================
T::group('Actions::openaiTest() — sem chave, bloqueia sem tentar rede');

putenv('OPENAI_API_KEY');
unset($_ENV['OPENAI_API_KEY'], $_SERVER['OPENAI_API_KEY']);
@Actions::openaiTest($app); // não deve lançar nem travar; sem chave, testConnection() já retorna antes de qualquer curl
T::ok(true, 'Actions::openaiTest() sem chave executa sem erro (bloqueio amigável, sem chamada de rede)');

TestDb::cleanup('openai_settings');
