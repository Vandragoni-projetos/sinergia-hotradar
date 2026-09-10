<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Discovery\DiscoveryService;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Report\ReportService;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('Relatórios — comparações baseadas em hr_product_snapshots');

$db = TestDb::fresh('report');
$discovery = new DiscoveryService(
    new ProductRepository($db),
    new SnapshotRepository($db),
    new RunRepository($db),
    new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php')),
);
$reports = new ReportService($db);

$fake = new class implements CollectorInterface {
    /** @var array<int,NormalizedProduct> */
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

$mk = static fn (string $id, float $price, int $disc, string $sales, ?bool $video = false): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: $id, title: "Produto $id",
    urlOriginal: "https://ml/$id", priceCurrent: $price, discountPct: $disc,
    salesSignal: $sales, rating: 4.7, hasVideo: (bool) $video, source: 'ofertas_ml',
    nicheConfidence: 'alta', radarSlug: 'casa-organizacao',
);

// coleta 1
$fake->out = [
    $mk('MLBA', 100.0, 20, 'alto', true),
    $mk('MLBB', 200.0, 30, 'muito_alto'),
    $mk('MLBC', 50.0, 10, 'baixo'),
];
$discovery->run($fake, new CollectorContext());

$last = $reports->lastRun();
T::ok($last['exists'] === true, 'lastRun existe');
T::eq(3, (int) $last['run']['products_new'], 'última coleta: 3 novos');

$top = $reports->topHotScores([], 10);
T::eq(3, count($top), 'topHotScores lista os 3');
T::ok((int) $top[0]['hot_score'] >= (int) $top[1]['hot_score'], 'ordenado por hot_score desc');

$video = $reports->withVideo([], 10);
T::eq(1, count($video), 'só 1 produto com vídeo');
T::eq('MLBA', (string) $video[0]['marketplace_product_id'], 'produto certo com vídeo');

// coleta 2 — MLBA cai de preço e some o vídeo; MLBB melhora desconto
$fake->out = [
    $mk('MLBA', 70.0, 45, 'alto', true),
    $mk('MLBB', 150.0, 55, 'muito_alto'),
    $mk('MLBC', 50.0, 10, 'baixo'),
];
$discovery->run($fake, new CollectorContext());

T::eq(3, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'dedup: continua 3 produtos');
T::eq(6, (int) $db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'], '6 snapshots (2 por produto)');

$movers = $reports->scoreMovers([], 10);
$upIds = array_map(static fn ($r) => (string) $r['title'], $movers['up']);
T::ok(in_array('Produto MLBA', $upIds, true) || in_array('Produto MLBB', $upIds, true), 'algum produto subiu de score entre as 2 coletas');

$drops = $reports->priceDrops([], 10);
$dropIds = array_map(static fn ($r) => (string) $r['title'], $drops);
T::ok(in_array('Produto MLBA', $dropIds, true), 'MLBA aparece nas quedas de preço (100 → 70)');
$mlba = array_values(array_filter($drops, static fn ($r) => $r['title'] === 'Produto MLBA'))[0];
T::eq(30, (int) $mlba['drop_pct'], 'queda de 30% calculada dos snapshots');

$new7 = $reports->newProducts(date('Y-m-d 00:00:00', strtotime('-1 day')), [], 50);
T::eq(3, count($new7), 'novos produtos (janela) = 3');

$updated = $reports->updatedProducts([], 50);
T::eq(3, count($updated), 'todos os 3 têm ≥ 2 snapshots → "atualizados"');

$byCat = $reports->byCategory([]);
T::ok($byCat[0]['n'] === 3, 'distribuição por nicho soma 3');

$byRadar = $reports->byRadar();
T::eq('casa-organizacao', $byRadar[0]['key'], 'distribuição por radar');

// filtro por radar inexistente → vazio
T::eq(0, count($reports->topHotScores(['radar' => 'nao-existe'], 10)), 'filtro por radar inexistente = vazio');

TestDb::cleanup('report');
