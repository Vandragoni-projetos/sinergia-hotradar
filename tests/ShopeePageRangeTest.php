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
 * Faixa EXPLÍCITA de páginas Shopee (Página inicial + Página final),
 * substituindo "Começar na página" + "Páginas do feed" — auditoria em
 * SINERGIA-HOTRADAR-AUDITORIA-PAGINACAO-SHOPEE.md e evolução pedida depois
 * do teste em produção. pages_per_category continua existindo e funcionando
 * SÓ para o Mercado Livre, exatamente como antes.
 */

$mkRadar = static fn (array $o): Radar => new Radar(
    id: null, slug: '', name: $o['name'] ?? 'R', enabled: true,
    marketplaces: $o['mp'] ?? ['shopee'],
    mlCategories: $o['cats'] ?? [], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: $o['ml_pages'] ?? 3, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
    desiredWords: [], desiredWordsMode: 'any',
    shopeePageStart: $o['start'] ?? 1,
    shopeePageEnd: $o['end'] ?? null,
);

/** Fake ShopeeCollector — registra os valores de "page"/"limit" enviados no payload, sem rede. */
$makeShopeeFake = static function (ShopeeStatus $status) {
    return new class('fake-app-id-pagerange', 'fake-secret-pagerange', 'https://fake.shopee.example/graphql', $status) extends ShopeeCollector {
        /** @var array<int,int> páginas efetivamente pedidas, na ordem */
        public array $pagesRequested = [];
        /** @var array<int,int> limit enviado em cada requisição */
        public array $limitsRequested = [];
        /** @var array<int,array{status:int,body:?string,error:?string}> fila de respostas; última repete se acabar */
        public array $queue = [];
        protected function httpPost(string $body, array $headers): array
        {
            $decoded = json_decode($body, true);
            $this->pagesRequested[] = (int) ($decoded['variables']['page'] ?? -1);
            $this->limitsRequested[] = (int) ($decoded['variables']['limit'] ?? -1);
            if ($this->queue !== []) {
                return count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
            }
            $page = (int) ($decoded['variables']['page'] ?? 0);
            return ['status' => 200, 'body' => json_encode(['data' => ['productOfferV2' => ['nodes' => [
                ['itemId' => 'P' . $page, 'shopId' => '1', 'productName' => 'Produto da página ' . $page, 'price' => '10.00', 'productLink' => 'https://x', 'offerLink' => 'https://x'],
            ]]]]), 'error' => null];
        }
    };
};

$dbSetup = TestDb::fresh('shopee_page_range');
$settings = new SettingsRepository($dbSetup, new AuditRepository($dbSetup));
$settings->set('shopee', ['open_api_access' => 'granted']);
foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}
putenv('SHOPEE_APP_ID=fake-app-id-pagerange');
putenv('SHOPEE_SECRET=fake-secret-pagerange');
$status = new ShopeeStatus($settings);

// =====================================================================
T::group('1) start=1 / end=10 => páginas 1..10');
$c1 = $makeShopeeFake($status);
$c1->collect(new CollectorContext(radar: $mkRadar(['start' => 1, 'end' => 10])));
T::eq(range(1, 10), $c1->pagesRequested, 'início=1, fim=10 => pede exatamente 1,2,...,10');

T::group('2) start=11 / end=20 => páginas 11..20');
$c2 = $makeShopeeFake($status);
$c2->collect(new CollectorContext(radar: $mkRadar(['start' => 11, 'end' => 20])));
T::eq(range(11, 20), $c2->pagesRequested, 'início=11, fim=20 => pede exatamente 11,12,...,20');

T::group('3) start=21 / end=30 => páginas 21..30');
$c3 = $makeShopeeFake($status);
$c3->collect(new CollectorContext(radar: $mkRadar(['start' => 21, 'end' => 30])));
T::eq(range(21, 30), $c3->pagesRequested, 'início=21, fim=30 => pede exatamente 21,22,...,30');

T::group('4) start=91 / end=100 => permitido, exatamente 10 páginas');
$c4 = $makeShopeeFake($status);
$r4 = $c4->collect(new CollectorContext(radar: $mkRadar(['start' => 91, 'end' => 100])));
T::eq(range(91, 100), $c4->pagesRequested, 'início=91, fim=100 => pede exatamente 91..100 — sem teto de página inventado');
T::eq(10, count($c4->pagesRequested), 'exatamente 10 páginas nesta faixa');

T::group('5) start=11 / end=11 => somente a página 11');
$c5 = $makeShopeeFake($status);
$c5->collect(new CollectorContext(radar: $mkRadar(['start' => 11, 'end' => 11])));
T::eq([11], $c5->pagesRequested, 'faixa de 1 página só: início=fim=11 => só pede a página 11');

// =====================================================================
T::group('6-8) Actions::radarSave() — validação clara (sem fallback silencioso)');

