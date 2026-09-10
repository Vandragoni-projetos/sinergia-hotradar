<?php
use HotRadar\Web\View;
/**
 * @var array<int,\HotRadar\Radar\Radar> $radars
 * @var array<string,int> $counts
 */
?>
<div style="display:flex;justify-content:space-between;align-items:center">
  <h1>Radares <span class="muted" style="font-size:14px">— nichos monitorados</span></h1>
  <a href="?r=radar.edit"><button class="primary" type="button">+ Criar Radar</button></a>
</div>
<p class="muted">Cada radar tem configuração própria (categorias, keywords, exclusões, filtros). Vários podem ficar ativos ao mesmo tempo sem misturar produtos — cada produto guarda de qual radar veio.</p>

<div class="grid" style="grid-template-columns:1fr;gap:12px">
  <?php foreach ($radars as $rd): ?>
  <div class="panel">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px">
      <div>
        <h2 style="margin:0 0 4px"><?= View::e($rd->name) ?>
          <span class="badge <?= $rd->enabled ? '' : 'baixo' ?>" style="<?= $rd->enabled ? 'background:var(--ok);color:#fff;border-color:transparent' : '' ?>"><?= $rd->enabled ? 'ATIVO' : 'DESLIGADO' ?></span>
        </h2>
        <div class="muted" style="font-size:12px"><code><?= View::e($rd->slug) ?></code> · <?= (int) ($counts[$rd->slug] ?? 0) ?> produtos coletados</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="?r=radar.edit&id=<?= (int) $rd->id ?>"><button type="button">Editar</button></a>
        <form method="post" action="?r=radar.toggle"><input type="hidden" name="id" value="<?= (int) $rd->id ?>">
          <button type="submit" class="ghost"><?= $rd->enabled ? 'Desativar' : 'Ativar' ?></button></form>
        <form method="post" action="?r=radar.collect"><input type="hidden" name="id" value="<?= (int) $rd->id ?>">
          <button type="submit" class="ok">Rodar coleta</button></form>
      </div>
    </div>
    <div class="kv" style="margin-top:10px;font-size:13px">
      <div>Marketplaces</div><div><?= View::e(implode(', ', $rd->marketplaces)) ?: '—' ?></div>
      <div>Categorias ML</div><div><?php
        echo $rd->mlCategories ? implode(', ', array_map(
          static fn ($c) => View::e($c['id'] . ' · ' . ($c['label'] ?? '')), $rd->mlCategories
        )) : '<span class="muted">nenhuma</span>'; ?></div>
      <div>Keywords adicionais</div><div><?= View::e(implode(', ', $rd->extraKeywords)) ?: '—' ?></div>
      <div>Palavras excluídas</div><div><?= View::e(implode(', ', $rd->excludedWords)) ?: '—' ?></div>
      <div>Páginas / categoria</div><div><?= (int) $rd->pagesPerCategory ?></div>
      <div>Filtros</div><div>
        <?= $rd->minDiscount !== null ? 'desconto ≥ ' . (int) $rd->minDiscount . '%' : '' ?>
        <?= $rd->priceMin !== null ? ' · preço ≥ ' . View::money($rd->priceMin) : '' ?>
        <?= $rd->priceMax !== null ? ' · preço ≤ ' . View::money($rd->priceMax) : '' ?>
        <?= $rd->requireVideo ? ' · exige vídeo' : '' ?>
        <?= ($rd->minDiscount === null && $rd->priceMin === null && $rd->priceMax === null && !$rd->requireVideo) ? '<span class="muted">nenhum</span>' : '' ?>
      </div>
    </div>
    <p style="margin:10px 0 0"><a href="?r=products&radar=<?= View::e($rd->slug) ?>">Ver produtos deste radar na Curadoria →</a></p>
  </div>
  <?php endforeach; ?>
  <?php if (!$radars): ?><div class="panel muted">Nenhum radar cadastrado. <a href="?r=radar.edit">Criar o primeiro</a>.</div><?php endif; ?>
</div>
