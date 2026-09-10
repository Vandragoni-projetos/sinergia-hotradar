<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * E4 — Semente do primeiro radar, equivalente ao preset "Casa + Cozinha + Organização"
 * que estava fixo no código (agora só em banco). Mantém a produção funcionando após o deploy.
 *
 * Idempotente: só insere se ainda não houver nenhum radar.
 */
return function (Connection $db, string $driver): void {
    $count = (int) ($db->first('SELECT COUNT(*) AS n FROM hr_radars')['n'] ?? 0);
    if ($count > 0) {
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
            'casa-organizacao',
            'Casa & Organização',
            1,
            json_encode(['mercado_livre'], JSON_UNESCAPED_UNICODE),
            json_encode([
                ['id' => 'MLB1574', 'label' => 'Casa, Móveis e Decoração'],
                ['id' => 'MLB5726', 'label' => 'Eletrodomésticos'],
            ], JSON_UNESCAPED_UNICODE),
            json_encode([], JSON_UNESCAPED_UNICODE),
            json_encode(['organizador', 'cozinha', 'utilidades'], JSON_UNESCAPED_UNICODE),
            json_encode(['usado', 'peça de reposição', 'reposição', 'somente a peça'], JSON_UNESCAPED_UNICODE),
            3,
            null,
            null,
            null,
            0,
            $now,
            $now,
        ]
    );
};
