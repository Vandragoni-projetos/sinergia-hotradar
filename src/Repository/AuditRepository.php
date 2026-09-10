<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;

/**
 * Audit log de mudanças de configuração (Hot Score, radares, settings gerais).
 */
final class AuditRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function log(
        string $area,
        string $action,
        ?string $ref,
        ?array $before,
        ?array $after,
        string $actor = 'humano'
    ): void {
        $this->db->run(
            'INSERT INTO hr_audit_log (area, action, ref, before_json, after_json, actor, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [
                $area,
                $action,
                $ref,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                $actor,
                $this->db->now(),
            ]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(?string $area = null, int $limit = 50): array
    {
        if ($area !== null) {
            return $this->db->all(
                "SELECT * FROM hr_audit_log WHERE area = ? ORDER BY created_at DESC, id DESC LIMIT $limit",
                [$area]
            );
        }
        return $this->db->all("SELECT * FROM hr_audit_log ORDER BY created_at DESC, id DESC LIMIT $limit");
    }
}
