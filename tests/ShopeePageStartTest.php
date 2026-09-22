<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\MercadoLivre\MercadoLivreCollector;
use HotRadar\Collector\MercadoLivre\OfertasJsonParser;
use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Radar\Radar;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Support\Http;
use HotRadar\Web\Actions;
use HotRadar\Web\View;

/**
 * "Começar na página" do feed Shopee — auditoria em
 * SINERGIA-HOTRADAR-AUDITORIA-PAGINACAO-SHOPEE.md. pages_per_category
 * continua limitado a 1–10 (QUANTIDADE); shopee_page_start é só DE ONDE
 * essas páginas começam. Mercado Livre não usa nem conhece este campo.
 */

$mkRadar = static fn (array $o): Radar => new Radar(
    id: null, slug: '', name: $o['name'] ?? 'R', enabled: true,
    marketplaces: $o['mp'] ?? ['shopee'],
    mlCategories: $o['cats'] ?? [], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: $o['pages'] ?? 10, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
    desiredWords: [], desiredWordsMode: 'any',
    shopeePageStart: $o['start'] ?? 1,
);

/** Fake ShopeeCollector — registra os valores de "page" enviados no payload, sem rede. */
$makeShopeeFake = static function (ShopeeStatus $status) {
    return new class('fake-app-id-pagestart', 'fake-secret-pagestart', 'https://fake.shopee.example/graphql', $status) extends ShopeeCollector {
        /** @var array<int,int> páginas efetivamente pedidas, na ordem */
        public array $pagesRequested = [];
        /** @var array<int,array{status:int,body:?string,error:?string}> fila de respostas; última repete se acabar */
        public array $queue = [];
        protected function httpPost(string $body, array $headers): array
        {
            $decoded = json_decode($body, true);
            $this->pagesRequested[] = (int) ($decoded['variables']['page'] ?? -1);
            if ($this->queue !== []) {
                return count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
            }
            // default: 1 produto novo por página, itemId único por página (nunca duplicado)
            $page = (int) ($decoded['variables']['page'] ?? 0);
            return ['status' => 200, 'body' => json_encode(['data' => ['productOfferV2' => ['nodes' => [
                ['itemId' => 'P' . $page, 'shopId' => '1', 'productName' => 'Produto da página ' . $page, 'price' => '10.00', 'productLink' => 'https://x', 'offerLink' => 'https://x'],
            ]]]]), 'error' => null];
        }
    };
};

$dbSetup = TestDb::fresh('shopee_page_start');
$settings = new SettingsRepository($dbSetup, new AuditRepository($dbSetup));
$settings->set('shopee', ['open_api_access' => 'granted']);
foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}
putenv('SHOPEE_APP_ID=fake-app-id-pagestart');
putenv('SHOPEE_SECRET=fake-secret-pagestart');
$status = new ShopeeStatus($settings);

// =====================================================================
T::group('1) Radar antigo/sem o campo — shopeePageStart vira 1 (fromRow)');

$legacyRow = [
    'id' => 1, 'slug' => 'legado', 'name' => 'Radar Legado', 'enabled' => 1,
    'marketplaces' => json_encode(['shopee']), 'ml_categories' => json_encode([]),
    'shopee_keywords' => json_encode([]), 'extra_keywords' => json_encode([]), 'excluded_words' => json_encode([]),
    'pages_per_category' => 5, 'min_discount' => null, 'price_min' => null, 'price_max' => null, 'require_video' => 0,
    // shopee_page_start DELIBERADAMENTE ausente (radar salvo antes desta migration)
];
$legacy = Radar::fromRow($legacyRow);
T::eq(1, $legacy->shopeePageStart, 'linha sem a coluna: shopeePageStart vira 1 (comportamento atual preservado)');

$legacyRowNull = $legacyRow + ['shopee_page_start' => null];
T::eq(1, Radar::fromRow($legacyRowNull)->shopeePageStart, 'coluna presente porém NULL: shopeePageStart também vira 1');

// =====================================================================
T::group('5) shopeePageStart inválido/0/negativo — normaliza para 1');

T::eq(1, Radar::normalizeShopeePageStart(0), '0 normaliza para 1');
T::eq(1, Radar::normalizeShopeePageStart(-5), 'negativo normaliza para 1');
T::eq(1, Radar::normalizeShopeePageStart(null), 'null normaliza para 1');
T::eq(1, Radar::normalizeShopeePageStart(1), '1 permanece 1');
T::eq(11, Radar::normalizeShopeePageStart(11), '11 é preservado (sem teto máximo inventado)');
T::eq(999, Radar::normalizeShopeePageStart(999), 'valor alto é preservado — auditoria não comprovou limite da Shopee, então não inventamos um');

