<?php
declare(strict_types=1);

use HotRadar\Radar\Radar;
use HotRadar\Radar\RadarLifecycleService;
use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\RunRepository;

T::group('RunRepository — coletas de radar excluído não inflam o número operacional');

$db = TestDb::fresh('run_orphan');
$audit = new AuditRepository($db);
$radars = new RadarRepository($db, $audit);
$lifecycle = new RadarLifecycleService($db, $audit);
$runs = new RunRepository($db);

$mkRadar = static fn (string $name): Radar => new Radar(
    id: null, slug: '', name: $name, enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: [['id' => 'MLB1574', 'label' => 'x']], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
);

// ---- radar A (vai ser excluído) com 1 coleta ----
$radarA = $radars->find($radars->create($mkRadar('Radar A')));
$runA = $runs->start('mercado_livre', 'ofertas_ml', 'live', $radarA->id, $radarA->slug);
$runs->finish($runA, 'ok', 1, 1, 5, 0, 5, []);

// ---- radar B (continua existindo) com 1 coleta ----
$radarB = $radars->find($radars->create($mkRadar('Radar B')));
$runB = $runs->start('mercado_livre', 'ofertas_ml', 'live', $radarB->id, $radarB->slug);
$runs->finish($runB, 'ok', 1, 1, 3, 0, 3, []);

// ---- coleta legada sem radar nenhum (radar_id NULL) ----
$runLegacy = $runs->start('mercado_livre', 'ofertas_ml', 'live', null, null);
$runs->finish($runLegacy, 'ok', 1, 1, 1, 0, 1, []);

T::eq(3, $runs->countAll(), 'setup: 3 coletas no total (A, B, legada)');
T::eq(3, $runs->countActive(), 'antes de excluir nada: as 3 contam como operacionais');

// ---- exclui o radar A (com produtos) ----
$lifecycle->deleteWithExclusive($radarA, 'humano:teste');

T::eq(3, $runs->countAll(), 'countAll() NUNCA muda por exclusão de radar — hr_collection_runs intacto (comportamento já exigido/testado no System Reset)');
T::eq(2, $runs->countActive(), 'countActive() cai para 2: só radar B (existe) + legada (nunca teve radar) — o run de A não infla mais o número operacional');

// ---- recent() marca corretamente os 3 estados: existe / excluído / nunca teve radar ----
$rows = $runs->recent(10);
$byId = [];
foreach ($rows as $r) { $byId[(int) $r['id']] = $r; }

T::eq(1, (int) $byId[$runB]['radar_existe'], 'run de radar B (existe): radar_existe = 1');
T::eq(0, (int) $byId[$runA]['radar_existe'], 'run de radar A (excluído): radar_existe = 0');
T::eq('radar-a', (string) $byId[$runA]['radar_slug'], 'run de A preserva o radar_slug congelado para exibição (mesmo o radar não existindo mais)');
T::eq(1, (int) $byId[$runLegacy]['radar_existe'], 'run legado sem radar (radar_id NULL): radar_existe = 1 (nunca foi invalidado, não é "excluído")');
T::eq(null, $byId[$runLegacy]['radar_slug'], 'run legado não tem radar_slug (nunca teve radar, diferente de "excluído")');

// =====================================================================
T::group('RunRepository — segurança de identidade: slug reaproveitado NÃO herda histórico do radar antigo');

// cria um radar NOVO com o MESMO slug do radar A (agora livre, pois A foi excluído)
$radarA2 = $radars->find($radars->create($mkRadar('Radar A')));
T::eq('radar-a', $radarA2->slug, 'radar novo reaproveitou o slug "radar-a" (confirma que o slug ficou livre após a exclusão)');
T::ok($radarA2->id !== $radarA->id, 'radar novo tem um radar_id DIFERENTE do antigo (mesmo slug, identidade distinta)');

// o run antigo de A (radar_id antigo) NÃO deve virar "existe" só porque um radar com o mesmo slug foi criado
$rowsAfter = $runs->recent(10);
$byIdAfter = [];
foreach ($rowsAfter as $r) { $byIdAfter[(int) $r['id']] = $r; }
T::eq(0, (int) $byIdAfter[$runA]['radar_existe'], 'run antigo de A CONTINUA marcado como excluído — não foi religado ao radar novo pelo slug');
T::eq(2, $runs->countActive(), 'countActive() não conta o run antigo de A mesmo com um radar de mesmo nome/slug recriado (nenhuma coleta nova foi feita por ele ainda)');

TestDb::cleanup('run_orphan');
