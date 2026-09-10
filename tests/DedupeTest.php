<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Db\Connection;
use HotRadar\Db\Migrator;
use HotRadar\Discovery\DiscoveryService;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('Deduplicação + histórico (snapshots)');

// banco sqlite isolado só para o teste
$tmp = HR_ROOT . '/storage/test_dedupe.sqlite';
@unlink($tmp);
$db = new Connection(['driver' => 'sqlite', 'sqlite_path' => $tmp]);
(new Migrator($db, HR_ROOT . '/migrations'))->migrate();

$discovery = new DiscoveryService(
    new ProductRepository($db),
    new SnapshotRepository($db),
    new RunRepository($db),
    new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php')),
    new HotRadar\Repository\ProductRadarRepository($db),
);

/** Collector falso que devolve o que mandarmos. */
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

$p1 = static fn (float $price, int $disc, int $score = 0): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB999', title: 'Pote hermético',
    urlOriginal: 'https://ml/p/MLB999', priceCurrent: $price, discountPct: $disc,
    salesSignal: 'alto', rating: 4.7, source: 'ofertas_ml',
);

// 1ª coleta
$fake->out = [$p1(89.90, 35)];
$r1 = $discovery->run($fake, new CollectorContext());
T::eq(1, $r1['new'], '1ª coleta: 1 novo');
T::eq(0, $r1['updated'], '1ª coleta: 0 atualizados');

// 2ª coleta — MESMO produto, preço/desconto diferentes
$fake->out = [$p1(74.90, 46)];
$r2 = $discovery->run($fake, new CollectorContext());
T::eq(0, $r2['new'], '2ª coleta: 0 novos (dedupe por marketplace+id)');
T::eq(1, $r2['updated'], '2ª coleta: 1 atualizado');

$products = new ProductRepository($db);
$row = $products->findByMarketplaceId('mercado_livre', 'MLB999');
T::eq('74.9', (string) (float) $row['price_current'], 'estado atual reflete o preço mais recente');
T::eq(46, (int) $row['discount_pct'], 'desconto atualizado');

$count = (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'];
T::eq(1, $count, 'continua sendo 1 linha em hr_products (não duplicou)');

$snaps = new SnapshotRepository($db);
$hist = $snaps->history((int) $row['id']);
T::eq(2, count($hist), '2 snapshots preservados (histórico não sobrescreve)');
T::eq('89.9', (string) (float) $hist[0]['price_current'], 'snapshot 1 guardou o preço antigo');
T::eq('74.9', (string) (float) $hist[1]['price_current'], 'snapshot 2 guardou o preço novo');

// dry-run não persiste
$fake->out = [new NormalizedProduct(marketplace: 'mercado_livre', marketplaceProductId: 'MLB_DRY', title: 'x', urlOriginal: 'https://x')];
$rd = $discovery->run($fake, new CollectorContext(dryRun: true));
T::eq(0, $rd['new'], 'dry-run: nada gravado');
T::eq(null, $products->findByMarketplaceId('mercado_livre', 'MLB_DRY'), 'dry-run não criou produto');

$db2 = null;
unset($db);
@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
