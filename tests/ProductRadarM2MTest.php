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
use HotRadar\Repository\ProductRadarRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('Produto × Radar — MUITOS-PARA-MUITOS');

$db = TestDb::fresh('m2m');
$products = new ProductRepository($db);
$pr = new ProductRadarRepository($db);
$radars = new RadarRepository($db, new AuditRepository($db));
$editorial = new EditorialRepository($db);
$discovery = new DiscoveryService(
    $products,
    new SnapshotRepository($db),
    new RunRepository($db),
    new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php')),
    $pr,
);

$mkRadar = static fn (string $name, array $cats, array $ex = []): int => $radars->create(new Radar(
    id: null, slug: '', name: $name, enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: array_map(static fn ($c) => ['id' => $c, 'label' => $c], $cats),
    shopeeKeywords: [], extraKeywords: [], excludedWords: $ex,
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
));

$rCasa = $radars->find($mkRadar('Casa', ['MLB1574']));
$rCozinha = $radars->find($mkRadar('Cozinha', ['MLB1574']));
$rOrg = $radars->find($mkRadar('Organização', ['MLB1574']));

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
        foreach ($this->out as $p) {
            if ($ctx->radar) { $p->radarSlug = $ctx->radar->slug; $p->radarId = $ctx->radar->id; }
            $r->add($p);
            $r->cardsSeen++;
        }
        return $r;
    }
};

$organizador = static fn (): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB555', title: 'Organizador de cozinha modular',
    urlOriginal: 'https://ml/MLB555', priceCurrent: 89.9, discountPct: 35, salesSignal: 'alto',
    rating: 4.8, source: 'ofertas_ml',
);

// ---- mesmo produto encontrado por 3 radares ----
$fake->out = [$organizador()];
$discovery->run($fake, new CollectorContext(radar: $rCasa));
$fake->out = [$organizador()];
$discovery->run($fake, new CollectorContext(radar: $rCozinha));
$fake->out = [$organizador()];
$r3 = $discovery->run($fake, new CollectorContext(radar: $rOrg));

T::eq(1, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], '1 produto no banco (não duplicou)');
$prod = $products->findByMarketplaceId('mercado_livre', 'MLB555');
$assoc = $pr->radarsForProduct((int) $prod['id']);
T::eq(3, count($assoc), '3 associações de radar para o mesmo produto');
$slugs = array_column($assoc, 'slug');
sort($slugs);
T::eq(['casa', 'cozinha', 'organizacao'], $slugs, 'associado a casa + cozinha + organizacao');

// ---- filtros de CADA radar encontram o MESMO produto ----
foreach (['casa', 'cozinha', 'organizacao'] as $slug) {
    $found = $products->search(['radar' => $slug]);
    T::eq(1, count($found), "filtro radar=$slug encontra o produto");
    T::eq('MLB555', (string) $found[0]['marketplace_product_id'], "produto certo no filtro radar=$slug");
}

// ---- nova coleta NÃO cria associação duplicada ----
$fake->out = [$organizador()];
$rDup = $discovery->run($fake, new CollectorContext(radar: $rCasa));
T::eq(0, $rDup['assoc_new'], '2ª coleta pelo mesmo radar: 0 novas associações');
T::eq(3, count($pr->radarsForProduct((int) $prod['id'])), 'continua com 3 associações');
$n = (int) $db->first('SELECT COUNT(*) n FROM hr_product_radars WHERE product_id = ?', [$prod['id']])['n'];
T::eq(3, $n, 'hr_product_radars tem exatamente 3 linhas p/ o produto (UNIQUE respeitado)');

// last_seen_at foi atualizado sem duplicar
$casaAssoc = $db->first('SELECT first_seen_at, last_seen_at FROM hr_product_radars pr JOIN hr_radars r ON r.id=pr.radar_id WHERE r.slug=? AND pr.product_id=?', ['casa', $prod['id']]);
T::ok($casaAssoc['last_seen_at'] >= $casaAssoc['first_seen_at'], 'last_seen_at >= first_seen_at após recoleta');

// ---- associação de radar NÃO altera decisão editorial ----
$products->setStatus((int) $prod['id'], EditorialStatus::APROVADO, null, 'humano:teste');
$editorial->log((int) $prod['id'], 'descoberto', EditorialStatus::APROVADO, null, 'humano:teste');
$fake->out = [$organizador()];
$discovery->run($fake, new CollectorContext(radar: $rCozinha)); // reassocia
$prod = $products->findByMarketplaceId('mercado_livre', 'MLB555');
T::eq('aprovado', (string) $prod['status'], 'status APROVADO intacto após nova associação/coleta');
T::eq(1, count($editorial->timeline((int) $prod['id'])), 'trilha editorial intacta (1 evento)');

// ---- produto exclusivo de 1 radar não aparece nos outros ----
$fake->out = [new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB999', title: 'Item só da casa',
    urlOriginal: 'https://ml/MLB999', priceCurrent: 10.0, source: 'ofertas_ml',
)];
$discovery->run($fake, new CollectorContext(radar: $rCasa));
T::eq(2, count($products->search(['radar' => 'casa'])), 'radar casa: 2 produtos');
T::eq(1, count($products->search(['radar' => 'cozinha'])), 'radar cozinha: 1 produto (só o organizador)');

TestDb::cleanup('m2m');
