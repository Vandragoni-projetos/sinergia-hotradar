<?php
use HotRadar\Web\View;
/**
 * @var string $tab
 * @var array<string,mixed> $filters
 * @var array<int,\HotRadar\Radar\Radar> $radars
 * @var bool $ai_ready
 * @var ?string $ai_text
 * @var array<string,mixed>|null $ai_payload_preview
 * @var array<string,mixed> $last_run @var array<string,mixed> $daily
 * @var array<int,array<string,mixed>> $new_products $updated_products $top_scores
 * @var array<int,array<string,mixed>> $score_up $score_down $price_drops $with_video
 * @var array<int,array{key:string,n:int}> $by_category $by_radar
 * @var array<string,int> $status_dist
 * @var array<int,array<string,mixed>> $aprovados $analisar $descartados $run_history
 * @var array<int,array<string,mixed>> $collection_errors
 */
$tabs = [
    'resumo' => 'Resumo', 'novos' => 'Novos & atualizados', 'top' => 'Top / movimentos',
    'preco' => 'Quedas de preço', 'video' => 'Com vídeo', 'distrib' => 'Distribuições',
    'editorial' => 'Editorial', 'coletas' => 'Coletas & erros', 'ia' => '🤖 Análise IA',
];
$qs = static function (array $over) use ($filters): string {
    return '?' . http_build_query(array_merge(['r' => 'reports'], array_filter($filters), $over));
};
$prodRow = static function (array $p): string {
    $t = View::e(mb_substr((string) $p['title'], 0, 70));
    $mp = View::e(View::marketplaceLabel((string) $p['marketplace']));
    $sc = isset($p['hot_score']) ? (int) $p['hot_score'] : null;
    $pr = View::money($p['price_current'] ?? null);
    return "<td>{$t}</td><td>{$mp}</td><td>" . ($sc !== null ? $sc : '—') . "</td><td>{$pr}</td>";
};
?>
<h1>Relatórios</h1>

<form method="get" class="filters" style="margin-bottom:8px">
  <input type="hidden" name="r" value="reports">
  <input type="hidden" name="tab" value="<?= View::e($tab) ?>">
  <div class="f"><label>Radar</label>
    <select name="radar" onchange="this.form.submit()">
      <option value="">todos</option>
      <?php foreach ($radars as $rd): ?>
        <option value="<?= View::e($rd->slug) ?>" <?= ($filters['radar'] ?? '') === $rd->slug ? 'selected' : '' ?>><?= View::e($rd->name) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="f"><label>Marketplace</label>
    <select name="marketplace" onchange="this.form.submit()">
      <option value="">todos</option>
      <option value="mercado_livre" <?= ($filters['marketplace'] ?? '') === 'mercado_livre' ? 'selected' : '' ?>>Mercado Livre</option>
      <option value="shopee" <?= ($filters['marketplace'] ?? '') === 'shopee' ? 'selected' : '' ?>>Shopee</option>
    </select>
  </div>
</form>

<div class="pills" style="margin-bottom:18px">
  <?php foreach ($tabs as $k => $lbl): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= $qs(['tab' => $k]) ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'resumo'): ?>
  <div class="panel">
    <h2 style="margin-top:0">Resumo da última coleta</h2>
    <?php if (!$last_run['exists']): ?><p class="muted">Nenhuma coleta ainda.</p><?php else: $r = $last_run['run']; ?>
      <div class="kv" style="font-size:13px">
        <div>Quando</div><div><?= View::e($r['started_at']) ?></div>
        <div>Radar</div><div><?= View::e($r['radar_slug'] ?? '—') ?></div>
        <div>Marketplace / status</div><div><?= View::e($r['marketplace']) ?> · <?= View::e($r['status']) ?></div>
        <div>Páginas / cards</div><div><?= (int) $r['pages_fetched'] ?> / <?= (int) $r['cards_seen'] ?></div>
        <div>Novos / atualizados / snapshots</div><div><?= (int) $r['products_new'] ?> / <?= (int) $r['products_updated'] ?> / <?= (int) $r['snapshots_written'] ?></div>
        <div>Observações</div><div class="muted"><?= View::e($r['notes'] ?? '—') ?></div>
        <div>Erros</div><div><?= $last_run['errors'] ? View::e(implode(' | ', $last_run['errors'])) : '<span class="muted">nenhum</span>' ?></div>
      </div>
    <?php endif; ?>
  </div>
  <div class="panel" style="margin-top:14px">
    <h2 style="margin-top:0">Resumo de hoje (<?= View::e($daily['date']) ?>)</h2>
    <div class="kv" style="font-size:13px">
      <div>Novos produtos hoje</div><div><?= (int) $daily['novos_produtos'] ?></div>
      <div>Coletas hoje</div><div><?= count($daily['coletas']) ?></div>
      <div>Snapshots hoje</div><div><?= (int) $daily['snapshots'] ?></div>
      <div>Faixa (descobertos hoje)</div><div>🔥 <?= $daily['faixa_hoje']['muito_quente'] ?? 0 ?> · 🟠 <?= $daily['faixa_hoje']['bom'] ?? 0 ?> · 🟡 <?= $daily['faixa_hoje']['analisar'] ?? 0 ?> · ⚪ <?= $daily['faixa_hoje']['baixo'] ?? 0 ?></div>
    </div>
  </div>

