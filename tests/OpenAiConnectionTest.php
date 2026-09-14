<?php
declare(strict_types=1);

use HotRadar\Integration\OpenAi\OpenAiClient;
use HotRadar\Integration\OpenAi\OpenAiConfig;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;

T::group('OpenAiClient::testConnection() — sucesso/erro via fake, SEM chamada de rede real');

foreach (['OPENAI_API_KEY', 'OPENAI_MODEL'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}

// ---- sem chave: bloqueia ANTES de qualquer tentativa de rede ----
$config = new OpenAiConfig();
$client = new OpenAiClient($config);
$r = $client->testConnection();
T::ok($r['ok'] === false, 'sem chave: testConnection() falha');
T::ok(str_contains((string) $r['error'], 'OPENAI_API_KEY'), 'mensagem instrui configurar a variável de ambiente');

putenv('OPENAI_API_KEY=sk-teste-fake-nao-usada-em-rede');
$configOk = new OpenAiConfig();

// ---- fake que simula SUCESSO (HTTP 200), sem tocar a rede de verdade ----
$clientOk = new class($configOk) extends OpenAiClient {
    protected function httpPost(string $body): array
    {
        return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => 'ok']]]]), 'errno' => 0];
    }
};
$rOk = $clientOk->testConnection();
T::ok($rOk['ok'] === true, 'fake HTTP 200: testConnection() reporta sucesso');
T::eq(null, $rOk['error'], 'sucesso não traz mensagem de erro');

// ---- fake que simula ERRO HTTP (ex.: chave inválida -> 401) ----
$clientAuthError = new class($configOk) extends OpenAiClient {
    protected function httpPost(string $body): array
    {
        return ['code' => 401, 'body' => '{"error":{"message":"Incorrect API key provided: sk-teste-fake-nao-usada-em-rede"}}', 'errno' => 0];
    }
};
$rErr = $clientAuthError->testConnection();
T::ok($rErr['ok'] === false, 'fake HTTP 401: testConnection() reporta falha');
T::eq('OpenAI retornou HTTP 401.', $rErr['error'], 'mensagem de erro é genérica (só o código HTTP)');
T::ok(!str_contains((string) $rErr['error'], 'sk-teste'), 'mensagem de erro NUNCA ecoa a chave, mesmo que o corpo bruto (fake) a mencionasse');
T::ok(!str_contains((string) $rErr['error'], 'Incorrect API key'), 'mensagem de erro NUNCA ecoa o corpo bruto da resposta da OpenAI');

// ---- fake que simula FALHA DE REDE (errno != 0, ex.: DNS) ----
$clientNetErr = new class($configOk) extends OpenAiClient {
    protected function httpPost(string $body): array
    {
        return ['code' => 0, 'body' => null, 'errno' => 6]; // CURLE_COULDNT_RESOLVE_HOST
    }
};
$rNet = $clientNetErr->testConnection();
T::ok($rNet['ok'] === false, 'fake erro de rede: testConnection() reporta falha');
T::eq('Falha de rede ao chamar a OpenAI.', $rNet['error'], 'mensagem de falha de rede é genérica');

// =====================================================================
T::group('OpenAiClient::testConnection() ignora o toggle "enabled" — só exige a chave');

$db = TestDb::fresh('openai_conn');
$audit = new AuditRepository($db);
$settings = new SettingsRepository($db, $audit);
$settings->set('openai', ['enabled' => false, 'model' => '']);
$configDisabled = new OpenAiConfig($settings);
T::ok($configDisabled->isConfigured() === false, 'setup: uso real está desativado (isConfigured() = false)');

$clientDisabled = new class($configDisabled) extends OpenAiClient {
    protected function httpPost(string $body): array
    {
        return ['code' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => 'ok']]]]), 'errno' => 0];
    }
};
$rDisabled = $clientDisabled->testConnection();
T::ok($rDisabled['ok'] === true, 'testConnection() funciona mesmo com enabled=false — testar a chave não depende do toggle de uso');

putenv('OPENAI_API_KEY');
TestDb::cleanup('openai_conn');
