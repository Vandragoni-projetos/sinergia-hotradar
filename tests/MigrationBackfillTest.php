<?php
declare(strict_types=1);

use HotRadar\Db\Connection;
use HotRadar\Db\Migrator;

T::group('Migration 004 — backfill radar_slug → hr_product_radars (idempotente, sem perda)');

// Banco só com migrations 001..003 (simula produção ANTES da E4.1)
$path = HR_ROOT . '/storage/test_backfill.sqlite';
foreach (['', '-wal', '-shm'] as $s) {
    @unlink($path . $s);
}
$db = new Connection(['driver' => 'sqlite', 'sqlite_path' => $path]);

// aplica só até a 003
$all = glob(HR_ROOT . '/migrations/*.php') ?: [];
sort($all);
$mig = new Migrator($db, HR_ROOT . '/migrations');
$mig->ensureTable();
foreach ($all as $file) {
    $name = basename($file, '.php');
    if ($name > '003_seed_default_radar') {
        continue;
    }
    (require $file)($db, 'sqlite');
    $db->run('INSERT INTO hr_migrations (name, applied_at) VALUES (?,?)', [$name, $db->now()]);
}
T::ok($db->first('SELECT 1 FROM hr_radars WHERE slug = ?', ['casa-organizacao']) !== null, 'radar semente existe (003)');
$radarId = (int) $db->first('SELECT id FROM hr_radars WHERE slug = ?', ['casa-organizacao'])['id'];

// simula produtos legados: alguns com radar (E4), alguns SEM (E0-E3, os "97")
$now = $db->now();
$ins = static function (Connection $db, string $mlid, ?int $rid, ?string $rslug) use ($now): void {
    $db->run(
        "INSERT INTO hr_products
         (marketplace, marketplace_product_id, title, url_original, status, data_quality, source,
          radar_id, radar_slug, discovered_at, last_collected_at, created_at, updated_at)
         VALUES ('mercado_livre', ?, 'x', 'https://x', 'aprovado', 'scrape_json', 'ofertas_ml', ?, ?, ?, ?, ?, ?)",
        [$mlid, $rid, $rslug, $now, $now, $now, $now]
    );
};
$ins($db, 'MLB_A', $radarId, 'casa-organizacao');        // tem radar_id + slug
$ins($db, 'MLB_B', null, 'casa-organizacao');            // só slug (radar recriado)
$ins($db, 'MLB_C', null, null);                          // legado E0-E3 (sem radar)
$ins($db, 'MLB_D', null, null);                          // idem

// ---- aplica a migration 004 ----
(require HR_ROOT . '/migrations/004_product_radars_m2m.php')($db, 'sqlite');
$db->run('INSERT INTO hr_migrations (name, applied_at) VALUES (?,?)', ['004_product_radars_m2m', $db->now()]);

$assoc = $db->all('SELECT p.marketplace_product_id m, pr.radar_id FROM hr_product_radars pr JOIN hr_products p ON p.id = pr.product_id ORDER BY m');
T::eq(2, count($assoc), 'backfill criou 2 associações (A por id, B por slug)');
T::eq('MLB_A', (string) $assoc[0]['m'], 'A associado');
T::eq('MLB_B', (string) $assoc[1]['m'], 'B associado (via slug)');

$orphans = (int) $db->first("SELECT COUNT(*) n FROM hr_products p WHERE NOT EXISTS (SELECT 1 FROM hr_product_radars pr WHERE pr.product_id = p.id)")['n'];
T::eq(2, $orphans, 'C e D permanecem sem radar (não sabemos a qual pertencem — nada inventado)');

// nada foi apagado / status intacto
T::eq(4, (int) $db->first('SELECT COUNT(*) n FROM hr_products')['n'], 'nenhum produto apagado');
T::eq('aprovado', (string) $db->first('SELECT status FROM hr_products WHERE marketplace_product_id = ?', ['MLB_A'])['status'], 'status preservado');
T::ok($db->first('SELECT radar_slug FROM hr_products WHERE marketplace_product_id = ?', ['MLB_A'])['radar_slug'] === 'casa-organizacao', 'coluna antiga radar_slug MANTIDA (compat)');

// ---- idempotência: rodar 004 de novo não duplica ----
(require HR_ROOT . '/migrations/004_product_radars_m2m.php')($db, 'sqlite');
T::eq(2, (int) $db->first('SELECT COUNT(*) n FROM hr_product_radars')['n'], 're-executar 004: continua 2 associações (idempotente)');

foreach (['', '-wal', '-shm'] as $s) {
    @unlink($path . $s);
}
