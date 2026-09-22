<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\MercadoLivre\MercadoLivreCollector;
use HotRadar\Collector\MercadoLivre\OfertasJsonParser;
use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Support\Http;
use HotRadar\Web\Actions;

/**
 * Filtro positivo do Radar ("Palavras desejadas") — auditoria em
 * SINERGIA-HOTRADAR-AUDITORIA-FILTRO-POSITIVO.md. Cobre os 13 cenários da
 * auditoria + 2 adicionais de normalização de desiredWordsMode (14, 15).
 */

$mkProduct = static fn (string $title, string $id = 'MLB1'): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre',
    marketplaceProductId: $id,
    title: $title,
    urlOriginal: 'https://example.com/x',
);

$mkRadar = static function (array $o): Radar {
    return new Radar(
        id: null, slug: '', name: $o['name'] ?? 'R', enabled: true,
        marketplaces: $o['mp'] ?? ['mercado_livre'],
        mlCategories: [], shopeeKeywords: [], extraKeywords: [],
        excludedWords: $o['ex'] ?? [],
        pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
        desiredWords: $o['dw'] ?? [],
        desiredWordsMode: $o['mode'] ?? 'any',
    );
};

// =====================================================================
T::group('1) desiredWords vazio — comportamento atual EXATAMENTE preservado');

$r1 = $mkRadar(['dw' => []]);
T::ok($r1->accepts($mkProduct('Qualquer título aqui'))['ok'], 'campo vazio: aceita qualquer produto (sem filtro extra)');
T::ok($r1->accepts($mkProduct('meia calcinha sutiã'))['ok'], 'campo vazio: aceita mesmo produto que casaria com termos hipotéticos');

$r1WithExcl = $mkRadar(['dw' => [], 'ex' => ['usado']]);
T::ok(!$r1WithExcl->accepts($mkProduct('Produto usado'))['ok'], 'campo vazio: excludedWords continua funcionando normalmente, sem interferência');

// =====================================================================
T::group('2) modo "any" — pelo menos um termo presente');

$r2 = $mkRadar(['dw' => ['meia', 'calcinha', 'sutiã'], 'mode' => 'any']);
T::eq('any', $r2->desiredWordsMode, 'modo salvo = any');
T::ok($r2->accepts($mkProduct('Kit 6 pares de meias femininas'))['ok'], 'any: casa com "meia" (substring de "meias") — aceito');
T::ok($r2->accepts($mkProduct('Sutiã de renda confortável'))['ok'], 'any: casa com "sutiã" — aceito');

// =====================================================================
T::group('3) modo "any" — nenhum termo presente');

$v3 = $r2->accepts($mkProduct('Panela de pressão 5 litros'));
T::ok(!$v3['ok'], 'any: nenhum termo desejado presente — rejeitado');
T::ok($v3['reason'] !== null, 'motivo da rejeição presente');

// =====================================================================
T::group('4) modo "all" — todos os termos presentes');

$r4 = $mkRadar(['dw' => ['meia', 'calcinha'], 'mode' => 'all']);
T::eq('all', $r4->desiredWordsMode, 'modo salvo = all');
T::ok($r4->accepts($mkProduct('Kit meia e calcinha femininas'))['ok'], 'all: título contém os dois termos — aceito');

// =====================================================================
T::group('5) modo "all" — só parte dos termos presentes (rejeitado)');

T::ok(!$r4->accepts($mkProduct('Kit só de meias femininas'))['ok'], 'all: falta "calcinha" — rejeitado');
T::ok(!$r4->accepts($mkProduct('Só calcinha de renda'))['ok'], 'all: falta "meia" — rejeitado');

// =====================================================================
T::group('6) exclusão prevalece sobre inclusão');

$r6 = $mkRadar(['dw' => ['meia', 'calcinha', 'sutiã', 'luva'], 'mode' => 'any', 'ex' => ['infantil', 'masculina']]);
T::ok($r6->accepts($mkProduct('Kit 6 pares de meias femininas'))['ok'], 'casa desejada, sem termo excluído — aceito');
$v6 = $r6->accepts($mkProduct('Kit meias masculinas'));
T::ok(!$v6['ok'], 'casa desejada ("meia") MAS também casa excluída ("masculina") — rejeitado');
T::ok(str_contains((string) $v6['reason'], 'excluída'), 'motivo da rejeição é a exclusão, não "não contém desejadas" — exclusão teve prioridade de avaliação');

