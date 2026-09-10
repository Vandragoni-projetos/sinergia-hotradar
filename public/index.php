<?php
declare(strict_types=1);

/**
 * SINERGIA HOTRADAR — painel. Front controller único, rotas por ?r=.
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use HotRadar\App;
use HotRadar\Web\Actions;
use HotRadar\Web\Screens;
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

$app->migrator()->migrate();

$route = (string) ($_GET['r'] ?? 'dashboard');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
    if ($isPost) {
        // ações que mudam estado — todas redirecionam
        match ($route) {
            'product.status'   => Actions::productStatus($app),
            'collect.run'      => Actions::collectRun($app),
            'radar.save'       => Actions::radarSave($app),
            'radar.toggle'     => Actions::radarToggle($app),
            'radar.delete'     => Actions::radarDelete($app),
            'radar.collect'    => Actions::radarCollect($app),
            'settings.general' => Actions::settingsGeneral($app),
            'settings.shopee'  => Actions::settingsShopee($app),
            'hotscore.save'    => Actions::hotscoreSave($app),
            'hotscore.reset'   => Actions::hotscoreReset($app),
            'report.ai'        => Actions::reportAi($app),
            default            => Actions::notFound(),
        };
        exit;
    }

    match ($route) {
        'dashboard'  => Screens::dashboard($app),
        'products'   => Screens::products($app),
        'product'    => Screens::product($app),
        'radars'     => Screens::radars($app),
        'radar.edit' => Screens::radarEdit($app),
        'reports'    => Screens::reports($app),
        'config'     => Screens::config($app),
        default      => Screens::dashboard($app),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    $msg = ($config['env'] ?? 'local') === 'local'
        ? $e->getMessage() . "\n\n" . $e->getTraceAsString()
        : 'Erro interno.';
    echo '<pre>' . View::e($msg) . '</pre>';
}
