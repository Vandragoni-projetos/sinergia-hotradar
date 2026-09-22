<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;

/** Fábrica de um NormalizedProduct do Mercado Livre com/sem vídeo, pra testar accepts() diretamente. */
$mkMlProduct = static fn (bool $hasVideo): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB1', title: 'Produto ML',
    urlOriginal: 'https://ml/x', hasVideo: $hasVideo, dataQuality: 'scrape_json', source: 'ofertas_ml',
);

$mkRadar = static function (array $overrides = []): Radar {
    $defaults = [
        'id' => null, 'slug' => '', 'name' => 'Radar Teste', 'enabled' => true,
        'marketplaces' => ['mercado_livre'], 'mlCategories' => [], 'shopeeKeywords' => [],
        'extraKeywords' => [], 'excludedWords' => [], 'pagesPerCategory' => 1,
        'minDiscount' => null, 'priceMin' => null, 'priceMax' => null, 'requireVideo' => false,
    ];
    $d = array_merge($defaults, $overrides);
    return new Radar(
        id: $d['id'], slug: $d['slug'], name: $d['name'], enabled: $d['enabled'],
        marketplaces: $d['marketplaces'], mlCategories: $d['mlCategories'],
        shopeeKeywords: $d['shopeeKeywords'], extraKeywords: $d['extraKeywords'],
        excludedWords: $d['excludedWords'], pagesPerCategory: $d['pagesPerCategory'],
        minDiscount: $d['minDiscount'], priceMin: $d['priceMin'], priceMax: $d['priceMax'],
        requireVideo: $d['requireVideo'],
    );
};

$bodyWithProduct = json_encode(['data' => ['productOfferV2' => ['nodes' => [
    ['itemId' => '111', 'shopId' => '9', 'productName' => 'Produto Shopee', 'price' => '19.90'],
]]]]);

$makeShopeeFake = static function (ShopeeStatus $status, string $body) {
    return new class('fake-app-id', 'fake-secret', 'https://fake.shopee.example/graphql', $status, responses: [$body]) extends ShopeeCollector {
        private array $queue;
        public function __construct(string $appId, string $secret, string $url, ShopeeStatus $status, array $responses = [])
        {
            parent::__construct($appId, $secret, $url, $status);
            $this->queue = $responses;
        }
        protected function httpPost(string $body, array $headers): array
        {
            $next = $this->queue !== [] ? array_shift($this->queue) : '{}';
            return ['status' => 200, 'body' => $next, 'error' => null];
        }
    };
};

$db = TestDb::fresh('shopee_form_semantics');
$settings = new SettingsRepository($db, new AuditRepository($db));
$settings->set('shopee', ['open_api_access' => 'granted']);
putenv('SHOPEE_APP_ID=fake-app-id');
putenv('SHOPEE_SECRET=fake-secret');
$status = new ShopeeStatus($settings);

// =====================================================================
T::group('A) Radar ML-only — comportamento existente PRESERVADO');

$radarMl = $mkRadar(['marketplaces' => ['mercado_livre'], 'requireVideo' => true]);
$semVideo = $mkMlProduct(false);
$comVideo = $mkMlProduct(true);
T::eq(false, $radarMl->accepts($semVideo)['ok'], 'ML: "Exigir vídeo"=true continua rejeitando produto sem vídeo (default videoFilterSupported=true)');
T::eq(true, $radarMl->accepts($comVideo)['ok'], 'ML: produto com vídeo continua aceito, comportamento igual a antes desta correção');

// =====================================================================
T::group('B) Radar Shopee-only — campos exclusivos do ML são ignorados na coleta real');

$radarShopeeComLixoMl = $mkRadar([
    'marketplaces' => ['shopee'],
    'mlCategories' => [['id' => 'MLB1574', 'label' => 'Casa']], // residual/irrelevante pra Shopee
    'extraKeywords' => ['produto'], // bateria com o título "Produto Shopee" se fosse usado
    'requireVideo' => true, // NUNCA pode zerar a coleta Shopee
]);

