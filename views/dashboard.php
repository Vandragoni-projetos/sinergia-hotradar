<?php
use HotRadar\Web\View;
use HotRadar\Editorial\EditorialStatus;
/**
 * @var int $total @var int $today @var int $with_video @var int $snapshots
 * @var array<string,int> $faixa @var array<string,int> $status
 * @var array<int,array{key:string,n:int}> $by_radar
 * @var array<int,array<string,mixed>> $runs
 * @var array<int,array{marketplace:string,available:bool,reason:?string,label:string}> $collectors
 * @var array<int,\HotRadar\Radar\Radar> $radars
 */
$countByRadar = [];
foreach ($by_radar as $r) { $countByRadar[$r['key']] = $r['n']; }
?>
<h1>Dashboard</h1>

<div class="grid cols-4">
  <div class="stat"><div class="k">Produtos</div><div class="v"><?= $total ?></div></div>
  <div class="stat"><div class="k">📥 Descobertos hoje</div><div class="v"><?= $today ?></div></div>
  <div class="stat"><div class="k">🎬 Com vídeo</div><div class="v"><?= $with_video ?></div></div>
  <div class="stat"><div class="k">Snapshots (histórico)</div><div class="v"><?= $snapshots ?></div></div>
</div>

<h2>HOT SCORE — distribuição</h2>
<div class="grid cols-4">
  <div class="stat"><div class="k">🔥 Muito quente</div><div class="v"><?= $faixa['muito_quente'] ?? 0 ?></div></div>
  <div class="stat"><div class="k">🟠 Bom candidato</div><div class="v"><?= $faixa['bom'] ?? 0 ?></div></div>
  <div class="stat"><div class="k">🟡 Analisar</div><div class="v"><?= $faixa['analisar'] ?? 0 ?></div></div>
  <div class="stat"><div class="k">⚪ Baixa prioridade</div><div class="v"><?= $faixa['baixo'] ?? 0 ?></div></div>
</div>

<div class="grid cols-3" style="margin-top:14px">
  <div class="panel">
    <h2 style="margin-top:0">Marketplaces</h2>
    <table>
      <?php foreach ($collectors as $c): ?>
        <tr>
          <td><strong><?= View::e(View::marketplaceLabel($c['marketplace'])) ?></strong></td>
          <td style="text-align:right">
            <span class="badge" style="<?= $c['available'] ? 'background:var(--ok);color:#fff;border-color:transparent' : '' ?>">
              <?= $c['available'] ? 'ATIVO' : 'DESLIGADO' ?>
            </span>
          </td>
        </tr>
        <tr><td colspan="2" class="muted" style="font-size:12px"><?= View::e($c['label']) ?><?= (!$c['available'] && $c['reason']) ? ' — ' . View::e($c['reason']) : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="panel">
    <h2 style="margin-top:0">Radares</h2>
    <table>
      <?php foreach ($radars as $rd): ?>
        <tr>
          <td><a href="?r=radar.edit&id=<?= (int) $rd->id ?>"><?= View::e($rd->name) ?></a></td>
          <td style="text-align:right">
            <span class="badge <?= $rd->enabled ? '' : 'baixo' ?>" style="<?= $rd->enabled ? 'background:var(--ok);color:#fff;border-color:transparent' : '' ?>"><?= $rd->enabled ? 'ATIVO' : 'off' ?></span>
            <span class="muted"><?= (int) ($countByRadar[$rd->slug] ?? 0) ?> prod.</span>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$radars): ?><tr><td colspan="2" class="muted">Nenhum radar. <a href="?r=radar.edit">Criar Radar</a></td></tr><?php endif; ?>
    </table>
    <p style="margin-bottom:0"><a href="?r=radars">Gerenciar radares →</a></p>
  </div>

  <div class="panel">
    <h2 style="margin-top:0">Status editorial</h2>
    <table>
      <?php foreach (EditorialStatus::active() as $s): ?>
        <tr><td><?= View::e(EditorialStatus::label($s)) ?></td><td style="text-align:right"><?= (int) ($status[$s] ?? 0) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <form method="post" action="?r=collect.run" style="margin-top:12px">
      <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><input type="checkbox" name="dry_run" value="1"> dry-run</label>
      <button class="primary" type="submit">Coletar agora (todos os radares ativos)</button>
    </form>
  </div>
</div>

<h2>Últimas coletas</h2>
<div class="panel">
  <table>
    <tr><th>Quando</th><th>Radar</th><th>Marketplace</th><th>Modo</th><th>Status</th><th>Pág.</th><th>Cards</th><th>Novos</th><th>Atual.</th><th>Snap.</th><th>Erros</th></tr>
    <?php foreach ($runs as $run):
      $errs = json_decode((string) ($run['errors'] ?? '[]'), true) ?: []; ?>
      <tr>
        <td><?= View::ago($run['started_at']) ?></td>
        <td><?= View::e($run['radar_slug'] ?? '—') ?></td>
        <td><?= View::e(View::marketplaceLabel((string) $run['marketplace'])) ?></td>
        <td><?= View::e($run['mode']) ?></td>
        <td><?= View::e($run['status']) ?></td>
        <td><?= (int) $run['pages_fetched'] ?></td>
        <td><?= (int) $run['cards_seen'] ?></td>
        <td><?= (int) $run['products_new'] ?></td>
        <td><?= (int) $run['products_updated'] ?></td>
        <td><?= (int) $run['snapshots_written'] ?></td>
        <td class="muted" style="font-size:11px"><?= $errs ? View::e(implode(' | ', array_slice($errs, 0, 2))) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?><tr><td colspan="11" class="muted">Nenhuma coleta ainda.</td></tr><?php endif; ?>
  </table>
</div>
