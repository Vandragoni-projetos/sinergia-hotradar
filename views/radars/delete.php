<?php
use HotRadar\Web\View;
/**
 * @var \HotRadar\Radar\Radar $radar
 * @var array<string,int> $impact
 */
?>
<p><a href="?r=radars">← Radares</a></p>
<h1>Excluir radar: <?= View::e($radar->name) ?></h1>

<div class="panel" style="max-width:640px">
  <h2 style="margin-top:0">O que será afetado</h2>
  <div class="impact">
    <div>Produtos neste radar</div><div><?= $impact['associados'] ?></div>
    <div>Só neste radar (exclusivos)</div><div><?= $impact['exclusivos'] ?></div>
    <div>Compartilhados com outros radares</div><div><?= $impact['compartilhados'] ?></div>
    <div>Registros de histórico dos exclusivos</div><div><?= $impact['snapshots_exclusivos'] ?></div>
    <div>Produtos aprovados neste radar</div><div><?= $impact['aprovados'] ?></div>
    <div>Produtos com decisão editorial</div><div><?= $impact['com_decisao'] ?></div>
  </div>
</div>

<div class="opt">
  <h3>Opção A — Excluir somente o radar <span class="badge" style="background:var(--ok);color:#fff;border-color:transparent">recomendado</span></h3>
  <p class="muted">Remove a configuração do radar (categorias, palavras-chave, filtros) e a ligação dos produtos com ele.
    <strong>Preserva todos os produtos, o histórico e as decisões.</strong>
    Produtos que também estão em outros radares continuam normalmente.</p>
  <form method="post" action="?r=radar.delete" onsubmit="return confirm('Excluir só o radar &quot;<?= View::e($radar->name) ?>&quot;? Os produtos serão preservados.');">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) $radar->id ?>">
    <input type="hidden" name="mode" value="somente_radar">
    <input type="hidden" name="confirm" value="EXCLUIR">
    <button class="btn" type="submit">Excluir somente o radar</button>
  </form>
</div>

<div class="opt danger">
  <h3>Opção B — Excluir o radar e os produtos exclusivos dele</h3>
  <p class="muted">Além da Opção A, apaga os <strong><?= $impact['exclusivos'] ?> produto(s)</strong> que existem
    <strong>apenas</strong> neste radar, junto com o histórico deles.
    <strong>Nenhum produto compartilhado é apagado</strong> — ele apenas perde a ligação com este radar.
    Esta ação não pode ser desfeita.</p>
  <form method="post" action="?r=radar.delete" onsubmit="return confirm('ATENÇÃO: apagar o radar E <?= $impact['exclusivos'] ?> produto(s) exclusivo(s)? Não dá para desfazer.');">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) $radar->id ?>">
    <input type="hidden" name="mode" value="radar_e_exclusivos">
    <input type="hidden" name="confirm" value="EXCLUIR">
    <button class="btn danger" type="submit" style="background:var(--danger);color:#fff;border-color:transparent">Excluir radar + <?= $impact['exclusivos'] ?> produto(s)</button>
  </form>
</div>

<div class="opt">
  <h3>Alternativa — Limpar dados, manter o radar</h3>
  <p class="muted">Zera os resultados deste radar (remove os produtos exclusivos e as ligações) mas <strong>mantém o radar e toda a configuração</strong>.
    Útil para recomeçar uma coleta do zero.</p>
  <form method="post" action="?r=radar.clear" onsubmit="return confirm('Limpar os dados do radar &quot;<?= View::e($radar->name) ?>&quot;? A configuração é mantida.');">
    <?= View::csrf() ?>
    <input type="hidden" name="id" value="<?= (int) $radar->id ?>">
    <input type="hidden" name="confirm" value="LIMPAR">
    <button class="btn" type="submit">Limpar dados deste radar</button>
  </form>
</div>
