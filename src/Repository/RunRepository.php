<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;

final class RunRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function start(
        string $marketplace,
        string $source,
        string $mode,
        ?int $radarId = null,
        ?string $radarSlug = null
    ): int {
        $this->db->run(
            'INSERT INTO hr_collection_runs (marketplace, source, mode, status, started_at, radar_id, radar_slug)
             VALUES (?,?,?,?,?,?,?)',
            [$marketplace, $source, $mode, 'running', $this->db->now(), $radarId, $radarSlug]
        );
        return (int) $this->db->lastInsertId();
    }

    /** @param array<int,string> $errors */
    public function finish(
        int $runId,
        string $status,
        int $pages,
        int $cards,
        int $new,
        int $updated,
        int $snapshots,
        array $errors,
        ?string $notes = null
    ): void {
        $this->db->run(
            'UPDATE hr_collection_runs SET status=?, finished_at=?, pages_fetched=?, cards_seen=?,
                products_new=?, products_updated=?, snapshots_written=?, errors=?, notes=?
             WHERE id=?',
            [
                $status,
                $this->db->now(),
                $pages,
                $cards,
                $new,
                $updated,
                $snapshots,
                json_encode(array_values($errors), JSON_UNESCAPED_UNICODE),
                $notes,
                $runId,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->db->all("SELECT * FROM hr_collection_runs ORDER BY started_at DESC LIMIT $limit");
    }

    /**
     * Total REAL de coletas registradas (COUNT(*), sem limite). Não confundir
     * com count(recent($n)) — que satura em $n e não é um total de verdade.
     * Usado pelo card "Coletas registradas" do dashboard.
     */
    public function countAll(): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM hr_collection_runs WHERE id = ?', [$id]);
    }
}
