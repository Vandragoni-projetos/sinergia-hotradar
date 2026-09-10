<?php
declare(strict_types=1);

namespace HotRadar\Radar;

use HotRadar\Db\Connection;
use HotRadar\Repository\AuditRepository;

final class RadarRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuditRepository $audit,
    ) {
    }

    /** @return array<int,Radar> */
    public function all(): array
    {
        return array_map(
            [Radar::class, 'fromRow'],
            $this->db->all('SELECT * FROM hr_radars ORDER BY enabled DESC, name ASC')
        );
    }

    /** @return array<int,Radar> */
    public function enabled(): array
    {
        return array_map(
            [Radar::class, 'fromRow'],
            $this->db->all('SELECT * FROM hr_radars WHERE enabled = 1 ORDER BY name ASC')
        );
    }

    public function find(int $id): ?Radar
    {
        $row = $this->db->first('SELECT * FROM hr_radars WHERE id = ?', [$id]);
        return $row ? Radar::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?Radar
    {
        $row = $this->db->first('SELECT * FROM hr_radars WHERE slug = ?', [$slug]);
        return $row ? Radar::fromRow($row) : null;
    }

    public function create(Radar $radar, string $actor = 'humano:painel'): int
    {
        $radar->slug = $this->uniqueSlug($radar->slug ?: Radar::slugify($radar->name));
        $data = $radar->toRow();
        $now = $this->db->now();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $cols = array_keys($data);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $this->db->run('INSERT INTO hr_radars (' . implode(',', $cols) . ") VALUES ($ph)", array_values($data));
        $id = (int) $this->db->lastInsertId();

        $this->audit->log('radar', 'create', $radar->slug, null, $radar->toRow(), $actor);
        return $id;
    }

    public function update(int $id, Radar $new, string $actor = 'humano:painel'): void
    {
        $before = $this->find($id);
        if ($before === null) {
            return;
        }
        $data = $new->toRow();
        unset($data['slug']); // slug é imutável após criação
        $data['updated_at'] = $this->db->now();

        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
        $params = array_values($data);
        $params[] = $id;
        $this->db->run("UPDATE hr_radars SET $set WHERE id = ?", $params);

        $this->audit->log('radar', 'update', $before->slug, $before->toRow(), $new->toRow(), $actor);
    }

    public function setEnabled(int $id, bool $enabled, string $actor = 'humano:painel'): void
    {
        $r = $this->find($id);
        if ($r === null) {
            return;
        }
        $this->db->run(
            'UPDATE hr_radars SET enabled = ?, updated_at = ? WHERE id = ?',
            [$enabled ? 1 : 0, $this->db->now(), $id]
        );
        $this->audit->log('radar', $enabled ? 'enable' : 'disable', $r->slug, ['enabled' => $r->enabled], ['enabled' => $enabled], $actor);
    }

    public function delete(int $id, string $actor = 'humano:painel'): void
    {
        $r = $this->find($id);
        if ($r === null) {
            return;
        }
        // Não apaga produtos já coletados: eles guardam radar_slug e continuam no histórico.
        $this->db->run('DELETE FROM hr_radars WHERE id = ?', [$id]);
        $this->audit->log('radar', 'delete', $r->slug, $r->toRow(), null, $actor);
    }

    public function count(): int
    {
        return (int) ($this->db->first('SELECT COUNT(*) AS n FROM hr_radars')['n'] ?? 0);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 2;
        while ($this->db->first('SELECT 1 FROM hr_radars WHERE slug = ?', [$slug])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
