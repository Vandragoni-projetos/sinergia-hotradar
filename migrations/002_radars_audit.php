<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * E4 — Radares/Nichos configuráveis + audit log + vínculo produto↔radar.
 *
 * "Radar" = um nicho monitorado com configuração PRÓPRIA (categorias, keywords,
 * exclusões, filtros de preço/desconto/vídeo, nº de páginas, on/off).
 * NENHUM id de categoria fica hardcoded no código — tudo vem de hr_radars.
 *
 * SQLite (dev) + MySQL/MariaDB (produção).
 */
return function (Connection $db, string $driver): void {
    $pdo = $db->pdo();

    if ($driver === 'sqlite') {
        $pk = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fk = 'INTEGER';
        $json = 'TEXT';
        $dt = 'TEXT';
        $money = 'NUMERIC';
        $bin = '';
        $engine = '';
    } else {
        $pk = 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $fk = 'BIGINT UNSIGNED';
        $json = 'JSON';
        $dt = 'DATETIME';
        $money = 'DECIMAL(12,2)';
        $bin = ' COLLATE utf8mb4_bin';
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    // ---------------------------------------------------------------- hr_radars
    $pdo->exec("
        CREATE TABLE hr_radars (
            id $pk,
            slug                VARCHAR(60)$bin NOT NULL,
            name                VARCHAR(120)    NOT NULL,
            enabled             INTEGER         NOT NULL DEFAULT 1,

            marketplaces        $json           NOT NULL,   -- [\"mercado_livre\"]
            ml_categories       $json           NOT NULL,   -- [{\"id\":\"MLB1574\",\"label\":\"...\"}]
            shopee_keywords     $json           NOT NULL,   -- [] (futuro)
            extra_keywords      $json           NOT NULL,   -- [\"organizador\",\"cozinha\"]
            excluded_words      $json           NOT NULL,   -- [\"usado\",\"peça\",\"reposição\"]

            pages_per_category  INTEGER         NOT NULL DEFAULT 3,
            min_discount        INTEGER         NULL,
            price_min           $money          NULL,
            price_max           $money          NULL,
            require_video       INTEGER         NOT NULL DEFAULT 0,

            created_at          $dt             NOT NULL,
            updated_at          $dt             NOT NULL
        )$engine
    ");
    $pdo->exec('CREATE UNIQUE INDEX ux_hr_radars_slug ON hr_radars (slug)');
    $pdo->exec('CREATE INDEX ix_hr_radars_enabled ON hr_radars (enabled)');

    // -------------------------------------------------------------- hr_audit_log
    // Histórico de alterações de configuração (Hot Score, radares, settings gerais).
    $pdo->exec("
        CREATE TABLE hr_audit_log (
            id $pk,
            area        VARCHAR(40)  NOT NULL,   -- hotscore | radar | settings
            action      VARCHAR(20)  NOT NULL,   -- create | update | delete | enable | disable
            ref         VARCHAR(120) NULL,       -- slug do radar / 'weights' / 'faixas' / chave
            before_json $json        NULL,
            after_json  $json        NULL,
            actor       VARCHAR(60)  NOT NULL DEFAULT 'humano',
            created_at  $dt          NOT NULL
        )$engine
    ");
    $pdo->exec('CREATE INDEX ix_hr_audit_area ON hr_audit_log (area, created_at)');

    // ------------------------------------------------ vínculo produto/coleta ↔ radar
    $pdo->exec("ALTER TABLE hr_products ADD COLUMN radar_id $fk NULL");
    $pdo->exec("ALTER TABLE hr_products ADD COLUMN radar_slug VARCHAR(60) NULL");
    $pdo->exec('CREATE INDEX ix_hr_products_radar ON hr_products (radar_slug)');

    $pdo->exec("ALTER TABLE hr_collection_runs ADD COLUMN radar_id $fk NULL");
    $pdo->exec("ALTER TABLE hr_collection_runs ADD COLUMN radar_slug VARCHAR(60) NULL");
};
