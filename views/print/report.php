<?php
use HotRadar\Web\View;
/**
 * @var ?string $radar_name
 * @var array<string,mixed> $last_run
 * @var array<string,int> $faixa @var array<string,int> $status_dist
 * @var array<int,array<string,mixed>> $top_scores $score_up $score_down $price_drops $with_video
 * @var array<int,array{key:string,n:int}> $by_category
 * @var bool $auto
 */
$lista = static function (array $rows, string $extraKey = 'hot_score', string $prefix = ''): string {
    if (!$rows) {
        return '<p style="color:#999;font-size:13px">Sem dados.</p>';
    }
    $h = '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    foreach ($rows as $r) {
        $v = $r[$extraKey] ?? ($r['last_hot'] ?? '');
        $h .= '<tr style="border-bottom:1px solid #eee"><td style="padding:4px 0">'
            . View::e(mb_substr((string) $r['title'], 0, 70))
            . '</td><td style="padding:4px 0;text-align:right;font-weight:700">' . $prefix . View::e((string) $v) . '</td></tr>';
    }
    return $h . '</table>';
};
?>
<div class="printbar">
  <button onclick="window.print()">Salvar como PDF / Imprimir</button>
  <a href="javascript:history.back()">← voltar</a>
</div>

<div class="sheet">
  <div class="ph">
    <div class="b">SINERGIA <span>HOTRADAR</span></div>
    <div style="font-size:12px;color:#666"><?= View::e(date('d/m/Y H:i')) ?></div>
  </div>
  <h1>Relatório<?= $radar_name ? ' — ' . View::e($radar_name) : ' — Geral' ?></h1>

  <?php if ($last_run['exists']): $r = $last_run['run']; ?>
    <p style="font-size:13px;color:#444">Última coleta: <?= View::e(View::dataCurta($r['started_at'])) ?> ·
      <?= (int) $r['products_new'] ?> novos · <?= (int) $r['products_updated'] ?> atualizados.</p>
  <?php endif; ?>

  <h2 style="font-size:14px;margin:16px 0 4px">Classificação dos produtos</h2>
  <p style="font-size:13px">🔥 Muito quente: <strong><?= $faixa['muito_quente'] ?? 0 ?></strong> ·
    🟠 Bom: <strong><?= $faixa['bom'] ?? 0 ?></strong> ·
    🟡 Analisar: <strong><?= $faixa['analisar'] ?? 0 ?></strong> ·
    ⚪ Baixa: <strong><?= $faixa['baixo'] ?? 0 ?></strong></p>

  <h2 style="font-size:14px;margin:16px 0 4px">Top Hot Scores</h2>
  <?= $lista(array_slice($top_scores, 0, 20)) ?>

  <h2 style="font-size:14px;margin:16px 0 4px">Produtos que subiram de score</h2>
  <?= $lista($score_up, 'delta', '+') ?>

  <h2 style="font-size:14px;margin:16px 0 4px">Produtos que caíram de score</h2>
  <?= $lista($score_down, 'delta') ?>

  <h2 style="font-size:14px;margin:16px 0 4px">Maiores quedas de preço</h2>
  <?= $lista($price_drops, 'drop_pct', '-') ?>

  <h2 style="font-size:14px;margin:16px 0 4px">Produtos com vídeo</h2>
  <?= $lista(array_slice($with_video, 0, 20)) ?>

  <h2 style="font-size:14px;margin:16px 0 4px">Por nicho</h2>
  <table style="font-size:13px">
    <?php foreach ($by_category as $c): ?>
      <tr><td style="padding:3px 12px 3px 0"><?= View::e($c['key']) ?></td><td style="font-weight:700"><?= (int) $c['n'] ?></td></tr>
    <?php endforeach; ?>
  </table>

  <div class="foot">SINERGIA HOTRADAR — comparações baseadas no histórico de coletas. Números determinísticos; nada estimado por IA.</div>
</div>
<?php if ($auto): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),400));</script><?php endif; ?>
