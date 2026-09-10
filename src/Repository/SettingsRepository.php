<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;

/**
 * Chave→JSON em hr_settings. Guarda SÓ configuração NÃO SECRETA
 * (nicho ativo, categorias, páginas, timezone, parâmetros editoriais, pesos do Hot Score).
 * Nenhuma credencial passa por aqui — segredos ficam só no Environment.
 */
final class SettingsRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuditRepository $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    public function get(string $key, array $default = []): array
    {
        try {
            $row = $this->db->first('SELECT svalue FROM hr_settings WHERE skey = ?', [$key]);
        } catch (\Throwable) {
            return $default;
        }
        if (!$row || !is_string($row['svalue']) || $row['svalue'] === '') {
            return $default;
        }
        $decoded = json_decode($row['svalue'], true);
        return is_array($decoded) ? $decoded : $default;
    }

    public function has(string $key): bool
    {
        try {
            return (bool) $this->db->first('SELECT 1 FROM hr_settings WHERE skey = ?', [$key]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $value
     */
    public function set(string $key, array $value, string $actor = 'humano:painel'): void
    {
        $before = $this->has($key) ? $this->get($key) : null;
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $now = $this->db->now();

        if ($before === null) {
            $this->db->run('INSERT INTO hr_settings (skey, svalue, updated_at) VALUES (?,?,?)', [$key, $json, $now]);
        } else {
            $this->db->run('UPDATE hr_settings SET svalue = ?, updated_at = ? WHERE skey = ?', [$json, $now, $key]);
        }
        $this->audit->log('settings', $before === null ? 'create' : 'update', $key, $before, $value, $actor);
    }
}