// =====================================================================
T::group('7) case-insensitive / UTF-8 (paridade com excludedWords — sem remoção de acento)');

$r7 = $mkRadar(['dw' => ['SUTIÃ'], 'mode' => 'any']);
T::ok($r7->accepts($mkProduct('sutiã básico'))['ok'], 'termo maiúsculo casa com título minúsculo (case-insensitive)');
T::ok($r7->accepts($mkProduct('SUTIÃ PROMOÇÃO'))['ok'], 'termo casa com título todo maiúsculo');
$r7b = $mkRadar(['dw' => ['sutia'], 'mode' => 'any']); // sem acento
T::ok(!$r7b->accepts($mkProduct('sutiã básico'))['ok'], 'SEM remoção de acento — "sutia" não casa com "sutiã" (paridade deliberada com excludedWords)');

// =====================================================================
T::group('8) parsing de vírgulas/espaços/quebras de linha (mesmo parser de excluded_words/extra_keywords)');

$_POST = ['name' => 'Radar Parsing', 'marketplaces' => ['mercado_livre'], 'desired_words' => "  meia ,, calcinha \n sutiã  "];
$dbParse = TestDb::fresh('radar_desired_words_parse');
$appParse = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbParse);
@Actions::radarSave($appParse);
$rowParse = $dbParse->first("SELECT * FROM hr_radars WHERE name = 'Radar Parsing'");
$savedParse = Radar::fromRow($rowParse);
T::eq(['meia', 'calcinha', 'sutiã'], $savedParse->desiredWords, 'vírgulas duplas, espaços e quebra de linha tratados igual a excluded_words/extra_keywords');
TestDb::cleanup('radar_desired_words_parse');

// =====================================================================
T::group('9) ShopeeCollector::collect() aplica o filtro (SEM chamada de rede — fake httpPost())');

$dbSh = TestDb::fresh('radar_desired_words_shopee');
$settingsSh = new SettingsRepository($dbSh, new AuditRepository($dbSh));
$settingsSh->set('shopee', ['open_api_access' => 'granted']);
foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}
putenv('SHOPEE_APP_ID=fake-app-id-dw-teste');
putenv('SHOPEE_SECRET=fake-secret-dw-teste');
$statusSh = new ShopeeStatus($settingsSh);

$fakeShopee = new class('fake-app-id-dw-teste', 'fake-secret-dw-teste', 'https://fake.shopee.example/graphql', $statusSh) extends ShopeeCollector {
    protected function httpPost(string $body, array $headers): array
    {
        $bodyJson = json_encode(['data' => ['productOfferV2' => ['nodes' => [
            ['itemId' => '1', 'shopId' => '1', 'productName' => 'Kit meias femininas coloridas', 'price' => '19.90', 'productLink' => 'https://shopee.com.br/a', 'offerLink' => 'https://s.shopee.com.br/a'],
            ['itemId' => '2', 'shopId' => '1', 'productName' => 'Panela de pressão 5 litros', 'price' => '99.90', 'productLink' => 'https://shopee.com.br/b', 'offerLink' => 'https://s.shopee.com.br/b'],
        ]]]]);
        return ['status' => 200, 'body' => $bodyJson, 'error' => null];
    }
};
$radarSh = $mkRadar(['dw' => ['meia', 'calcinha'], 'mode' => 'any', 'mp' => ['shopee']]);
$reportSh = $fakeShopee->collect(new CollectorContext(maxPages: 1, radar: $radarSh));
T::eq(1, count($reportSh->products), 'Shopee: só o produto que casa "meia" passa');
T::eq('Kit meias femininas coloridas', $reportSh->products[0]->title, 'produto correto foi mantido');
T::eq(1, $reportSh->filteredByRadar, 'produto sem termo desejado foi contado como filtrado pelo radar');
TestDb::cleanup('radar_desired_words_shopee');
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);

// =====================================================================
T::group('10) MercadoLivreCollector::collect() aplica o filtro (SEM chamada de rede — fake Http::get())');

