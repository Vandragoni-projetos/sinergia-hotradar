<?php
use HotRadar\Score\ScoreBreakdown;
use HotRadar\Web\View;
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,\HotRadar\Radar\Radar> $radars
 * @var string $radar_slug
 * @var int $scored_count
 * @var string $active_version
 * @var \HotRadar\Score\HotScoreV2Config $v2_config
 */
?>
<h1>Hot Score V1 × V2 <span class="muted" style="font-weight:400;font-size:14px">(diagnóstico — shadow mode)</span></h1>

<div class="panel" style="margin-bottom:14px">
  <p style="margin:0 0 6px">
    Fonte oficial em uso pelo painel: <strong><?= View::e($active_version) ?></strong> — a V2 ainda
    <strong>não está ativa</strong>; esta tela é só para comparação.
  </p>
  <p class="muted" style="font-size:12px;margin:0">
    <?= (int) $scored_count ?> associação(ões) produto×radar já têm HOT SCORE V2 calculado.
    Para (re)calcular: <code>php bin/hr.php hotscore:shadow-v2</code> (não recoleta, não altera a V1, não muda status editorial).
    Fórmula V2 vigente: soma máxima de <strong><?= $v2_config->totalMax() ?></strong> (ideal 100),
    versão <code><?= View::e($v2_config->data['version'] ?? 'v2') ?></code>.
  </p>
</div>

<form method="get" style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
  <input type="hidden" name="r" value="hotscore.compare">
  <label class="muted" style="font-size:13px">Radar:</label>
  <select name="radar" onchange="this.form.submit()">
    <option value="">— todos —</option>
    <?php foreach ($radars as $r): ?>
      <option value="<?= View::e($r->slug) ?>" <?= $radar_slug === $r->slug ? 'selected' : '' ?>><?= View::e($r->name) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<div class="panel" style="overflow-x:auto">
  <table>
    <tr>
      <th>Produto</th><th>Radar</th>
      <th style="text-align:right">V1</th><th>Faixa V1</th>
      <th style="text-align:right">V2</th><th>Faixa V2</th>
      <th style="text-align:right">Δ</th>
      <th>Aderência V2</th>
      <th>Principais componentes V2</th>
    </tr>
    <?php foreach ($rows as $row):
        $bd = ScoreBreakdown::fromJson($row['context_breakdown'] ?? null);
        $v1 = $row['v1_score'] !== null ? (int) $row['v1_score'] : null;
        $v2 = $row['context_score'] !== null ? (int) $row['context_score'] : null;
        $delta = ($v1 !== null && $v2 !== null) ? $v2 - $v1 : null;
    ?>
      <tr>
        <td><a href="?r=product&id=<?= (int) $row['product_id'] ?>"><?= View::e(mb_substr((string) $row['product_title'], 0, 55)) ?></a></td>
        <td><?= View::e($row['radar_name']) ?></td>
        <td style="text-align:right"><?= $v1 ?? '—' ?></td>
        <td><?= View::e(View::faixaNome($row['v1_faixa'] ?? null)) ?></td>
        <td style="text-align:right"><strong><?= $v2 ?? '—' ?></strong></td>
        <td><?= $row['context_faixa'] ? View::e(View::faixaNome((string) $row['context_faixa'])) : '<span class="muted">não calculado</span>' ?></td>
        <td style="text-align:right">
          <?php if ($delta === null): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <span style="color:<?= $delta > 0 ? 'var(--ok)' : ($delta < 0 ? 'var(--danger)' : 'inherit') ?>"><?= $delta > 0 ? '+' . $delta : $delta ?></span>
          <?php endif; ?>
        </td>
        <td><?= $row['adherence_level'] ? View::e(ucfirst((string) $row['adherence_level'])) . ' (' . (int) $row['adherence_points'] . ')' : '<span class="muted">—</span>' ?></td>
        <td class="muted" style="font-size:12px">
          <?php if ($bd): ?>
            <?php foreach ($bd->components as $c): ?>
              <?= View::e($c['key']) ?>=<?= (int) $c['points'] ?>&nbsp;
            <?php endforeach; ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="muted">Nenhuma associação produto×radar encontrada<?= $radar_slug ? ' para este radar' : '' ?>.</td></tr>
    <?php endif; ?>
  </table>
</div>

<p class="muted" style="font-size:12px;margin-top:10px">
  Mostrando até 500 linhas, ordenadas por score V2 (as ainda não calculadas aparecem por último).
  Esta tela não altera nenhum dado — é só leitura.
</p>