// =====================================================================
T::group('2-4) ShopeeCollector — faixa de páginas efetivamente pedida (SEM rede)');

$c1 = $makeShopeeFake($status);
$radar1 = $mkRadar(['start' => 1, 'pages' => 10]);
$c1->collect(new CollectorContext(radar: $radar1));
T::eq(range(1, 10), $c1->pagesRequested, 'início=1, 10 páginas => pede exatamente 1,2,...,10');

$c2 = $makeShopeeFake($status);
$radar2 = $mkRadar(['start' => 11, 'pages' => 10]);
$c2->collect(new CollectorContext(radar: $radar2));
T::eq(range(11, 20), $c2->pagesRequested, 'início=11, 10 páginas => pede exatamente 11,12,...,20');

$c3 = $makeShopeeFake($status);
$radar3 = $mkRadar(['start' => 21, 'pages' => 10]);
$c3->collect(new CollectorContext(radar: $radar3));
T::eq(range(21, 30), $c3->pagesRequested, 'início=21, 10 páginas => pede exatamente 21,22,...,30');

$c3b = $makeShopeeFake($status);
$radar3b = $mkRadar(['start' => 21, 'pages' => 5]);
$c3b->collect(new CollectorContext(radar: $radar3b));
T::eq(range(21, 25), $c3b->pagesRequested, 'início=21, 5 páginas => pede exatamente 21,22,23,24,25 (endPage = start + pages - 1)');

// =====================================================================
T::group('6) Actions::radarSave() — quantidade de páginas continua limitada a 1-10, independente do início');

$dbAction = TestDb::fresh('shopee_page_start_action');
$appAction = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbAction);

$_POST = ['name' => 'Radar Clamp', 'marketplaces' => ['shopee'], 'pages_per_category' => '999', 'shopee_page_start' => '11'];
@Actions::radarSave($appAction);
$rowClamp = $dbAction->first("SELECT * FROM hr_radars WHERE name = 'Radar Clamp'");
$savedClamp = Radar::fromRow($rowClamp);
T::eq(10, $savedClamp->pagesPerCategory, 'pages_per_category=999 continua sendo travado em 10 — limite de QUANTIDADE inalterado');
T::eq(11, $savedClamp->shopeePageStart, 'shopee_page_start=11 é salvo sem nenhum teto (só min 1)');

// =====================================================================
T::group('7) Formulário — Shopee apresenta "Começar na página"');

$catalog = [];
$html = View::render('radars/edit', [
    'radar' => $mkRadar(['name' => 'Radar View', 'mp' => ['shopee'], 'start' => 11]),
    'catalog' => $catalog,
    'shopee_available' => true,
]);
T::ok(str_contains($html, 'Começar na página'), 'formulário contém o rótulo "Começar na página"');
T::ok(str_contains($html, 'name="shopee_page_start"'), 'formulário contém o input name="shopee_page_start"');
T::ok(str_contains($html, 'value="11"'), 'valor atual do radar (11) aparece pré-preenchido no input');
T::ok(str_contains($html, 'início 11 + 10 páginas'), 'texto de ajuda curto está presente');

// =====================================================================
T::group('8) Round-trip de persistência (create → find)');

$dbRt = TestDb::fresh('shopee_page_start_roundtrip');
$appRt = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbRt);
$radarToSave = $mkRadar(['name' => 'Radar Round-trip', 'start' => 21, 'pages' => 10]);
$newId = $appRt->radars()->create($radarToSave);
$reloaded = $appRt->radars()->find($newId);
T::eq(21, $reloaded->shopeePageStart, 'round-trip: shopeePageStart preservado');
TestDb::cleanup('shopee_page_start_roundtrip');

// =====================================================================
T::group('9) ShopeeCollector usa a página inicial configurada (comprovado acima, reforço com radar combinado ML+Shopee)');

$c9 = $makeShopeeFake($status);
$radar9 = $mkRadar(['mp' => ['mercado_livre', 'shopee'], 'start' => 11, 'pages' => 10]);
$c9->collect(new CollectorContext(radar: $radar9));
T::eq(range(11, 20), $c9->pagesRequested, 'mesmo num radar combinado ML+Shopee, o lado Shopee começa em 11');

// =====================================================================
T::group('10) Condições de parada antecipada continuam funcionando (mesmo com início > 1)');

$c10 = $makeShopeeFake($status);
$c10->queue = [['status' => 200, 'body' => json_encode(['data' => ['productOfferV2' => ['nodes' => []]]]), 'error' => null]];
$radar10 = $mkRadar(['start' => 11, 'pages' => 10]);
$r10 = $c10->collect(new CollectorContext(radar: $radar10));
T::eq([11], $c10->pagesRequested, 'início=11: página vazia já para o loop na 1ª requisição (não insiste até a 20)');
T::eq(0, count($r10->errors), 'página vazia não é erro');