<?php elseif ($tab === 'novos'): ?>
  <?= reportTable('Novos produtos (últimos 7 dias)', $new_products, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>
  <?= reportTable('Produtos atualizados (≥ 2 coletas)', $updated_products, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>

<?php elseif ($tab === 'top'): ?>
  <?= reportTable('Maiores HOT SCORES', $top_scores, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>
  <div class="panel" style="margin-top:14px">
    <h2 style="margin-top:0">Subiram de score (última coleta vs anterior)</h2>
    <table class="hist"><tr><th>Produto</th><th>Marketplace</th><th>Antes→Depois</th><th>Δ</th></tr>
      <?php foreach ($score_up as $m): ?>
        <tr><td><?= View::e(mb_substr((string) $m['title'], 0, 70)) ?></td><td><?= View::e($m['marketplace']) ?></td>
        <td><?= (int) $m['prev_hot'] ?> → <?= (int) $m['last_hot'] ?></td><td class="updown-up">+<?= (int) $m['delta'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$score_up): ?><tr><td colspan="4" class="muted">Sem movimentos (precisa de ≥ 2 coletas do mesmo produto).</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="panel" style="margin-top:14px">
    <h2 style="margin-top:0">Caíram de score</h2>
    <table class="hist"><tr><th>Produto</th><th>Marketplace</th><th>Antes→Depois</th><th>Δ</th></tr>
      <?php foreach ($score_down as $m): ?>
        <tr><td><?= View::e(mb_substr((string) $m['title'], 0, 70)) ?></td><td><?= View::e($m['marketplace']) ?></td>
        <td><?= (int) $m['prev_hot'] ?> → <?= (int) $m['last_hot'] ?></td><td class="updown-down"><?= (int) $m['delta'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$score_down): ?><tr><td colspan="4" class="muted">Sem movimentos.</td></tr><?php endif; ?>
    </table>
  </div>

<?php elseif ($tab === 'preco'): ?>
  <div class="panel">
    <h2 style="margin-top:0">Maiores quedas de preço (última coleta vs anterior)</h2>
    <table class="hist"><tr><th>Produto</th><th>Marketplace</th><th>De</th><th>Para</th><th>Queda</th></tr>
      <?php foreach ($price_drops as $d): ?>
        <tr><td><?= View::e(mb_substr((string) $d['title'], 0, 60)) ?></td><td><?= View::e($d['marketplace']) ?></td>
        <td><?= View::money($d['prev_price']) ?></td><td><?= View::money($d['last_price']) ?></td>
        <td class="updown-down">−<?= (int) $d['drop_pct'] ?>% (<?= View::money($d['drop_abs']) ?>)</td></tr>
      <?php endforeach; ?>
      <?php if (!$price_drops): ?><tr><td colspan="5" class="muted">Nenhuma queda registrada ainda (precisa de ≥ 2 coletas).</td></tr><?php endif; ?>
    </table>
  </div>

<?php elseif ($tab === 'video'): ?>
  <?= reportTable('Produtos com vídeo (' . count($with_video) . ')', $with_video, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>

<?php elseif ($tab === 'distrib'): ?>
  <div class="grid cols-3">
    <div class="panel"><h2 style="margin-top:0">Por nicho / categoria</h2>
      <table><?php foreach ($by_category as $c): ?><tr><td><?= View::e($c['key']) ?></td><td style="text-align:right"><?= (int) $c['n'] ?></td></tr><?php endforeach; ?></table></div>
    <div class="panel"><h2 style="margin-top:0">Por radar</h2>
      <table><?php foreach ($by_radar as $c): ?><tr><td><?= View::e($c['key']) ?></td><td style="text-align:right"><?= (int) $c['n'] ?></td></tr><?php endforeach; ?></table></div>
    <div class="panel"><h2 style="margin-top:0">Por status editorial</h2>
      <table><?php foreach ($status_dist as $k => $n): ?><tr><td><?= View::e(\HotRadar\Editorial\EditorialStatus::label($k)) ?></td><td style="text-align:right"><?= (int) $n ?></td></tr><?php endforeach; ?></table></div>
  </div>

<?php elseif ($tab === 'editorial'): ?>
  <?= reportTable('⭐ Aprovados (' . count($aprovados) . ')', $aprovados, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>
  <?= reportTable('🟡 Analisar (' . count($analisar) . ')', $analisar, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>
  <?= reportTable('❌ Descartados (' . count($descartados) . ')', $descartados, $prodRow, ['Produto', 'Marketplace', 'HOT', 'Preço']) ?>

<?php elseif ($tab === 'coletas'): ?>
  <div class="panel">
    <h2 style="margin-top:0">Histórico de coletas</h2>
    <table class="hist"><tr><th>Quando</th><th>Radar</th><th>Modo</th><th>Status</th><th>Pág.</th><th>Cards</th><th>Novos</th><th>Atual.</th><th>Snap.</th></tr>
      <?php foreach ($run_history as $r): ?>
        <tr><td><?= View::e(date('d/m H:i', strtotime((string) $r['started_at']))) ?></td>
        <td><?= View::e($r['radar_slug'] ?? '—') ?></td><td><?= View::e($r['mode']) ?></td><td><?= View::e($r['status']) ?></td>
        <td><?= (int) $r['pages_fetched'] ?></td><td><?= (int) $r['cards_seen'] ?></td>
        <td><?= (int) $r['products_new'] ?></td><td><?= (int) $r['products_updated'] ?></td><td><?= (int) $r['snapshots_written'] ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="panel" style="margin-top:14px">
    <h2 style="margin-top:0">Erros de coleta</h2>
    <table class="hist"><tr><th>Quando</th><th>Radar</th><th>Erro</th></tr>
      <?php foreach ($collection_errors as $e): ?>
        <tr><td><?= View::e(date('d/m H:i', strtotime($e['when']))) ?></td><td><?= View::e($e['radar'] ?? '—') ?></td><td class="muted"><?= View::e($e['error']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$collection_errors): ?><tr><td colspan="3" class="muted">Nenhum erro registrado. 🎉</td></tr><?php endif; ?>
    </table>
  </div>

<?php elseif ($tab === 'ia'): ?>
  <div class="panel">
    <h2 style="margin-top:0">🤖 Análise inteligente</h2>
    <?php if (!$ai_ready): ?>
      <p>OpenAI <strong>não configurada</strong>. Defina <code>OPENAI_API_KEY</code> no Environment do EasyPanel para habilitar.</p>
      <p class="muted" style="font-size:12px">A IA recebe apenas números já calculados do banco e produz um resumo interpretativo. Não inventa preço, desconto, venda, rating ou score; dado ausente é marcado como indisponível. Não altera o HOT SCORE.</p>
    <?php else: ?>
      <form method="post" action="?r=report.ai">
        <?php if (!empty($filters['radar'])): ?><input type="hidden" name="radar" value="<?= View::e($filters['radar']) ?>"><?php endif; ?>
        <?php if (!empty($filters['marketplace'])): ?><input type="hidden" name="marketplace" value="<?= View::e($filters['marketplace']) ?>"><?php endif; ?>
        <button class="primary" type="submit">Gerar análise inteligente</button>
        <span class="muted" style="font-size:12px">usa o modelo configurado; só dados estruturados do banco.</span>
      </form>
    <?php endif; ?>
    <?php if ($ai_text !== null): ?>
      <div style="margin-top:14px;padding:14px;background:var(--surface-2);border-radius:10px;white-space:pre-wrap"><?= View::e($ai_text) ?></div>
    <?php endif; ?>
  </div>
  <div class="panel" style="margin-top:14px">
    <h2 style="margin-top:0">Dados que a IA recebe (estruturados)</h2>
    <p><a href="<?= $qs(['tab' => 'ia', 'ai_preview' => 1]) ?>">Mostrar payload</a></p>
    <?php if ($ai_payload_preview !== null): ?>
      <pre style="overflow:auto;font-size:11px;max-height:420px"><?= View::e(json_encode($ai_payload_preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
function reportTable(string $title, array $rows, callable $rowFn, array $head): string
{
    $h = '';
    foreach ($head as $x) {
        $h .= '<th>' . \HotRadar\Web\View::e($x) . '</th>';
    }
    $body = '';
    foreach ($rows as $r) {
        $body .= '<tr>' . $rowFn($r) . '</tr>';
    }
    if ($body === '') {
        $body = '<tr><td colspan="' . count($head) . '" class="muted">Nada aqui ainda.</td></tr>';
    }
    return '<div class="panel" style="margin-top:14px"><h2 style="margin-top:0">' . \HotRadar\Web\View::e($title)
        . '</h2><table class="hist"><tr>' . $h . '</tr>' . $body . '</table></div>';
}
?>
