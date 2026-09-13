<?php
use HotRadar\Web\View;
use HotRadar\Editorial\EditorialStatus;
/**
 * @var int $total @var int $today @var int $with_video @var int $snapshots
 * @var array<string,int> $faixa @var array<string,int> $status
 * @var array<int,array{key:string,n:int}> $by_radar
 * @var array<int,array<string,mixed>> $runs cada linha inclui radar_existe (1/0)
 * @var int $runs_total coletas OPERACIONAIS (radar existe ou nunca teve radar) — não confundir com count($runs), que satura em 12
 * @var int $runs_total_historico total bruto real de hr_collection_runs, incluindo radares já excluídos
 * @var array<int,array{marketplace:string,available:bool,reason:?string,label:string}> $collectors
 * @var array<int,\HotRadar\Radar\Radar> $radars
 */
$countByRadar = [];
foreach ($by_radar as $r) { $countByRadar[$r['key']] = $r['n']; }
$ativos = array_filter($radars, static fn ($r) => $r->enabled && $r->hasMarketplace('mercado_livre'));
?>
<h1>Início</h1>

<div class="grid cols-4">
  <div class="stat"><div class="k">Produtos monitorados</div><div class="v"><?= $total ?></div></div>
  <div class="stat"><div class="k">Descobertos hoje</div><div class="v"><?= $today ?></div></div>
  <div class="stat"><div class="k">🎬 Com vídeo</div><div class="v"><?= $with_video ?></div></div>
  <div class="stat"><div class="k">Coletas registradas</div><div class="v"><?= $runs_total ?></div>
    <?php if ($runs_total_historico > $runs_total): ?>
      <div class="muted" style="font-size:11px">Histórico total (com radares já excluídos): <?= $runs_total_historico ?></div>
    <?php endif; ?>
  </div>
</div>

<h2>Classificação dos produtos</h2>
<div class="grid cols-4">
  <div class="stat"><div class="k">🔥 Muito quentes</div><div class="v"><?= $faixa['muito_quente'] ?? 0 ?></div></div>
  <div class="stat"><div class="k">🟠 Bons candidatos</div><div class="v"><?= $faixa['bom'] ?? 0 ?></div></div>
  <div class="stat"><div class="k">🟡 Analisar</div><div class="v"><?= $faixa['analisar'] ?? 0 ?></div></div>
  <div class="stat"><div class="k">⚪ Baixa prioridade</div><div class="v"><?= $faixa['baixo'] ?? 0 ?></div></div>
</div>

<div class="grid" style="grid-template-columns:1.4fr 1fr;gap:14px;margin-top:14px">
  <div class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <h2 style="margin:0">Seus radares</h2>
      <a class="btn" href="?r=radars">Gerenciar</a>
    </div>
    <table style="margin-top:8px">
      <tr><th>Radar</th><th>Situação</th><th>Produtos</th><th style="text-align:right">Coletar</th></tr>
      <?php foreach ($radars as $rd): ?>
        <tr>
          <td><a href="?r=radar.edit&id=<?= (int) $rd->id ?>"><?= View::e($rd->name) ?></a></td>
          <td>
            <?php if ($rd->enabled): ?>
              <span class="badge" style="background:var(--ok);color:#fff;border-color:transparent">● Ativo</span>
            <?php else: ?>
              <span class="badge">❚❚ Pausado</span>
            <?php endif; ?>
          </td>
          <td><strong><?= (int) ($countByRadar[$rd->slug] ?? 0) ?></strong></td>
          <td style="text-align:right">
            <?php if ($rd->hasMarketplace('mercado_livre')): ?>
              <form method="post" action="?r=radar.collect" style="display:inline"><?= View::csrf() ?>
                <input type="hidden" name="id" value="<?= (int) $rd->id ?>">
                <button class="btn primary" type="submit">Coletar este radar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$radars): ?><tr><td colspan="4" class="muted">Nenhum radar. <a href="?r=radar.edit">Criar o primeiro</a>.</td></tr><?php endif; ?>
    </table>

    <div style="border-top:1px solid var(--border);margin-top:10px;padding-top:10px">
      <form method="post" action="?r=collect.run" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= View::csrf() ?>
        <button class="primary" type="submit" <?= $ativos ? '' : 'disabled' ?>>Coletar tudo (<?= count($ativos) ?> radar(es) ativo(s))</button>
        <label style="display:flex;gap:6px;align-items:center;font-size:12px" class="muted"><input type="checkbox" name="dry_run" value="1"> simular (não salva)</label>
      </form>
    </div>
  </div>

  <div class="panel">
    <h2 style="margin-top:0">Marketplaces</h2>
    <table>
      <?php foreach ($collectors as $c): ?>
        <tr>
          <td><strong><?= View::e(View::marketplaceLabel($c['marketplace'])) ?></strong></td>
          <td style="text-align:right">
            <span class="badge" style="<?= $c['available'] ? 'background:var(--ok);color:#fff;border-color:transparent' : '' ?>">
              <?= $c['available'] ? 'Funcionando' : 'Indisponível' ?></span>
          </td>
        </tr>
        <?php if (!$c['available']): ?>
          <tr><td colspan="2" class="muted" style="font-size:12px">
            <?= $c['marketplace'] === 'shopee'
                ? 'A Shopee ainda não liberou o acesso automático para esta conta. Configure em Configurações → Marketplaces quando disponível.'
                : 'Temporariamente indisponível.' ?>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </table>

    <h2>Situação editorial</h2>
    <table>
      <?php foreach (EditorialStatus::active() as $s): ?>
        <tr><td><?= View::e(EditorialStatus::label($s)) ?></td><td style="text-align:right"><?= (int) ($status[$s] ?? 0) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<h2>Últimas coletas</h2>
<div class="panel" style="overflow-x:auto">
  <table>
    <tr><th>Quando</th><th>Radar</th><th>Situação</th><th>Novos</th><th>Atualizados</th><th>Observações</th></tr>
    <?php foreach ($runs as $run):
      $errs = json_decode((string) ($run['errors'] ?? '[]'), true) ?: [];
      $reduzida = false;
      foreach ($errs as $e) { if (str_contains((string) $e, 'fallback') || str_contains((string) $e, 'reduzid')) { $reduzida = true; } }
    ?>
      <tr>
        <td><?= View::ago($run['started_at']) ?></td>
        <td>
          <?= View::e($run['radar_slug'] ?? '—') ?>
          <?php if ($run['radar_slug'] && !(int) ($run['radar_existe'] ?? 1)): ?>
            <span class="muted" style="font-size:11px"> — excluído</span>
          <?php endif; ?>
        </td>
        <td>
          <?= $run['status'] === 'ok'
              ? '<span class="badge" style="background:var(--ok);color:#fff;border-color:transparent">OK</span>'
              : '<span class="badge" style="background:var(--danger);color:#fff;border-color:transparent">erro</span>' ?>
          <?= $run['mode'] === 'dry_run' ? ' <span class="muted">(simulação)</span>' : '' ?>
        </td>
        <td><?= (int) $run['products_new'] ?></td>
        <td><?= (int) $run['products_updated'] ?></td>
        <td class="muted" style="font-size:12px">
          <?= $reduzida ? 'coleta reduzida (site com limitação no momento) — dados anteriores preservados' : ($errs ? 'com avisos' : '—') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?><tr><td colspan="6" class="muted">Nenhuma coleta ainda. Use "Coletar este radar" ou "Coletar tudo" acima.</td></tr><?php endif; ?>
  </table>
</div>
