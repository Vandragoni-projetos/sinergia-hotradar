<?php
declare(strict_types=1);

use HotRadar\Support\Env;

/**
 * Configuração central do HOTRADAR. Lê do ambiente (.env). Sem segredos hardcoded.
 * @return array<string,mixed>
 */
return [
    'env' => Env::get('HR_ENV', 'local'),
    'app_url' => Env::get('HR_APP_URL', 'http://localhost:8090'),
    'timezone' => Env::get('HR_TIMEZONE', 'America/Sao_Paulo'),

    'db' => [
        'driver' => Env::get('HR_DB_DRIVER', 'sqlite'),
        'sqlite_path' => Env::get('HR_DB_SQLITE_PATH', 'storage/hotradar.sqlite'),
        'host' => Env::get('HR_DB_HOST', 'hotradar-db'),
        'port' => Env::int('HR_DB_PORT', 3306),
        'name' => Env::get('HR_DB_NAME', 'hotradar'),
        'user' => Env::get('HR_DB_USER', 'hotradar'),
        'password' => Env::get('HR_DB_PASSWORD', ''),
    ],

    'marketplaces' => [
        'mercado_livre' => [
            'enabled' => Env::bool('HR_ML_ENABLED', true),
            'http_timeout' => Env::int('HR_ML_HTTP_TIMEOUT', 30),
            'max_pages' => Env::int('HR_ML_MAX_PAGES', 3),
            'request_delay_ms' => Env::int('HR_ML_REQUEST_DELAY_MS', 3500),
            'user_agent' => Env::get(
                'HR_ML_USER_AGENT',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ),
            'target_categories' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) Env::get('HR_ML_TARGET_CATEGORIES', ''))
            ))),
        ],
        'shopee' => [
            // Credenciais SOMENTE por Environment. Nomes oficiais: SHOPEE_APP_ID / SHOPEE_SECRET
            // (aceita HR_SHOPEE_* como retrocompatibilidade). O "enabled" real é decidido por
            // HotRadar\Integration\ShopeeStatus (precisa de credenciais + acesso Open API concedido).
            'app_id' => Env::get('SHOPEE_APP_ID', Env::get('HR_SHOPEE_APP_ID', '')),
            'secret' => Env::get('SHOPEE_SECRET', Env::get('HR_SHOPEE_SECRET', '')),
            'graphql_url' => Env::get('HR_SHOPEE_GRAPHQL_URL', 'https://open-api.affiliate.shopee.com.br/graphql'),
        ],
    ],

    // OpenAI — SOMENTE por Environment. Usada APENAS para resumos de relatório;
    // nunca toca no HOT SCORE. Nomes: OPENAI_API_KEY / OPENAI_MODEL.
    // (a chave NÃO é lida aqui — ver HotRadar\Integration\OpenAi\OpenAiConfig)
    'openai' => [
        'configured_hint' => Env::get('OPENAI_API_KEY', '') !== '' ? 'sim' : 'nao',
        'model' => Env::get('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'panel' => [
        'user' => Env::get('HR_PANEL_USER', 'admin'),
        'password' => Env::get('HR_PANEL_PASSWORD', 'hotradar'),
    ],

    'affiliate' => [
        // Fase posterior. Em E0/E1/E3 o campo url_afiliada simplesmente fica vazio.
        'ml_mode' => Env::get('HR_ML_AFFILIATE_MODE', 'off'),
        'ml_endpoint' => Env::get('HR_ML_AFFILIATE_ENDPOINT', ''),
        'ml_token' => Env::get('HR_ML_AFFILIATE_TOKEN', ''),
    ],

    'hotscore' => require __DIR__ . '/hotscore.php',
];
