<?php
declare(strict_types=1);

/**
 * SINERGIA HOTRADAR — CLI
 *
 *   php bin/hr.php migrate
 *   php bin/hr.php migrate:status
 *   php bin/hr.php radars                          lista radares
 *   php bin/hr.php collect:radar <slug> [--dry-run] roda um radar
 *   php bin/hr.php collect:all [--dry-run]          roda todos os radares ativos
 *   php bin/hr.php collect:ml [--dry-run] [--pages=3] [--categories=MLB1574,...] [--radar=slug]
 *   php bin/hr.php collect:shopee                   (mostra status Shopee)
 *   php bin/hr.php score:recompute
 *   php bin/hr.php hotscore:shadow-v2 [--radar=slug]   calcula HOT SCORE V2 (shadow — não altera a V1)
 *   php bin/hr.php stats
 *   php bin/hr.php test
 */

require __DIR__ . '/../config/bootstrap.php';

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Model\ProductHydrator;
use HotRadar\Radar\Radar;

$argvv = $argv;
array_shift($argvv);
$command = array_shift($argvv) ?: 'help';

$opts = [];
$flags = [];
foreach ($argvv as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$k, $v] = explode('=', substr($arg, 2), 2);
        $opts[$k] = $v;
    } elseif (str_starts_with($arg, '--')) {
        $flags[substr($arg, 2)] = true;
    }
}

$app = App::boot(hr_config());

function line(string $s = ''): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

