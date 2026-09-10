<?php
declare(strict_types=1);

namespace HotRadar\Radar;

use HotRadar\Db\Connection;
use HotRadar\Repository\AuditRepository;

/**
 * Operações de ciclo de vida de um radar com IMPACTO explícito e transação.
 *
 *  - impact()            → relatório de impacto (nada muda)
 *  - deleteRadarOnly()   → Opção A: remove só o radar + suas associações; produtos,
 *                          histórico, snapshots e decisões editoriais preservados.
 *  - deleteWithExclusive()→ Opção B: A + apaga produtos EXCLUSIVOS do radar
 *                          (e seus snapshots/eventos). NUNCA toca produto compartilhado
 *                          (esse só perde a associação).
 *  - clearData()         → mantém o radar; zera os resultados: remove associações do
 *                          radar e apaga os produtos que ficariam exclusivos dele.
 */
final class RadarLifecycleService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuditRepository $audit,
    ) {
    }

    /**
     * @return array{
     *   radar_id:int, radar_name:string, radar_slug:string,
     *   associados:int, exclusivos:int, compartilhados:int,
     *   snapshots_exclusivos:int, aprovados:int, com_decisao:int
     * }
     */
    public function impact(Radar $radar): array
    {
        $rid = (int) $radar->id;

        $associados = (int) $this->one(
            'SELECT COUNT(*) n FROM hr_product_radars WHERE radar_id = ?',
            [$rid]
        );
        // exclusivos = produtos associados A ESTE radar e a NENHUM outro
        $exclusivos = (int) $this->one(
            "SELECT COUNT(*) n FROM hr_product_radars pr
             WHERE pr.radar_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM hr_product_radars o
                   WHERE o.product_id = pr.product_id AND o.radar_id <> ?
               )",
            [$rid, $rid]
        );
        $compartilhados = $associados - $exclusivos;

        $snapshotsExclusivos = (int) $this->one(
            "SELECT COUNT(*) n FROM hr_product_snapshots s
             WHERE s.product_id IN (
                 SELECT pr.product_id FROM hr_product_radars pr
                 WHERE pr.radar_id = ?
                   AND NOT EXISTS (SELECT 1 FROM hr_product_radars o WHERE o.product_id = pr.product_id AND o.radar_id <> ?)
             )",
            [$rid, $rid]
        );

        $aprovados = (int) $this->one(
            "SELECT COUNT(*) n FROM hr_products p
             WHERE p.status = 'aprovado'
               AND EXISTS (SELECT 1 FROM hr_product_radars pr WHERE pr.product_id = p.id AND pr.radar_id = ?)",
            [$rid]
        );
        $comDecisao = (int) $this->one(
            "SELECT COUNT(*) n FROM hr_products p
             WHERE p.status <> 'descoberto'
               AND EXISTS (SELECT 1 FROM hr_product_radars pr WHERE pr.product_id = p.id AND pr.radar_id = ?)",
            [$rid]
        );

        return [
            'radar_id' => $rid,
            'radar_name' => $radar->name,
            'radar_slug' => $radar->slug,
            'associados' => $associados,
            'exclusivos' => $exclusivos,
            'compartilhados' => $compartilhados,
            'snapshots_exclusivos' => $snapshotsExclusivos,
            'aprovados' => $aprovados,
            'com_decisao' => $comDecisao,
        ];
    }

    /** OPÇÃO A — remove só o radar. @return array<string,int> resumo do que foi feito */
    public function deleteRadarOnly(Radar $radar, string $actor = 'humano:painel'): array
    {
        $rid = (int) $radar->id;
        $impact = $this->impact($radar);

        $done = $this->db->transaction(function () use ($rid) {
            $assoc = $this->db->run('DELETE FROM hr_product_radars WHERE radar_id = ?', [$rid])->rowCount();
            $this->db->run('DELETE FROM hr_radars WHERE id = ?', [$rid]);
            return ['associacoes_removidas' => $assoc, 'produtos_removidos' => 0, 'snapshots_removidos' => 0];
        });

        $this->audit->log('radar', 'delete', $radar->slug, $impact, $done + ['modo' => 'somente_radar'], $actor);
        return $done;
    }

    /** OPÇÃO B — radar + produtos EXCLUSIVOS. @return array<string,int> */
    public function deleteWithExclusive(Radar $radar, string $actor = 'humano:painel'): array
    {
        $rid = (int) $radar->id;
        $impact = $this->impact($radar);

        $done = $this->db->transaction(function () use ($rid) {
            // ids exclusivos ANTES de mexer nas associações
            $exclusiveIds = array_map(
                static fn ($r) => (int) $r['product_id'],
                $this->db->all(
                    "SELECT pr.product_id FROM hr_product_radars pr
                     WHERE pr.radar_id = ?
                       AND NOT EXISTS (SELECT 1 FROM hr_product_radars o WHERE o.product_id = pr.product_id AND o.radar_id <> ?)",
                    [$rid, $rid]
                )
            );

            $assoc = $this->db->run('DELETE FROM hr_product_radars WHERE radar_id = ?', [$rid])->rowCount();

            $prod = 0;
            $snap = 0;
            if ($exclusiveIds !== []) {
                $snap = $this->deleteRowsByProductIds('hr_product_snapshots', $exclusiveIds);
                $this->deleteRowsByProductIds('hr_editorial_events', $exclusiveIds);
                $this->deleteRowsByProductIds('hr_product_radars', $exclusiveIds); // defensivo (já removidas)
                $prod = $this->deleteRowsByIds('hr_products', $exclusiveIds);
            }

            $this->db->run('DELETE FROM hr_radars WHERE id = ?', [$rid]);
            return ['associacoes_removidas' => $assoc, 'produtos_removidos' => $prod, 'snapshots_removidos' => $snap];
        });

        $this->audit->log('radar', 'delete', $radar->slug, $impact, $done + ['modo' => 'radar_e_exclusivos'], $actor);
        return $done;
    }

    /** LIMPAR DADOS — mantém o radar, zera resultados. @return array<string,int> */
    public function clearData(Radar $radar, string $actor = 'humano:painel'): array
    {
        $rid = (int) $radar->id;
        $impact = $this->impact($radar);

        $done = $this->db->transaction(function () use ($rid) {
            $exclusiveIds = array_map(
                static fn ($r) => (int) $r['product_id'],
                $this->db->all(
                    "SELECT pr.product_id FROM hr_product_radars pr
                     WHERE pr.radar_id = ?
                       AND NOT EXISTS (SELECT 1 FROM hr_product_radars o WHERE o.product_id = pr.product_id AND o.radar_id <> ?)",
                    [$rid, $rid]
                )
            );
            $assoc = $this->db->run('DELETE FROM hr_product_radars WHERE radar_id = ?', [$rid])->rowCount();

            $prod = 0;
            $snap = 0;
            if ($exclusiveIds !== []) {
                $snap = $this->deleteRowsByProductIds('hr_product_snapshots', $exclusiveIds);
                $this->deleteRowsByProductIds('hr_editorial_events', $exclusiveIds);
                $prod = $this->deleteRowsByIds('hr_products', $exclusiveIds);
            }
            // radar permanece (nome/categorias/keywords/filtros intactos)
            return ['associacoes_removidas' => $assoc, 'produtos_removidos' => $prod, 'snapshots_removidos' => $snap];
        });

        $this->audit->log('radar', 'clear_data', $radar->slug, $impact, $done, $actor);
        return $done;
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string|int,mixed> $params */
    private function one(string $sql, array $params): mixed
    {
        $row = $this->db->first($sql, $params);
        return $row === null ? 0 : reset($row);
    }

    /** @param array<int,int> $ids */
    private function deleteRowsByIds(string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->run("DELETE FROM $table WHERE id IN ($ph)", $ids)->rowCount();
    }

    /** @param array<int,int> $ids */
    private function deleteRowsByProductIds(string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->run("DELETE FROM $table WHERE product_id IN ($ph)", $ids)->rowCount();
    }
}
