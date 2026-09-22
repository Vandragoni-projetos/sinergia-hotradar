<?php
declare(strict_types=1);

use HotRadar\Web\HealthCheck;

T::group('Health-check de banco (E5)');

// ---------------------------------------------------------------- saudável (sqlite local — plumbing genérico)
// Não há MariaDB real disponível neste ambiente de teste; o que se prova aqui é o
// MECANISMO do health-check (status/http/campos), que é idêntico para qualquer
// driver válido. A regra "produção só aceita mysql" já está coberta, à parte,
// em EnvironmentValidationTest (sem precisar de conexão real).
$db = TestDb::fresh('healthcheck_ok');
$cfgOk = [
    'env' => 'local',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => HR_ROOT . '/storage/test_healthcheck_ok.sqlite', 'host' => '', 'port' => '', 'name' => '', 'user' => '', 'password' => ''],
];
$r = HealthCheck::run($cfgOk);
T::eq(200, $r['http'], 'banco saudável -> HTTP 200');
T::eq('ok', $r['body']['status'], 'banco saudável -> status=ok');
T::eq('ok', $r['body']['db_connection'], 'banco saudável -> db_connection=ok');
T::eq('sqlite', $r['body']['db_driver'], 'reporta o driver realmente ativo');
T::ok(($r['body']['migrations_applied'] ?? null) === 8, 'reporta a contagem de migrations aplicadas (8)');
TestDb::cleanup('healthcheck_ok');

// ---------------------------------------------------------------- config inválida (produção sem driver)
$cfgBad = ['env' => 'production', 'db' => ['driver' => '', 'host' => '', 'port' => '', 'name' => '', 'user' => '', 'password' => '']];
$r = HealthCheck::run($cfgBad);
T::eq(503, $r['http'], 'config inválida em produção -> HTTP 503');
T::eq('error', $r['body']['status'], 'config inválida -> status=error');
T::eq('invalid_config', $r['body']['db_connection'], 'config inválida -> db_connection=invalid_config');
T::ok(!empty($r['body']['problems']), 'lista de problemas presente (nomes de variáveis)');

// ---------------------------------------------------------------- conexão indisponível (config completa, host que recusa)
$cfgUnreachable = [
    'env' => 'production',
    'db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '1', 'name' => 'x', 'user' => 'x', 'password' => 'segredo-que-nao-pode-aparecer'],
];
$r = HealthCheck::run($cfgUnreachable);
T::eq(503, $r['http'], 'banco indisponível -> HTTP 503');
T::eq('error', $r['body']['status'], 'banco indisponível -> status=error');
T::eq('failed', $r['body']['db_connection'], 'banco indisponível -> db_connection=failed');

// ---------------------------------------------------------------- NUNCA vaza segredo, DSN ou stack trace, em nenhum cenário
foreach ([$cfgOk, $cfgBad, $cfgUnreachable] as $cfg) {
    $r = HealthCheck::run($cfg);
    $json = json_encode($r['body'], JSON_UNESCAPED_UNICODE);
    T::ok(!str_contains((string) $json, 'segredo-que-nao-pode-aparecer'), 'resposta JSON nunca contém a senha');
    foreach (['password', 'senha', 'db_user', 'dsn', 'stack', 'Trace'] as $forbidden) {
        T::ok(!str_contains((string) $json, $forbidden), "resposta JSON nunca contém a chave/termo \"$forbidden\"");
    }
}

echo "\n(health-check é somente leitura — nenhum comando de coleta/migration/deploy foi executado)\n";