$c10b = $makeShopeeFake($status);
$c10b->queue = [['status' => 401, 'body' => '{}', 'error' => null]];
$radar10b = $mkRadar(['start' => 21, 'pages' => 10]);
$r10b = $c10b->collect(new CollectorContext(radar: $radar10b));
T::eq([21], $c10b->pagesRequested, 'início=21: erro HTTP já para na 1ª requisição da faixa');
T::eq('Shopee p21: HTTP 401.', $r10b->errors[0], 'mensagem de erro usa o número de página REAL (21), não um índice relativo de loop');

// =====================================================================
T::group('11) Dedupe continua funcionando dentro da faixa');

$c11 = $makeShopeeFake($status);
$dupBody = json_encode(['data' => ['productOfferV2' => ['nodes' => [
    ['itemId' => 'DUP1', 'shopId' => '1', 'productName' => 'Produto duplicado', 'price' => '10.00', 'productLink' => 'https://x', 'offerLink' => 'https://x'],
]]]]);
$c11->queue = [
    ['status' => 200, 'body' => $dupBody, 'error' => null],
    ['status' => 200, 'body' => $dupBody, 'error' => null], // mesmo itemId de novo — não pode virar 2 produtos
];
$radar11 = $mkRadar(['start' => 1, 'pages' => 2]);
$r11 = $c11->collect(new CollectorContext(radar: $radar11));
T::eq(1, count($r11->products), 'mesmo itemId repetido entre páginas: só 1 produto no relatório (dedupe por dedupeKey() intacto)');

// =====================================================================
T::group('12-13) Mercado Livre — comportamento IDÊNTICO ao de antes, mesmo com shopeePageStart preenchido');

$fakeItem = static function (string $id, int $page): array {
    return [
        'position' => 1,
        'card' => [
            'metadata' => ['id' => $id, 'product_id' => $id, 'url' => 'www.mercadolivre.com.br/produto/p/' . $id],
            'components' => [['type' => 'title', 'title' => ['text' => 'Produto ML página ' . $page]]],
        ],
    ];
};
$fakeHttp = new class($fakeItem) extends Http {
    public array $urls = [];
    public function __construct(private $fakeItem)
    {
        parent::__construct('test-agent');
    }
    public function get(string $url, array $headers = []): array
    {
        $this->urls[] = $url;
        // extrai page= da URL (ausente = página 1, igual ao código real do MercadoLivreCollector)
        $page = 1;
        if (preg_match('/[?&]page=(\d+)/', $url, $m)) {
            $page = (int) $m[1];
        }
        $payload = ['appProps' => ['pageProps' => ['data' => ['items' => [($this->fakeItem)('MLB90000' . $page, $page)]]]]];
        $html = '<html><script>_n.ctx.r=' . json_encode($payload, JSON_UNESCAPED_UNICODE) . ';</script></html>';
        return ['status' => 200, 'body' => $html, 'error' => null, 'bytes' => strlen($html), 'final_url' => $url];
    }
};
$mlCollector = new MercadoLivreCollector($fakeHttp, new OfertasJsonParser(), requestDelayMs: 0, defaultCategories: ['MLB1574']);

// MESMO Radar tem shopeePageStart=11 — mas isso NUNCA é lido pelo MercadoLivreCollector/CollectorContext::effectiveCategories()/effectiveMaxPages()
$radarMlComShopeeStart = $mkRadar(['mp' => ['mercado_livre', 'shopee'], 'cats' => [['id' => 'MLB1574', 'label' => 'Casa']], 'start' => 11, 'pages' => 3]);
$reportMl = $mlCollector->collect(new CollectorContext(categories: ['MLB1574'], radar: $radarMlComShopeeStart));
T::eq(3, $reportMl->pagesFetched, 'ML: continua buscando exatamente pagesPerCategory páginas (3), sem nenhuma relação com shopeePageStart');
T::ok(!str_contains($fakeHttp->urls[0], 'page='), 'ML: 1ª página segue SEM parâmetro "page" na URL (page 1 implícito) — comportamento inalterado, shopeePageStart=11 do MESMO radar não vazou para o ML');
T::ok(str_contains($fakeHttp->urls[1], 'page=2'), 'ML: 2ª chamada é page=2 (não 12) — confirma que o ML nunca soma/lê shopeePageStart');
T::ok(str_contains($fakeHttp->urls[2], 'page=3'), 'ML: 3ª chamada é page=3 — sequência normal de sempre, idêntica a antes desta feature existir');

TestDb::cleanup('shopee_page_start');
TestDb::cleanup('shopee_page_start_action');
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);