$dbAction = TestDb::fresh('shopee_page_range_action');
$appAction = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbAction);
$countRadars = static fn () => (int) $dbAction->first('SELECT COUNT(*) n FROM hr_radars')['n'];

// 6) end < start => rejeitado, nada salvo
$before6 = $countRadars();
$_POST = ['name' => 'Radar End Menor', 'marketplaces' => ['shopee'], 'shopee_page_start' => '20', 'shopee_page_end' => '11'];
@Actions::radarSave($appAction);
T::eq($before6, $countRadars(), 'end(11) < start(20): nada foi salvo');

// 7) intervalo > 10 páginas => rejeitado, nada salvo
$before7 = $countRadars();
$_POST = ['name' => 'Radar Intervalo Grande', 'marketplaces' => ['shopee'], 'shopee_page_start' => '1', 'shopee_page_end' => '20'];
@Actions::radarSave($appAction);
T::eq($before7, $countRadars(), '1–20 (20 páginas, > 10): rejeitado, nada foi salvo');

// intervalos válidos de exatamente 10 e menos de 10 são aceitos, para contraste
$_POST = ['name' => 'Radar 10 Paginas OK', 'marketplaces' => ['shopee'], 'shopee_page_start' => '1', 'shopee_page_end' => '10'];
@Actions::radarSave($appAction);
$rowOk10 = $dbAction->first("SELECT * FROM hr_radars WHERE name = 'Radar 10 Paginas OK'");
T::ok($rowOk10 !== null, '1–10 (exatamente 10 páginas): aceito e salvo');

// 8) start < 1 => rejeitado no salvamento (não é silenciosamente normalizado para 1 quando o usuário está editando)
$before8 = $countRadars();
$_POST = ['name' => 'Radar Start Invalido', 'marketplaces' => ['shopee'], 'shopee_page_start' => '0', 'shopee_page_end' => '5'];
@Actions::radarSave($appAction);
T::eq($before8, $countRadars(), 'start=0: rejeitado no salvamento pela interface, nada foi salvo');
// a normalização defensiva do MODELO (não do formulário) continua existindo, para uso interno/round-trip seguro:
T::eq(1, Radar::normalizeShopeePageStart(0), 'Radar::normalizeShopeePageStart(0) continua normalizando para 1 — rede de segurança do modelo, não do formulário');

// ML-only não precisa nem valida os campos Shopee (nem são enviados — ficam desabilitados no form)
$before9 = $countRadars();
$_POST = ['name' => 'Radar ML Only Sem Shopee Fields', 'marketplaces' => ['mercado_livre']];
@Actions::radarSave($appAction);
T::eq($before9 + 1, $countRadars(), 'radar só-ML sem nenhum campo shopee_page_* no POST: salvo normalmente (validação Shopee não se aplica)');

// =====================================================================
T::group('9-10) Radar antigo sem shopee_page_end — retrocompatibilidade via fromRow()');

$legacyRowBase = [
    'id' => 1, 'slug' => 'legado', 'name' => 'Radar Legado', 'enabled' => 1,
    'marketplaces' => json_encode(['shopee']), 'ml_categories' => json_encode([]),
    'shopee_keywords' => json_encode([]), 'extra_keywords' => json_encode([]), 'excluded_words' => json_encode([]),
    'min_discount' => null, 'price_min' => null, 'price_max' => null, 'require_video' => 0,
    // shopee_page_end DELIBERADAMENTE ausente (radar salvo antes da migration 010)
];

$legacy9 = Radar::fromRow($legacyRowBase + ['pages_per_category' => 10, 'shopee_page_start' => 11]);
T::eq(11, $legacy9->shopeePageStart, 'radar antigo: start preservado (11)');
T::eq(20, $legacy9->shopeePageEnd, 'radar antigo SEM shopee_page_end: end derivado = start(11) + pages_per_category(10) - 1 = 20 — comportamento equivalente ao de antes');

$legacy10 = Radar::fromRow($legacyRowBase + ['pages_per_category' => 10, 'shopee_page_start' => 1]);
T::eq(1, $legacy10->shopeePageStart, 'radar antigo (caso padrão): start=1');
T::eq(10, $legacy10->shopeePageEnd, 'radar antigo (caso padrão) SEM shopee_page_end: end derivado = 1 + 10 - 1 = 10');

// radar antigo E com shopee_page_end já presente (pós-migration, já salvo pela nova UI): usa o valor real, ignora a derivação
$legacyComEnd = Radar::fromRow($legacyRowBase + ['pages_per_category' => 10, 'shopee_page_start' => 11, 'shopee_page_end' => 15]);
T::eq(15, $legacyComEnd->shopeePageEnd, 'com shopee_page_end já presente: usa o valor salvo (15), não deriva de pages_per_category');

