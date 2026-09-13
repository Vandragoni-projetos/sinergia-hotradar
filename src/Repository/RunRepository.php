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

    /**
     * @return array<int,array<string,mixed>> cada linha traz também radar_existe (1/0):
     *         1 = o radar_id do run corresponde a um radar que ainda existe, OU o run
     *         nunca teve radar (radar_id NULL); 0 = radar_id aponta para um radar já
     *         excluído. Comparação sempre por radar_id (nunca por radar_slug — um slug
     *         pode ser reaproveitado por um radar novo depois que o antigo é excluído).
     */
    public function recent(int $limit = 20): array
    {
        return $this->db->all(
            "SELECT cr.*, CASE WHEN cr.radar_id IS NULL OR r.id IS NOT NULL THEN 1 ELSE 0 END AS radar_existe
             FROM hr_collection_runs cr
             LEFT JOIN hr_radars r ON r.id = cr.radar_id
             ORDER BY cr.started_at DESC LIMIT $limit"
        );
    }

    /**
     * Total REAL de coletas registradas (COUNT(*), sem limite), radar existindo ou não.
     * Não confundir com count(recent($n)) — que satura em $n e não é um total de verdade.
     * Preservado como histórico bruto (ex.: "Histórico total"), NÃO é mais o que o card
     * principal "Coletas registradas" do dashboard usa — ver countActive().
     */
    public function countAll(): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'] ?? 0);
    }

    /**
     * Total de coletas "operacionais": runs de radares que ainda existem hoje, mais
     * runs que nunca tiveram radar associado (radar_id NULL — formato legado/avulso,
     * nunca foi invalidado por nenhuma exclusão). Exclui só runs cujo radar_id aponta
     * para um radar já excluído. É o que alimenta o card "Coletas registradas" do
     * dashboard — não infla o número com histórico de radares que não existem mais.
     * Comparação sempre por radar_id (nunca por radar_slug).
     */
    public function countActive(): int
    {
        return (int) ($this->db->first(
            'SELECT COUNT(*) n FROM hr_collection_runs
             WHERE radar_id IS NULL OR radar_id IN (SELECT id FROM hr_radars)'
        )['n'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM hr_collection_runs WHERE id = ?', [$id]);
    }
}
