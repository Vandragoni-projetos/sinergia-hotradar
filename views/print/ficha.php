<?php
use HotRadar\Web\View;
use HotRadar\Score\ScoreBreakdown;
/**
 * @var array<string,mixed> $p
 * @var ScoreBreakdown|null $breakdown
 * @var array<int,array<string,mixed>> $radares
 * @var bool $auto
 */
$radNames = implode(', ', array_map(static fn ($r) => $r['name'], $radares)) ?: '—';
?>
<div class="printbar">
  <button onclick="window.print()">Salvar como PDF / Imprimir</button>
  <a href="?r=product&id=<?= (int) $p['id'] ?>">← voltar à ficha</a>
  <span class="muted" style="color:#bbb;font-size:12px">Use "Salvar como PDF" na janela de impressão.</span>
</div>

<div class="sheet commercial">
  <div class="ph">
    <div class="b">SINERGIA <span>HOTRADAR</span></div>
    <div style="font-size:12px;color:#666">Análise de produto · <?= View::e(date('d/m/Y H:i')) ?></div>
  </div>

  <h1><?= View::e($p['title']) ?></h1>

  <div class="prod">
    <?php if ($p['image_url']): ?><img src="<?= View::e($p['image_url']) ?>" alt=""><?php endif; ?>
    <div style="flex:1">
      <div class="scorebox">
        <div><span class="n"><?= View::faixaEmoji($p['hot_faixa']) ?> <?= (int) $p['hot_score'] ?></span> <span style="color:#666">/100</span></div>
        <div style="font-weight:700"><?= View::e(View::faixaNome($p['hot_faixa'])) ?></div>
      </div>
      <div class="facts">
        <div>Marketplace</div><div><?= View::e(View::marketplaceLabel((string) $p['marketplace'])) ?></div>
        <div>Radar / nicho</div><div><?= View::e($radNames) ?></div>
        <div>Preço atual</div><div><strong><?= View::money($p['price_current']) ?></strong></div>
        <div>Preço anterior</div><div><?= $p['price_previous'] ? '<s>' . View::money($p['price_previous']) . '</s>' : '—' ?></div>
        <div>Desconto</div><div><?= $p['discount_pct'] !== null ? (int) $p['discount_pct'] . '%' : '—' ?></div>
        <div>Avaliação</div><div><?= $p['rating'] !== null ? '★ ' . number_format((float) $p['rating'], 1, ',', '') : 'Não informada' ?></div>
        <div>Procura / vendas</div><div><?= View::e(View::vendasNome($p['sales_signal'])) ?></div>
        <div>Tem vídeo</div><div><?= ((int) $p['has_video']) ? 'Sim' : 'Não' ?></div>
        <div>Data da análise</div><div><?= View::e(View::dataCurta($p['last_collected_at'])) ?></div>
      </div>
    </div>
  </div>

  <?php if ($breakdown): ?>
    <h2 style="font-size:15px;margin:18px 0 6px">Por que este Hot Score</h2>
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <?php foreach ($breakdown->components as $c): ?>
        <tr style="border-bottom:1px solid #eee">
          <td style="padding:5px 0"><?= View::e($c['label']) ?></td>
          <td style="padding:5px 0;color:#666"><?= View::e(View::fatorDetalhe((string) $c["detail"])) ?></td>
          <td style="padding:5px 0;text-align:right;font-weight:700;width:60px"><?= (int) $c['points'] ?>/<?= (int) $c['max'] ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td style="padding:6px 0;font-weight:800">TOTAL</td><td></td>
        <td style="padding:6px 0;text-align:right;font-weight:800"><?= $breakdown->total ?>/<?= $breakdown->maxTotal ?></td></tr>
    </table>
  <?php endif; ?>

  <div class="foot">
    SINERGIA HOTRADAR — os dados exibidos foram coletados de fontes públicas do marketplace no momento da análise.
    Campos "não informado" indicam ausência de dado na fonte; nada é estimado.
    <?= $p['url_original'] ? '<br>Link: ' . View::e($p['url_original']) : '' ?>
  </div>
</div>
<?php if ($auto): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script><?php endif; ?>
