<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Discovery\DiscoveryService;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Repository\ProductRadarRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('Fallback HTML — NÃO rebaixa dado rico existente para NULL');

$db = TestDb::fresh('fbmerge');
$products = new ProductRepository($db);
$discovery = new DiscoveryService(
    $products,
    new SnapshotRepository($db),
    new RunRepository($db),
    new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php')),
    new ProductRadarRepository($db),
);

$fake = new class implements CollectorInterface {
    public array $out = [];
    public function marketplace(): string { return 'mercado_livre'; }
    public function source(): string { return 'ofertas_ml'; }
    public function isAvailable(): bool { return true; }
    public function unavailableReason(): ?string { return null; }
    public function collect(CollectorContext $ctx): CollectorReport
    {
        $r = new CollectorReport();
        $r->pagesFetched = 1;
        foreach ($this->out as $p) { $r->add($p); $r->cardsSeen++; }
        return $r;
    }
};

// ---- coleta 1: RICA (scrape_json) ----
$rich = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB42', title: 'Air Fryer 5L',
    urlOriginal: 'https://ml/MLB42', imageUrl: 'https://img/rich.webp',
    priceCurrent: 399.90, pricePrevious: 699.90, discountPct: 43,
    salesSignal: 'muito_alto', rating: 4.9, rankPosition: 3,
    specialSignals: ['best_seller_candidate', 'deal_of_the_day', 'has_published_clips'],
    hasVideo: true, campaign: 'DEAL_OF_THE_DAY',
    dataQuality: 'scrape_json', source: 'ofertas_ml', nicheConfidence: 'alta',
    marketplaceExtra: ['ml_badges' => ['MAIS VENDIDO']],
);
$fake->out = [$rich];
$discovery->run($fake, new CollectorContext());

$row1 = $products->findByMarketplaceId('mercado_livre', 'MLB42');
T::eq('4.9', (string) (float) $row1['rating'], 'coleta rica gravou rating 4.9');
T::eq(1, (int) $row1['has_video'], 'coleta rica gravou has_video = 1');
T::eq('scrape_json', (string) $row1['data_quality'], 'data_quality = scrape_json');
$score1 = (int) $row1['hot_score'];
T::ok($score1 >= 80, "HOT SCORE alto na coleta rica ($score1)");

// ---- coleta 2: DEGRADADA (fallback HTML) — só título/url/imagem/preço ----
$degraded = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB42', title: 'Air Fryer 5L (novo título)',
    urlOriginal: 'https://ml/MLB42', imageUrl: null,
    priceCurrent: 349.90,          // preço mudou — DEVE atualizar
    pricePrevious: null, discountPct: null,   // fallback não parseou — NÃO deve virar NULL
    salesSignal: null, rating: null, rankPosition: null,
    specialSignals: [], hasVideo: false, campaign: null,
    dataQuality: 'scrape_html', source: 'ofertas_ml',
    marketplaceExtra: ['parser' => 'html_fallback'],
);
$fake->out = [$degraded];
$r2 = $discovery->run($fake, new CollectorContext());

$row2 = $products->findByMarketplaceId('mercado_livre', 'MLB42');
T::eq(1, $r2['gaps_filled'], 'DiscoveryService reportou 1 produto com dados ricos preservados');

// preço/título ATUALIZAM (foram observados)
T::eq('349.9', (string) (float) $row2['price_current'], 'preço atual foi atualizado pelo fallback');
T::eq('Air Fryer 5L (novo título)', (string) $row2['title'], 'título foi atualizado pelo fallback');

// dados ricos PRESERVADOS (fallback não os viu)
T::eq('4.9', (string) (float) $row2['rating'], 'rating 4.9 PRESERVADO (não virou NULL)');
T::eq(1, (int) $row2['has_video'], 'has_video = 1 PRESERVADO (fallback não vê vídeo)');
T::eq('muito_alto', (string) $row2['sales_signal'], 'sales_signal PRESERVADO');
T::eq(3, (int) $row2['rank_position'], 'rank_position PRESERVADO');
T::eq('43', (string) $row2['discount_pct'], 'discount_pct PRESERVADO (fallback não parseou)');
T::eq('699.9', (string) (float) $row2['price_previous'], 'price_previous PRESERVADO');
T::eq('DEAL_OF_THE_DAY', (string) $row2['campaign'], 'campaign PRESERVADO');
$sig = json_decode((string) $row2['special_signals'], true);
T::ok(in_array('has_published_clips', $sig, true), 'special_signals PRESERVADOS');
$extra2 = json_decode((string) $row2['marketplace_extra'], true) ?: [];
T::ok(in_array('MAIS VENDIDO', (array) ($extra2['ml_badges'] ?? []), true), 'ml_badges PRESERVADO após coleta degradada (fallback não apaga selo válido)');
T::eq('scrape_json', (string) $row2['data_quality'], 'data_quality mantém a MELHOR fidelidade conhecida (scrape_json)');

// HOT SCORE não despencou por causa do fallback
$score2 = (int) $row2['hot_score'];
T::ok($score2 >= $score1 - 5, "HOT SCORE preservado após fallback ($score1 → $score2)");

// snapshot da coleta 2 reflete o estado MERGEADO (nosso melhor conhecimento), não NULLs
$snap = $db->first('SELECT * FROM hr_product_snapshots WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$row2['id']]);
T::eq('4.9', (string) (float) $snap['rating'], 'snapshot 2 registra rating conhecido (merge), não NULL');
T::eq('349.9', (string) (float) $snap['price_current'], 'snapshot 2 registra o preço novo observado');

// ---- produto NOVO só via fallback: fica esparso mesmo (não temos como saber) ----
$fake->out = [new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB_NOVO', title: 'Item novo via fallback',
    urlOriginal: 'https://ml/MLB_NOVO', priceCurrent: 50.0,
    dataQuality: 'scrape_html', source: 'ofertas_ml', marketplaceExtra: ['parser' => 'html_fallback'],
)];
$discovery->run($fake, new CollectorContext());
$novo = $products->findByMarketplaceId('mercado_livre', 'MLB_NOVO');
T::eq(null, $novo['rating'], 'produto novo só-fallback: rating fica NULL (honesto, não inventamos)');
T::eq(0, (int) $novo['has_video'], 'produto novo só-fallback: has_video 0 (desconhecido)');
T::eq('scrape_html', (string) $novo['data_quality'], 'produto novo só-fallback: data_quality = scrape_html');

TestDb::cleanup('fbmerge');
