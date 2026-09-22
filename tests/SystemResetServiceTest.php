<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\EditorialRepository;
use HotRadar\Repository\ProductRadarRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;
use HotRadar\System\SystemResetService;
use HotRadar\Web\Actions;

T::group('SystemResetService — "zerar tudo" (reset global, separado do ciclo de vida do radar)');

$db = TestDb::fresh('system_reset');
$audit = new AuditRepository($db);
$products = new ProductRepository($db);
$snapshots = new SnapshotRepository($db);
$editorial = new EditorialRepository($db);
$radars = new RadarRepository($db, $audit);
$pr = new ProductRadarRepository($db);
$runs = new RunRepository($db);
$settings = new SettingsRepository($db, $audit);
$hotScore = new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php'));
$svc = new SystemResetService($db, $audit);

$mkRadar = static fn (string $name, array $cats = ['MLB1574']): Radar => $radars->find($radars->create(new Radar(
    id: null, slug: '', name: $name, enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: array_map(static fn ($c) => ['id' => $c, 'label' => $c], $cats),
    shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
)));

// =====================================================================
T::group('1) Reset com banco (quase) vazio — só o que as migrations semeiam');

$impactVazio = $svc->impact();
T::eq(0, $impactVazio['hr_products'], 'banco recém-migrado: 0 produtos');
T::eq(0, $impactVazio['hr_product_snapshots'], 'banco recém-migrado: 0 snapshots');
T::eq(0, $impactVazio['hr_collection_runs'], 'banco recém-migrado: 0 coletas');
T::ok($impactVazio['hr_radars'] >= 2, 'banco recém-migrado já tem os radares semeados pelas migrations 003/006');

$doneVazio = $svc->factoryReset('humano:teste');
T::eq(0, $doneVazio['hr_products'], 'reset em banco quase vazio: 0 produtos removidos');
T::ok($doneVazio['hr_radars'] >= 2, 'reset em banco quase vazio: remove os radares semeados também');
T::eq(0, (int) $db->first('SELECT COUNT(*) n FROM hr_radars')['n'], 'após reset: 0 radares (nem os semeados sobram)');

// =====================================================================
T::group('2-7) Reset com dados reais — produtos, snapshots, eventos, associações, coletas, múltiplos radares');

$r1 = $mkRadar('Radar Um', ['MLB1574']);
$r2 = $mkRadar('Radar Dois', ['MLB5726']);

$mkProduct = static fn (string $id, string $title): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: $id, title: $title,
    urlOriginal: 'https://ml/' . $id, priceCurrent: 50.0, discountPct: 40, salesSignal: 'alto', rating: 4.6,
    source: 'ofertas_ml', radarId: $r1->id, radarSlug: $r1->slug,
);

$p1 = $mkProduct('MLB111', 'Produto Um');
$p2 = $mkProduct('MLB222', 'Produto Dois');

$id1 = $products->upsert($p1, $hotScore->evaluate($p1))['id'];
$id2 = $products->upsert($p2, $hotScore->evaluate($p2))['id'];
$pr->link($id1, $r1->id);
$pr->link($id1, $r2->id); // produto compartilhado entre 2 radares — reset deve zerar do mesmo jeito
$pr->link($id2, $r2->id);

$snapshots->record($id1, null, $p1, $hotScore->evaluate($p1));
$snapshots->record($id2, null, $p2, $hotScore->evaluate($p2));

$products->setStatus($id1, EditorialStatus::APROVADO, null, 'humano:teste');
$editorial->log($id1, 'descoberto', EditorialStatus::APROVADO, null, 'humano:teste');

$runId = $runs->start('mercado_livre', 'ofertas_ml', 'live', $r1->id, $r1->slug);
$runs->finish($runId, 'ok', 1, 10, 2, 0, 2, []);
$runId2 = $runs->start('mercado_livre', 'ofertas_ml', 'live', $r2->id, $r2->slug);
$runs->finish($runId2, 'ok', 1, 5, 0, 0, 0, []);

$impact = $svc->impact();
T::eq(2, $impact['hr_products'], 'impact(): 2 produtos');
T::eq(2, $impact['hr_product_snapshots'], 'impact(): 2 snapshots');
T::eq(3, $impact['hr_product_radars'], 'impact(): 3 associações (1 produto em 2 radares + 1 em 1 radar)');
T::eq(1, $impact['hr_editorial_events'], 'impact(): 1 evento editorial');
T::eq(2, $impact['hr_collection_runs'], 'impact(): 2 coletas');
T::eq(2, $impact['hr_radars'], 'impact(): 2 radares');

// grava algo em settings/audit_log ANTES do reset, para provar que sobrevive
$settings->set('general', ['timezone' => 'America/Sao_Paulo', 'max_products_per_collect' => 500, 'marketplaces_enabled' => ['mercado_livre'], 'editorial' => ['auto_expire_days' => 0]], 'humano:teste');
$auditCountBefore = (int) $db->first('SELECT COUNT(*) n FROM hr_audit_log')['n'];
$migrationsBefore = $db->all('SELECT name FROM hr_migrations ORDER BY name');

