<?php
declare(strict_types=1);

namespace HotRadar\Discovery;

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Repository\ProductRadarRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Model\ProductHydrator;

/**
 * Orquestra: collector -> (merge de fallback) -> HOT SCORE -> persistência
 * (dedup + associação M2M produto↔radar + snapshot + log de run).
 * O collector NÃO conhece o banco; o banco NÃO conhece o marketplace.
 */
final class DiscoveryService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SnapshotRepository $snapshots,
        private readonly RunRepository $runs,
        private readonly HotScore $hotScore,
        private readonly ProductRadarRepository $productRadars,
    ) {
    }

    /**
     * @return array{
     *   run_id:?int, marketplace:string, source:string, mode:string, radar:?string,
     *   radar_name:?string, available:bool, unavailable_reason:?string, pages:int, cards:int,
     *   filtered:int, collected:int, new:int, updated:int, snapshots:int,
     *   assoc_new:int, gaps_filled:int, errors:array<int,string>,
     *   score_distribution:array<string,int>, preview:array<int,array<string,mixed>>
     * }
     */
    public function run(CollectorInterface $collector, CollectorContext $ctx): array
    {
        $mode = $ctx->dryRun ? 'dry_run' : 'live';
        $radar = $ctx->radar;
        $base = [
            'run_id' => null,
            'marketplace' => $collector->marketplace(),
            'source' => $collector->source(),
            'mode' => $mode,
            'radar' => $radar?->slug,
            'radar_name' => $radar?->name,
            'available' => $collector->isAvailable(),
            'unavailable_reason' => $collector->unavailableReason(),
            'pages' => 0, 'cards' => 0, 'filtered' => 0, 'collected' => 0,
            'new' => 0, 'updated' => 0, 'snapshots' => 0, 'assoc_new' => 0, 'gaps_filled' => 0,
            'errors' => [],
            'score_distribution' => ['muito_quente' => 0, 'bom' => 0, 'analisar' => 0, 'baixo' => 0],
            'preview' => [],
        ];

        if (!$collector->isAvailable()) {
            $base['errors'][] = (string) $collector->unavailableReason();
            return $base;
        }

        $runId = $this->runs->start(
            $collector->marketplace(),
            $collector->source(),
            $mode,
            $radar?->id,
            $radar?->slug
        );
        $base['run_id'] = $runId;

        $report = $collector->collect($ctx);
        $base['pages'] = $report->pagesFetched;
        $base['cards'] = $report->cardsSeen;
        $base['filtered'] = $report->filteredByRadar;
        $base['errors'] = $report->errors;
        $base['collected'] = count($report->products);

        $new = 0;
        $updated = 0;
        $snaps = 0;
        $assocNew = 0;
        $gapsFilled = 0;

        foreach ($report->products as $np) {
            // ---- Merge conservador de fallback: coleta degradada NÃO apaga dado rico anterior.
            $existingRow = $ctx->dryRun ? null
                : $this->products->findByMarketplaceId($np->marketplace, $np->marketplaceProductId);
            if ($existingRow !== null && $np->isDegraded()) {
                $richBefore = $this->richFingerprint($np);
                $np->fillGapsFrom(ProductHydrator::fromRow($existingRow));
                if ($this->richFingerprint($np) !== $richBefore) {
                    $gapsFilled++; // algum campo RICO (nota/vídeo/vendas/rank/desconto/campanha) foi preservado
                }
            }

            $breakdown = $this->hotScore->evaluate($np);
            $base['score_distribution'][$breakdown->faixaKey] =
                ($base['score_distribution'][$breakdown->faixaKey] ?? 0) + 1;

            if (count($base['preview']) < 60) {
                $base['preview'][] = [
                    'marketplace' => $np->marketplace,
                    'id' => $np->marketplaceProductId,
                    'title' => $np->title,
                    'price' => $np->priceCurrent,
                    'discount' => $np->discountPct,
                    'rating' => $np->rating,
                    'sales_signal' => $np->salesSignal,
                    'has_video' => $np->hasVideo,
                    'rank' => $np->rankPosition,
                    'hot_score' => $breakdown->total,
                    'faixa' => $breakdown->faixaKey,
                ];
            }

            if ($ctx->dryRun) {
                continue;
            }

            $res = $this->products->upsert($np, $breakdown);
            $res['is_new'] ? $new++ : $updated++;

            // ---- Associação M2M produto↔radar (não mexe em status/histórico/outros radares)
            if ($radar !== null && $radar->id !== null) {
                if ($this->productRadars->link($res['id'], $radar->id)) {
                    $assocNew++;
                }
            }

            $this->snapshots->record($res['id'], $runId, $np, $breakdown);
            $snaps++;
        }

        $base['new'] = $new;
        $base['updated'] = $updated;
        $base['snapshots'] = $snaps;
        $base['assoc_new'] = $assocNew;
        $base['gaps_filled'] = $gapsFilled;

        $status = $report->errors === [] ? 'ok' : ($report->products === [] ? 'error' : 'ok');
        $this->runs->finish(
            $runId,
            $status,
            $report->pagesFetched,
            $report->cardsSeen,
            $new,
            $updated,
            $snaps,
            $report->errors,
            trim(
                ($ctx->dryRun ? 'DRY-RUN: nada persistido. ' : '')
                . ($radar ? 'Radar: ' . $radar->name . '. ' : '')
                . ($assocNew > 0 ? $assocNew . ' novas associações. ' : '')
                . ($gapsFilled > 0 ? $gapsFilled . ' produtos com dados ricos preservados (fallback). ' : '')
                . ($report->filteredByRadar > 0 ? $report->filteredByRadar . ' descartados por filtro do radar.' : '')
            ) ?: null
        );

        return $base;
    }

    /** Assinatura só dos campos RICOS (para saber se o merge preservou algo). */
    private function richFingerprint(\HotRadar\Model\NormalizedProduct $p): string
    {
        return json_encode([
            $p->rating, $p->ratingCount, $p->salesSignal, $p->salesExact,
            $p->rankPosition, $p->discountPct, $p->pricePrevious, $p->campaign,
            $p->hasVideo, $p->commissionPct, $p->specialSignals,
        ]) ?: '';
    }
}
