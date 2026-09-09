<?php
declare(strict_types=1);

use HotRadar\Db\Connection;

/**
 * Schema inicial do HOTRADAR (E0). Banco PRÓPRIO — prefixo hr_.
 * Modelo normalizado multi-marketplace + histórico (snapshots) + log de coletas
 * + trilha editorial + settings (override do HOT SCORE).
 *
 * Escrito para SQLite (dev) e MySQL/MariaDB (produção).
 */
return function (Connection $db, string $driver): void {
    $pdo = $db->pdo();

    if ($driver === 'sqlite') {
        $pk = 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fk = 'INTEGER';                 // referência lógica (sem constraint FK nesta V1)
        $json = 'TEXT';
        $dt = 'TEXT';
        $money = 'NUMERIC';
        $bin = '';                       // SQLite TEXT já é comparado byte-a-byte (BINARY)
        $engine = '';
    } else {
        $pk = 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $fk = 'BIGINT UNSIGNED';         // mesmo tipo do PK -> migration de FK futura fica trivial
        $json = 'JSON';
        $dt = 'DATETIME';
        $money = 'DECIMAL(12,2)';
        // dedup byte-a-byte na MariaDB (a collation utf8mb4 default é case/accent-insensitive)
        $bin = ' COLLATE utf8mb4_bin';
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    // ------------------------------------------------------------------ produtos
    $pdo->exec("
        CREATE TABLE hr_products (
            id $pk,
            marketplace              VARCHAR(30)$bin  NOT NULL,
            marketplace_product_id   VARCHAR(40)$bin  NOT NULL,
            shop_id                  VARCHAR(40)  NULL,
            title                    VARCHAR(300) NOT NULL,
            category                 VARCHAR(80)  NULL,
            subcategory              VARCHAR(80)  NULL,
            url_original             TEXT         NOT NULL,
            url_affiliate            TEXT         NULL,
            image_url                TEXT         NULL,

            price_current            $money       NULL,
            price_previous           $money       NULL,
            discount_pct             INTEGER      NULL,

            sales_signal             VARCHAR(20)  NULL,   -- muito_alto|alto|medio|baixo (NULL = não disponível)
            sales_exact              INTEGER      NULL,   -- número exato (Shopee); NULL no ML

            rating                   $money       NULL,
            rating_count             INTEGER      NULL,

            rank_position            INTEGER      NULL,
            special_signals          $json        NULL,   -- tags: best_seller_candidate, deal_of_the_day...
            has_video                INTEGER      NOT NULL DEFAULT 0,

            commission_pct           $money       NULL,   -- Shopee
            commission_estimated     $money       NULL,   -- Shopee
            campaign                 VARCHAR(80)  NULL,   -- promo_type ML / campanha Shopee

            marketplace_extra        $json        NULL,   -- dados específicos do marketplace

            hot_score                INTEGER      NULL,
            hot_faixa                VARCHAR(20)  NULL,
            hot_score_breakdown      $json        NULL,   -- componentes: por que recebeu a nota
            hot_score_version        VARCHAR(10)  NULL,

            data_quality             VARCHAR(20)  NOT NULL DEFAULT 'scrape_json', -- scrape_json|api|feed|manual
            source                   VARCHAR(40)  NOT NULL,                        -- ofertas_ml|mais_vendidos_ml|shopee_api...

            status                   VARCHAR(20)  NOT NULL DEFAULT 'descoberto',
            discard_reason           VARCHAR(255) NULL,

            discovered_at            $dt          NOT NULL,
            last_collected_at        $dt          NOT NULL,
            created_at               $dt          NOT NULL,
            updated_at               $dt          NOT NULL
        )$engine
    ");
    $pdo->exec('CREATE UNIQUE INDEX ux_hr_products_mp ON hr_products (marketplace, marketplace_product_id)');
    $pdo->exec('CREATE INDEX ix_hr_products_status ON hr_products (status)');
    $pdo->exec('CREATE INDEX ix_hr_products_score ON hr_products (hot_score)');
    $pdo->exec('CREATE INDEX ix_hr_products_disc ON hr_products (discovered_at)');

    // ------------------------------------------------------------- coletas (runs)
    $pdo->exec("
        CREATE TABLE hr_collection_runs (
            id $pk,
            marketplace       VARCHAR(30)  NOT NULL,
            source            VARCHAR(40)  NOT NULL,
            mode              VARCHAR(10)  NOT NULL DEFAULT 'live',  -- live|dry_run
            status            VARCHAR(20)  NOT NULL DEFAULT 'running', -- running|ok|error
            started_at        $dt          NOT NULL,
            finished_at       $dt          NULL,
            pages_fetched     INTEGER      NOT NULL DEFAULT 0,
            cards_seen        INTEGER      NOT NULL DEFAULT 0,
            products_new      INTEGER      NOT NULL DEFAULT 0,
            products_updated  INTEGER      NOT NULL DEFAULT 0,
            snapshots_written INTEGER      NOT NULL DEFAULT 0,
            errors            $json        NULL,
            notes             TEXT         NULL
        )$engine
    ");
    $pdo->exec('CREATE INDEX ix_hr_runs_started ON hr_collection_runs (started_at)');

    // -------------------------------------------------------- snapshots históricos
    $pdo->exec("
        CREATE TABLE hr_product_snapshots (
            id $pk,
            product_id     $fk          NOT NULL,
            run_id         $fk          NULL,
            collected_at   $dt          NOT NULL,

            price_current  $money       NULL,
            price_previous $money       NULL,
            discount_pct   INTEGER      NULL,
            sales_signal   VARCHAR(20)  NULL,
            sales_exact    INTEGER      NULL,
            rating         $money       NULL,
            rating_count   INTEGER      NULL,
            rank_position  INTEGER      NULL,

            hot_score      INTEGER      NULL,
            hot_faixa      VARCHAR(20)  NULL,
            present        INTEGER      NOT NULL DEFAULT 1,   -- 0 = produto sumiu do pool nesta coleta
            raw            $json        NULL
        )$engine
    ");
    $pdo->exec('CREATE INDEX ix_hr_snap_prod ON hr_product_snapshots (product_id, collected_at)');
    $pdo->exec('CREATE INDEX ix_hr_snap_run ON hr_product_snapshots (run_id)');

    // ----------------------------------------------------------- trilha editorial
    $pdo->exec("
        CREATE TABLE hr_editorial_events (
            id $pk,
            product_id   $fk          NOT NULL,
            from_status  VARCHAR(20)  NULL,
            to_status    VARCHAR(20)  NOT NULL,
            reason       VARCHAR(255) NULL,
            actor        VARCHAR(60)  NOT NULL DEFAULT 'humano',
            created_at   $dt          NOT NULL
        )$engine
    ");
    $pdo->exec('CREATE INDEX ix_hr_edit_prod ON hr_editorial_events (product_id, created_at)');

    // ------------------------------------------------------------------- settings
    $pdo->exec("
        CREATE TABLE hr_settings (
            skey        VARCHAR(60) NOT NULL PRIMARY KEY,
            svalue      $json       NOT NULL,
            updated_at  $dt         NOT NULL
        )$engine
    ");
};
