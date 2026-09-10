<?php
declare(strict_types=1);

namespace HotRadar\Report;

use HotRadar\Db\Connection;

/**
 * Todos os relatórios. 100% derivado do banco (hr_products, hr_product_snapshots,
 * hr_collection_runs). Comparações históricas usam hr_product_snapshots.
 * Sem OpenAI aqui — a IA só entra em AiReportService, sobre a saída daqui.
 *
 * Convenção: um campo ausente vem como null (nunca 0 fabricado).
 */
final class ReportService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string,mixed> $f filtros: radar, marketplace
     * @param string $alias alias da tabela hr_products na query (para a chave primária no EXISTS)
     */
    private function scope(array $f, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $idCol = ($alias !== '' ? $alias . '.' : 'hr_products.') . 'id';
        $cond = [];
        $params = [];
        if (!empty($f['radar'])) {
            // M2M: produto associado ao radar em hr_product_radars
            $cond[] = "EXISTS (SELECT 1 FROM hr_product_radars pr
                               JOIN hr_radars r ON r.id = pr.radar_id
                               WHERE pr.product_id = {$idCol} AND r.slug = ?)";
            $params[] = $f['radar'];
        }
        if (!empty($f['marketplace'])) {
            $cond[] = "{$p}marketplace = ?";
            $params[] = $f['marketplace'];
        }
        return [$cond ? ' AND ' . implode(' AND ', $cond) : '', $params];
    }

    // ---------------------------------------------------------------- resumos

    /** @return array<string,mixed> */
    public function lastRun(): array
    {
        $run = $this->db->first('SELECT * FROM hr_collection_runs ORDER BY started_at DESC, id DESC LIMIT 1');
        if ($run === null) {
            return ['exists' => false];
        }
        $errors = json_decode((string) ($run['errors'] ?? '[]'), true) ?: [];
        return [
            'exists' => true,
            'run' => $run,
            'errors' => is_array($errors) ? $errors : [],
        ];
    }

    /**
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    public function daily(string $date, array $f = []): array
    {
        [$sc, $scp] = $this->scope($f);
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $novos = (int) ($this->db->first(
            "SELECT COUNT(*) n FROM hr_products WHERE discovered_at BETWEEN ? AND ?$sc",
            array_merge([$start, $end], $scp)
        )['n'] ?? 0);

        $coletas = $this->db->all(
            'SELECT * FROM hr_collection_runs WHERE started_at BETWEEN ? AND ? ORDER BY started_at',
            [$start, $end]
        );
        $snaps = (int) ($this->db->first(
            'SELECT COUNT(*) n FROM hr_product_snapshots WHERE collected_at BETWEEN ? AND ?',
            [$start, $end]
        )['n'] ?? 0);

        return [
            'date' => $date,
            'novos_produtos' => $novos,
            'coletas' => $coletas,
            'snapshots' => $snaps,
            'faixa_hoje' => $this->faixaDistribution(array_merge($f, ['since' => $start, 'until' => $end])),
        ];
    }

    // -------------------------------------------------------------- listagens

    /** @return array<int,array<string,mixed>> */
    public function newProducts(string $since, array $f = [], int $limit = 100): array
    {
        [$sc, $scp] = $this->scope($f);
        return $this->db->all(
            "SELECT * FROM hr_products WHERE discovered_at >= ?$sc ORDER BY discovered_at DESC LIMIT $limit",
            array_merge([$since], $scp)
        );
    }

    /** Produtos vistos em mais de uma coleta (têm ≥ 2 snapshots). @return array<int,array<string,mixed>> */
    public function updatedProducts(array $f = [], int $limit = 100): array
    {
        [$sc, $scp] = $this->scope($f, 'p');
        return $this->db->all(
            "SELECT p.*, (SELECT COUNT(*) FROM hr_product_snapshots s WHERE s.product_id = p.id) AS snap_count
             FROM hr_products p
             WHERE (SELECT COUNT(*) FROM hr_product_snapshots s WHERE s.product_id = p.id) >= 2 $sc
             ORDER BY p.last_collected_at DESC LIMIT $limit",
            $scp
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function topHotScores(array $f = [], int $limit = 30): array
    {
        [$sc, $scp] = $this->scope($f);
        return $this->db->all(
            "SELECT * FROM hr_products WHERE hot_score IS NOT NULL$sc ORDER BY hot_score DESC, discovered_at DESC LIMIT $limit",
            $scp
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function withVideo(array $f = [], int $limit = 100): array
    {
        [$sc, $scp] = $this->scope($f);
        return $this->db->all(
            "SELECT * FROM hr_products WHERE has_video = 1$sc ORDER BY hot_score DESC LIMIT $limit",
            $scp
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function byStatus(string $status, array $f = [], int $limit = 200): array
    {
        [$sc, $scp] = $this->scope($f);
        return $this->db->all(
            "SELECT * FROM hr_products WHERE status = ?$sc ORDER BY hot_score DESC LIMIT $limit",
            array_merge([$status], $scp)
        );
    }

    // ----------------------------------------------- comparações históricas

    /**
     * Compara o snapshot mais recente com o anterior de cada produto.
     * @return array{ up: array<int,array<string,mixed>>, down: array<int,array<string,mixed>> }
     */
    public function scoreMovers(array $f = [], int $limit = 25): array
    {
        $rows = $this->twoLatestSnapshotsPerProduct($f);
        $up = [];
        $down = [];
        foreach ($rows as $r) {
            if ($r['prev_hot'] === null || $r['last_hot'] === null) {
                continue;
            }
            $delta = (int) $r['last_hot'] - (int) $r['prev_hot'];
            if ($delta === 0) {
                continue;
            }
            $entry = $r + ['delta' => $delta];
            if ($delta > 0) {
                $up[] = $entry;
            } else {
                $down[] = $entry;
            }
        }
        usort($up, static fn ($a, $b) => $b['delta'] <=> $a['delta']);
        usort($down, static fn ($a, $b) => $a['delta'] <=> $b['delta']);
        return ['up' => array_slice($up, 0, $limit), 'down' => array_slice($down, 0, $limit)];
    }

    /**
     * Maiores quedas de preço (anterior → atual).
     * @return array<int,array<string,mixed>>
     */
    public function priceDrops(array $f = [], int $limit = 25): array
    {
        $rows = $this->twoLatestSnapshotsPerProduct($f);
        $drops = [];
        foreach ($rows as $r) {
            if ($r['prev_price'] === null || $r['last_price'] === null) {
                continue;
            }
            $prev = (float) $r['prev_price'];
            $last = (float) $r['last_price'];
            if ($prev <= 0 || $last >= $prev) {
                continue;
            }
            $drops[] = $r + [
                'drop_abs' => round($prev - $last, 2),
                'drop_pct' => (int) round(($prev - $last) / $prev * 100),
            ];
        }
        usort($drops, static fn ($a, $b) => $b['drop_pct'] <=> $a['drop_pct']);
        return array_slice($drops, 0, $limit);
    }

    /**
     * Para cada produto com ≥ 2 snapshots: valores do último e do penúltimo.
     * @return array<int,array<string,mixed>>
     */
    private function twoLatestSnapshotsPerProduct(array $f): array
    {
        [$sc, $scp] = $this->scope($f, 'p');
        $products = $this->db->all(
            "SELECT p.id, p.title, p.marketplace, p.radar_slug, p.url_original,
                    p.price_current, p.hot_score, p.hot_faixa
             FROM hr_products p
             WHERE (SELECT COUNT(*) FROM hr_product_snapshots s WHERE s.product_id = p.id) >= 2 $sc",
            $scp
        );
        $out = [];
        foreach ($products as $p) {
            $snaps = $this->db->all(
                'SELECT hot_score, price_current, collected_at
                 FROM hr_product_snapshots WHERE product_id = ?
                 ORDER BY collected_at DESC, id DESC LIMIT 2',
                [$p['id']]
            );
            if (count($snaps) < 2) {
                continue;
            }
            $out[] = $p + [
                'last_hot' => $snaps[0]['hot_score'],
                'prev_hot' => $snaps[1]['hot_score'],
                'last_price' => $snaps[0]['price_current'],
                'prev_price' => $snaps[1]['price_current'],
                'last_at' => $snaps[0]['collected_at'],
                'prev_at' => $snaps[1]['collected_at'],
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------- distribuições

    /** @return array<string,int> */
    public function faixaDistribution(array $f = []): array
    {
        $cond = [];
        $params = [];
        if (!empty($f['radar'])) {
            $cond[] = 'EXISTS (SELECT 1 FROM hr_product_radars pr
                               JOIN hr_radars r ON r.id = pr.radar_id
                               WHERE pr.product_id = hr_products.id AND r.slug = ?)';
            $params[] = $f['radar'];
        }
        if (!empty($f['marketplace'])) {
            $cond[] = 'marketplace = ?';
            $params[] = $f['marketplace'];
        }
        if (!empty($f['since'])) {
            $cond[] = 'discovered_at >= ?';
            $params[] = $f['since'];
        }
        if (!empty($f['until'])) {
            $cond[] = 'discovered_at <= ?';
            $params[] = $f['until'];
        }
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        $out = ['muito_quente' => 0, 'bom' => 0, 'analisar' => 0, 'baixo' => 0];
        foreach ($this->db->all("SELECT hot_faixa, COUNT(*) n FROM hr_products $where GROUP BY hot_faixa", $params) as $r) {
            if (($r['hot_faixa'] ?? '') !== '') {
                $out[(string) $r['hot_faixa']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** @return array<int,array{key:string,n:int}> */
    public function byCategory(array $f = []): array
    {
        [$sc, $scp] = $this->scope($f);
        $rows = $this->db->all(
            "SELECT COALESCE(category, '(sem nicho)') k, COUNT(*) n
             FROM hr_products WHERE 1=1$sc GROUP BY category ORDER BY n DESC",
            $scp
        );
        return array_map(static fn ($r) => ['key' => (string) $r['k'], 'n' => (int) $r['n']], $rows);
    }

    /**
     * Distribuição por radar via M2M (um produto pode contar em vários radares)
     * + linha "(sem radar)" para produtos sem nenhuma associação.
     * @return array<int,array{key:string,n:int}>
     */
    public function byRadar(): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT r.slug k, COUNT(DISTINCT pr.product_id) n
             FROM hr_product_radars pr JOIN hr_radars r ON r.id = pr.radar_id
             GROUP BY r.slug ORDER BY n DESC"
        ) as $r) {
            $out[] = ['key' => (string) $r['k'], 'n' => (int) $r['n']];
        }
        $orphans = (int) ($this->db->first(
            'SELECT COUNT(*) n FROM hr_products p
             WHERE NOT EXISTS (SELECT 1 FROM hr_product_radars pr WHERE pr.product_id = p.id)'
        )['n'] ?? 0);
        if ($orphans > 0) {
            $out[] = ['key' => '(sem radar)', 'n' => $orphans];
        }
        return $out;
    }

    /** @return array<string,int> */
    public function statusDistribution(array $f = []): array
    {
        [$sc, $scp] = $this->scope($f);
        $out = [];
        foreach ($this->db->all("SELECT status, COUNT(*) n FROM hr_products WHERE 1=1$sc GROUP BY status", $scp) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    // -------------------------------------------------------- coletas / erros

    /** @return array<int,array<string,mixed>> */
    public function runHistory(int $limit = 50): array
    {
        return $this->db->all("SELECT * FROM hr_collection_runs ORDER BY started_at DESC LIMIT $limit");
    }

    /** @return array<int,array{run_id:int,when:string,marketplace:string,radar:?string,error:string}> */
    public function collectionErrors(int $limit = 50): array
    {
        $out = [];
        foreach ($this->db->all("SELECT * FROM hr_collection_runs WHERE errors IS NOT NULL ORDER BY started_at DESC LIMIT $limit") as $run) {
            $errs = json_decode((string) $run['errors'], true) ?: [];
            foreach ((array) $errs as $e) {
                $out[] = [
                    'run_id' => (int) $run['id'],
                    'when' => (string) $run['started_at'],
                    'marketplace' => (string) $run['marketplace'],
                    'radar' => $run['radar_slug'] !== null ? (string) $run['radar_slug'] : null,
                    'error' => (string) $e,
                ];
            }
        }
        return $out;
    }
}
