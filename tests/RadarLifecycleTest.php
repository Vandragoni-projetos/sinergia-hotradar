<?php
declare(strict_types=1);

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Discovery\DiscoveryService;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Radar\RadarLifecycleService;
use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\EditorialRepository;
use HotRadar\Repository\ProductRadarRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('Radar — exclusão segura e limpeza (impacto, opção A/B, transacional)');

$db = TestDb::fresh('radarlc');
$radars = new RadarRepository($db, new AuditRepository($db));
$pr = new ProductRadarRepository($db);
$products = new ProductRepository($db);
$editorial = new EditorialRepository($db);
$lc = new RadarLifecycleService($db, new AuditRepository($db));
$discovery = new DiscoveryService(
    $products, new SnapshotRepository($db), new RunRepository($db),
    new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php')), $pr,
);

$mkRadar = static fn (string $name): Radar => new Radar(
    null, '', $name, true, ['mercado_livre'], [['id' => 'MLB1574', 'label' => 'Casa']],
    [], [], [], 1, null, null, null, false,
);
$rA = $radars->find($radars->create($mkRadar('Radar A')));
$rB = $radars->find($radars->create($mkRadar('Radar B')));

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
            $r->add($p); $r->cardsSeen++;
        }
        return $r;
    }
};
$mk = static fn (string $id): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: $id, title: "Produto $id",
    urlOriginal: "https://ml/$id", priceCurrent: 99.9, discountPct: 30, salesSignal: 'alto', rating: 4.6,
    source: 'ofertas_ml',
);

// P1 e P2 só no Radar A ; P3 compartilhado A+B
$fake->out = [$mk('MLB1'), $mk('MLB2'), $mk('MLB3')];
$discovery->run($fake, new CollectorContext(radar: $rA));
$fake->out = [$mk('MLB3'), $mk('MLB9')]; // P3 também em B, P9 só em B
$discovery->run($fake, new CollectorContext(radar: $rB));

$p1 = (int) $products->findByMarketplaceId('mercado_livre', 'MLB1')['id'];
$products->setStatus($p1, EditorialStatus::APROVADO, null, 'humano');
$editorial->log($p1, 'descoberto', EditorialStatus::APROVADO, null, 'humano');

// ---- impacto ----
$imp = $lc->impact($rA);
T::eq(3, $imp['associados'], 'Radar A: 3 produtos associados');
T::eq(2, $imp['exclusivos'], 'Radar A: 2 exclusivos (P1, P2)');
T::eq(1, $imp['compartilhados'], 'Radar A: 1 compartilhado (P3)');
T::eq(1, $imp['aprovados'], 'Radar A: 1 aprovado');

$prodBefore = (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'];
$snapBefore = (int) $db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'];

// ---- OPÇÃO A: só o radar ----
$rC = $radars->find($radars->create($mkRadar('Radar C temporário')));
$fake->out = [$mk('MLB1')]; // associa P1 também a C
$discovery->run($fake, new CollectorContext(radar: $rC));
$lc->deleteRadarOnly($rC);
T::eq(null, $radars->find($rC->id), 'Opção A: radar removido');
T::eq($prodBefore, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'Opção A: NENHUM produto apagado');
T::eq('aprovado', (string) $products->find($p1)['status'], 'Opção A: status editorial preservado');
T::eq(0, $pr->countForRadar((int) $rC->id), 'Opção A: associações do radar removidas');

// ---- OPÇÃO B: radar + exclusivos ----
$impB = $lc->impact($rA);
$done = $lc->deleteWithExclusive($rA);
T::eq(null, $radars->find($rA->id), 'Opção B: Radar A removido');
T::eq(2, $done['produtos_removidos'], 'Opção B: 2 produtos exclusivos removidos (P1, P2)');
T::eq(null, $products->findByMarketplaceId('mercado_livre', 'MLB1'), 'P1 (exclusivo) apagado');
T::eq(null, $products->findByMarketplaceId('mercado_livre', 'MLB2'), 'P2 (exclusivo) apagado');
$p3 = $products->findByMarketplaceId('mercado_livre', 'MLB3');
T::ok($p3 !== null, 'P3 (compartilhado) PRESERVADO');
T::eq(1, count($pr->radarsForProduct((int) $p3['id'])), 'P3 agora só no Radar B (perdeu a ligação com A)');
T::eq('radar-b', $pr->radarsForProduct((int) $p3['id'])[0]['slug'], 'P3 ligado ao Radar B');
$p9 = $products->findByMarketplaceId('mercado_livre', 'MLB9');
T::ok($p9 !== null, 'P9 (exclusivo do B) intacto');

// ---- LIMPAR DADOS: mantém o radar ----
$impClr = $lc->impact($rB);
$doneClr = $lc->clearData($rB);
T::ok($radars->find($rB->id) !== null, 'Limpar: radar B CONTINUA existindo');
T::eq('Radar B', $radars->find($rB->id)->name, 'Limpar: configuração do radar mantida');
T::eq(0, $pr->countForRadar((int) $rB->id), 'Limpar: associações zeradas');
T::eq(2, $doneClr['produtos_removidos'], 'Limpar: 2 exclusivos do B removidos (P3 agora exclusivo + P9)');
T::eq(0, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'todos os produtos exclusivos limpos');

// audit registrou tudo
$logs = (new AuditRepository($db))->recent('radar', 20);
$acts = array_column($logs, 'action');
T::ok(in_array('delete', $acts, true) && in_array('clear_data', $acts, true), 'audit log registrou delete + clear_data');

TestDb::cleanup('radarlc');
