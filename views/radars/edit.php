<?php
use HotRadar\Web\View;
/**
 * @var \HotRadar\Radar\Radar|null $radar
 * @var array<string,string> $catalog  id => rótulo (config/ml_categories.php)
 */
$isNew = $radar === null;
$selected = [];
if ($radar) {
    foreach ($radar->mlCategoryIds() as $id) { $selected[$id] = true; }
}
$val = static fn ($v) => $v === null ? '' : (string) $v;
?>
<p><a href="?r=radars">← Radares</a></p>
<h1><?= $isNew ? 'Criar Radar' : 'Editar Radar: ' . View::e($radar->name) ?></h1>

<form method="post" action="?r=radar.save" class="panel" style="max-width:820px">
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $radar->id ?>"><?php endif; ?>

  <div class="f" style="margin-bottom:14px">
    <label>Nome do radar</label>
    <input type="text" name="name" required value="<?= View::e($radar->name ?? '') ?>"
           placeholder="Casa &amp; Organização" style="width:100%">
    <?php if (!$isNew): ?><span class="muted" style="font-size:12px">slug: <code><?= View::e($radar->slug) ?></code> (imutável)</span><?php endif; ?>
  </div>

  <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
    <input type="checkbox" name="enabled" value="1" <?= (!$radar || $radar->enabled) ? 'checked' : '' ?>>
    Radar ativo (entra na coleta "Coletar agora")
  </label>

  <div class="f" style="margin-bottom:14px">
    <label>Marketplaces deste radar</label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="marketplaces[]" value="mercado_livre" <?= (!$radar || in_array('mercado_livre', $radar->marketplaces, true)) ? 'checked' : '' ?>> Mercado Livre</label>
    <label style="display:flex;gap:8px;align-items:center;opacity:.6"><input type="checkbox" name="marketplaces[]" value="shopee" <?= ($radar && in_array('shopee', $radar->marketplaces, true)) ? 'checked' : '' ?>> Shopee <span class="muted">(coleta só quando a Open API estiver ativa)</span></label>
  </div>

  <div class="f" style="margin-bottom:14px">
    <label>Categorias do Mercado Livre (uma ou várias)</label>
    <div style="border:1px solid var(--border);border-radius:8px;padding:10px;max-height:260px;overflow:auto;display:grid;grid-template-columns:1fr 1fr;gap:4px">
      <?php foreach ($catalog as $id => $lbl): ?>
        <label style="display:flex;gap:7px;align-items:center;font-size:13px">
          <input type="checkbox" name="ml_categories[]" value="<?= View::e($id) ?>" <?= isset($selected[$id]) ? 'checked' : '' ?>>
          <span><code><?= View::e($id) ?></code> <?= View::e($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <span class="muted" style="font-size:12px">IDs vêm de <code>config/ml_categories.php</code> (editável). A configuração ativa fica só neste radar, no banco.</span>
  </div>

  <div class="grid" style="grid-template-columns:1fr 1fr;gap:14px">
    <div class="f">
      <label>Keywords adicionais (uma por linha ou vírgula)</label>
      <textarea name="extra_keywords" rows="4" style="width:100%"><?= View::e(implode("\n", $radar->extraKeywords ?? [])) ?></textarea>
      <span class="muted" style="font-size:12px">Reforçam a aderência ao nicho no HOT SCORE.</span>
    </div>
    <div class="f">
      <label>Palavras proibidas / exclusões</label>
      <textarea name="excluded_words" rows="4" style="width:100%"><?= View::e(implode("\n", $radar->excludedWords ?? [])) ?></textarea>
      <span class="muted" style="font-size:12px">Produto cujo título contém qualquer uma é descartado na coleta.</span>
    </div>
  </div>

  <div class="f" style="margin:14px 0">
    <label>Keywords Shopee (futuro — sem efeito enquanto a Open API estiver desligada)</label>
    <textarea name="shopee_keywords" rows="2" style="width:100%"><?= View::e(implode("\n", $radar->shopeeKeywords ?? [])) ?></textarea>
  </div>

  <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px">
    <div class="f"><label>Páginas / categoria</label>
      <input type="number" name="pages_per_category" min="1" max="10" value="<?= (int) ($radar->pagesPerCategory ?? 3) ?>"></div>
    <div class="f"><label>Desconto mín. %</label>
      <input type="number" name="min_discount" min="0" max="99" value="<?= $val($radar->minDiscount ?? null) ?>" placeholder="—"></div>
    <div class="f"><label>Preço mín. R$</label>
      <input type="number" step="0.01" name="price_min" value="<?= $val($radar->priceMin ?? null) ?>" placeholder="—"></div>
    <div class="f"><label>Preço máx. R$</label>
      <input type="number" step="0.01" name="price_max" value="<?= $val($radar->priceMax ?? null) ?>" placeholder="—"></div>
  </div>

  <label style="display:flex;gap:8px;align-items:center;margin-bottom:16px">
    <input type="checkbox" name="require_video" value="1" <?= ($radar && $radar->requireVideo) ? 'checked' : '' ?>>
    Exigir vídeo (só coleta produtos com <code>has_published_clips</code>)
  </label>

  <button class="primary" type="submit"><?= $isNew ? 'Criar Radar' : 'Salvar' ?></button>
  <?php if (!$isNew): ?>
    <a href="?r=radars" style="margin-left:8px">cancelar</a>
  <?php endif; ?>
</form>

<?php if (!$isNew): ?>
<div class="panel" style="max-width:820px;margin-top:14px;border-color:var(--danger)">
  <h2 style="margin-top:0;color:var(--danger)">Excluir radar</h2>
  <p class="muted">Os produtos já coletados <strong>permanecem</strong> no histórico (guardam o slug). A configuração é removida.</p>
  <form method="post" action="?r=radar.delete" onsubmit="return confirm('Excluir o radar &quot;<?= View::e($radar->name) ?>&quot;?');">
    <input type="hidden" name="id" value="<?= (int) $radar->id ?>">
    <input type="hidden" name="confirm" value="DELETE">
    <button class="danger" type="submit">Excluir definitivamente</button>
  </form>
</div>
<?php endif; ?>
