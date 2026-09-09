<?php
declare(strict_types=1);

namespace HotRadar\Support;

/**
 * Autoloader PSR-4 mínimo (sem Composer). Namespace raiz "HotRadar\" => src/.
 */
final class Autoloader
{
    public static function register(string $srcDir): void
    {
        spl_autoload_register(static function (string $class) use ($srcDir): void {
            $prefix = 'HotRadar\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $srcDir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
}
