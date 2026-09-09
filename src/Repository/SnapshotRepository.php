<?php
declare(strict_types=1);

namespace HotRadar\Repository;

use HotRadar\Db\Connection;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Score\ScoreBreakdown;

/**
 * Histórico de coletas. Cada execução que "vê" um produto grava um snapshot dos
 * sinais voláteis (preço, desconto, vendas, nota, rank, hot_score). É isto que
 * permitirá detectar TENDÊNCIA depois (E6). Nunca sobrescreve — só adiciona.
 */
final class SnapshotRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function record(int $productId, ?int $runId, NormalizedProduct $p, ScoreBreakdown $score): void
    {
        $this->db->run(
            'INSERT INTO hr_product_snapshots
                (product_id, run_id, collected_at, price_current, price_previous, discount_pct,
                 sales_signal, sales_exact, rating, rating_count, rank_position, hot_score, hot_faixa, present, raw)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)',
            [
                $productId,
                $runId,
                $this->db->now(),
                $p->priceCurrent,
                $p->pricePrevious,
                $p->discountPct,
                $p->salesSignal,
                $p->salesExact,
                $p->rating,
                $p->ratingCount,
                $p->rankPosition,
                $score->total,
                $score->faixaKey,
                json_encode($p->toRow(), JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $productId): array
    {
        return $this->db->all(
            'SELECT * FROM hr_product_snapshots WHERE product_id = ? ORDER BY collected_at ASC',
            [$productId]
        );
    }

    public function countForProduct(int $productId): int
    {
        $row = $this->db->first('SELECT COUNT(*) AS n FROM hr_product_snapshots WHERE product_id = ?', [$productId]);
        return (int) ($row['n'] ?? 0);
    }
}
