<?php
use HotRadar\Web\View;
/**
 * @var array<int,\HotRadar\Radar\Radar> $radars
 * @var array<string,int> $counts
 * @var array<string,?string> $last_by_radar
 */
?>
<div style="display:flex;justify-content:space-between;align-items:center">
  <h1>Radares <span class="muted" style="font-size:14px">— seus nichos monitorados</span></h1>
  <a class="btn primary" href="?r=radar.edit">+ Novo radar</a>
</div>
<p class="muted">Cada radar procura produtos nas categorias e com os filtros que você definir. Um mesmo produto pode aparecer em vários radares ao mesmo tempo.</p>

<div class="panel" style="padding:0;overflow-x:auto">
  <table>
    <tr>
      <th>Radar</th><th>Situação</th><th>Marketplace</th><th>Categorias</th>
      <th>Produtos</th><th>Última coleta</th><th style="text-align:right">Ações</th>
    </tr>
    <?php foreach ($radars as $rd):
      $n = (int) ($counts[$rd->slug] ?? 0);
      $cats = implode(', ', array_map(static fn ($c) => $c['label'] ?? $c['id'], $rd->mlCategories));
    ?>
      <tr>
        <td>
          <strong><?= View::e($rd->name) ?></strong>
          <?php if ($rd->requireVideo || $rd->minDiscount !== null || $rd->priceMin !== null): ?>
            <span class="muted" style="font-size:11px;display:block">
              <?= $rd->minDiscount !== null ? 'desconto ≥ ' . (int) $rd->minDiscount . '% · ' : '' ?>
              <?= $rd->priceMin !== null ? 'a partir de ' . View::money($rd->priceMin) . ' · ' : '' ?>
              <?= $rd->requireVideo ? 'só com vídeo' : '' ?>
            </span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($rd->enabled): ?>
            <span class="badge" style="background:var(--ok);color:#fff;border-color:transparent">● Ativo</span>
          <?php else: ?>
            <span class="badge">❚❚ Pausado</span>
          <?php endif; ?>
        </td>
        <td><?= View::e(implode(', ', array_map([View::class, 'marketplaceLabel'], $rd->marketplaces))) ?></td>
        <td class="muted" style="max-width:240px;font-size:12px"><?= View::e($cats ?: '—') ?></td>
        <td><strong><?= $n ?></strong></td>
        <td class="muted" style="font-size:12px"><?= View::ago($last_by_radar[$rd->slug] ?? null) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if (array_intersect($rd->marketplaces, \HotRadar\Radar\Radar::KNOWN_MARKETPLACES) !== []): ?>
            <form method="post" action="?r=radar.collect" style="display:inline">
              <?= View::csrf() ?>
              <input type="hidden" name="id" value="<?= (int) $rd->id ?>">
              <button class="btn primary" type="submit" title="Coleta só este radar; os outros não são afetados. Se algum marketplace não estiver disponível (ex.: Shopee sem credenciais/acesso), o aviso aparece depois de clicar — nada é substituído por Mercado Livre.">Coletar</button>
            </form>
          <?php endif; ?>
          <a class="btn" href="?r=products&radar=<?= View::e($rd->slug) ?>">Ver produtos</a>
          <a class="btn" href="?r=radar.edit&id=<?= (int) $rd->id ?>">Editar</a>
          <form method="post" action="?r=radar.toggle" style="display:inline">
            <?= View::csrf() ?>
            <input type="hidden" name="id" value="<?= (int) $rd->id ?>">
            <button class="btn" type="submit"><?= $rd->enabled ? 'Pausar' : 'Ativar' ?></button>
          </form>
          <a class="btn" href="?r=radar.delete&id=<?= (int) $rd->id ?>" style="border-color:var(--danger);color:var(--danger)">Excluir</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$radars): ?><tr><td colspan="7" class="muted">Nenhum radar. <a href="?r=radar.edit">Criar o primeiro</a>.</td></tr><?php endif; ?>
  </table>
</div>

<p class="muted" style="font-size:12px">💡 "Coletar" roda apenas o radar escolhido — você não precisa pausar os outros.
No <a href="?r=dashboard">Início</a> há o botão "Coletar tudo" que roda todos os radares ativos de uma vez.</p>
