<?php
use HotRadar\Web\View;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Score\ScoreBreakdown;
/**
 * @var array<string,mixed> $p
 * @var ScoreBreakdown|null $breakdown
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $timeline
 * @var array<string,mixed> $extra
 * @var array<int,string> $signals
 * @var \HotRadar\Radar\Radar|null $primary_radar
 * @var array<int,array<string,mixed>> $product_radars
 */
$radNomes = implode(', ', array_map(static fn ($r) => $r['name'], $product_radars)) ?: '—';
?>
<div class="toolbar">
  <a class="btn" href="?r=products">← voltar à curadoria</a>
  <div class="spacer"></div>
  <a class="btn primary" href="?r=print.ficha&id=<?= (int) $p['id'] ?>" target="_blank">📄 Gerar PDF</a>
  <a class="btn" href="<?= View::e($p['url_original']) ?>" target="_blank" rel="noopener">Abrir no site ↗</a>
</div>

<h1><?= View::e($p['title']) ?></h1>

<div class="grid" style="grid-template-columns:320px 1fr;gap:20px">
  <div>
    <div class="card">
      <div class="thumb pcard" style="height:280px;background:var(--surface-2)">
        <?php if ($p['image_url']): ?><img src="<?= View::e($p['image_url']) ?>" style="width:100%;height:100%;object-fit:contain" alt=""><?php endif; ?>
      </div>
      <div style="padding:14px" class="commercial">
        <div class="big-score"><?= View::faixaEmoji($p['hot_faixa']) ?> <?= (int) $p['hot_score'] ?><span class="muted" style="font-size:16px">/100</span></div>
        <div class="muted"><?= View::e(View::faixaNome($p['hot_faixa'])) ?></div>
        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
        <div class="price" style="font-size:20px;font-weight:800"><?= View::money($p['price_current']) ?>
          <?php if ($p['discount_pct']): ?><span class="off" style="color:var(--ok);font-size:14px">-<?= (int) $p['discount_pct'] ?>%</span><?php endif; ?>
        </div>
        <?php if ($p['price_previous']): ?><div class="muted"><s><?= View::money($p['price_previous']) ?></s></div><?php endif; ?>
      </div>
    </div>

    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Sua decisão</h2>
      <p>Situação: <strong><?= View::e(EditorialStatus::label((string) $p['status'])) ?></strong>
        <?php if ($p['discard_reason']): ?><br><span class="muted">Motivo: <?= View::e($p['discard_reason']) ?></span><?php endif; ?>
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ([['aprovado','Aprovar','ok'],['analisar','Analisar','warn'],['descoberto','Reabrir','ghost']] as [$to,$lbl,$cls]): ?>
          <form method="post" action="?r=product.status"><?= View::csrf() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="to" value="<?= $to ?>">
            <button class="<?= $cls ?>" type="submit"><?= $lbl ?></button>
          </form>
        <?php endforeach; ?>
        <form method="post" action="?r=product.status" onsubmit="var r=prompt('Motivo do descarte (opcional):');if(r===null)return false;this.reason.value=r;"><?= View::csrf() ?>
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="to" value="descartado">
          <input type="hidden" name="reason" value="">
          <button class="danger" type="submit">Descartar</button>
        </form>
      </div>
    </div>
  </div>

  <div class="commercial">
    <div class="panel">
      <h2 style="margin-top:0">Informações do produto</h2>
      <div class="kv2">
        <div>Marketplace</div><div><?= View::e(View::marketplaceLabel((string) $p['marketplace'])) ?></div>
        <div>Radar / nicho</div><div><?= View::e($radNomes) ?></div>
        <div>Loja / vendedor</div><div><?= View::e($extra['seller'] ?? '—') ?><?= !empty($extra['official_store']) ? ' · loja oficial' : '' ?></div>
        <div>Preço atual</div><div><strong><?= View::money($p['price_current']) ?></strong></div>
        <div>Preço anterior</div><div><?= $p['price_previous'] ? View::money($p['price_previous']) : '—' ?></div>
        <div>Desconto</div><div><?= $p['discount_pct'] !== null ? (int) $p['discount_pct'] . '%' : '—' ?></div>
        <div>Avaliação</div><div><?= $p['rating'] !== null ? '★ ' . number_format((float) $p['rating'],1,',','') : 'Não informada' ?></div>
        <div>Procura / vendas</div><div><?= View::e(View::vendasTexto($p['sales_signal'], $p['sales_exact'] !== null ? (int) $p['sales_exact'] : null)) ?></div>
        <div>Tem vídeo</div><div><?= ((int) $p['has_video']) ? 'Sim' : 'Não' ?></div>
        <div>Descoberto</div><div><?= View::e(View::dataCurta($p['discovered_at'])) ?></div>
        <div>Última coleta</div><div><?= View::e(View::dataCurta($p['last_collected_at'])) ?></div>
        <div>Confiança do dado</div><div><?= View::e(View::confiancaDado($p['data_quality'])) ?></div>
      </div>
    </div>

    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Por que este Hot Score</h2>
      <?php if ($breakdown): foreach ($breakdown->components as $c):
        $pct = $c['max'] > 0 ? round($c['points'] / $c['max'] * 100) : 0; ?>
        <div class="scoreline <?= $c['available'] ? '' : 'absent' ?>">
          <div><?= View::e($c['label']) ?></div>
          <div>
            <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
            <div class="muted" style="font-size:11px"><?= View::e(View::fatorDetalhe((string) $c['detail'])) ?></div>
          </div>
          <div class="pts"><?= (int) $c['points'] ?>/<?= (int) $c['max'] ?></div>
        </div>
      <?php endforeach; ?>
        <div class="scoreline total"><div>TOTAL</div><div></div>
          <div class="pts"><?= $breakdown->total ?>/<?= $breakdown->maxTotal ?> <?= $breakdown->faixaEmoji ?></div></div>
        <p class="muted" style="font-size:12px">Fatores esmaecidos = dado não informado pela fonte (nada é estimado).</p>
      <?php else: ?><p class="muted">Sem detalhamento salvo.</p><?php endif; ?>
    </div>

    <?php if (count($history) > 1): ?>
    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Evolução (coletas anteriores)</h2>
      <table class="hist">
        <tr><th>Quando</th><th>Preço</th><th>Desconto</th><th>Procura</th><th>Nota</th><th>Hot Score</th></tr>
        <?php $prev = null; foreach ($history as $h): ?>
          <tr>
            <td><?= View::e(date('d/m H:i', strtotime((string) $h['collected_at']))) ?></td>
            <td><?= View::money($h['price_current']) ?>
              <?php if ($prev && $h['price_current'] !== null && $prev['price_current'] !== null):
                $d = (float) $h['price_current'] - (float) $prev['price_current'];
                if (abs($d) >= 0.01): ?><span class="<?= $d < 0 ? 'updown-down' : 'updown-up' ?>"><?= $d < 0 ? '▼' : '▲' ?></span><?php endif; endif; ?></td>
            <td><?= $h['discount_pct'] !== null ? (int) $h['discount_pct'] . '%' : '—' ?></td>
            <td><?= View::e(View::vendasNome($h['sales_signal'] ?? null)) ?></td>
            <td><?= $h['rating'] !== null ? number_format((float) $h['rating'],1,',','') : '—' ?></td>
            <td><strong><?= (int) $h['hot_score'] ?></strong></td>
          </tr>
        <?php $prev = $h; endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <details class="tech">
      <summary>Detalhes técnicos (para diagnóstico)</summary>
      <div class="kv" style="font-size:13px">
        <div>ID do marketplace (dedup)</div><div><?= View::e($p['marketplace_product_id']) ?></div>
        <div>Item ID (anúncio)</div><div><?= !empty($extra['ml_item_id']) ? View::e($extra['ml_item_id']) : '—' ?></div>
        <div>Catalog Product ID</div><div><?= !empty($extra['ml_catalog_id']) ? View::e($extra['ml_catalog_id']) : '—' ?></div>
        <div>Categoria de origem</div><div><?= View::e($extra['source_category_label'] ?? '—') ?></div>
        <div>Aderência ao nicho</div><div><?= View::e($extra['niche_confidence'] ?? '—') ?></div>
        <div>Posição/ranking</div><div><?= $p['rank_position'] !== null ? '#' . (int) $p['rank_position'] : '—' ?></div>
        <div>Campanha (código)</div><div><?= View::e($p['campaign'] ?? '—') ?></div>
        <div>Sinais especiais</div><div><?= $signals ? View::e(implode(', ', $signals)) : '—' ?></div>
        <div>Selos do Mercado Livre</div><div><?= !empty($extra['ml_badges']) ? View::e(implode(', ', $extra['ml_badges'])) : '—' ?></div>
        <div>Comissão</div><div><?= $p['commission_pct'] !== null ? $p['commission_pct'] . '%' : '—' ?></div>
        <div>URL afiliada</div><div><?= $p['url_affiliate'] ? View::e($p['url_affiliate']) : '— (fase posterior)' ?></div>
        <div>Origem da coleta</div><div><?= View::e($p['data_quality']) ?> · <?= View::e($p['source']) ?></div>
        <div>Radares (com "descobriu")</div><div>
          <?php foreach ($product_radars as $pr): ?>
            <span class="badge q">📡 <?= View::e($pr['name']) ?><?php if ($primary_radar && $pr['radar_id'] === $primary_radar->id): ?> (descobriu)<?php endif; ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <h3 style="margin:14px 0 6px">Trilha editorial</h3>
      <?php if ($timeline): ?>
        <table class="hist">
          <?php foreach ($timeline as $t): ?>
            <tr><td><?= View::e(date('d/m H:i', strtotime((string) $t['created_at']))) ?></td>
              <td><?= View::e($t['from_status'] ?? '—') ?> → <strong><?= View::e($t['to_status']) ?></strong></td>
              <td class="muted"><?= View::e($t['actor']) ?><?= $t['reason'] ? ' · ' . View::e($t['reason']) : '' ?></td></tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?><p class="muted">Sem mudanças de status ainda.</p><?php endif; ?>
    </details>
  </div>
</div>