$done = $svc->factoryReset('humano:teste');

T::eq(2, $done['hr_products'], 'factoryReset(): removeu os 2 produtos');
T::eq(2, $done['hr_product_snapshots'], 'factoryReset(): removeu os 2 snapshots');
T::eq(3, $done['hr_product_radars'], 'factoryReset(): removeu as 3 associações');
T::eq(1, $done['hr_editorial_events'], 'factoryReset(): removeu o evento editorial');
T::eq(2, $done['hr_collection_runs'], 'factoryReset(): removeu as 2 coletas (a causa raiz do bug reportado)');
T::eq(2, $done['hr_radars'], 'factoryReset(): removeu os 2 radares');

foreach (['hr_products', 'hr_product_snapshots', 'hr_product_radars', 'hr_editorial_events', 'hr_collection_runs', 'hr_radars'] as $table) {
    T::eq(0, (int) $db->first("SELECT COUNT(*) n FROM $table")['n'], "$table: 0 linhas após o reset");
}

// =====================================================================
T::group('8-10) Preservação obrigatória: settings, audit_log, migrations');

$generalAfter = $settings->get('general');
T::eq(500, $generalAfter['max_products_per_collect'] ?? null, 'hr_settings: configuração geral PRESERVADA após o reset');

$auditCountAfter = (int) $db->first('SELECT COUNT(*) n FROM hr_audit_log')['n'];
T::ok($auditCountAfter > $auditCountBefore, 'hr_audit_log: CRESCEU (o próprio reset foi registrado), nada anterior foi apagado');
$lastAudit = $db->first("SELECT * FROM hr_audit_log WHERE area='system' AND action='factory_reset' ORDER BY id DESC LIMIT 1");
T::ok($lastAudit !== null, 'hr_audit_log: evento factory_reset foi gravado');
$afterJson = json_decode((string) $lastAudit['after_json'], true);
T::eq(2, $afterJson['hr_products'] ?? null, 'audit log do reset registra as contagens corretas do que foi removido');

$migrationsAfter = $db->all('SELECT name FROM hr_migrations ORDER BY name');
T::eq($migrationsBefore, $migrationsAfter, 'hr_migrations: EXATAMENTE as mesmas linhas antes/depois (nunca tocado)');
T::eq(8, count($migrationsAfter), 'hr_migrations: continua com as 8 migrations aplicadas');

// =====================================================================
T::group('11) Atomicidade — falha no meio do reset não deixa dado parcialmente apagado');

// Banco ISOLADO, dedicado só a este cenário — evita que o DROP TABLE usado para
// simular a falha contamine os testes seguintes (que reusam $db/$svc normalmente).
$dbTx = TestDb::fresh('system_reset_rollback');
$auditTx = new AuditRepository($dbTx);
$radarsTx = new RadarRepository($dbTx, $auditTx);
$productsTx = new ProductRepository($dbTx);
$snapshotsTx = new SnapshotRepository($dbTx);
$prTx = new ProductRadarRepository($dbTx);
$svcTx = new SystemResetService($dbTx, $auditTx);

$r3 = $radarsTx->find($radarsTx->create(new Radar(
    id: null, slug: '', name: 'Radar Rollback Test', enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: [['id' => 'MLB1574', 'label' => 'x']], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
)));
$p3 = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB333', title: 'Produto Rollback',
    urlOriginal: 'https://ml/MLB333', priceCurrent: 50.0, source: 'ofertas_ml',
);
$id3 = $productsTx->upsert($p3, $hotScore->evaluate($p3))['id'];
$prTx->link($id3, $r3->id);
$snapshotsTx->record($id3, null, $p3, $hotScore->evaluate($p3));

// radares já existiam 2 (semeados pelas migrations 003/006) + o "Radar Rollback Test" = 3.
// Guarda o total ANTES da tentativa de reset para comparar depois (não assumir "1").
$radarCountBeforeAttempt = (int) $dbTx->first('SELECT COUNT(*) n FROM hr_radars')['n'];

// simula uma falha no meio da transação: remove uma tabela que o reset tentaria limpar
// (hr_collection_runs é a 5ª das 6 tabelas, então snapshots/eventos/associações/produtos
// já teriam sido apagados ANTES da falha, dentro da MESMA transação).
$dbTx->pdo()->exec('DROP TABLE hr_collection_runs');

$threw = false;
try {
    $svcTx->factoryReset('humano:teste');
} catch (\Throwable $e) {
    $threw = true;
}
T::ok($threw, 'factoryReset() propaga a exceção quando uma etapa falha (não engole o erro)');

