<?php
use HotRadar\Web\View;
/**
 * @var \HotRadar\Radar\Radar|null $radar
 * @var array<string,string> $catalog  id => rótulo (config/ml_categories.php)
 * @var bool $shopee_available  true só quando credenciais + acesso à Open API estão prontos
 */
$isNew = $radar === null;
$selected = [];
if ($radar) {
    foreach ($radar->mlCategoryIds() as $id) { $selected[$id] = true; }
}
$val = static fn ($v) => $v === null ? '' : (string) $v;
$mlChecked = $radar && in_array('mercado_livre', $radar->marketplaces, true);
$shopeeChecked = $radar && in_array('shopee', $radar->marketplaces, true);
$shopeeOnlyInitial = $shopeeChecked && !$mlChecked;
?>
<p><a href="?r=radars">← Radares</a></p>
<h1><?= $isNew ? 'Criar Radar' : 'Editar Radar: ' . View::e($radar->name) ?></h1>

<form method="post" action="?r=radar.save" class="panel" style="max-width:820px">
  <?= View::csrf() ?>
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
    <label>Marketplaces deste radar <span class="muted" style="font-size:12px">(marque ao menos um — nenhum vem pré-selecionado)</span></label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="mp_ml" name="marketplaces[]" value="mercado_livre" <?= $mlChecked ? 'checked' : '' ?>> Mercado Livre</label>
    <label style="display:flex;gap:8px;align-items:center<?= $shopee_available ? '' : ';opacity:.6' ?>">
      <input type="checkbox" id="mp_shopee" name="marketplaces[]" value="shopee" <?= $shopeeChecked ? 'checked' : '' ?>> Shopee
      <?php if ($shopee_available): ?>
        <span class="badge" style="background:var(--ok);color:#fff;border-color:transparent;font-size:11px">disponível</span>
      <?php else: ?>
        <span class="muted">(a coleta só roda quando a Open API estiver ativa — você pode marcar agora e a coleta começa a valer quando a Shopee liberar o acesso)</span>
      <?php endif; ?>
    </label>
  </div>

  <div class="f" style="margin-bottom:14px" id="section-ml-categories" <?= $shopeeOnlyInitial ? 'hidden' : '' ?>>
    <label>Categorias do Mercado Livre (uma ou várias)</label>
    <div style="border:1px solid var(--border);border-radius:8px;padding:10px;max-height:260px;overflow:auto;display:grid;grid-template-columns:1fr 1fr;gap:4px">
      <?php foreach ($catalog as $id => $lbl): ?>
        <label style="display:flex;gap:7px;align-items:center;font-size:13px">
          <input type="checkbox" name="ml_categories[]" value="<?= View::e($id) ?>" <?= isset($selected[$id]) ? 'checked' : '' ?> <?= $shopeeOnlyInitial ? 'disabled' : '' ?>>
          <span><code><?= View::e($id) ?></code> <?= View::e($lbl) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <span class="muted" style="font-size:12px">IDs vêm de <code>config/ml_categories.php</code> (editável). A configuração ativa fica só neste radar, no banco. Usado <strong>somente</strong> na coleta do Mercado Livre — a Shopee não lê este campo.</span>
  </div>

  <div class="grid" style="grid-template-columns:1fr 1fr;gap:14px">
    <div class="f" id="section-extra-keywords" <?= $shopeeOnlyInitial ? 'hidden' : '' ?>>
      <label>Keywords adicionais (Mercado Livre) <span class="muted" style="font-size:12px">(uma por linha ou vírgula)</span></label>
      <textarea name="extra_keywords" rows="4" style="width:100%" <?= $shopeeOnlyInitial ? 'disabled' : '' ?>><?= View::e(implode("\n", $radar->extraKeywords ?? [])) ?></textarea>
      <span class="muted" style="font-size:12px">Reforçam a aderência ao nicho no HOT SCORE — usado somente na coleta do Mercado Livre. Não afeta a Shopee.</span>
    </div>
    <div class="f">
      <label>Palavras proibidas / exclusões</label>
      <textarea name="excluded_words" rows="4" style="width:100%"><?= View::e(implode("\n", $radar->excludedWords ?? [])) ?></textarea>
      <span class="muted" style="font-size:12px">Produto cujo título contém qualquer uma é descartado na coleta — vale para Mercado Livre e Shopee.</span>
    </div>
  </div>

  <div class="f" style="margin:14px 0" id="section-shopee-keywords" <?= $shopeeChecked ? '' : 'hidden' ?>>
    <label>Keywords Shopee</label>
    <textarea name="shopee_keywords" rows="2" style="width:100%" <?= $shopeeChecked ? '' : 'disabled' ?>><?= View::e(implode("\n", $radar->shopeeKeywords ?? [])) ?></textarea>
    <span class="muted" style="font-size:12px">A consulta oficial usada hoje (<code>productOfferV2</code>) não aceita filtro por palavra-chave — preencher aqui <strong>não altera</strong> o que é buscado nem filtrado. Guardado para quando/se a API passar a suportar isso.</span>
  </div>

  <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px">
    <div class="f"><label id="label-pages"><?= $shopeeOnlyInitial ? 'Páginas do feed' : 'Páginas / categoria' ?></label>
      <input type="number" name="pages_per_category" min="1" max="10" value="<?= (int) ($radar->pagesPerCategory ?? 3) ?>">
      <span class="muted" style="font-size:11px" id="help-pages"><?php if ($mlChecked && $shopeeChecked): ?>No Mercado Livre: páginas por categoria. Na Shopee: páginas do feed geral (não existe categoria na consulta).<?php elseif ($shopeeOnlyInitial): ?>Quantidade de páginas do feed geral da Shopee a consultar.<?php endif; ?></span>
    </div>
    <div class="f"><label>Desconto mín. %</label>
      <input type="number" name="min_discount" min="0" max="99" value="<?= $val($radar->minDiscount ?? null) ?>" placeholder="—"></div>
    <div class="f"><label>Preço mín. R$</label>
      <input type="number" step="0.01" name="price_min" value="<?= $val($radar->priceMin ?? null) ?>" placeholder="—"></div>
    <div class="f"><label>Preço máx. R$</label>
      <input type="number" step="0.01" name="price_max" value="<?= $val($radar->priceMax ?? null) ?>" placeholder="—"></div>
  </div>

  <div id="section-require-video">
    <label style="display:flex;gap:8px;align-items:center;margin-bottom:4px">
      <input type="checkbox" id="require_video_checkbox" name="require_video" value="1" <?= ($radar && $radar->requireVideo) ? 'checked' : '' ?> <?= $shopeeOnlyInitial ? 'disabled' : '' ?>>
      Exigir vídeo (só coleta produtos com <code>has_published_clips</code>)
    </label>
    <p class="muted" style="font-size:11px;margin:0 0 16px" id="note-require-video" <?= $shopeeOnlyInitial ? '' : 'hidden' ?>>A Shopee (API de afiliados) não informa se o produto tem vídeo — este filtro não tem como ser satisfeito lá. Para um radar só-Shopee, o campo fica desabilitado aqui (o valor salvo anteriormente, se houver, é ignorado na coleta — nunca zera o resultado). Se o radar também tiver Mercado Livre, o filtro continua valendo normalmente para o lado ML.</p>
  </div>

  <button class="primary" type="submit"><?= $isNew ? 'Criar Radar' : 'Salvar' ?></button>
  <?php if (!$isNew): ?>
    <a href="?r=radars" style="margin-left:8px">cancelar</a>
  <?php endif; ?>
