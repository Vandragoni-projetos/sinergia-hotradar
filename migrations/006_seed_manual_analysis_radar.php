<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * E4.2 — Radar "Análises manuais": destino padrão para produtos que o usuário
 * analisa por URL e decide guardar. Fica PAUSADO por padrão (não entra em
 * "Coletar agora"). O usuário pode renomear/ativar/excluir livremente.
 *
 * Incremental, idempotente, NÃO destrutivo. Só INSERE se o slug ainda não existe.
 */
return function (Connection $db, string $driver): void {
    $exists = $db->first('SELECT 1 FROM hr_radars WHERE slug = ?', ['analises-manuais']);
    if ($exists !== null) {
        return;
    }
    $now = $db->now();
    $db->run(
        'INSERT INTO hr_radars
            (slug, name, enabled, marketplaces, ml_categories, shopee_keywords,
             extra_keywords, excluded_words, pages_per_category, min_discount,
             price_min, price_max, require_video, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            'analises-manuais',
            'Análises manuais',
            0, // pausado
            json_encode(['mercado_livre'], JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            1,
            null,
            null,
            null,
            0,
            $now,
            $now,
        ]
    );
};
