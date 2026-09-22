<?php
declare(strict_types=1);

use HotRadar\Db\Connection;
use HotRadar\Db\Migrator;

T::group('Migration 005 — backfill dos produtos LEGADOS para o radar padrão');

$path = HR_ROOT . '/storage/test_legacy005.sqlite';
foreach (['', '-wal', '-shm'] as $s) {
    @unlink($path . $s);
}
$db = new Connection(['driver' => 'sqlite', 'sqlite_path' => $path]);

$files = glob(HR_ROOT . '/migrations/*.php') ?: [];
sort($files);
$mig = new Migrator($db, HR_ROOT . '/migrations');
$mig->ensureTable();

// aplica 001..004 (estado "produção pós-E4.1, antes da 005")
foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($name > '004_product_radars_m2m') {
        continue;
    }
    (require $file)($db, 'sqlite');
    $db->run('INSERT INTO hr_migrations (name, applied_at) VALUES (?,?)', [$name, $db->now()]);
}
// 008 é puramente aditiva em hr_radars (2 colunas novas, nullable) e não tem
// nenhuma relação com o backfill 005 que este teste isola — mas o fixture
// "Outro Radar" abaixo usa RadarRepository::create(), que grava o schema
// ATUAL de Radar::toRow(). Sem isso, o INSERT falharia por coluna ausente
// numa tabela congelada num ponto anterior a esta feature — em produção
// isso nunca acontece (o migrator sempre roda até a última migration).
(require HR_ROOT . '/migrations/008_radar_desired_words.php')($db, 'sqlite');
$radarDefault = (int) $db->first('SELECT id FROM hr_radars WHERE slug = ?', ['casa-organizacao'])['id'];
$outroId = (int) (new HotRadar\Radar\RadarRepository($db, new HotRadar\Repository\AuditRepository($db)))
    ->create(new HotRadar\Radar\Radar(
        null, '', 'Outro Radar', true, ['mercado_livre'],
        [['id' => 'MLB1000', 'label' => 'Eletrônicos']], [], [], [], 1, null, null, null, false
    ));

// marco 004 = "ontem" para o teste (para os legados terem created_at anterior)
$ontem = date('Y-m-d H:i:s', strtotime('-1 day'));
$db->run('UPDATE hr_migrations SET applied_at = ? WHERE name = ?', [date('Y-m-d H:i:s', strtotime('-12 hours')), '004_product_radars_m2m']);

$mkProd = static function (Connection $db, string $mlid, string $createdAt, ?string $radarSlug, ?int $radarId, string $status = 'aprovado') use ($radarDefault): int {
    $db->run(
        "INSERT INTO hr_products
          (marketplace, marketplace_product_id, title, url_original, status, data_quality, source,
           radar_id, radar_slug, price_current, hot_score, discovered_at, last_collected_at, created_at, updated_at)
         VALUES ('mercado_livre', ?, 'x', 'https://x', ?, 'scrape_json', 'ofertas_ml', ?, ?, 99.9, 70, ?, ?, ?, ?)",
        [$mlid, $status, $radarId, $radarSlug, $createdAt, $createdAt, $createdAt, $createdAt]
    );
    return (int) $db->lastInsertId();
};

// 10 produtos LEGADOS (simulam os ~97): sem radar_*, criados ANTES do marco 004
$legacyIds = [];
for ($i = 1; $i <= 10; $i++) {
    $legacyIds[] = $mkProd($db, "MLB_LEG_$i", $ontem, null, null);
}
// 1 legado que JÁ está associado a outro radar (não deve ganhar o radar padrão)
$jaAssoc = $mkProd($db, 'MLB_JA_ASSOC', $ontem, null, null);
$db->run('INSERT INTO hr_product_radars (product_id, radar_id, first_seen_at, last_seen_at, present) VALUES (?,?,?,?,1)',
    [$jaAssoc, $outroId, $ontem, $ontem]);
// 1 produto FUTURO (E4+): tem radar_slug preenchido, sem associação — NÃO é legado
$futuro = $mkProd($db, 'MLB_FUTURO', date('Y-m-d H:i:s'), 'outro-radar', $outroId);