$fakeB = $makeShopeeFake($status, $bodyWithProduct);
$reportB = $fakeB->collect(new CollectorContext(maxPages: 1, radar: $radarShopeeComLixoMl));
T::eq(1, count($reportB->products), 'Shopee: produto é coletado normalmente mesmo com ml_categories/extra_keywords/require_video residuais');
T::eq(0, $reportB->filteredByRadar, 'Shopee: "Exigir vídeo" residual NÃO filtrou nada (nenhum produto perdido por isso)');
// nicheConfidence agora É calculado para Shopee (NicheClassifier aplicado no ProductOfferV2Mapper,
// correção de SINERGIA-HOTRADAR-AUDITORIA-CLASSIFICACAO-RETORNOS.md) — mas só a partir do TÍTULO.
// "Produto Shopee" não bate com nenhum termo do nicho-alvo, então vira 'fora'. O que este teste
// precisa provar continua verdadeiro: extra_keywords (ML) não influencia esse resultado — o "boost"
// de applyRadarNicheBoost() é exclusivo do MercadoLivreCollector, nunca chamado pelo ShopeeCollector.
T::eq('fora', $reportB->products[0]->nicheConfidence, 'Shopee: nicheConfidence calculado a partir só do título ("fora" — não relacionado ao nicho-alvo)');

// mesmo radar, mas SEM os campos residuais — resultado idêntico, prova que eles não fazem diferença nenhuma
$radarShopeeLimpo = $mkRadar(['marketplaces' => ['shopee']]);
$fakeB2 = $makeShopeeFake($status, $bodyWithProduct);
$reportB2 = $fakeB2->collect(new CollectorContext(maxPages: 1, radar: $radarShopeeLimpo));
T::eq(count($reportB->products), count($reportB2->products), 'Shopee: mesmo resultado com ou sem os campos exclusivos do ML preenchidos');

// =====================================================================
T::group('C) Radar ML + Shopee — cada coletor respeita só o que suporta, sem regressão nem fallback');

$radarAmbos = $mkRadar(['marketplaces' => ['mercado_livre', 'shopee'], 'requireVideo' => true]);

// lado ML do radar combinado: "Exigir vídeo" continua funcionando normalmente (accepts() default)
T::eq(false, $radarAmbos->accepts($semVideo)['ok'], 'radar ML+Shopee: lado ML ainda rejeita produto sem vídeo normalmente');
T::eq(true, $radarAmbos->accepts($comVideo)['ok'], 'radar ML+Shopee: lado ML ainda aceita produto com vídeo normalmente');

// lado Shopee do MESMO radar: "Exigir vídeo" é ignorado (Shopee nunca informa hasVideo)
$fakeC = $makeShopeeFake($status, $bodyWithProduct);
$reportC = $fakeC->collect(new CollectorContext(maxPages: 1, radar: $radarAmbos));
T::eq(1, count($reportC->products), 'radar ML+Shopee: lado Shopee ainda coleta normalmente, "Exigir vídeo" não se aplica a ele');

// =====================================================================
T::group('D) Radar Shopee legado — múltiplos dados residuais do ML ao mesmo tempo, coleta continua processável');

$radarLegado = $mkRadar([
    'marketplaces' => ['shopee'],
    'mlCategories' => [['id' => 'MLB1574', 'label' => 'Casa'], ['id' => 'MLB1051', 'label' => 'Celulares']],
    'shopeeKeywords' => ['promoção', 'oferta'],
    'extraKeywords' => ['produto', 'shopee'],
    'requireVideo' => true,
]);
$fakeD = $makeShopeeFake($status, $bodyWithProduct);
$reportD = $fakeD->collect(new CollectorContext(maxPages: 1, radar: $radarLegado));
T::eq(1, count($reportD->products), 'radar Shopee legado (todos os campos residuais de ML ao mesmo tempo): coleta continua processável, produto normalizado normalmente');
T::eq(0, $reportD->filteredByRadar, 'radar Shopee legado: nada foi filtrado por engano pelos valores herdados do ML');
T::eq('shopee', $reportD->products[0]->marketplace, 'produto final continua marcado como shopee, não vira mercado_livre por engano');

putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
TestDb::cleanup('shopee_form_semantics');
