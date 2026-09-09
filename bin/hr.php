<?php
declare(strict_types=1);

/**
 * SINERGIA HOTRADAR — CLI
 *
 *   php bin/hr.php migrate
 *   php bin/hr.php migrate:status
 *   php bin/hr.php collect:ml [--dry-run] [--pages=3] [--categories=MLB1574,MLB5726] [--save-raw]
 *   php bin/hr.php collect:shopee                 (mostra "aguardando credenciais")
 *   php bin/hr.php score:recompute
 *   php bin/hr.php stats
 *   php bin/hr.php test
 */

require __DIR__ . '/../config/bootstrap.php';

use HotRadar\App;
use HotRadar\Collector\CollectorContext;

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

        case 'collect:ml':
            $ml = hr_config()['marketplaces']['mercado_livre'];
            $ctx = new CollectorContext(
                dryRun: isset($flags['dry-run']),
                maxPages: (int) ($opts['pages'] ?? $ml['max_pages']),
                categories: isset($opts['categories'])
                    ? array_values(array_filter(array_map('trim', explode(',', $opts['categories']))))
                    : [],
                saveRawToDisk: isset($flags['save-raw']),
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
    line('  páginas ........... ' . $r['pages']);
    line('  cards vistos ...... ' . $r['cards']);
    line('  produtos coletados  ' . $r['collected']);
    line('  novos ............. ' . $r['new']);
    line('  atualizados ....... ' . $r['updated']);
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
        $np = productRowToNormalized($row);
        $b = $hotScore->evaluate($np);
        $app->db->run(
            'UPDATE hr_products SET hot_score=?, hot_faixa=?, hot_score_breakdown=?, hot_score_version=?, updated_at=? WHERE id=?',
            [$b->total, $b->faixaKey, json_encode($b->toArray(), JSON_UNESCAPED_UNICODE), $b->version, $app->db->now(), $row['id']]
        );
        $n++;
    }
    return $n;
}

/** @param array<string,mixed> $row */
function productRowToNormalized(array $row): \HotRadar\Model\NormalizedProduct
{
    $extra = json_decode((string) ($row['marketplace_extra'] ?? '{}'), true) ?: [];
    $signals = json_decode((string) ($row['special_signals'] ?? '[]'), true) ?: [];
    return new \HotRadar\Model\NormalizedProduct(
        marketplace: (string) $row['marketplace'],
        marketplaceProductId: (string) $row['marketplace_product_id'],
        title: (string) $row['title'],
        urlOriginal: (string) $row['url_original'],
        shopId: $row['shop_id'] !== null ? (string) $row['shop_id'] : null,
        category: $row['category'] !== null ? (string) $row['category'] : null,
        subcategory: $row['subcategory'] !== null ? (string) $row['subcategory'] : null,
        urlAffiliate: $row['url_affiliate'] !== null ? (string) $row['url_affiliate'] : null,
        imageUrl: $row['image_url'] !== null ? (string) $row['image_url'] : null,
        priceCurrent: $row['price_current'] !== null ? (float) $row['price_current'] : null,
        pricePrevious: $row['price_previous'] !== null ? (float) $row['price_previous'] : null,
        discountPct: $row['discount_pct'] !== null ? (int) $row['discount_pct'] : null,
        salesSignal: $row['sales_signal'] !== null ? (string) $row['sales_signal'] : null,
        salesExact: $row['sales_exact'] !== null ? (int) $row['sales_exact'] : null,
        rating: $row['rating'] !== null ? (float) $row['rating'] : null,
        ratingCount: $row['rating_count'] !== null ? (int) $row['rating_count'] : null,
        rankPosition: $row['rank_position'] !== null ? (int) $row['rank_position'] : null,
        specialSignals: is_array($signals) ? $signals : [],
        hasVideo: (bool) $row['has_video'],
        commissionPct: $row['commission_pct'] !== null ? (float) $row['commission_pct'] : null,
        commissionEstimated: $row['commission_estimated'] !== null ? (float) $row['commission_estimated'] : null,
        campaign: $row['campaign'] !== null ? (string) $row['campaign'] : null,
        marketplaceExtra: is_array($extra) ? $extra : [],
        dataQuality: (string) $row['data_quality'],
        source: (string) $row['source'],
        nicheConfidence: is_array($extra) ? ($extra['niche_confidence'] ?? null) : null,
    );
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