// snapshots + evento editorial num legado, para provar que a migration não os toca
$db->run('INSERT INTO hr_product_snapshots (product_id, run_id, collected_at, price_current, hot_score, present) VALUES (?,?,?,?,?,1)',
    [$legacyIds[0], null, $ontem, 99.9, 70]);
$db->run('INSERT INTO hr_editorial_events (product_id, from_status, to_status, reason, actor, created_at) VALUES (?,?,?,?,?,?)',
    [$legacyIds[0], 'descoberto', 'aprovado', null, 'humano', $ontem]);

$snapBefore = (int) $db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'];
$editBefore = (int) $db->first('SELECT COUNT(*) n FROM hr_editorial_events')['n'];
$prodHashBefore = md5(json_encode($db->all('SELECT id,status,price_current,hot_score,radar_slug FROM hr_products ORDER BY id')));

// ---- aplica a migration 005 ----
(require HR_ROOT . '/migrations/005_backfill_legacy_default_radar.php')($db, 'sqlite');
$db->run('INSERT INTO hr_migrations (name, applied_at) VALUES (?,?)', ['005_backfill_legacy_default_radar', $db->now()]);

// 1) 10 legados órfãos → associados ao radar padrão
$assocDefault = $db->all(
    'SELECT p.marketplace_product_id m FROM hr_product_radars pr JOIN hr_products p ON p.id = pr.product_id WHERE pr.radar_id = ? ORDER BY m',
    [$radarDefault]
);
T::eq(10, count($assocDefault), '10 produtos legados associados ao radar padrão (representam os ~97)');
foreach ($legacyIds as $lid) {
    $n = (int) $db->first('SELECT COUNT(*) n FROM hr_product_radars WHERE product_id = ? AND radar_id = ?', [$lid, $radarDefault])['n'];
    T::eq(1, $n, "legado #$lid tem exatamente 1 associação ao radar padrão");
}

// 2) produto já associado a outro radar → NÃO alterado
$jaN = $db->all('SELECT radar_id FROM hr_product_radars WHERE product_id = ?', [$jaAssoc]);
T::eq(1, count($jaN), 'produto já associado continua com 1 associação');
T::eq($outroId, (int) $jaN[0]['radar_id'], 'e continua sendo a associação ao OUTRO radar (não ganhou o padrão)');

// 3) produto futuro (radar_slug setado) → NÃO tocado
T::eq(0, (int) $db->first('SELECT COUNT(*) n FROM hr_product_radars WHERE product_id = ?', [$futuro])['n'],
    'produto FUTURO sem associação NÃO é apanhado pelo backfill de legado');

// 4) reexecutar a migration → nenhuma duplicata
$assocTotal1 = (int) $db->first('SELECT COUNT(*) n FROM hr_product_radars')['n'];
(require HR_ROOT . '/migrations/005_backfill_legacy_default_radar.php')($db, 'sqlite');
$assocTotal2 = (int) $db->first('SELECT COUNT(*) n FROM hr_product_radars')['n'];
T::eq($assocTotal1, $assocTotal2, 're-executar 005: total de associações inalterado (idempotente)');

// 5) snapshots e decisões editoriais intactos; produto não alterado
T::eq($snapBefore, (int) $db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'], 'snapshots intactos');
T::eq($editBefore, (int) $db->first('SELECT COUNT(*) n FROM hr_editorial_events')['n'], 'eventos editoriais intactos');
T::eq(
    $prodHashBefore,
    md5(json_encode($db->all('SELECT id,status,price_current,hot_score,radar_slug FROM hr_products ORDER BY id'))),
    'hr_products inalterado (status/preço/hot_score/radar_slug idênticos)'
);

// 6) migration tolera ausência do radar padrão
$db->run('DELETE FROM hr_radars WHERE slug = ?', ['casa-organizacao']);
(require HR_ROOT . '/migrations/005_backfill_legacy_default_radar.php')($db, 'sqlite'); // não deve lançar
T::ok(true, '005 sem o radar padrão: no-op sem erro');

foreach (['', '-wal', '-shm'] as $s) {
    @unlink($path . $s);
}
