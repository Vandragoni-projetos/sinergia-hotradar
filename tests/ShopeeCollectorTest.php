<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;

T::group('ShopeeCollector — coleta real via fake (SEM chamada de rede), sucesso/erro/vazio');

$db = TestDb::fresh('shopee_collector');
$settings = new SettingsRepository($db, new AuditRepository($db));
$settings->set('shopee', ['open_api_access' => 'granted']);

foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}
putenv('SHOPEE_APP_ID=fake-app-id-teste-999');
putenv('SHOPEE_SECRET=fake-secret-teste-999-nao-usado-em-rede');
$statusEnabled = new ShopeeStatus($settings);
T::ok($statusEnabled->collectorEnabled() === true, 'setup: status Shopee habilitado (credenciais + acesso concedido)');

/** Fábrica de um collector fake — nunca toca a rede, só devolve respostas enfileiradas. */
$makeFake = static function (ShopeeStatus $status, array $responses) {
    return new class('fake-app-id-teste-999', 'fake-secret-teste-999-nao-usado-em-rede', 'https://fake.shopee.example/graphql', $status, responses: $responses) extends ShopeeCollector {
        public array $calls = [];
        public array $lastHeaders = [];
        private array $queue;
        public function __construct(string $appId, string $secret, string $url, ShopeeStatus $status, array $responses = [])
        {
            parent::__construct($appId, $secret, $url, $status);
            $this->queue = $responses;
        }
        protected function httpPost(string $body, array $headers): array
        {
            $this->calls[] = $body;
            $this->lastHeaders = $headers;
            return $this->queue !== [] ? array_shift($this->queue) : ['status' => 200, 'body' => '{}', 'error' => null];
        }
    };
};

// ---- 1) sucesso: resposta válida com 2 produtos ----
$bodyOk = json_encode(['data' => ['productOfferV2' => ['nodes' => [
    ['itemId' => '111', 'shopId' => '9', 'productName' => 'Produto A', 'price' => '19.90', 'priceDiscountRate' => '10', 'sales' => '50', 'ratingStar' => '4.5', 'productLink' => 'https://shopee.com.br/a', 'offerLink' => 'https://s.shopee.com.br/a', 'imageUrl' => 'https://img/a.jpg', 'shopName' => 'Loja A'],
    ['itemId' => '222', 'shopId' => '9', 'productName' => 'Produto B', 'price' => '29.90', 'sales' => '5', 'productLink' => 'https://shopee.com.br/b'],
]]]]);
$c1 = $makeFake($statusEnabled, [['status' => 200, 'body' => $bodyOk, 'error' => null]]);
$r1 = $c1->collect(new CollectorContext(maxPages: 1));
T::eq(2, count($r1->products), 'resposta válida: 2 produtos normalizados');
T::eq(0, count($r1->errors), 'sem erros numa resposta válida');
$p1 = $r1->products[0];
T::ok($p1 instanceof NormalizedProduct, 'produto é NormalizedProduct');
T::eq('shopee', $p1->marketplace, 'marketplace = shopee');
T::eq('111', $p1->marketplaceProductId, 'id do produto = itemId');
T::eq('Produto A', $p1->title, 'título mapeado');
T::eq(19.90, $p1->priceCurrent, 'preço atual mapeado');
T::ok($p1->pricePrevious > $p1->priceCurrent, 'preço anterior derivado do desconto (maior que o atual)');
T::eq(10, $p1->discountPct, 'desconto mapeado');
T::eq('https://s.shopee.com.br/a', $p1->urlAffiliate, 'link de afiliado = offerLink (a Shopee já entrega pronto)');
T::eq(50, $p1->salesExact, 'vendas exatas mapeadas');
T::eq(4.5, $p1->rating, 'nota mapeada');
T::eq(false, $p1->hasVideo, 'Shopee nunca declara vídeo (a API de afiliados não expõe isso)');
T::eq('api', $p1->dataQuality, 'data_quality = api (vem direto da Open API, não é scrape)');
T::eq('shopee_api', $p1->source, 'source = shopee_api');
T::ok(str_contains($c1->lastHeaders[1] ?? '', 'SHA256 Credential='), 'Authorization usa o esquema SHA256/Credential já previsto no código');
foreach ($r1->errors as $e) {
    T::ok(!str_contains($e, 'fake-secret-teste-999'), 'nenhum erro contém a secret');
    T::ok(!str_contains($e, 'fake-app-id-teste-999'), 'nenhum erro contém o app id');
}

