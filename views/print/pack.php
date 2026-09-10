<?php
use HotRadar\Web\View;
/**
 * @var array<int,array<string,mixed>> $rows
 * @var ?string $radar_name
 * @var bool $only_approved
 * @var bool $auto
 */
$titulo = ($radar_name ? $radar_name : 'Produtos') . ($only_approved ? ' — Aprovados' : '');
?>
<div class="printbar">
  <button onclick="window.print()">Salvar como PDF / Imprimir</button>
  <a href="javascript:history.back()">← voltar</a>
  <span class="muted" style="color:#bbb;font-size:12px"><?= count($rows) ?> produto(s)</span>
</div>

<div class="sheet">
  <div class="ph">
    <div class="b">SINERGIA <span>HOTRADAR</span></div>
    <div style="font-size:12px;color:#666"><?= View::e(date('d/m/Y')) ?></div>
  </div>

  <h1><?= count($rows) ?> Produtos<?= $radar_name ? ' — ' . View::e($radar_name) : '' ?><?= $only_approved ? ' (Aprovados)' : '' ?></h1>
  <p style="color:#666;font-size:13px;margin-top:0">Seleção ordenada por Hot Score. Gerado pelo SINERGIA HOTRADAR.</p>

  <h2 style="font-size:14px;margin:16px 0 4px">Resumo</h2>
  <ol style="font-size:13px;columns:2;margin-top:4px">
    <?php foreach ($rows as $p): ?>
      <li><?= View::faixaEmoji($p['hot_faixa']) ?> <strong><?= (int) $p['hot_score'] ?></strong> —
        <?= View::e(mb_substr((string) $p['title'], 0, 55)) ?></li>
    <?php endforeach; ?>
  </ol>

  <h2 style="font-size:14px;margin:22px 0 4px">Fichas</h2>
  <?php foreach ($rows as $p): ?>
    <div class="packitem">
      <?php if ($p['image_url']): ?><img src="<?= View::e($p['image_url']) ?>" alt=""><?php endif; ?>
      <div style="flex:1">
        <div style="font-weight:700;margin-bottom:4px"><?= View::e($p['title']) ?></div>
        <div class="facts" style="grid-template-columns:120px 1fr">
          <div>Hot Score</div><div><?= View::faixaEmoji($p['hot_faixa']) ?> <?= (int) $p['hot_score'] ?>/100 · <?= View::e(View::faixaNome($p['hot_faixa'])) ?></div>
          <div>Preço</div><div><strong><?= View::money($p['price_current']) ?></strong>
            <?= $p['price_previous'] ? ' <s style="color:#999">' . View::money($p['price_previous']) . '</s>' : '' ?>
            <?= $p['discount_pct'] !== null ? ' · ' . (int) $p['discount_pct'] . '% OFF' : '' ?></div>
          <div>Avaliação</div><div><?= $p['rating'] !== null ? '★ ' . number_format((float) $p['rating'], 1, ',', '') : 'Não informada' ?></div>
          <div>Procura</div><div><?= View::e(View::vendasNome($p['sales_signal'])) ?></div>
          <div>Vídeo</div><div><?= ((int) $p['has_video']) ? 'Sim' : 'Não' ?></div>
          <div>Marketplace</div><div><?= View::e(View::marketplaceLabel((string) $p['marketplace'])) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">Nenhum produto selecionado.</p><?php endif; ?>

  <div class="foot">SINERGIA HOTRADAR — dados de fontes públicas do marketplace no momento da coleta. Nada estimado.</div>
</div>
<?php if ($auto): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),400));</script><?php endif; ?>