$fakeItem = static function (string $id, string $title): array {
    return [
        'position' => 1,
        'card' => [
            'metadata' => ['id' => $id, 'product_id' => $id, 'url' => 'www.mercadolivre.com.br/produto/p/' . $id],
            'components' => [
                ['type' => 'title', 'title' => ['text' => $title]],
            ],
        ],
    ];
};
$mlPayload = [
    'appProps' => ['pageProps' => ['data' => ['items' => [
        $fakeItem('MLB900001', 'Kit 6 pares de meias femininas'),
        $fakeItem('MLB900002', 'Panela de pressão 5 litros'),
    ]]]],
];
$fakeHtml = '<html><script>_n.ctx.r=' . json_encode($mlPayload, JSON_UNESCAPED_UNICODE) . ';</script></html>';

$fakeHttp = new class($fakeHtml) extends Http {
    public function __construct(private readonly string $html)
    {
        parent::__construct('test-agent');
    }
    public function get(string $url, array $headers = []): array
    {
        return ['status' => 200, 'body' => $this->html, 'error' => null, 'bytes' => strlen($this->html), 'final_url' => $url];
    }
};
$mlCollector = new MercadoLivreCollector($fakeHttp, new OfertasJsonParser(), requestDelayMs: 0, defaultCategories: ['MLB1574']);
$radarMl = $mkRadar(['dw' => ['meia', 'calcinha'], 'mode' => 'any', 'mp' => ['mercado_livre']]);
$reportMl = $mlCollector->collect(new CollectorContext(maxPages: 1, categories: ['MLB1574'], radar: $radarMl));
T::eq(1, count($reportMl->products), 'ML: só o produto que casa "meia" passa');
T::eq('Kit 6 pares de meias femininas', $reportMl->products[0]->title, 'produto correto foi mantido');
T::eq(1, $reportMl->filteredByRadar, 'produto sem termo desejado foi contado como filtrado pelo radar');

// =====================================================================
T::group('11) Radar combinado (Mercado Livre + Shopee) — mesmo veredito nos dois lados');

$radarBoth = $mkRadar(['dw' => ['meia'], 'mode' => 'any', 'mp' => ['mercado_livre', 'shopee']]);
$prodMatch = $mkProduct('Meia infantil colorida');
$prodNoMatch = $mkProduct('Panela antiaderente');
// videoFilterSupported=true (chamada do ML) e =false (chamada da Shopee) — resultado do filtro de palavras é idêntico nos dois, pois não depende desse parâmetro
T::eq($radarBoth->accepts($prodMatch, videoFilterSupported: true)['ok'], $radarBoth->accepts($prodMatch, videoFilterSupported: false)['ok'], 'produto que casa: mesmo veredito via chamada estilo ML e estilo Shopee');
T::ok($radarBoth->accepts($prodMatch, videoFilterSupported: true)['ok'], 'radar combinado: produto que casa é aceito');
T::ok(!$radarBoth->accepts($prodNoMatch, videoFilterSupported: false)['ok'], 'radar combinado: produto que não casa é rejeitado, também do lado Shopee');

// =====================================================================
T::group('12) Radar::fromRow() com linha SEM as colunas novas (radar salvo antes da migration)');

$legacyRow = [
    'id' => 1, 'slug' => 'legado', 'name' => 'Radar Legado', 'enabled' => 1,
    'marketplaces' => json_encode(['mercado_livre']),
    'ml_categories' => json_encode([]),
    'shopee_keywords' => json_encode([]),
    'extra_keywords' => json_encode([]),
    'excluded_words' => json_encode(['usado']),
    'pages_per_category' => 3,
    'min_discount' => null, 'price_min' => null, 'price_max' => null, 'require_video' => 0,
    // desired_words / desired_words_mode DELIBERADAMENTE ausentes do array
];
$legacy = Radar::fromRow($legacyRow);
T::eq([], $legacy->desiredWords, 'linha sem a coluna: desiredWords vira [] (não erro)');
T::eq('any', $legacy->desiredWordsMode, 'linha sem a coluna: desiredWordsMode vira "any" (não erro)');
T::ok($legacy->accepts($mkProduct('Qualquer coisa'))['ok'], 'radar legado continua aceitando produtos normalmente');
T::ok(!$legacy->accepts($mkProduct('Produto usado'))['ok'], 'radar legado: excludedWords pré-existente continua funcionando');

