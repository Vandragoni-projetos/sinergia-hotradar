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
 * @var array<int,array<string,mixed>> $product_radars  associações M2M deste produto
 */
?>
<p><a href="?r=products">← voltar à curadoria</a></p>
<h1><?= View::e($p['title']) ?></h1>

<div class="grid" style="grid-template-columns:320px 1fr;gap:20px">
  <div>
    <div class="card">
      <div class="thumb pcard" style="aspect-ratio:1/1;background:var(--surface-2)">
        <?php if ($p['image_url']): ?><img src="<?= View::e($p['image_url']) ?>" style="width:100%;height:100%;object-fit:contain" alt=""><?php endif; ?>
      </div>
      <div style="padding:14px">
        <div style="font-size:30px;font-weight:800">
          <?= match ($p['hot_faixa']) {'muito_quente'=>'🔥','bom'=>'🟠','analisar'=>'🟡',default=>'⚪'} ?>
          <?= (int) $p['hot_score'] ?><span class="muted" style="font-size:16px">/100</span>
        </div>
        <div class="muted"><?= View::e(View::faixaBadge($p['hot_faixa'])) ?> · versão <?= View::e($p['hot_score_version']) ?></div>
        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
        <div class="price" style="font-size:20px;font-weight:800"><?= View::money($p['price_current']) ?>
          <?php if ($p['discount_pct']): ?><span class="off" style="color:var(--ok);font-size:14px">-<?= (int) $p['discount_pct'] ?>%</span><?php endif; ?>
        </div>
        <?php if ($p['price_previous']): ?><div class="muted"><s><?= View::money($p['price_previous']) ?></s></div><?php endif; ?>
      </div>
    </div>

    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Decisão editorial</h2>
      <p>Status atual: <strong><?= View::e(EditorialStatus::label((string) $p['status'])) ?></strong>
        <?php if ($p['discard_reason']): ?><br><span class="muted">Motivo: <?= View::e($p['discard_reason']) ?></span><?php endif; ?>
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ([['aprovado','Aprovar','ok'],['analisar','Analisar','warn'],['descoberto','Reabrir','ghost']] as [$to,$lbl,$cls]): ?>
          <form method="post" action="?r=product.status">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="to" value="<?= $to ?>">
            <button class="<?= $cls ?>" type="submit"><?= $lbl ?></button>
          </form>
        <?php endforeach; ?>
        <form method="post" action="?r=product.status" onsubmit="var r=prompt('Motivo do descarte (opcional):');if(r===null)return false;this.reason.value=r;">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="to" value="descartado">
          <input type="hidden" name="reason" value="">
          <button class="danger" type="submit">Descartar</button>
        </form>
      </div>
      <p style="margin-bottom:0"><a href="<?= View::e($p['url_original']) ?>" target="_blank" rel="noopener">Abrir no Mercado Livre ↗</a></p>
    </div>
  </div>

  <div>
    <div class="panel">
      <h2 style="margin-top:0">Por que recebeu esse HOT SCORE?</h2>
      <?php if ($breakdown): foreach ($breakdown->components as $c):
        $pct = $c['max'] > 0 ? round($c['points'] / $c['max'] * 100) : 0; ?>
        <div class="scoreline <?= $c['available'] ? '' : 'absent' ?>">
          <div><?= View::e($c['label']) ?></div>
          <div>
            <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
            <div class="muted" style="font-size:11px"><?= View::e($c['detail']) ?></div>
          </div>
          <div class="pts"><?= (int) $c['points'] ?>/<?= (int) $c['max'] ?></div>
        </div>
      <?php endforeach; ?>
        <div class="scoreline total">
          <div>TOTAL</div><div></div>
          <div class="pts"><?= $breakdown->total ?>/<?= $breakdown->maxTotal ?> <?= $breakdown->faixaEmoji ?></div>
        </div>
        <p class="muted" style="font-size:12px">Blocos com opacidade reduzida = dado não disponível (não inventamos valor). "Avaliação" tem neutro explícito quando não há nota.</p>
      <?php else: ?>
        <p class="muted">Sem detalhamento salvo.</p>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Dados disponíveis</h2>
      <div class="kv">
        <div>Marketplace</div><div><?= View::e(View::marketplaceLabel((string) $p['marketplace'])) ?></div>
        <div>ID do marketplace</div><div><?= View::e($p['marketplace_product_id']) ?></div>
        <div>Loja / vendedor</div><div><?= View::e($extra['seller'] ?? '—') ?><?= !empty($extra['official_store']) ? ' · loja oficial' : '' ?></div>
        <div>Categoria (nicho)</div><div><?= View::e($p['category'] ?? '—') ?> <span class="muted">(aderência: <?= View::e($extra['niche_confidence'] ?? 'n/d') ?>)</span></div>
        <div>Categoria de origem</div><div><?= View::e($extra['source_category_label'] ?? '—') ?></div>
        <div>Radares associados</div><div>
          <?php if ($product_radars): foreach ($product_radars as $pr): ?>
            <span class="badge q">📡 <?= View::e($pr['name']) ?><?php if ($primary_radar && $pr['radar_id'] === $primary_radar->id): ?> <span class="muted">(descobriu)</span><?php endif; ?></span>
          <?php endforeach; else: ?><span class="muted">nenhum (produto anterior aos radares)</span><?php endif; ?>
        </div>
        <div>Preço atual / anterior</div><div><?= View::money($p['price_current']) ?> / <?= View::money($p['price_previous']) ?></div>
        <div>Desconto</div><div><?= $p['discount_pct'] !== null ? (int) $p['discount_pct'] . '%' : '—' ?></div>
        <div>Sinal de vendas</div><div><?= View::e(View::salesLabel($p['sales_signal'])) ?> <span class="muted">(faixa textual do ML — não é número)</span></div>
        <div>Avaliação</div><div><?= $p['rating'] !== null ? '★ ' . number_format((float) $p['rating'],1,'.','') : '—' ?><?= $p['rating_count'] !== null ? ' (' . (int) $p['rating_count'] . ')' : '' ?></div>
        <div>Posição / ranking</div><div><?= $p['rank_position'] !== null ? '#' . (int) $p['rank_position'] . ' na página de ofertas' : '—' ?></div>
        <div>Campanha / promo</div><div><?= View::e($p['campaign'] ?? '—') ?></div>
        <div>🎬 Possui vídeo</div><div><?= $p['has_video'] ? 'SIM (has_published_clips)' : 'NÃO' ?></div>
        <div>Comissão</div><div><?= $p['commission_pct'] !== null ? $p['commission_pct'] . '%' : '— (Shopee only)' ?></div>
        <div>URL afiliada</div><div><?= $p['url_affiliate'] ? View::e($p['url_affiliate']) : '— (fase posterior)' ?></div>
        <div>Sinais especiais</div><div><?= $signals ? View::e(implode(', ', $signals)) : '—' ?></div>
        <div>Qualidade do dado</div><div><?= View::e($p['data_quality']) ?> · fonte <?= View::e($p['source']) ?></div>
        <div>Descoberto em</div><div><?= View::e($p['discovered_at']) ?></div>
        <div>Última coleta</div><div><?= View::e($p['last_collected_at']) ?></div>
      </div>
    </div>

    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Histórico de coletas <span class="muted">(<?= count($history) ?> snapshot<?= count($history) === 1 ? '' : 's' ?>)</span></h2>
      <?php if (count($history) > 1): ?>
        <table class="hist">
          <tr><th>Quando</th><th>Preço</th><th>Desc.</th><th>Vendas</th><th>Nota</th><th>Rank</th><th>HOT</th></tr>
          <?php $prev = null; foreach ($history as $h): ?>
            <tr>
              <td><?= View::e(date('d/m H:i', strtotime((string) $h['collected_at']))) ?></td>
              <td><?= View::money($h['price_current']) ?>
                <?php if ($prev && $h['price_current'] !== null && $prev['price_current'] !== null):
                  $d = (float) $h['price_current'] - (float) $prev['price_current'];
                  if (abs($d) >= 0.01): ?>
                    <span class="<?= $d < 0 ? 'updown-down' : 'updown-up' ?>"><?= ($d < 0 ? '▼' : '▲') ?></span>
                  <?php endif; endif; ?>
              </td>
              <td><?= $h['discount_pct'] !== null ? (int) $h['discount_pct'] . '%' : '—' ?></td>
              <td><?= View::e($h['sales_signal'] ?? '—') ?></td>
              <td><?= $h['rating'] !== null ? number_format((float) $h['rating'],1,'.','') : '—' ?></td>
              <td><?= $h['rank_position'] !== null ? '#' . (int) $h['rank_position'] : '—' ?></td>
              <td><strong><?= (int) $h['hot_score'] ?></strong></td>
            </tr>
          <?php $prev = $h; endforeach; ?>
        </table>
      <?php else: ?>
        <p class="muted">Só uma coleta até agora. A tendência aparece a partir da 2ª coleta do mesmo produto (base para E6).</p>
      <?php endif; ?>
    </div>

    <div class="panel" style="margin-top:14px">
      <h2 style="margin-top:0">Trilha editorial</h2>
      <?php if ($timeline): ?>
        <table class="hist">
          <?php foreach ($timeline as $t): ?>
            <tr>
              <td><?= View::e(date('d/m H:i', strtotime((string) $t['created_at']))) ?></td>
              <td><?= View::e($t['from_status'] ?? '—') ?> → <strong><?= View::e($t['to_status']) ?></strong></td>
              <td class="muted"><?= View::e($t['actor']) ?><?= $t['reason'] ? ' · ' . View::e($t['reason']) : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php else: ?><p class="muted">Sem mudanças de status ainda.</p><?php endif; ?>
    </div>
  </div>
</div>
