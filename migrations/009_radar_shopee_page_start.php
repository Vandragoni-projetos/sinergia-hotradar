<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * "Começar na página" do feed Shopee — coluna nova em hr_radars.
 *
 * Hoje o ShopeeCollector sempre pagina o feed a partir da página 1. Esta
 * coluna permite escolher DE ONDE as páginas configuradas em
 * pages_per_category começam a ser lidas (ex.: início=11 + 10 páginas =
 * páginas 11–20) — pages_per_category continua sendo só a QUANTIDADE,
 * limitada a 1–10 como hoje, sem nenhuma mudança nesse limite.
 *
 * Específica da Shopee: Mercado Livre continua usando só pages_per_category
 * (páginas por categoria, sempre a partir da 1), sem nenhuma relação com
 * esta coluna.
 *
 * Estritamente ADITIVA e NÃO DESTRUTIVA:
 *   - só ADD COLUMN, NULLABLE;
 *   - nenhuma linha existente de hr_radars é lida ou alterada por esta migration;
 *   - radar antigo sem este campo = comportamento de coleta inalterado
 *     (Radar::fromRow() trata NULL/ausente como página inicial 1, igual a hoje).
 *
 * Idempotente: o ADD COLUMN só roda se a coluna ainda não existir (mesmo
 * padrão de migrations/007_hotscore_v2_context.php e
 * migrations/008_radar_desired_words.php).
 *
 * SQLite (dev) + MySQL/MariaDB (produção).
 */
return function (Connection $db, string $driver): void {
    $pdo = $db->pdo();

    $existing = [];
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(hr_radars)') as $row) {
            $existing[] = (string) $row['name'];
        }
    } else {
        foreach ($pdo->query('SHOW COLUMNS FROM hr_radars') as $row) {
            $existing[] = (string) $row['Field'];
        }
    }
    $has = static fn (string $c): bool => in_array($c, $existing, true);

    if (!$has('shopee_page_start')) {
        $pdo->exec('ALTER TABLE hr_radars ADD COLUMN shopee_page_start INTEGER NULL');
    }
};