// radar antigo sem NENHUM dos dois campos (anterior à migration 009 também)
$legacyMuitoAntigo = Radar::fromRow($legacyRowBase + ['pages_per_category' => 5]);
T::eq(1, $legacyMuitoAntigo->shopeePageStart, 'radar muito antigo (pré-009): start vira 1');
T::eq(5, $legacyMuitoAntigo->shopeePageEnd, 'radar muito antigo (pré-009): end derivado = 1 + 5 - 1 = 5');

// prova end a end: um radar assim, coletando via ShopeeCollector, percorre exatamente a faixa equivalente à de antes
$cLegacy = $makeShopeeFake($status);
$radarLegacyEquivalente = Radar::fromRow($legacyRowBase + ['pages_per_category' => 10, 'shopee_page_start' => 11]);
$cLegacy->collect(new CollectorContext(radar: $radarLegacyEquivalente));
T::eq(range(11, 20), $cLegacy->pagesRequested, 'coleta real do radar antigo (start=11, pages_per_category=10, sem end salvo): percorre 11..20, idêntico ao comportamento anterior a esta mudança');

// =====================================================================
T::group('11-15) Formulário Shopee — "Página inicial" / "Página final", sem "Páginas do feed", controles separados');

$captionVisible = static function (string $html, string $id): bool {
    if (!preg_match('/<label id="' . preg_quote($id, '/') . '"[^>]*>/', $html, $m)) {
        return false;
    }
    return !str_contains($m[0], 'hidden');
};

$htmlShopeeOnly = View::render('radars/edit', [
    'radar' => $mkRadar(['name' => 'Radar View', 'mp' => ['shopee'], 'start' => 11, 'end' => 20]),
    'catalog' => [],
    'shopee_available' => true,
]);
T::ok(str_contains($htmlShopeeOnly, 'Página inicial'), 'formulário contém "Página inicial"');
T::ok(str_contains($htmlShopeeOnly, 'Página final'), 'formulário contém "Página final"');
T::ok(str_contains($htmlShopeeOnly, 'name="shopee_page_start"'), 'input name="shopee_page_start" presente');
T::ok(str_contains($htmlShopeeOnly, 'name="shopee_page_end"'), 'input name="shopee_page_end" presente');
T::ok(str_contains($htmlShopeeOnly, 'value="11"') && str_contains($htmlShopeeOnly, 'value="20"'), 'valores atuais do radar (11 e 20) pré-preenchidos');
T::ok(!str_contains($htmlShopeeOnly, 'Páginas do feed'), '13) "Páginas do feed" NÃO aparece mais em lugar nenhum do formulário — não é mais um controle da paginação Shopee');

$htmlMlOnly = View::render('radars/edit', [
    'radar' => $mkRadar(['name' => 'Radar ML', 'mp' => ['mercado_livre'], 'cats' => [['id' => 'MLB1574', 'label' => 'Casa']], 'ml_pages' => 7]),
    'catalog' => [],
    'shopee_available' => true,
]);
T::ok(str_contains($htmlMlOnly, 'Páginas / categoria'), '14) ML-only: continua apresentando "Páginas / categoria"');
T::ok(str_contains($htmlMlOnly, 'name="pages_per_category"') && str_contains($htmlMlOnly, 'value="7"'), '14) ML-only: pages_per_category presente com o valor atual (7)');
T::ok(str_contains($htmlMlOnly, 'max="10"'), 'o atributo max="10" do pages_per_category continua no HTML (limite do ML preservado)');
T::eq(false, $captionVisible($htmlMlOnly, 'label-ml-caption'), 'ML-only: legenda "MERCADO LIVRE" fica oculta (preserva a aparência atual do formulário só-ML)');

$htmlBoth = View::render('radars/edit', [
    'radar' => $mkRadar(['name' => 'Radar Ambos', 'mp' => ['mercado_livre', 'shopee'], 'cats' => [['id' => 'MLB1574', 'label' => 'Casa']], 'ml_pages' => 3, 'start' => 1, 'end' => 10]),
    'catalog' => [],
    'shopee_available' => true,
]);
T::eq(true, $captionVisible($htmlBoth, 'label-ml-caption'), '15) ML+Shopee: legenda "MERCADO LIVRE" visível — controles separados');
T::eq(true, $captionVisible($htmlBoth, 'label-shopee-caption'), '15) ML+Shopee: legenda "SHOPEE" visível — controles separados');
T::ok(str_contains($htmlBoth, 'Páginas / categoria'), 'ML+Shopee: "Páginas / categoria" continua presente para o lado ML');
T::ok(str_contains($htmlBoth, 'Página inicial') && str_contains($htmlBoth, 'Página final'), 'ML+Shopee: "Página inicial"/"Página final" presentes para o lado Shopee');

