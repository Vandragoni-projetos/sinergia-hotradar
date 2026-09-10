<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Discovery\DiscoveryService;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\EditorialRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('E4 — decisão editorial e atribuição de radar preservadas nas recoletas');

$db = TestDb::fresh('editorial');
$products = new ProductRepository($db);
$editorial = new EditorialRepository($db);
$discovery = new DiscoveryService(
    $products,
    new SnapshotRepository($db),
    new RunRepository($db),
    new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php')),
    new HotRadar\Repository\ProductRadarRepository($db),
);

$radarRepo = new RadarRepository($db, new AuditRepository($db));
$rid = $radarRepo->create(new Radar(
    id: null, slug: '', name: 'Radar A', enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: [['id' => 'MLB1574', 'label' => 'Casa']], shopeeKeywords: [], extraKeywords: [],
    excludedWords: [], pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
));
$radarA = $radarRepo->find($rid);

$fake = new class implements CollectorInterface {
    public array $out = [];
    public function marketplace(): string { return 'mercado_livre'; }
    public function source(): string { return 'ofertas_ml'; }
    public function isAvailable(): bool { return true; }
    public function unavailableReason(): ?string { return null; }
    public function collect(CollectorContext $ctx): CollectorReport
    {
        $r = new CollectorReport();
        foreach ($this->out as $p) {
            if ($ctx->radar) { $p->radarSlug = $ctx->radar->slug; $p->radarId = $ctx->radar->id; }
            $r->add($p);
            $r->cardsSeen++;
        }
        $r->pagesFetched = 1;
        return $r;
    }
};

$mk = static fn (float $price, int $disc): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB777', title: 'Air Fryer',
    urlOriginal: 'https://ml/MLB777', priceCurrent: $price, discountPct: $disc,
    salesSignal: 'muito_alto', rating: 4.8, source: 'ofertas_ml',
);

// coleta 1 pelo Radar A
$fake->out = [$mk(400.0, 30)];
$discovery->run($fake, new CollectorContext(radar: $radarA));
$row = $products->findByMarketplaceId('mercado_livre', 'MLB777');
T::eq('radar-a', (string) $row['radar_slug'], 'produto atribuído ao Radar A');
T::eq('descoberto', (string) $row['status'], 'status inicial = descoberto');

// humano aprova
$products->setStatus((int) $row['id'], EditorialStatus::APROVADO, null, 'humano:teste');
$editorial->log((int) $row['id'], 'descoberto', EditorialStatus::APROVADO, null, 'humano:teste');

// coleta 2 pelo Radar A (preço muda)
$fake->out = [$mk(320.0, 46)];
$discovery->run($fake, new CollectorContext(radar: $radarA));
$row = $products->findByMarketplaceId('mercado_livre', 'MLB777');
T::eq('aprovado', (string) $row['status'], 'status APROVADO preservado após recoleta');
T::eq('320', (string) (float) $row['price_current'], 'preço atualizado (decisão intacta)');

// coleta 3 por OUTRO radar — não rouba a atribuição nem o status
$ridB = $radarRepo->create(new Radar(
    id: null, slug: '', name: 'Radar B', enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: [['id' => 'MLB5726', 'label' => 'Eletro']], shopeeKeywords: [], extraKeywords: [],
    excludedWords: [], pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
));
$radarB = $radarRepo->find($ridB);
$fake->out = [$mk(300.0, 50)];
$discovery->run($fake, new CollectorContext(radar: $radarB));
$row = $products->findByMarketplaceId('mercado_livre', 'MLB777');
T::eq('radar-a', (string) $row['radar_slug'], 'atribuição de radar NÃO muda (pertence a quem descobriu)');
T::eq('aprovado', (string) $row['status'], 'status ainda preservado após coleta de outro radar');

// trilha editorial mantida
$tl = $editorial->timeline((int) $row['id']);
T::eq(1, count($tl), 'trilha editorial tem 1 evento (a aprovação humana)');

// dedupe: 1 linha só
T::eq(1, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'dedupe: continua 1 produto após 3 coletas');
T::eq(3, (int) $db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'], '3 snapshots preservados');

TestDb::cleanup('editorial');
