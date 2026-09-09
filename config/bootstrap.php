<?php
declare(strict_types=1);

/**
 * Bootstrap único do HOTRADAR. Carregado pela CLI (bin/hr.php) e pelo painel (public/index.php).
 * NÃO toca em nada do Achadinhos.
 */

define('HR_ROOT', dirname(__DIR__));

require HR_ROOT . '/src/Support/Autoloader.php';
\HotRadar\Support\Autoloader::register(HR_ROOT . '/src');

\HotRadar\Support\Env::load(HR_ROOT);

/** @var array<string,mixed> $config */
$config = require HR_ROOT . '/config/config.php';

date_default_timezone_set((string) $config['timezone']);

mb_internal_encoding('UTF-8');

if (($config['env'] ?? 'local') === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

@mkdir(HR_ROOT . '/storage/logs', 0775, true);
@mkdir(HR_ROOT . '/storage/collect', 0775, true);

$GLOBALS['hr_config'] = $config;

/**
 * @return array<string,mixed>
 */
function hr_config(): array
{
    return $GLOBALS['hr_config'];
}

function hr_db(): \HotRadar\Db\Connection
{
    static $conn = null;
    if ($conn === null) {
        $conn = new \HotRadar\Db\Connection(hr_config()['db']);
    }
    return $conn;
}
