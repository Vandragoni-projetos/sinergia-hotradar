<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * E4.1 — Produto × Radar passa a ser MUITOS-PARA-MUITOS.
 *
 * O MESMO produto (deduplicado por marketplace+id em hr_products) pode ser
 * relevante para vários radares. Antes: hr_products.radar_slug (1 radar, o que
 * descobriu primeiro). Agora: hr_product_radars (N associações).
 *
 * As colunas hr_products.radar_id / radar_slug são MANTIDAS (radar "primário" =
 * quem descobriu; usado só para exibição/retrocompat). Removidas numa migration
 * posterior, se desejado.
 *
 * Idempotente:
 *   - CREATE só se a tabela não existe;
 *   - backfill com INSERT ... IGNORE (a UNIQUE product_id+radar_id evita duplicar)
 *     e SEM subconsulta na própria tabela-alvo (evita o erro 1093 do MySQL/MariaDB).
 *
 * SQLite (dev) + MySQL/MariaDB (produção).
 */
return function (Connection $db, string $driver): void {
    $pdo = $db->pdo();

    $exists = false;
    try {
        $pdo->query('SELECT 1 FROM hr_product_radars LIMIT 1');
        $exists = true;
    } catch (\Throwable) {
        $exists = false;
    }

    if ($driver === 'sqlite') {
        $pk = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fk = 'INTEGER';
        $dt = 'TEXT';
        $engine = '';
        $insertIgnore = 'INSERT OR IGNORE INTO';
    } else {
        $pk = 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $fk = 'BIGINT UNSIGNED';
        $dt = 'DATETIME';
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $insertIgnore = 'INSERT IGNORE INTO';
    }

    if (!$exists) {
        $pdo->exec("
            CREATE TABLE hr_product_radars (
                id $pk,
                product_id     $fk      NOT NULL,
                radar_id       $fk      NOT NULL,
                first_seen_at  $dt      NOT NULL,
                last_seen_at   $dt      NOT NULL,
                present        INTEGER  NOT NULL DEFAULT 1
            )$engine
        ");
        $pdo->exec('CREATE UNIQUE INDEX ux_hr_product_radars ON hr_product_radars (product_id, radar_id)');
        $pdo->exec('CREATE INDEX ix_hr_pr_radar ON hr_product_radars (radar_id)');
        $pdo->exec('CREATE INDEX ix_hr_pr_product ON hr_product_radars (product_id)');
    }

    // -------------------------------------------------------- backfill idempotente
    // Transforma a atribuição antiga em associações, sem duplicar, sem apagar nada.
    // A UNIQUE (product_id, radar_id) + INSERT IGNORE tornam o passo repetível.

    // (a) por radar_id direto
    $pdo->exec("
        $insertIgnore hr_product_radars (product_id, radar_id, first_seen_at, last_seen_at, present)
        SELECT p.id, p.radar_id, p.discovered_at, p.last_collected_at, 1
        FROM hr_products p
        WHERE p.radar_id IS NOT NULL
    ");

    // (b) por radar_slug quando radar_id ficou nulo mas o slug ainda casa um radar
    $pdo->exec("
        $insertIgnore hr_product_radars (product_id, radar_id, first_seen_at, last_seen_at, present)
        SELECT p.id, r.id, p.discovered_at, p.last_collected_at, 1
        FROM hr_products p
        JOIN hr_radars r ON r.slug = p.radar_slug
        WHERE p.radar_slug IS NOT NULL AND p.radar_id IS NULL
    ");

    // Produtos anteriores aos radares (radar_slug NULL) NÃO recebem associação:
    // não sabemos a qual radar pertencem. Ficam visíveis em "todos os radares" e
    // são re-associados na próxima coleta em que reaparecerem por um radar.
};
