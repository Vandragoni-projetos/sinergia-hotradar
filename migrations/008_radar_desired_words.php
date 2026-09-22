<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * Filtro positivo de Radar ("Palavras desejadas") — colunas novas em hr_radars.
 *
 * Complementa o filtro negativo já existente (excluded_words): permite que o
 * usuário diga o que o Radar DEVE encontrar, em vez de só o que deve excluir.
 * Aplicado em Radar::accepts() — vale igualmente para Mercado Livre e Shopee,
 * sem qualquer mudança nos coletores.
 *
 * Estritamente ADITIVA e NÃO DESTRUTIVA:
 *   - só ADD COLUMN, ambas NULLABLE;
 *   - nenhuma linha existente de hr_radars é lida ou alterada por esta migration;
 *   - radar antigo sem estes campos = comportamento de coleta inalterado
 *     (Radar::fromRow() trata NULL/ausente exatamente como "sem filtro").
 *
 * Idempotente: cada ADD COLUMN só roda se a coluna ainda não existir (mesmo
 * padrão de migrations/007_hotscore_v2_context.php).
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

    $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';

    // termos que o produto deve conter no título (filtro positivo) — [] = sem filtro extra
    if (!$has('desired_words')) {
        $pdo->exec("ALTER TABLE hr_radars ADD COLUMN desired_words $json NULL");
    }
    // 'any' (pelo menos um termo) | 'all' (todos os termos) — valor inválido/ausente vira 'any' em runtime
    if (!$has('desired_words_mode')) {
        $pdo->exec('ALTER TABLE hr_radars ADD COLUMN desired_words_mode VARCHAR(10) NULL');
    }
};
