<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;

final class EditorialRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function log(int $productId, ?string $from, string $to, ?string $reason, string $actor = 'humano'): void
    {
        $this->db->run(
            'INSERT INTO hr_editorial_events (product_id, from_status, to_status, reason, actor, created_at)
             VALUES (?,?,?,?,?,?)',
            [$productId, $from, $to, $reason, $actor, $this->db->now()]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function timeline(int $productId): array
    {
        return $this->db->all(
            'SELECT * FROM hr_editorial_events WHERE product_id = ? ORDER BY created_at ASC',
            [$productId]
        );
    }
}