// ---- 2) resposta vazia (nodes: []) — tratada sem erro, sem produto ----
$bodyEmpty = json_encode(['data' => ['productOfferV2' => ['nodes' => []]]]);
$c2 = $makeFake($statusEnabled, [['status' => 200, 'body' => $bodyEmpty, 'error' => null]]);
$r2 = $c2->collect(new CollectorContext(maxPages: 3));
T::eq(0, count($r2->products), 'resposta vazia: 0 produtos');
T::eq(0, count($r2->errors), 'resposta vazia não é tratada como erro');
T::eq(1, count($c2->calls), 'para de paginar assim que uma página vem vazia (não insiste em mais páginas)');

// ---- 3) erro HTTP (ex.: 401, credencial inválida) — nenhum produto inválido gravado ----
$c3 = $makeFake($statusEnabled, [['status' => 401, 'body' => '{"error":{"message":"Invalid signature for app fake-app-id-teste-999"}}', 'error' => null]]);
$r3 = $c3->collect(new CollectorContext(maxPages: 1));
T::eq(0, count($r3->products), 'HTTP 401: 0 produtos');
T::ok(count($r3->errors) >= 1, 'HTTP 401: erro registrado');
T::eq('Shopee p1: HTTP 401.', $r3->errors[0], 'mensagem de erro é genérica (só o código), nunca ecoa o corpo bruto');
foreach ($r3->errors as $e) {
    T::ok(!str_contains($e, 'fake-app-id-teste-999'), 'erro HTTP não ecoa o app id, mesmo que o corpo bruto (fake) o mencionasse');
}

// ---- 4) erro no formato GraphQL ({"errors":[...]}) com HTTP 200 ----
$bodyGqlErr = json_encode(['errors' => [['message' => 'internal']]]);
$c4 = $makeFake($statusEnabled, [['status' => 200, 'body' => $bodyGqlErr, 'error' => null]]);
$r4 = $c4->collect(new CollectorContext(maxPages: 1));
T::eq(0, count($r4->products), 'erro GraphQL: 0 produtos');
T::ok(count($r4->errors) >= 1, 'erro GraphQL: registrado no report');

// ---- 5) falha de rede (sem HTTP, curl errno) ----
$c5 = $makeFake($statusEnabled, [['status' => 0, 'body' => null, 'error' => 'Could not resolve host: fake.shopee.example']]);
$r5 = $c5->collect(new CollectorContext(maxPages: 1));
T::eq(0, count($r5->products), 'falha de rede: 0 produtos');
T::eq('Shopee p1: falha de rede — Could not resolve host: fake.shopee.example', $r5->errors[0], 'mensagem de falha de rede registrada');

// ---- 6) indisponível (sem credenciais/acesso) NUNCA tenta rede ----
$db2 = TestDb::fresh('shopee_collector_off');
$settingsOff = new SettingsRepository($db2, new AuditRepository($db2));
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);
$statusOff = new ShopeeStatus($settingsOff);
$c6 = $makeFake($statusOff, [['status' => 200, 'body' => $bodyOk, 'error' => null]]);
T::ok($c6->isAvailable() === false, 'sem credenciais: isAvailable() false');
$r6 = $c6->collect(new CollectorContext(maxPages: 1));
T::eq(0, count($r6->products), 'indisponível: 0 produtos');
T::eq(0, count($c6->calls), 'indisponível: httpPost() NUNCA foi chamado — nenhuma tentativa de rede');

TestDb::cleanup('shopee_collector');
TestDb::cleanup('shopee_collector_off');
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