try {
    switch ($command) {
        case 'migrate':
            $ran = $app->migrator()->migrate();
            line($ran === [] ? 'Nada a migrar (schema atualizado).' : 'Migrations aplicadas: ' . implode(', ', $ran));
            break;

        case 'migrate:status':
            line('Aplicadas: ' . implode(', ', $app->migrator()->status() ?: ['(nenhuma)']));
            break;

        case 'radars':
            foreach ($app->radars()->all() as $rd) {
                line(sprintf(
                    '  [%s] %-22s (%s) — cats: %s | páginas: %d',
                    $rd->enabled ? 'ON ' : 'off',
                    $rd->name,
                    $rd->slug,
                    implode(',', $rd->mlCategoryIds()) ?: '(nenhuma)',
                    $rd->pagesPerCategory
                ));
            }
            break;

        case 'collect:radar':
            $slug = (string) ($argvv[0] ?? '');
            $rd = $slug !== '' ? $app->radars()->findBySlug($slug) : null;
            if ($rd === null) {
                line('Radar não encontrado. Use: php bin/hr.php radars');
                exit(1);
            }
            printCollectResult($app->discovery()->run(
                $app->mercadoLivreCollector(),
                new CollectorContext(dryRun: isset($flags['dry-run']), radar: $rd)
            ));
            break;

        case 'collect:all':
            foreach ($app->radars()->enabled() as $rd) {
                if (!$rd->hasMarketplace('mercado_livre')) {
                    continue;
                }
                line('>>> Radar: ' . $rd->name);
                printCollectResult($app->discovery()->run(
                    $app->mercadoLivreCollector(),
                    new CollectorContext(dryRun: isset($flags['dry-run']), radar: $rd)
                ));
            }
            break;

        case 'collect:ml':
            // modo legado / manual. Aceita --radar=slug ou --categories=.
            $ml = hr_config()['marketplaces']['mercado_livre'];
            $radar = isset($opts['radar']) ? $app->radars()->findBySlug($opts['radar']) : null;
            $ctx = new CollectorContext(
                dryRun: isset($flags['dry-run']),
                maxPages: (int) ($opts['pages'] ?? $ml['max_pages']),
                categories: isset($opts['categories'])
                    ? array_values(array_filter(array_map('trim', explode(',', $opts['categories']))))
                    : [],
                saveRawToDisk: isset($flags['save-raw']),
                radar: $radar,
            );
            $r = $app->discovery()->run($app->mercadoLivreCollector(), $ctx);
            printCollectResult($r);
            break;

        case 'collect:shopee':
            $r = $app->discovery()->run($app->shopeeCollector(), new CollectorContext(dryRun: isset($flags['dry-run'])));
            printCollectResult($r);
            break;

        case 'score:recompute':
            $n = recomputeScores($app);
            line("HOT SCORE recalculado para $n produto(s).");
            break;

        case 'hotscore:shadow-v2':
            runHotScoreShadowV2($app, isset($opts['radar']) ? (string) $opts['radar'] : null);
            break;

        case 'stats':
            printStats($app);
            break;

        case 'test':
            require __DIR__ . '/../tests/run.php';
            break;

        case 'help':
        default:
            line(file_get_contents(__FILE__, false, null, 0, 900));
            break;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}

/** @param array<string,mixed> $r */
function printCollectResult(array $r): void
{
    line('');
    line('== Coleta: ' . $r['marketplace'] . ' / ' . $r['source'] . ' (' . $r['mode'] . ') ==');
    if (!$r['available']) {
        line('  INDISPONÍVEL: ' . $r['unavailable_reason']);
        return;
    }
    line('  run_id ............. ' . $r['run_id']);
    if (!empty($r['radar'])) {
        line('  radar ............. ' . ($r['radar_name'] ?? $r['radar']));
    }
    line('  páginas ........... ' . $r['pages']);
    line('  cards vistos ...... ' . $r['cards']);
    if (($r['filtered'] ?? 0) > 0) {
        line('  filtrados (radar) . ' . $r['filtered']);
    }
    line('  produtos coletados  ' . $r['collected']);
    line('  novos ............. ' . $r['new']);
    line('  atualizados ....... ' . $r['updated']);
    if (($r['assoc_new'] ?? 0) > 0) {
        line('  novas assoc. radar  ' . $r['assoc_new']);
    }
    if (($r['gaps_filled'] ?? 0) > 0) {
        line('  dados ricos manti. ' . $r['gaps_filled'] . ' (merge de fallback)');
    }
    line('  snapshots ......... ' . $r['snapshots']);
    line('  distribuição HOT SCORE:');
    foreach ($r['score_distribution'] as $k => $v) {
        line(sprintf('     %-14s %d', $k, $v));
    }
    if ($r['errors'] !== []) {
        line('  ERROS:');
        foreach ($r['errors'] as $e) {
            line('     - ' . $e);
        }
    }
    if (!empty($r['preview'])) {
        line('');
        line('  Top por HOT SCORE:');
        $preview = $r['preview'];
        usort($preview, static fn ($a, $b) => $b['hot_score'] <=> $a['hot_score']);
        foreach (array_slice($preview, 0, 15) as $p) {
            line(sprintf(
                '   %s %3d | %-13s | %s%s | %s',
                faixaEmoji($p['faixa']),
                $p['hot_score'],
                $p['marketplace'],
                $p['price'] !== null ? 'R$ ' . number_format((float) $p['price'], 2, ',', '.') : 's/preço',
                $p['discount'] !== null ? ' -' . $p['discount'] . '%' : '',
                mb_substr($p['title'], 0, 60)
            ));
        }
    }
}

function faixaEmoji(string $k): string
{
    return ['muito_quente' => '🔥', 'bom' => '🟠', 'analisar' => '🟡', 'baixo' => '⚪'][$k] ?? '·';
}

function recomputeScores(App $app): int
{
    $hotScore = $app->hotScore();
    $repo = $app->products();
    $rows = $app->db->all('SELECT * FROM hr_products');
    $n = 0;
    foreach ($rows as $row) {
        $np = ProductHydrator::fromRow($row);
        $b = $hotScore->evaluate($np);
        $app->db->run(
            'UPDATE hr_products SET hot_score=?, hot_faixa=?, hot_score_breakdown=?, hot_score_version=?, updated_at=? WHERE id=?',
            [$b->total, $b->faixaKey, json_encode($b->toArray(), JSON_UNESCAPED_UNICODE), $b->version, $app->db->now(), $row['id']]
        );
        $n++;
    }
    return $n;
}

/**
 * HOT SCORE V2 — SHADOW MODE.
 *
 * Calcula BASE + aderência contextual para cada associação produto×radar já
 * existente e grava SOMENTE nas colunas novas de hr_product_radars (migration
 * 007). NÃO toca em hr_products.hot_score/hot_faixa (a V1 continua sendo a
 * fonte oficial usada por toda a aplicação), NÃO recoleta, NÃO altera status
 * editorial, NÃO cria produto nem associação nova.
 */
function runHotScoreShadowV2(App $app, ?string $radarSlugFilter): void
{
    $v2 = $app->hotScoreV2();
    $pairs = $app->productRadars()->allPairIds();

    $radarCache = [];
    $getRadar = static function (int $id) use ($app, &$radarCache): ?Radar {
        return $radarCache[$id] ??= $app->radars()->find($id);
    };

    $v1Faixas = ['muito_quente' => 0, 'bom' => 0, 'analisar' => 0, 'baixo' => 0];
    $v2Faixas = ['muito_quente' => 0, 'bom' => 0, 'analisar' => 0, 'baixo' => 0];
    $processed = 0;
    $skipped = 0;

    foreach ($pairs as $pair) {
        $radar = $getRadar($pair['radar_id']);
        if ($radar === null) {
            $skipped++;
            continue;
        }
        if ($radarSlugFilter !== null && $radarSlugFilter !== '' && $radar->slug !== $radarSlugFilter) {
            continue;
        }
        $row = $app->products()->find($pair['product_id']);
        if ($row === null) {
            $skipped++;
            continue;
        }

        $np = ProductHydrator::fromRow($row);
        $breakdown = $v2->evaluateForRadar($np, $radar);
        $ad = $v2->resolveAdherence($np, $radar);

        $app->productRadars()->saveShadowContext(
            $pair['id'],
            $ad['level'],
            $ad['points'],
            $breakdown->total,
            $breakdown->faixaKey,
            json_encode($breakdown->toArray(), JSON_UNESCAPED_UNICODE),
            $breakdown->version,
            $app->db->now(),
        );

        $v1Faixa = (string) ($row['hot_faixa'] ?? 'baixo');
        $v1Faixas[$v1Faixa] = ($v1Faixas[$v1Faixa] ?? 0) + 1;
        $v2Faixas[$breakdown->faixaKey] = ($v2Faixas[$breakdown->faixaKey] ?? 0) + 1;
        $processed++;
    }

    line("HOT SCORE V2 (shadow) calculado para $processed associação(ões) produto×radar." . ($skipped > 0 ? " ($skipped ignoradas — produto/radar não encontrado)" : ''));
    line('');
    line('Distribuição de faixas nas associações processadas (V1 do produto x V2 no contexto do radar):');
    line(sprintf('  %-16s %8s %8s', 'faixa', 'V1', 'V2'));
    foreach (['muito_quente', 'bom', 'analisar', 'baixo'] as $k) {
        line(sprintf('  %s %-14s %8d %8d', faixaEmoji($k), $k, $v1Faixas[$k], $v2Faixas[$k]));
    }
    line('');
    line('A V1 continua sendo a fonte oficial (hr_products.hot_score/hot_faixa) — nada mudou na Curadoria.');
    line('Ver comparação detalhada em: ?r=hotscore.compare (painel).');
}

function printStats(App $app): void
{
    $db = $app->db;
    $total = (int) ($db->first('SELECT COUNT(*) n FROM hr_products')['n'] ?? 0);
    $snaps = (int) ($db->first('SELECT COUNT(*) n FROM hr_product_snapshots')['n'] ?? 0);
    $runs = (int) ($db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'] ?? 0);
    line("Produtos: $total | Snapshots: $snaps | Coletas: $runs");
    line('');
    line('Por faixa:');
    foreach ($app->products()->faixaDistribution() as $k => $v) {
        line(sprintf('  %s %-14s %d', faixaEmoji($k), $k, $v));
    }
    line('');
    line('Por status:');
    foreach ($app->products()->statusDistribution() as $k => $v) {
        line(sprintf('  %-14s %d', $k, $v));
    }
    line('');
    line('Por marketplace:');
    foreach ($db->all('SELECT marketplace, COUNT(*) n FROM hr_products GROUP BY marketplace') as $r) {
        line(sprintf('  %-14s %d', $r['marketplace'], $r['n']));
    }
}
