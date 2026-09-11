<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * E5 (shadow) — colunas contextuais do HOT SCORE V2 em hr_product_radars.
 *
 * O HOT SCORE V2 separa o score em duas partes:
 *   - BASE (fatos do produto) continua em hr_products, como hoje;
 *   - ADERÊNCIA passa a ser uma propriedade da RELAÇÃO produto × radar
 *     (não mais um atributo global hardcoded do produto) — por isso vive
 *     aqui, em hr_product_radars, e não em hr_products.
 *
 * Estritamente ADITIVA e NÃO DESTRUTIVA:
 *   - só ADD COLUMN, todas NULLABLE;
 *   - nenhuma coluna de hr_products é tocada;
 *   - nenhuma linha existente é lida ou alterada por esta migration;
 *   - nenhum produto/snapshot/evento editorial/associação é afetado.
 *
 * Idempotente: cada ADD COLUMN só roda se a coluna ainda não existir (defesa
 * extra, além do controle normal de "já aplicada" do Migrator via hr_migrations).
 *
 * SQLite (dev) + MySQL/MariaDB (produção).
 *
 * Estas colunas são preenchidas SOMENTE pelo comando
 * `php bin/hr.php hotscore:shadow-v2` (shadow mode) — nada aqui é lido pela
 * Curadoria, pelo dashboard ou pela coleta enquanto a V2 não for ativada.
 */
return function (Connection $db, string $driver): void {
    $pdo = $db->pdo();

    $existing = [];
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info(hr_product_radars)') as $row) {
            $existing[] = (string) $row['name'];
        }
    } else {
        foreach ($pdo->query('SHOW COLUMNS FROM hr_product_radars') as $row) {
            $existing[] = (string) $row['Field'];
        }
    }
    $has = static fn (string $c): bool => in_array($c, $existing, true);

    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
    $dt = $driver === 'sqlite' ? 'TEXT' : 'DATETIME';

    // nível de aderência calculado no contexto DESTE radar: alta|media|baixa|fora
    if (!$has('adherence_level')) {
        $pdo->exec('ALTER TABLE hr_product_radars ADD COLUMN adherence_level VARCHAR(10) NULL');
    }
    // pontos do bloco de aderência (0 a 18 na V2 atual)
    if (!$has('adherence_points')) {
        $pdo->exec('ALTER TABLE hr_product_radars ADD COLUMN adherence_points INTEGER NULL');
    }
    // HOT SCORE efetivo NESTE radar = BASE(produto) + aderência(este radar)
    if (!$has('context_score')) {
        $pdo->exec('ALTER TABLE hr_product_radars ADD COLUMN context_score INTEGER NULL');
    }
    // faixa (muito_quente|bom|analisar|baixo) calculada a partir do context_score
    if (!$has('context_faixa')) {
        $pdo->exec('ALTER TABLE hr_product_radars ADD COLUMN context_faixa VARCHAR(20) NULL');
    }
    // detalhamento explicável (mesmo formato de hr_products.hot_score_breakdown)
    if (!$has('context_breakdown')) {
        $pdo->exec("ALTER TABLE hr_product_radars ADD COLUMN context_breakdown $json NULL");
    }
    // versão da fórmula usada (ex.: 'v2') — permite conviver com futuras v3, v4...
    if (!$has('context_score_version')) {
        $pdo->exec('ALTER TABLE hr_product_radars ADD COLUMN context_score_version VARCHAR(10) NULL');
    }
    // quando este contexto foi calculado pela última vez
    if (!$has('context_score_updated_at')) {
        $pdo->exec("ALTER TABLE hr_product_radars ADD COLUMN context_score_updated_at $dt NULL");
    }
};