</form>

<script>
(function () {
  var mlCb = document.getElementById('mp_ml');
  var shopeeCb = document.getElementById('mp_shopee');
  var secML = document.getElementById('section-ml-categories');
  var secExtraKw = document.getElementById('section-extra-keywords');
  var secShopeeKw = document.getElementById('section-shopee-keywords');
  var labelPages = document.getElementById('label-pages');
  var helpPages = document.getElementById('help-pages');
  var reqVideoCb = document.getElementById('require_video_checkbox');
  var noteReqVideo = document.getElementById('note-require-video');
  if (!mlCb || !shopeeCb) { return; }

  function setSectionEnabled(section, enabled) {
    if (!section) { return; }
    section.hidden = !enabled;
    var fields = section.querySelectorAll('input, textarea, select');
    for (var i = 0; i < fields.length; i++) { fields[i].disabled = !enabled; }
  }

  function update() {
    var ml = mlCb.checked;
    var shopee = shopeeCb.checked;
    var shopeeOnly = shopee && !ml;

    setSectionEnabled(secML, ml);
    setSectionEnabled(secExtraKw, ml);
    setSectionEnabled(secShopeeKw, shopee);

    if (ml && shopee) {
      labelPages.textContent = 'Páginas / categoria (ML) · Páginas do feed (Shopee)';
      helpPages.textContent = 'No Mercado Livre: páginas por categoria. Na Shopee: páginas do feed geral (não existe categoria na consulta).';
    } else if (shopeeOnly) {
      labelPages.textContent = 'Páginas do feed';
      helpPages.textContent = 'Quantidade de páginas do feed geral da Shopee a consultar.';
    } else {
      labelPages.textContent = 'Páginas / categoria';
      helpPages.textContent = '';
    }

    reqVideoCb.disabled = shopeeOnly;
    noteReqVideo.hidden = !shopeeOnly;
  }

  mlCb.addEventListener('change', update);
  shopeeCb.addEventListener('change', update);
  update();
})();
</script>

<?php if (!$isNew): ?>
<div class="panel" style="max-width:820px;margin-top:14px;border-color:var(--danger)">
  <h2 style="margin-top:0;color:var(--danger)">Excluir ou limpar este radar</h2>
  <p class="muted">Você verá o impacto antes de confirmar (quantos produtos, quais são exclusivos, quais são compartilhados) e escolhe o que remover.</p>
  <a class="btn" href="?r=radar.delete&id=<?= (int) $radar->id ?>" style="border-color:var(--danger);color:var(--danger)">Opções de exclusão / limpeza</a>
</div>
<?php endif; ?>
