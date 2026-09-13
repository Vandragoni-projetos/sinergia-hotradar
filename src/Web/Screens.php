<?php
declare(strict_types=1);

namespace HotRadar\Web;

use HotRadar\App;
use HotRadar\Collector\MercadoLivre\MlCategories;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Score\HotScoreConfig;
use HotRadar\Score\ScoreBreakdown;

/**
 * Renderização das telas (GET). Cada método monta os dados e chama View::page().
 */
final class Screens
{
    public static function dashboard(App $app): void
    {
        $db = $app->db;
        $products = $app->products();
        $today = date('Y-m-d 00:00:00');

        View::page('dashboard', [
            'active' => 'dashboard',
            'total' => (int) ($db->first('SELECT COUNT(*) n FROM hr_products')['n'] ?? 0),
            'today' => (int) ($db->first('SELECT COUNT(*) n FROM hr_products WHERE discovered_at >= ?', [$today])['n'] ?? 0),
            'with_video' => (int) ($db->first('SELECT COUNT(*) n FROM hr_products WHERE has_video = 1')['n'] ?? 0),
            'snapshots' => (int) ($db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'] ?? 0),
            'faixa' => $products->faixaDistribution(),
            'status' => $products->statusDistribution(),
            'by_radar' => $app->reports()->byRadar(),
            'runs' => $app->runs()->recent(12),
            'runs_total' => $app->runs()->countActive(), // operacional: radares existentes + sem radar (não infla com radar excluído)
            'runs_total_historico' => $app->runs()->countAll(), // total bruto real, incluindo radares já excluídos
            'collectors' => self::collectorStatuses($app),
            'radars' => $app->radars()->all(),
        ], 'Dashboard');
    }

    /** Whitelist de ordenação aceita pela Curadoria — valor fora daqui cai no padrão. */
    private const SORT_OPTIONS = ['hot_desc', 'hot_asc'];
    private const DEFAULT_SORT = 'hot_desc';

    public static function products(App $app): void
    {
        $sort = (string) ($_GET['sort'] ?? '');
        if (!in_array($sort, self::SORT_OPTIONS, true)) {
            $sort = self::DEFAULT_SORT;
        }
        $filters = [
            'radar' => $_GET['radar'] ?? '',
            'marketplace' => $_GET['marketplace'] ?? '',
            'faixa' => $_GET['faixa'] ?? '',
            'status' => $_GET['status'] ?? '',
            'category' => $_GET['category'] ?? '',
            'has_video' => $_GET['has_video'] ?? '',
            'min_discount' => $_GET['min_discount'] ?? '',
            'min_rating' => $_GET['min_rating'] ?? '',
            'discovered_since' => $_GET['discovered_since'] ?? '',
            'q' => $_GET['q'] ?? '',
            'sort' => $sort,
            'limit' => 300,
        ];
        $rows = $app->products()->search(array_filter($filters, static fn ($v) => $v !== ''));
        $categories = array_column(
            $app->db->all('SELECT DISTINCT category FROM hr_products WHERE category IS NOT NULL ORDER BY category'),
            'category'
        );
        // radares (M2M) por produto, em lote — nomes amigáveis
        $radarSlugsByProduct = $app->productRadars()->slugsByProductIds(array_column($rows, 'id'));
        $radarNames = [];
        foreach ($app->radars()->all() as $r) {
            $radarNames[$r->slug] = $r->name;
        }

        View::page('products/index', [
            'active' => 'products',
            'rows' => $rows,
            'filters' => $filters,
            'categories' => $categories,
            'radars' => $app->radars()->all(),
            'radar_slugs_by_product' => $radarSlugsByProduct,
            'radar_names' => $radarNames,
            'total' => count($rows),
            'query_string' => http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== 300)),
        ], 'Curadoria');
    }

    public static function product(App $app): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $row = $app->products()->find($id);
        if ($row === null) {
            http_response_code(404);
            echo 'Produto não encontrado.';
            return;
        }
        View::page('products/show', [
            'active' => 'products',
            'p' => $row,
            'breakdown' => ScoreBreakdown::fromJson($row['hot_score_breakdown'] ?? null),
            'history' => $app->snapshots()->history($id),
            'timeline' => $app->editorial()->timeline($id),
            'extra' => json_decode((string) ($row['marketplace_extra'] ?? '{}'), true) ?: [],
            'signals' => json_decode((string) ($row['special_signals'] ?? '[]'), true) ?: [],
            'primary_radar' => $row['radar_slug'] ? $app->radars()->findBySlug((string) $row['radar_slug']) : null,
            'product_radars' => $app->productRadars()->radarsForProduct($id),
        ], 'Ficha — ' . mb_substr((string) $row['title'], 0, 40));
    }

    public static function login(App $app): void
    {
        if (Auth::check()) {
            header('Location: ?r=dashboard');
            return;
        }
        View::bare('login', [
            'error' => $_GET['e'] ?? null,
        ], 'Entrar');
    }

    public static function radars(App $app): void
    {
        $radars = $app->radars()->all();
        $counts = self::radarProductCounts($app);
        // última coleta por radar
        $lastByRadar = [];
        foreach ($app->db->all(
            "SELECT radar_slug, MAX(started_at) last, MAX(status) st FROM hr_collection_runs
             WHERE radar_slug IS NOT NULL GROUP BY radar_slug"
        ) as $r) {
            $lastByRadar[(string) $r['radar_slug']] = $r['last'];
        }
        View::page('radars/index', [
            'active' => 'radars',
            'radars' => $radars,
            'counts' => $counts,
            'last_by_radar' => $lastByRadar,
        ], 'Radares');
    }

    public static function radarDelete(App $app): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $radar = $app->radars()->find($id);
        if ($radar === null) {
            header('Location: ?r=radars');
            return;
        }
        View::page('radars/delete', [
            'active' => 'radars',
            'radar' => $radar,
            'impact' => $app->radarLifecycle()->impact($radar),
        ], 'Excluir radar');
    }

    public static function radarEdit(App $app): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $radar = $id > 0 ? $app->radars()->find($id) : null;

        View::page('radars/edit', [
            'active' => 'radars',
            'radar' => $radar,
            'catalog' => MlCategories::catalog(),
        ], $radar ? 'Editar Radar' : 'Criar Radar');
    }

    public static function reports(App $app): void
    {
        $reports = $app->reports();
        $f = array_filter([
            'radar' => $_GET['radar'] ?? '',
            'marketplace' => $_GET['marketplace'] ?? '',
        ], static fn ($v) => $v !== '');

        $tab = (string) ($_GET['tab'] ?? 'resumo');
        $today = date('Y-m-d');
        $since7 = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $movers = $reports->scoreMovers($f, 25);

        View::page('reports/index', [
            'active' => 'reports',
            'tab' => $tab,
            'filters' => $f,
            'radars' => $app->radars()->all(),
            'ai_ready' => $app->aiReports()->isReady(),
            'ai_text' => $_GET['ai'] ?? null,
            'ai_payload_preview' => isset($_GET['ai_preview']) ? $app->aiReports()->buildPayload($f) : null,
            'last_run' => $reports->lastRun(),
            'daily' => $reports->daily($today, $f),
            'new_products' => $reports->newProducts($since7, $f, 100),
            'updated_products' => $reports->updatedProducts($f, 100),
            'top_scores' => $reports->topHotScores($f, 30),
            'score_up' => $movers['up'],
            'score_down' => $movers['down'],
            'price_drops' => $reports->priceDrops($f, 25),
            'with_video' => $reports->withVideo($f, 100),
            'by_category' => $reports->byCategory($f),
            'by_radar' => $reports->byRadar(),
            'status_dist' => $reports->statusDistribution($f),
            'aprovados' => $reports->byStatus(EditorialStatus::APROVADO, $f),
            'analisar' => $reports->byStatus(EditorialStatus::ANALISAR, $f),
            'descartados' => $reports->byStatus(EditorialStatus::DESCARTADO, $f),
            'run_history' => $reports->runHistory(50),
            'collection_errors' => $reports->collectionErrors(60),
        ], 'Relatórios');
    }

    public static function config(App $app): void
    {
        $sub = (string) ($_GET['sub'] ?? 'geral');
        $shopee = $app->shopeeStatus();
        $openai = $app->openAiConfig();
        $settings = $app->settings();

        View::page('config/index', [
            'active' => 'config',
            'sub' => $sub,
            'general' => $settings->get('general', self::generalDefaults()),
            'general_defaults' => self::generalDefaults(),
            'ml' => hr_config()['marketplaces']['mercado_livre'],
            'shopee_state' => $shopee->state(),
            'shopee_label' => $shopee->label(),
            'shopee_appid_present' => $shopee->appIdPresent(),
            'shopee_secret_present' => $shopee->secretPresent(),
            'shopee_granted' => $shopee->openApiGranted(),
            'openai_configured' => $openai->isConfigured(),
            'openai_status' => $openai->statusLabel(),
            'openai_model' => $openai->model(),
            'hs_config' => $app->hotScoreConfig(),
            'hs_source' => HotScoreConfig::activeSource($app->db),
            'hs_default' => HotScoreConfig::fileDefault(),
            'audit' => $app->audit()->recent(null, 40),
            'radars_count' => $app->radars()->count(),
            'auth_required' => Auth::required(),
            'auth_user_set' => trim((string) (hr_config()['panel']['user'] ?? '')) !== '',
            'auth_hash_set' => trim((string) (hr_config()['panel']['password_hash'] ?? '')) !== '',
        ], 'Configurações');
    }

    /**
     * Diagnóstico HOT SCORE V1 × V2 (shadow mode). Somente leitura — não
     * altera nada. Compara o score oficial (V1, em hr_products) com o score
     * V2 calculado por `php bin/hr.php hotscore:shadow-v2` no contexto de
     * cada radar (hr_product_radars).
     */
    public static function hotscoreCompare(App $app): void
    {
        $radarSlug = (string) ($_GET['radar'] ?? '');
        $rows = $app->productRadars()->compareRows($radarSlug !== '' ? $radarSlug : null, 500);

        View::page('hotscore/compare', [
            'active' => 'config',
            'rows' => $rows,
            'radars' => $app->radars()->all(),
            'radar_slug' => $radarSlug,
            'scored_count' => $app->productRadars()->shadowScoredCount(),
            'active_version' => $app->hotScoreActiveVersion(),
            'v2_config' => $app->hotScoreV2Config(),
        ], 'Hot Score V1 × V2 (diagnóstico)');
    }

    /**
     * "Zerar tudo" — tela de impacto (GET), SEPARADA da exclusão/limpeza de
     * radar. Só leitura — mostra o que seria apagado, nada muda aqui.
     */
    public static function systemResetImpact(App $app): void
    {
        View::page('system/reset', [
            'active' => 'config',
            'impact' => $app->systemReset()->impact(),
        ], 'Zerar tudo — SINERGIA HOTRADAR');
    }

    public static function analyze(App $app): void
    {
        View::page('analyze/index', [
            'active' => 'analyze',
            'result' => null,
            'url' => '',
            'radars' => $app->radars()->all(),
        ], 'Analisar por URL');
    }

    // ---- telas de impressão (viram PDF pelo "Salvar como PDF" do navegador) ----

    public static function printFicha(App $app): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $row = $app->products()->find($id);
        if ($row === null) {
            http_response_code(404);
            echo 'Produto não encontrado.';
            return;
        }
        View::bare('print/ficha', [
            'p' => $row,
            'breakdown' => ScoreBreakdown::fromJson($row['hot_score_breakdown'] ?? null),
            'radares' => $app->productRadars()->radarsForProduct($id),
            'auto' => isset($_GET['auto']),
        ], 'Ficha — ' . mb_substr((string) $row['title'], 0, 40));
    }

    public static function printPack(App $app): void
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))));
        $radarSlug = (string) ($_GET['radar'] ?? '');
        $onlyApproved = isset($_GET['aprovados']);

        if ($ids === []) {
            $filters = ['limit' => 200];
            if ($radarSlug !== '') {
                $filters['radar'] = $radarSlug;
            }
            if ($onlyApproved) {
                $filters['status'] = 'aprovado';
            }
            $rows = $app->products()->search($filters);
        } else {
            $rows = [];
            foreach ($ids as $i) {
                $r = $app->products()->find($i);
                if ($r) {
                    $rows[] = $r;
                }
            }
            usort($rows, static fn ($a, $b) => (int) ($b['hot_score'] ?? 0) <=> (int) ($a['hot_score'] ?? 0));
        }

        $radar = $radarSlug !== '' ? $app->radars()->findBySlug($radarSlug) : null;
        View::bare('print/pack', [
            'rows' => $rows,
            'radar_name' => $radar?->name,
            'only_approved' => $onlyApproved,
            'auto' => isset($_GET['auto']),
        ], 'Pack — ' . ($radar?->name ?? 'Produtos'));
    }

    public static function printReport(App $app): void
    {
        $reports = $app->reports();
        $f = array_filter([
            'radar' => $_GET['radar'] ?? '',
            'marketplace' => $_GET['marketplace'] ?? '',
        ], static fn ($v) => $v !== '');
        $movers = $reports->scoreMovers($f, 20);
        $radar = !empty($f['radar']) ? $app->radars()->findBySlug((string) $f['radar']) : null;

        View::bare('print/report', [
            'radar_name' => $radar?->name,
            'last_run' => $reports->lastRun(),
            'faixa' => $reports->faixaDistribution($f),
            'status_dist' => $reports->statusDistribution($f),
            'top_scores' => $reports->topHotScores($f, 20),
            'score_up' => $movers['up'],
            'score_down' => $movers['down'],
            'price_drops' => $reports->priceDrops($f, 15),
            'with_video' => $reports->withVideo($f, 30),
            'by_category' => $reports->byCategory($f),
            'auto' => isset($_GET['auto']),
        ], 'Relatório — ' . ($radar?->name ?? 'Geral'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    public static function generalDefaults(): array
    {
        return [
            'timezone' => hr_config()['timezone'] ?? 'America/Sao_Paulo',
            'max_products_per_collect' => 500,
            'marketplaces_enabled' => ['mercado_livre'],
            'editorial' => [
                'auto_expire_days' => 0,          // 0 = desligado
                'default_status' => 'descoberto',
            ],
        ];
    }

    /** @return array<int,array{marketplace:string,available:bool,reason:?string,label:string}> */
    public static function collectorStatuses(App $app): array
    {
        $out = [];
        foreach ($app->collectors() as $c) {
            $label = match ($c->marketplace()) {
                'mercado_livre' => 'Mercado Livre — ATIVO / coletor público',
                'shopee' => 'Shopee — ' . $app->shopeeStatus()->label(),
                default => $c->marketplace(),
            };
            $out[] = [
                'marketplace' => $c->marketplace(),
                'available' => $c->isAvailable(),
                'reason' => $c->unavailableReason(),
                'label' => $label,
            ];
        }
        return $out;
    }

    /** @return array<string,int> radar_slug => nº de produtos ASSOCIADOS (M2M) */
    public static function radarProductCounts(App $app): array
    {
        return $app->productRadars()->countsBySlug();
    }
}
