<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;

/**
 * Relação MUITOS-PARA-MUITOS produto ↔ radar (hr_product_radars).
 *
 * Um produto associado a 3 radares aparece ao filtrar por qualquer um dos 3.
 * Nunca duplica produto; nunca apaga associação de outro radar; não toca em
 * status editorial nem em histórico.
 */
final class ProductRadarRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Cria ou atualiza a associação produto↔radar (UNIQUE product_id+radar_id).
     * Retorna true se a associação é NOVA.
     */
    public function link(int $productId, int $radarId, ?string $seenAt = null): bool
    {
        $now = $seenAt ?? $this->db->now();
        $existing = $this->db->first(
            'SELECT id FROM hr_product_radars WHERE product_id = ? AND radar_id = ?',
            [$productId, $radarId]
        );
        if ($existing !== null) {
            $this->db->run(
                'UPDATE hr_product_radars SET last_seen_at = ?, present = 1 WHERE id = ?',
                [$now, (int) $existing['id']]
            );
            return false;
        }
        $this->db->run(
            'INSERT INTO hr_product_radars (product_id, radar_id, first_seen_at, last_seen_at, present)
             VALUES (?,?,?,?,1)',
            [$productId, $radarId, $now, $now]
        );
        return true;
    }

    /** @return array<int,int> radar_ids do produto */
    public function radarIdsForProduct(int $productId): array
    {
        return array_map(
            static fn ($r) => (int) $r['radar_id'],
            $this->db->all('SELECT radar_id FROM hr_product_radars WHERE product_id = ?', [$productId])
        );
    }

    /** @return array<int,array{slug:string,name:string,radar_id:int,first_seen_at:string,last_seen_at:string}> */
    public function radarsForProduct(int $productId): array
    {
        return $this->db->all(
            'SELECT r.slug, r.name, pr.radar_id, pr.first_seen_at, pr.last_seen_at
             FROM hr_product_radars pr
             JOIN hr_radars r ON r.id = pr.radar_id
             WHERE pr.product_id = ?
             ORDER BY pr.first_seen_at ASC',
            [$productId]
        );
    }

    /**
     * Slugs de radar por produto, em lote (para a listagem da Curadoria).
     * @param array<int,int> $productIds
     * @return array<int,array<int,string>> product_id => [slug, ...]
     */
    public function slugsByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->db->all(
            "SELECT pr.product_id, r.slug
             FROM hr_product_radars pr
             JOIN hr_radars r ON r.id = pr.radar_id
             WHERE pr.product_id IN ($ph)
             ORDER BY r.name",
            $productIds
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']][] = (string) $r['slug'];
        }
        return $out;
    }

    public function countForRadar(int $radarId): int
    {
        return (int) ($this->db->first(
            'SELECT COUNT(*) n FROM hr_product_radars WHERE radar_id = ?',
            [$radarId]
        )['n'] ?? 0);
    }

    /** @return array<string,int> slug => nº de produtos associados */
    public function countsBySlug(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT r.slug, COUNT(DISTINCT pr.product_id) n
             FROM hr_product_radars pr JOIN hr_radars r ON r.id = pr.radar_id
             GROUP BY r.slug'
        ) as $r) {
            $out[(string) $r['slug']] = (int) $r['n'];
        }
        return $out;
    }

    /** Produtos sem NENHUMA associação de radar (anteriores aos radares). */
    public function orphanCount(): int
    {
        return (int) ($this->db->first(
            'SELECT COUNT(*) n FROM hr_products p
             WHERE NOT EXISTS (SELECT 1 FROM hr_product_radars pr WHERE pr.product_id = p.id)'
        )['n'] ?? 0);
    }
}