// linha com a coluna presente mas NULL (simula ALTER TABLE ADD COLUMN ... NULL sem backfill)
$legacyRowNull = $legacyRow + ['desired_words' => null, 'desired_words_mode' => null];
$legacyNull = Radar::fromRow($legacyRowNull);
T::eq([], $legacyNull->desiredWords, 'coluna presente porém NULL: desiredWords vira []');
T::eq('any', $legacyNull->desiredWordsMode, 'coluna presente porém NULL: desiredWordsMode vira "any"');

// =====================================================================
T::group('13) round-trip de persistência (create → find)');

$dbRt = TestDb::fresh('radar_desired_words_roundtrip');
$appRt = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbRt);
$radarToSave = $mkRadar(['name' => 'Radar Round-trip', 'dw' => ['meia', 'calcinha', 'sutiã'], 'mode' => 'all']);
$newId = $appRt->radars()->create($radarToSave);
$reloaded = $appRt->radars()->find($newId);
T::eq(['meia', 'calcinha', 'sutiã'], $reloaded->desiredWords, 'round-trip: desiredWords preservado');
T::eq('all', $reloaded->desiredWordsMode, 'round-trip: desiredWordsMode preservado');
TestDb::cleanup('radar_desired_words_roundtrip');

// =====================================================================
T::group('14) desiredWordsMode inválido vindo do BANCO → normaliza para "any"');

foreach (['ALL', 'todos', '', 'qualquer-coisa-invalida', '  any  ', null] as $bogus) {
    $row = $legacyRow + ['desired_words' => json_encode(['x']), 'desired_words_mode' => $bogus];
    $r = Radar::fromRow($row);
    T::eq('any', $r->desiredWordsMode, 'valor de banco ' . json_encode($bogus) . ' normaliza para "any" (nunca vira "all" silenciosamente)');
}
// o único jeito de obter "all" é o valor exato "all"
$rowAll = $legacyRow + ['desired_words' => json_encode(['x']), 'desired_words_mode' => 'all'];
T::eq('all', Radar::fromRow($rowAll)->desiredWordsMode, 'valor de banco exatamente "all" é preservado');

// =====================================================================
T::group('15) desired_words_mode inválido vindo do POST → salva como "any"');

$dbPost = TestDb::fresh('radar_desired_words_post');
$appPost = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $dbPost);

foreach (['ALL', 'todos-os-termos', '<script>x</script>', ''] as $i => $bogus) {
    $_POST = ['name' => 'Radar POST Bogus ' . $i, 'marketplaces' => ['mercado_livre'], 'desired_words_mode' => $bogus];
    @Actions::radarSave($appPost);
    $row = $dbPost->first('SELECT * FROM hr_radars WHERE name = ?', ['Radar POST Bogus ' . $i]);
    T::ok($row !== null, 'radar foi criado mesmo com desired_words_mode inválido no POST');
    T::eq('any', Radar::fromRow($row)->desiredWordsMode, 'POST com desired_words_mode=' . json_encode($bogus) . ' é salvo como "any"');
}

// valor válido no POST é preservado
$_POST = ['name' => 'Radar POST Valido', 'marketplaces' => ['mercado_livre'], 'desired_words_mode' => 'all'];
@Actions::radarSave($appPost);
$rowValido = $dbPost->first("SELECT * FROM hr_radars WHERE name = 'Radar POST Valido'");
T::eq('all', Radar::fromRow($rowValido)->desiredWordsMode, 'POST com desired_words_mode=all válido é preservado');

// ausência do campo no POST = "any" (mesmo comportamento de "campo vazio")
$_POST = ['name' => 'Radar POST Sem Campo', 'marketplaces' => ['mercado_livre']];
@Actions::radarSave($appPost);
$rowSemCampo = $dbPost->first("SELECT * FROM hr_radars WHERE name = 'Radar POST Sem Campo'");
T::eq('any', Radar::fromRow($rowSemCampo)->desiredWordsMode, 'POST sem desired_words_mode: default "any"');
T::eq([], Radar::fromRow($rowSemCampo)->desiredWords, 'POST sem desired_words: default []');

TestDb::cleanup('radar_desired_words_post');