// =====================================================================
T::group('16) Round-trip de persistência (create → find)');

$dbRt = TestDb::fresh('shopee_page_range_roundtrip');
$appRt = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbRt);
$radarToSave = $mkRadar(['name' => 'Radar Round-trip', 'start' => 21, 'end' => 30]);
$newId = $appRt->radars()->create($radarToSave);
$reloaded = $appRt->radars()->find($newId);
T::eq(21, $reloaded->shopeePageStart, 'round-trip: shopeePageStart preservado');
T::eq(30, $reloaded->shopeePageEnd, 'round-trip: shopeePageEnd preservado');
TestDb::cleanup('shopee_page_range_roundtrip');

// =====================================================================
T::group('17) Dedupe continua funcionando dentro da faixa');

$c17 = $makeShopeeFake($status);
$dupBody = json_encode(['data' => ['productOfferV2' => ['nodes' => [
    ['itemId' => 'DUP1', 'shopId' => '1', 'productName' => 'Produto duplicado', 'price' => '10.00', 'productLink' => 'https://x', 'offerLink' => 'https://x'],
]]]]);
$c17->queue = [
    ['status' => 200, 'body' => $dupBody, 'error' => null],
    ['status' => 200, 'body' => $dupBody, 'error' => null],
];
$r17 = $c17->collect(new CollectorContext(radar: $mkRadar(['start' => 1, 'end' => 2])));
T::eq(1, count($r17->products), 'mesmo itemId repetido entre páginas da faixa: só 1 produto no relatório (dedupe intacto)');

// =====================================================================
T::group('18) Condições de parada antecipada continuam funcionando (mesmo com início > 1)');

$c18a = $makeShopeeFake($status);
$c18a->queue = [['status' => 200, 'body' => json_encode(['data' => ['productOfferV2' => ['nodes' => []]]]), 'error' => null]];
$r18a = $c18a->collect(new CollectorContext(radar: $mkRadar(['start' => 11, 'end' => 20])));
T::eq([11], $c18a->pagesRequested, 'início=11: página vazia já para o loop na 1ª requisição (não insiste até a 20)');
T::eq(0, count($r18a->errors), 'página vazia não é erro');

$c18b = $makeShopeeFake($status);
$c18b->queue = [['status' => 401, 'body' => '{}', 'error' => null]];
$r18b = $c18b->collect(new CollectorContext(radar: $mkRadar(['start' => 21, 'end' => 30])));
T::eq([21], $c18b->pagesRequested, 'início=21: erro HTTP já para na 1ª requisição da faixa');
T::eq('Shopee p21: HTTP 401.', $r18b->errors[0], 'mensagem de erro usa o número de página REAL (21), não um índice relativo de loop');

// =====================================================================
T::group('19) Mercado Livre — comportamento IDÊNTICO ao de antes, mesmo com shopeePageStart/End preenchidos');

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

// MESMO Radar tem shopeePageStart=11/shopeePageEnd=50 — nunca lidos pelo MercadoLivreCollector/CollectorContext::effectiveCategories()/effectiveMaxPages()
$radarMlComShopeeRange = $mkRadar(['mp' => ['mercado_livre', 'shopee'], 'cats' => [['id' => 'MLB1574', 'label' => 'Casa']], 'ml_pages' => 3, 'start' => 11, 'end' => 50]);
$reportMl = $mlCollector->collect(new CollectorContext(categories: ['MLB1574'], radar: $radarMlComShopeeRange));
T::eq(3, $reportMl->pagesFetched, 'ML: continua buscando exatamente pagesPerCategory páginas (3), sem nenhuma relação com shopeePageStart/End');
T::ok(!str_contains($fakeHttp->urls[0], 'page='), 'ML: 1ª página segue SEM parâmetro "page" na URL (page 1 implícito) — shopeePageStart/End=11/50 do MESMO radar não vazaram para o ML');
T::ok(str_contains($fakeHttp->urls[1], 'page=2'), 'ML: 2ª chamada é page=2 (não 12) — confirma que o ML nunca lê shopeePageStart/End');
T::ok(str_contains($fakeHttp->urls[2], 'page=3'), 'ML: 3ª chamada é page=3 — sequência normal de sempre');

// =====================================================================
T::group('20) Query GraphQL / limit=50 não foram alterados');

$c20 = $makeShopeeFake($status);
$c20->collect(new CollectorContext(radar: $mkRadar(['start' => 5, 'end' => 7])));
foreach ($c20->limitsRequested as $limit) {
    T::eq(50, $limit, 'limit enviado continua 50 em toda requisição da faixa');
}
T::eq(3, count($c20->limitsRequested), '3 requisições feitas para a faixa 5-7 (todas com limit=50)');

TestDb::cleanup('shopee_page_range');
TestDb::cleanup('shopee_page_range_action');
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);