T::eq(1, (int) $dbTx->first('SELECT COUNT(*) n FROM hr_products')['n'], 'ROLLBACK: o produto criado antes da falha CONTINUA no banco (transação revertida)');
T::eq(1, (int) $dbTx->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'], 'ROLLBACK: o snapshot criado antes da falha CONTINUA no banco');
T::eq(1, (int) $dbTx->first('SELECT COUNT(*) n FROM hr_product_radars')['n'], 'ROLLBACK: a associação criada antes da falha CONTINUA no banco');
T::eq($radarCountBeforeAttempt, (int) $dbTx->first('SELECT COUNT(*) n FROM hr_radars')['n'], 'ROLLBACK: nº de radares INALTERADO (o radar criado antes da falha continua no banco)');
TestDb::cleanup('system_reset_rollback');

// =====================================================================
T::group('12) Confirmação textual obrigatória (camada de Action — nunca a service)');

$app = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $db);

// $db já foi zerado na seção 2-7 — insere 1 produto de novo, só para ter algo
// concreto que a confirmação errada precisa DEIXAR intacto.
$p4 = $mkProduct('MLB444', 'Produto Confirmação');
$products->upsert($p4, $hotScore->evaluate($p4));
$countBefore = (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'];
T::eq(1, $countBefore, 'setup: 1 produto presente antes de testar a confirmação');

// Actions::systemReset() chama header() (dentro de redirect()) — em CLI isso nunca
// afeta nada de verdade, mas o runner de testes já imprimiu texto antes, então o PHP
// emite um aviso inofensivo ("headers already sent"). @ aqui só cala esse ruído de
// harness; a checagem real (o produto sobreviver ou não) continua sem suprimir nada.
$_POST = ['confirm' => 'zerar tudo']; // minúsculo — não é igual ao exigido
@Actions::systemReset($app);
T::eq($countBefore, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'confirmação errada (minúscula): NADA foi apagado');

$_POST = ['confirm' => 'ZERAR'];
@Actions::systemReset($app);
T::eq($countBefore, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'confirmação incompleta ("ZERAR"): NADA foi apagado');

$_POST = ['confirm' => ''];
@Actions::systemReset($app);
T::eq($countBefore, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'confirmação vazia: NADA foi apagado');

$_POST = ['confirm' => 'ZERAR TUDO'];
@Actions::systemReset($app);
T::eq(0, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'confirmação EXATA "ZERAR TUDO": reset executado de verdade');
$_POST = [];

// =====================================================================
T::group('13) Dashboard coerente após o reset (contadores reais, não "count(recent(12))")');

T::eq(0, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'dashboard: produtos monitorados = 0');
T::eq(0, $runs->countAll(), 'dashboard: coletas registradas (total REAL) = 0');
T::eq([], $runs->recent(12), 'dashboard: últimas coletas = vazio');
T::eq(0, count($radars->all()), 'dashboard: nenhum radar');

// =====================================================================
T::group('14) RunRepository::countAll() nunca satura em 12 (correção do card do dashboard)');

$dbCount = TestDb::fresh('runcount_total');
$runsCount = new RunRepository($dbCount);
for ($i = 0; $i < 15; $i++) {
    $rid = $runsCount->start('mercado_livre', 'ofertas_ml', 'live', null, null);
    $runsCount->finish($rid, 'ok', 1, 1, 0, 0, 0, []);
}
T::eq(15, $runsCount->countAll(), 'countAll() retorna o TOTAL real (15), mesmo havendo mais de 12 linhas');
T::eq(12, count($runsCount->recent(12)), 'recent(12) continua limitado a 12 (é para a listagem, não para o total)');
T::ok(count($runsCount->recent(12)) !== $runsCount->countAll(), 'confirma que count(recent(12)) e countAll() NÃO são a mesma coisa quando há >12 linhas');
TestDb::cleanup('runcount_total');

// =====================================================================
T::group('15) Regressão — excluir/limpar UM radar continua SEM tocar hr_collection_runs');

$dbReg = TestDb::fresh('system_reset_regression');
$auditReg = new AuditRepository($dbReg);
$radarsReg = new RadarRepository($dbReg, $auditReg);
$runsReg = new RunRepository($dbReg);
$lifecycle = new \HotRadar\Radar\RadarLifecycleService($dbReg, $auditReg);

$rReg = $radarsReg->find($radarsReg->create(new Radar(
    id: null, slug: '', name: 'Radar Regressão', enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: [['id' => 'MLB1574', 'label' => 'x']], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
)));
$runIdReg = $runsReg->start('mercado_livre', 'ofertas_ml', 'live', $rReg->id, $rReg->slug);
$runsReg->finish($runIdReg, 'ok', 1, 1, 0, 0, 0, []);
T::eq(1, $runsReg->countAll(), 'setup: 1 coleta registrada para o radar de regressão');

$lifecycle->deleteRadarOnly($rReg, 'humano:teste');
T::eq(1, $runsReg->countAll(), 'excluir radar (Opção A) NÃO apaga hr_collection_runs — comportamento LOCAL preservado, como exigido');
TestDb::cleanup('system_reset_regression');

TestDb::cleanup('system_reset');

echo "\n(nenhuma coleta, nenhum banco de produção, nenhuma ativação de V2 foi tocada por este teste)\n";
