<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * "Página final" do feed Shopee — coluna nova em hr_radars.
 *
 * Complementa shopee_page_start (migration 009): a paginação Shopee passa a
 * ser uma faixa EXPLÍCITA [shopee_page_start, shopee_page_end], em vez de
 * início + quantidade. pages_per_category deixa de ser usado pela Shopee a
 * partir desta mudança — continua existindo e funcionando exatamente como
 * antes, só para o Mercado Livre.
 *
 * Retrocompatibilidade (radar antigo com shopee_page_start mas SEM
 * shopee_page_end): Radar::fromRow() deriva o fim equivalente ao
 * comportamento anterior — end = start + pages_per_category - 1 — então um
 * radar salvo antes desta migration continua coletando exatamente a mesma
 * faixa de páginas que coletava antes, sem nenhuma ação manual.
 *
 * Estritamente ADITIVA e NÃO DESTRUTIVA:
 *   - só ADD COLUMN, NULLABLE;
 *   - nenhuma linha existente de hr_radars é lida ou alterada por esta migration;
 *   - nada é recalculado ou escrito em lote — a derivação acontece em
 *     tempo de leitura (Radar::fromRow()), nunca aqui.
 *
 * Idempotente: o ADD COLUMN só roda se a coluna ainda não existir (mesmo
 * padrão de migrations/007, /008 e /009).
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

    if (!$has('shopee_page_end')) {
        $pdo->exec('ALTER TABLE hr_radars ADD COLUMN shopee_page_end INTEGER NULL');
    }
};
