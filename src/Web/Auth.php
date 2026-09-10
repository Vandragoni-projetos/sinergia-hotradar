<?php
declare(strict_types=1);

namespace HotRadar\Web;

/**
 * Autenticação do painel.
 *
 * Ordem de verificação:
 *   1) sessão iniciada por login no formulário;
 *   2) HTTP Basic (compatibilidade com o acesso atual em produção — NÃO removido).
 *
 * Credenciais SÓ por Environment. Nunca hardcoded, nunca no Git, nunca exibidas.
 *   HR_PANEL_USER           usuário
 *   HR_PANEL_PASSWORD_HASH   hash bcrypt (preferido)   -> gere com: php -r "echo password_hash('senha', PASSWORD_BCRYPT);"
 *   HR_PANEL_PASSWORD        senha em texto (fallback de compatibilidade)
 *
 * Se nenhuma senha/hash estiver definida, a autenticação fica DESLIGADA (uso local).
 */
final class Auth
{
    private const SESS_KEY = 'hr_auth_user';

    /** @param array<string,mixed> $panelConfig */
    public static function boot(array $panelConfig): void
    {
        $GLOBALS['hr_panel_cfg'] = $panelConfig;
        if (session_status() === PHP_SESSION_NONE) {
            $https = (($_SERVER['HTTPS'] ?? '') === 'on')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('HOTRADAR_SESS');
            @session_start();
        }
    }

    public static function required(): bool
    {
        $cfg = $GLOBALS['hr_panel_cfg'] ?? [];
        return trim((string) ($cfg['password'] ?? '')) !== ''
            || trim((string) ($cfg['password_hash'] ?? '')) !== '';
    }

    public static function check(): bool
    {
        if (!self::required()) {
            return true;
        }
        if (!empty($_SESSION[self::SESS_KEY])) {
            return true;
        }
        // HTTP Basic (fallback de compatibilidade)
        $u = $_SERVER['PHP_AUTH_USER'] ?? '';
        $p = $_SERVER['PHP_AUTH_PW'] ?? '';
        if ($u !== '' && self::credentialsOk($u, $p)) {
            return true;
        }
        return false;
    }

    public static function currentUser(): ?string
    {
        return $_SESSION[self::SESS_KEY] ?? ($_SERVER['PHP_AUTH_USER'] ?? null);
    }

    public static function attempt(string $user, string $pass, bool $remember): bool
    {
        if (!self::credentialsOk($user, $pass)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION[self::SESS_KEY] = $user;
        $_SESSION['hr_auth_at'] = time();
        if ($remember) {
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => time() + 60 * 60 * 24 * 30,
                'path' => '/',
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
    }

    // ------------------------------------------------------------------- CSRF

    public static function csrfToken(): string
    {
        if (empty($_SESSION['hr_csrf'])) {
            $_SESSION['hr_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['hr_csrf'];
    }

    public static function csrfValid(?string $token): bool
    {
        return is_string($token) && $token !== '' && !empty($_SESSION['hr_csrf'])
            && hash_equals((string) $_SESSION['hr_csrf'], $token);
    }

    // --------------------------------------------------------------- internos

    private static function credentialsOk(string $user, string $pass): bool
    {
        $cfg = $GLOBALS['hr_panel_cfg'] ?? [];
        $expectedUser = (string) ($cfg['user'] ?? 'admin');
        if (!hash_equals($expectedUser, $user)) {
            return false;
        }
        $hash = trim((string) ($cfg['password_hash'] ?? ''));
        if ($hash !== '') {
            return password_verify($pass, $hash);
        }
        $plain = (string) ($cfg['password'] ?? '');
        return $plain !== '' && hash_equals($plain, $pass);
    }
}
