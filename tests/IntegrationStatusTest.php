<?php
declare(strict_types=1);

use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Integration\OpenAi\OpenAiClient;
use HotRadar\Integration\OpenAi\OpenAiConfig;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;

T::group('Integrações — credencial presente/ausente, Shopee e OpenAI desligadas');

$db = TestDb::fresh('integ');
$settings = new SettingsRepository($db, new AuditRepository($db));

// garantir ambiente limpo de credenciais para o teste
foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET', 'HR_SHOPEE_APP_ID', 'HR_SHOPEE_SECRET', 'OPENAI_API_KEY', 'OPENAI_MODEL'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}

// ---- Shopee sem credenciais ----
$sh = new ShopeeStatus($settings);
T::ok($sh->credentialsPresent() === false, 'sem SHOPEE_APP_ID/SECRET → credenciais ausentes');
T::eq(ShopeeStatus::NOT_CONFIGURED, $sh->state(), 'estado = não configurado');
T::eq('Não configurado', $sh->label(), 'label = Não configurado');
T::ok($sh->collectorEnabled() === false, 'coletor Shopee desligado');

$collector = new ShopeeCollector('', '', 'https://x', $sh);
T::ok($collector->isAvailable() === false, 'ShopeeCollector.isAvailable() == false');
$report = $collector->collect(new HotRadar\Collector\CollectorContext());
T::eq(0, count($report->products), 'Shopee não retorna produtos e NÃO faz chamada');
T::ok(count($report->errors) >= 1, 'motivo registrado no report');

// ---- Shopee com credenciais mas sem acesso concedido ----
putenv('SHOPEE_APP_ID=abc123');
putenv('SHOPEE_SECRET=zzz');
$sh2 = new ShopeeStatus($settings);
T::ok($sh2->credentialsPresent(), 'credenciais agora presentes');
T::eq(ShopeeStatus::WAITING_OPEN_API, $sh2->state(), 'estado = aguardando Open API (flag pending)');
T::eq('Aguardando acesso Open API', $sh2->label(), 'label correto');
T::ok($sh2->collectorEnabled() === false, 'ainda desligado enquanto acesso não concedido');

// ---- acesso concedido ----
$settings->set('shopee', ['open_api_access' => 'granted']);
$sh3 = new ShopeeStatus($settings);
T::eq(ShopeeStatus::CONFIGURED, $sh3->state(), 'estado = configurado');
T::ok($sh3->collectorEnabled() === true, 'coletor habilitado com credenciais + acesso');
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');

// ---- OpenAI sem chave ----
$oc = new OpenAiConfig();
T::ok($oc->isConfigured() === false, 'sem OPENAI_API_KEY → não configurada');
T::eq('Não configurada', $oc->statusLabel(), 'label OpenAI');
T::eq('gpt-4o-mini', $oc->model(), 'modelo default quando OPENAI_MODEL ausente');

$client = new OpenAiClient($oc);
T::ok($client->isReady() === false, 'OpenAiClient.isReady() == false');
$r = $client->summarize('sys', '{}');
T::ok($r['ok'] === false, 'summarize falha sem chave');
T::ok(str_contains((string) $r['error'], 'OPENAI_API_KEY'), 'erro instrui a definir a variável');
T::eq(null, $r['text'], 'nenhum texto retornado');

// ---- OpenAI com chave, mas SEM nunca ter salvo hr_settings['openai'] ----
// default é enabled=false: a chave só significa "Configurada", a IA não fica
// "Ativa" sozinha — exige que alguém marque o toggle explicitamente e salve.
putenv('OPENAI_API_KEY=sk-teste');
putenv('OPENAI_MODEL=gpt-4.1-mini');
$oc2 = new OpenAiConfig($settings);
T::eq('Configurada · modelo gpt-4.1-mini', $oc2->statusLabel(), 'com chave: status da CHAVE é "Configurada" (independente do toggle)');
T::ok($oc2->enabled() === false, 'hr_settings[openai] nunca salvo → enabled() default FALSE');
T::ok($oc2->isConfigured() === false, 'com chave mas NUNCA ativada explicitamente → isConfigured() false (IA não fica ativa sozinha)');
T::eq('gpt-4.1-mini', $oc2->model(), 'modelo custom (env) respeitado mesmo desativada');

// ---- só fica "Ativa" depois que o usuário marca o toggle explicitamente e salva ----
$settings->set('openai', ['enabled' => true, 'model' => '']);
$oc3 = new OpenAiConfig($settings);
T::ok($oc3->isConfigured() === true, 'após ativar explicitamente em hr_settings: isConfigured() true');

// ---- desativa de novo: "Testar conexão" continua possível (chave presente) ----
// (testConnection() só olha apiKeyPresent(), nunca enabled() — cobertura completa
// de sucesso/erro/rede via fake fica em tests/OpenAiConnectionTest.php)
$settings->set('openai', ['enabled' => false, 'model' => '']);
$ocDisabled = new OpenAiConfig($settings);
T::ok($ocDisabled->isConfigured() === false, 'setup: desativada de novo');
T::ok($ocDisabled->apiKeyPresent() === true, 'setup: chave continua presente (testConnection() poderia rodar)');

putenv('OPENAI_API_KEY');
putenv('OPENAI_MODEL');

TestDb::cleanup('integ');
