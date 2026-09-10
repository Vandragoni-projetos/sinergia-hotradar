<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Score\ScoreBreakdown;

final class ProductRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByMarketplaceId(string $marketplace, string $marketplaceProductId): ?array
    {
        return $this->db->first(
            'SELECT * FROM hr_products WHERE marketplace = ? AND marketplace_product_id = ?',
            [$marketplace, strtoupper($marketplaceProductId)]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM hr_products WHERE id = ?', [$id]);
    }

    /**
     * Insere ou atualiza o estado ATUAL do produto (dedup por marketplace+id).
     * @return array{id:int, is_new:bool}
     */
    public function upsert(NormalizedProduct $p, ScoreBreakdown $score): array
    {
        $now = $this->db->now();
        $existing = $this->findByMarketplaceId($p->marketplace, $p->marketplaceProductId);
        $row = $p->toRow();

        $scoreCols = [
            'hot_score' => $score->total,
            'hot_faixa' => $score->faixaKey,
            'hot_score_breakdown' => json_encode($score->toArray(), JSON_UNESCAPED_UNICODE),
            'hot_score_version' => $score->version,
        ];

        if ($existing === null) {
            $data = $row + $scoreCols + [
                'status' => 'descoberto',
                'discovered_at' => $now,
                'last_collected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $cols = array_keys($data);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $this->db->run(
                'INSERT INTO hr_products (' . implode(',', $cols) . ") VALUES ($ph)",
                array_values($data)
            );
            return ['id' => (int) $this->db->lastInsertId(), 'is_new' => true];
        }

        // Atualiza só o estado corrente. NÃO mexe em status/discovered_at/discard_reason
        // nem na atribuição de radar (produto pertence ao radar que o DESCOBRIU primeiro).
        $data = $row + $scoreCols + ['last_collected_at' => $now, 'updated_at' => $now];
        unset(
            $data['marketplace'],
            $data['marketplace_product_id'],
            $data['radar_id'],
            $data['radar_slug']
        );
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($data)));
        $params = array_values($data);
        $params[] = (int) $existing['id'];
        $this->db->run("UPDATE hr_products SET $set WHERE id = ?", $params);

        return ['id' => (int) $existing['id'], 'is_new' => false];
    }

    public function setStatus(int $id, string $status, ?string $reason, string $actor = 'humano'): void
    {
        $this->db->run(
            'UPDATE hr_products SET status = ?, discard_reason = ?, updated_at = ? WHERE id = ?',
            [$status, $reason, $this->db->now(), $id]
        );
    }

    /**
     * Listagem para o painel de curadoria com filtros.
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $order = 'ORDER BY (hot_score IS NULL) ASC, hot_score DESC, discovered_at DESC';
        $limit = (int) ($filters['limit'] ?? 200);
        return $this->db->all("SELECT * FROM hr_products $where $order LIMIT $limit", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countBy(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $row = $this->db->first("SELECT COUNT(*) AS n FROM hr_products $where", $params);
        return (int) ($row['n'] ?? 0);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $cond = [];
        $params = [];

        if (!empty($filters['marketplace'])) {
            $cond[] = 'marketplace = ?';
            $params[] = $filters['marketplace'];
        }
        if (!empty($filters['radar'])) {
            // relação MUITOS-PARA-MUITOS: o produto aparece se associado ao radar por hr_product_radars
            $cond[] = 'EXISTS (SELECT 1 FROM hr_product_radars pr
                               JOIN hr_radars r ON r.id = pr.radar_id
                               WHERE pr.product_id = hr_products.id AND r.slug = ?)';
            $params[] = $filters['radar'];
        }
        if (!empty($filters['faixa'])) {
            $cond[] = 'hot_faixa = ?';
            $params[] = $filters['faixa'];
        }
        if (!empty($filters['status'])) {
            $cond[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $cond[] = 'category = ?';
            $params[] = $filters['category'];
        }
        if (isset($filters['has_video']) && $filters['has_video'] !== '') {
            $cond[] = 'has_video = ?';
            $params[] = ((int) $filters['has_video']) ? 1 : 0;
        }
        if (!empty($filters['min_discount'])) {
            $cond[] = 'discount_pct >= ?';
            $params[] = (int) $filters['min_discount'];
        }
        if (!empty($filters['min_rating'])) {
            $cond[] = 'rating >= ?';
            $params[] = (float) $filters['min_rating'];
        }
        if (!empty($filters['discovered_since'])) {
            $cond[] = 'discovered_at >= ?';
            $params[] = $filters['discovered_since'];
        }
        if (!empty($filters['q'])) {
            $cond[] = 'title LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        return [$cond ? 'WHERE ' . implode(' AND ', $cond) : '', $params];
    }

    /** @return array<string,int> distribuição por faixa */
    public function faixaDistribution(): array
    {
        $out = ['muito_quente' => 0, 'bom' => 0, 'analisar' => 0, 'baixo' => 0];
        foreach ($this->db->all('SELECT hot_faixa, COUNT(*) AS n FROM hr_products GROUP BY hot_faixa') as $r) {
            $k = (string) ($r['hot_faixa'] ?? '');
            if ($k !== '') {
                $out[$k] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** @return array<string,int> */
    public function statusDistribution(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT status, COUNT(*) AS n FROM hr_products GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
