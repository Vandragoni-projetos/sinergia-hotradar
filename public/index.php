<?php
declare(strict_types=1);

/**
 * SINERGIA HOTRADAR — painel. Front controller único, rotas por ?r=.
 */

require dirname(__DIR__) . '/config/bootstrap.php';

use HotRadar\App;
use HotRadar\Support\BootFailedException;
use HotRadar\Web\Actions;
use HotRadar\Web\Auth;
use HotRadar\Web\Screens;
use HotRadar\Web\View;

$config = hr_config();

// Fail-fast (E2/E4, auditoria 2026-09-11): se a config de banco for inválida
// para o ambiente, ou a conexão real falhar, a aplicação PARA AQUI — nunca
// cria SQLite, nunca roda migration, nunca segue para rotas/telas. O detalhe
// (sem senha) já foi para o error_log dentro de App::bootOrFail().
try {
    $app = App::bootOrFail($config, 'web');
} catch (BootFailedException) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Serviço indisponível: falha de configuração ou conexão com o banco.\n";
    echo "Detalhes registrados no log do servidor. Nenhuma ação foi tomada.\n";
    exit;
}

Auth::boot($config['panel']);

$route = (string) ($_GET['r'] ?? 'dashboard');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ---- login / logout (não exigem sessão) ----
if ($route === 'login' && $isPost) {
    Actions::login($app);
    exit;
}
if ($route === 'logout') {
    Auth::logout();
    header('Location: ?r=login');
    exit;
}
if ($route === 'login') {
    Screens::login($app);
    exit;
}

// ---- porteiro ----
if (!Auth::check()) {
    // se o navegador mandou Basic inválido, ainda oferecemos o desafio 1x; senão, form.
    header('Location: ?r=login');
    exit;
}

$app->migrator()->migrate();

// ---- respostas de arquivo (CSV) — precisam sair antes de qualquer HTML ----
if (in_array($route, ['export.products', 'report.export'], true)) {
    match ($route) {
        'export.products' => Actions::exportProducts($app),
        'report.export'   => Actions::reportExport($app),
    };
    exit;
}

try {
    if ($isPost) {
        // ações que mudam estado — todas redirecionam
        if (!Auth::csrfValid($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            echo 'Sessão expirada. Recarregue a página e tente de novo.';
            exit;
        }
        match ($route) {
            'product.status'   => Actions::productStatus($app),
            'product.bulk'     => Actions::productBulk($app),
            'collect.run'      => Actions::collectRun($app),
            'radar.save'       => Actions::radarSave($app),
            'radar.toggle'     => Actions::radarToggle($app),
            'radar.delete'     => Actions::radarDelete($app),
            'radar.clear'      => Actions::radarClear($app),
            'radar.collect'    => Actions::radarCollect($app),
            'settings.general' => Actions::settingsGeneral($app),
            'settings.shopee'  => Actions::settingsShopee($app),
            'hotscore.save'    => Actions::hotscoreSave($app),
            'hotscore.reset'   => Actions::hotscoreReset($app),
            'report.ai'        => Actions::reportAi($app),
            'analyze.url'      => Actions::analyzeUrl($app),
            'analyze.save'     => Actions::analyzeSave($app),
            default            => Actions::notFound(),
        };
        exit;
    }

    match ($route) {
        'dashboard'    => Screens::dashboard($app),
        'products'     => Screens::products($app),
        'product'      => Screens::product($app),
        'radars'       => Screens::radars($app),
        'radar.edit'   => Screens::radarEdit($app),
        'radar.delete' => Screens::radarDelete($app),   // tela de impacto (GET)
        'reports'      => Screens::reports($app),
        'config'       => Screens::config($app),
        'analyze'      => Screens::analyze($app),
        'hotscore.compare' => Screens::hotscoreCompare($app),   // diagnóstico V1 x V2 (shadow, só leitura)
        'print.ficha'  => Screens::printFicha($app),
        'print.pack'   => Screens::printPack($app),
        'print.report' => Screens::printReport($app),
        default        => Screens::dashboard($app),
    };
} catch (\Throwable $e) {
    http_response_code(500);
    $msg = ($config['env'] ?? 'local') === 'local'
        ? $e->getMessage() . "\n\n" . $e->getTraceAsString()
        : 'Erro interno.';
    echo '<pre>' . View::e($msg) . '</pre>';
}
