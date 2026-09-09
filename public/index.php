<?php
declare(strict_types=1);

/**
 * SINERGIA HOTRADAR — painel de curadoria (E3).
 * Front controller único. Sem framework. Roteamento por ?r=.
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Score\ScoreBreakdown;
use HotRadar\Web\View;

$config = hr_config();
$app = App::boot($config);

// --- auth básica (painel interno) -------------------------------------------
$panelUser = (string) $config['panel']['user'];
$panelPass = (string) $config['panel']['password'];
if ($panelPass !== '') {
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    if (!hash_equals($panelUser, $u) || !hash_equals($panelPass, $p)) {
        header('WWW-Authenticate: Basic realm="SINERGIA HOTRADAR"');
        http_response_code(401);
        echo 'Autenticação necessária.';
        exit;
    }
}

// garante schema
$app->migrator()->migrate();

$route = $_GET['r'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST' && $route === 'product.status') {
        handleStatusChange($app);
        exit;
    }
    if ($method === 'POST' && $route === 'collect.run') {
        handleCollectRun($app);
        exit;
    }

    match ($route) {
        'dashboard' => screenDashboard($app),
        'products' => screenProducts($app),
        'product' => screenProduct($app),
        default => screenDashboard($app),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<pre>' . View::e($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>';
}

// ---------------------------------------------------------------------------

function screenDashboard(App $app): void
{
    $products = $app->products();
    $runs = $app->runs();
    $db = $app->db;

    $today = date('Y-m-d 00:00:00');
    $data = [
        'active' => 'dashboard',
        'total' => (int) ($db->first('SELECT COUNT(*) n FROM hr_products')['n'] ?? 0),
        'today' => (int) ($db->first('SELECT COUNT(*) n FROM hr_products WHERE discovered_at >= ?', [$today])['n'] ?? 0),
        'faixa' => $products->faixaDistribution(),
        'status' => $products->statusDistribution(),
        'with_video' => (int) ($db->first('SELECT COUNT(*) n FROM hr_products WHERE has_video = 1')['n'] ?? 0),
        'by_marketplace' => $db->all('SELECT marketplace, COUNT(*) n FROM hr_products GROUP BY marketplace'),
        'runs' => $runs->recent(12),
        'collectors' => collectorStatuses($app),
        'snapshots' => (int) ($db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'] ?? 0),
    ];
    View::page('dashboard', $data, 'Dashboard');
}

function screenProducts(App $app): void
{
    $filters = [
        'marketplace' => $_GET['marketplace'] ?? '',
        'faixa' => $_GET['faixa'] ?? '',
        'status' => $_GET['status'] ?? '',
        'category' => $_GET['category'] ?? '',
        'has_video' => $_GET['has_video'] ?? '',
        'min_discount' => $_GET['min_discount'] ?? '',
        'min_rating' => $_GET['min_rating'] ?? '',
        'discovered_since' => $_GET['discovered_since'] ?? '',
        'q' => $_GET['q'] ?? '',
        'limit' => 300,
    ];
    $rows = $app->products()->search(array_filter($filters, static fn ($v) => $v !== ''));

    $categories = array_column(
        $app->db->all('SELECT DISTINCT category FROM hr_products WHERE category IS NOT NULL ORDER BY category'),
        'category'
    );

    View::page('products/index', [
        'active' => 'products',
        'rows' => $rows,
        'filters' => $filters,
        'categories' => $categories,
        'total' => count($rows),
    ], 'Curadoria');
}

function screenProduct(App $app): void
{
    $id = (int) ($_GET['id'] ?? 0);
    $row = $app->products()->find($id);
    if ($row === null) {
        http_response_code(404);
        echo 'Produto não encontrado.';
        return;
    }
    $breakdown = ScoreBreakdown::fromJson($row['hot_score_breakdown'] ?? null);
    $history = $app->snapshots()->history($id);
    $timeline = $app->editorial()->timeline($id);

    View::page('products/show', [
        'active' => 'products',
        'p' => $row,
        'breakdown' => $breakdown,
        'history' => $history,
        'timeline' => $timeline,
        'extra' => json_decode((string) ($row['marketplace_extra'] ?? '{}'), true) ?: [],
        'signals' => json_decode((string) ($row['special_signals'] ?? '[]'), true) ?: [],
    ], 'Ficha — ' . mb_substr((string) $row['title'], 0, 40));
}

function handleStatusChange(App $app): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $to = (string) ($_POST['to'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? '')) ?: null;

    if (!in_array($to, EditorialStatus::active(), true)) {
        http_response_code(422);
        echo 'Status inválido.';
        return;
    }
    $row = $app->products()->find($id);
    if ($row === null) {
        http_response_code(404);
        echo 'Produto não encontrado.';
        return;
    }
    $from = (string) $row['status'];
    $app->products()->setStatus($id, $to, $to === EditorialStatus::DESCARTADO ? $reason : null);
    $app->editorial()->log($id, $from, $to, $reason, 'humano:painel');

    $back = $_POST['back'] ?? ('?r=product&id=' . $id);
    header('Location: ' . $back);
}

function handleCollectRun(App $app): void
{
    $pages = max(1, min(10, (int) ($_POST['pages'] ?? 3)));
    $dry = !empty($_POST['dry_run']);
    $result = $app->discovery()->run(
        $app->mercadoLivreCollector(),
        new CollectorContext(dryRun: $dry, maxPages: $pages)
    );
    $q = http_build_query([
        'r' => 'dashboard',
        'flash' => sprintf(
            '%s: %d coletados, %d novos, %d atualizados%s',
            $result['mode'],
            $result['collected'],
            $result['new'],
            $result['updated'],
            $result['errors'] ? ' — ' . count($result['errors']) . ' erro(s)' : ''
        ),
    ]);
    header('Location: ?' . $q);
}

/** @return array<int,array{marketplace:string,available:bool,reason:?string}> */
function collectorStatuses(App $app): array
{
    $out = [];
    foreach ($app->collectors() as $c) {
        $out[] = [
            'marketplace' => $c->marketplace(),
            'available' => $c->isAvailable(),
            'reason' => $c->unavailableReason(),
        ];
    }
    return $out;
}
