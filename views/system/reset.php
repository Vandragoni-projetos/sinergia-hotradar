<?php
use HotRadar\Web\View;
/**
 * @var array<string,int> $impact  chave = nome da tabela, valor = quantidade atual
 */
$labels = [
    'hr_products' => 'Produtos monitorados',
    'hr_product_snapshots' => 'Snapshots (histórico de coletas)',
    'hr_product_radars' => 'Associações produto × radar',
    'hr_editorial_events' => 'Eventos editoriais (aprovações/descartes)',
    'hr_collection_runs' => 'Coletas registradas',
    'hr_radars' => 'Radares configurados',
];
$total = array_sum($impact);
?>
<p><a href="?r=config&sub=avancado">← Configurações</a></p>
<h1>⚠️ Zerar tudo — começar uma nova rodada do zero</h1>

<div class="panel" style="max-width:640px">
  <p>Esta ação é <strong>diferente</strong> de excluir ou limpar um radar. Ela apaga
    <strong>TODOS os radares e TODOS os produtos do sistema</strong>, sem exceção — não só de um radar específico.</p>
  <p class="muted" style="font-size:13px">
    <strong>É preservado:</strong> suas configurações gerais, os pesos do Hot Score, as credenciais/autenticação,
    as configurações de marketplace, e o histórico de auditoria (o registro de que este reset aconteceu).
    Nada disso é apagado por esta ação.
  </p>
</div>

<div class="panel" style="max-width:640px;margin-top:14px">
  <h2 style="margin-top:0">O que será apagado agora</h2>
  <div class="impact">
    <?php foreach ($labels as $table => $label): ?>
      <div><?= View::e($label) ?></div><div><strong><?= (int) ($impact[$table] ?? 0) ?></strong></div>
    <?php endforeach; ?>
  </div>
  <?php if ($total === 0): ?>
    <p class="muted" style="margin-top:10px">O sistema já está vazio — não há nada para apagar no momento.</p>
  <?php endif; ?>
</div>

<div class="opt danger" style="max-width:640px;margin-top:14px">
  <h3 style="margin-top:0">Confirmação</h3>
  <p class="muted">Esta ação <strong>não pode ser desfeita</strong>. Para confirmar, digite exatamente
    <code>ZERAR TUDO</code> no campo abaixo.</p>
  <form method="post" action="?r=system.reset" id="system-reset-form"
        onsubmit="return confirm('Última confirmação: isso apaga TODOS os produtos, radares e coletas do HOTRADAR. Não dá para desfazer. Confirmar?');">
    <?= View::csrf() ?>
    <input type="text" name="confirm" id="system-reset-confirm" autocomplete="off"
           placeholder="Digite ZERAR TUDO" style="width:260px" oninput="document.getElementById('system-reset-btn').disabled = (this.value !== 'ZERAR TUDO');">
    <button class="btn danger" type="submit" id="system-reset-btn" disabled
            style="background:var(--danger);color:#fff;border-color:transparent;margin-left:8px">Zerar tudo agora</button>
  </form>
</div>
