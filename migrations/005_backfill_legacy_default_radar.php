<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * E4.1.1 — Backfill de CONTINUIDADE: associa os produtos LEGADOS ao radar padrão.
 *
 * Contexto: os ~97 produtos coletados antes da implantação de Radares (E4) têm
 * radar_id/radar_slug = NULL e, após a migration 004, ficaram sem nenhuma
 * associação em hr_product_radars. Todos foram coletados quando o sistema usava
 * exclusivamente o preset fixo "Casa + Cozinha + Organização" — exatamente o que
 * o radar semeado "Casa & Organização" (slug casa-organizacao, migration 003)
 * representa. Logo, devem ser associados a ele em vez de ficarem "sem radar".
 *
 * ────────────────────────────────────────────────────────────────────────────
 * CRITÉRIO INEQUÍVOCO DE "PRODUTO LEGADO" (baseado no schema/estado pré-E4):
 *   (a) hr_products.radar_id IS NULL AND radar_slug IS NULL
 *       → assinatura do coletor pré-E4: essas colunas só existem desde a
 *         migration 002 e o coletor antigo NUNCA as preenchia. O coletor E4+
 *         SEMPRE grava radar_id/radar_slug no INSERT de um produto novo.
 *   (b) hr_products.created_at <= applied_at da migration 004
 *       → o produto existe desde antes de a tabela hr_product_radars ser criada.
 *         Impede que produtos criados por coletas FUTURAS sejam apanhados aqui.
 *   (c) NÃO possui nenhuma linha em hr_product_radars
 *       → não mexe em produto já associado a qualquer radar.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Garantias: não altera hr_products, preço, Hot Score, snapshots nem status
 * editorial. Só INSERE associações. Idempotente (critério (c) + INSERT IGNORE +
 * UNIQUE product_id+radar_id). Não altera migrations 001–004.
 */
return function (Connection $db, string $driver): void {
    // 1) radar padrão semeado
    $radar = $db->first('SELECT id FROM hr_radars WHERE slug = ?', ['casa-organizacao']);
    if ($radar === null) {
        // radar padrão inexistente (removido manualmente) → nada a fazer, sem erro.
        return;
    }
    $radarId = (int) $radar['id'];

    // 2) marco temporal: quando a tabela M2M foi criada (migration 004)
    $ref = $db->first('SELECT applied_at FROM hr_migrations WHERE name = ?', ['004_product_radars_m2m']);
    $cutoff = ($ref['applied_at'] ?? null) ?: $db->now();

    // 3) produtos legados órfãos (SELECT puro — pode referenciar hr_product_radars sem erro 1093)
    $legacy = $db->all(
        'SELECT p.id, p.discovered_at, p.last_collected_at
         FROM hr_products p
         WHERE p.radar_id IS NULL
           AND p.radar_slug IS NULL
           AND p.created_at <= ?
           AND NOT EXISTS (SELECT 1 FROM hr_product_radars pr WHERE pr.product_id = p.id)',
        [$cutoff]
    );

    if ($legacy === []) {
        return;
    }

    // 4) associa ao radar padrão — INSERT ... VALUES (sem subconsulta na tabela-alvo).
    //    INSERT IGNORE + UNIQUE(product_id, radar_id) = rede de segurança extra p/ idempotência.
    $insertIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
    $sql = "$insertIgnore hr_product_radars
                (product_id, radar_id, first_seen_at, last_seen_at, present)
            VALUES (?, ?, ?, ?, 1)";

    foreach ($legacy as $row) {
        $db->run($sql, [
            (int) $row['id'],
            $radarId,
            // datas retroativas: melhor estimativa a partir do próprio produto
            $row['discovered_at'],
            $row['last_collected_at'],
        ]);
    }
};
