<?php
declare(strict_types=1);

namespace HotRadar\Discovery;

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;

/**
 * Orquestra: collector -> HOT SCORE -> persistência (dedup + snapshot + log de run).
 * O collector NÃO conhece o banco; o banco NÃO conhece o marketplace.
 */
final class DiscoveryService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly SnapshotRepository $snapshots,
        private readonly RunRepository $runs,
        private readonly HotScore $hotScore,
    ) {
    }

    /**
     * @return array{
     *   run_id:?int, marketplace:string, source:string, mode:string, available:bool,
     *   unavailable_reason:?string, pages:int, cards:int, collected:int, new:int,
     *   updated:int, snapshots:int, errors:array<int,string>,
     *   score_distribution:array<string,int>, preview:array<int,array<string,mixed>>
     * }
     */
    public function run(CollectorInterface $collector, CollectorContext $ctx): array
    {
        $mode = $ctx->dryRun ? 'dry_run' : 'live';
        $base = [
            'run_id' => null,
            'marketplace' => $collector->marketplace(),
            'source' => $collector->source(),
            'mode' => $mode,
            'available' => $collector->isAvailable(),
            'unavailable_reason' => $collector->unavailableReason(),
            'pages' => 0, 'cards' => 0, 'collected' => 0, 'new' => 0, 'updated' => 0, 'snapshots' => 0,
            'errors' => [],
            'score_distribution' => ['muito_quente' => 0, 'bom' => 0, 'analisar' => 0, 'baixo' => 0],
            'preview' => [],
        ];

        if (!$collector->isAvailable()) {
            $base['errors'][] = (string) $collector->unavailableReason();
            return $base;
        }

        $runId = $this->runs->start($collector->marketplace(), $collector->source(), $mode);
        $base['run_id'] = $runId;

        $report = $collector->collect($ctx);
        $base['pages'] = $report->pagesFetched;
        $base['cards'] = $report->cardsSeen;
        $base['errors'] = $report->errors;
        $base['collected'] = count($report->products);

        $new = 0;
        $updated = 0;
        $snaps = 0;

        foreach ($report->products as $np) {
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

            $this->snapshots->record($res['id'], $runId, $np, $breakdown);
            $snaps++;
        }

        $base['new'] = $new;
        $base['updated'] = $updated;
        $base['snapshots'] = $snaps;

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
            $ctx->dryRun ? 'DRY-RUN: nada persistido' : null
        );

        return $base;
    }
}
