<?php
declare(strict_types=1);

namespace HotRadar\Support;

/**
 * Leitor de .env + variáveis de ambiente. Sem dependências.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $projectRoot): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        foreach (['.env', '.env.local'] as $file) {
            $path = $projectRoot . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $val = trim(substr($line, $pos + 1));
                if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                    $val = substr($val, 1, -1);
                } else {
                    // Achado incidental durante a auditoria de 2026-09-11: valores NÃO citados
                    // podem ter um comentário inline (ex.: "HR_ENV=local   # local | production",
                    // presente tanto no .env.example quanto em .env reais copiados dele). Sem este
                    // corte, `Env::get('HR_ENV', 'local')` nunca era exatamente "local", e
                    // `EnvironmentValidator::isLocal()` (e o antigo `bootstrap.php` display_errors)
                    // tratavam ambiente LOCAL como se fosse produção. Só corta "# ..." quando
                    // precedido de espaço, para não mutilar um valor legítimo que contenha "#".
                    $val = rtrim((string) preg_replace('/\s+#.*$/', '', $val));
                }
                self::$data[$key] = $val;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return self::$data[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default): bool
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
