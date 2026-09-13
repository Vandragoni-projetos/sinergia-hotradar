<?php
use HotRadar\Web\View;
use HotRadar\Editorial\EditorialStatus;
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 * @var array<int,string> $categories
 * @var array<int,\HotRadar\Radar\Radar> $radars
 * @var array<int,array<int,string>> $radar_slugs_by_product
 * @var array<string,string> $radar_names
 * @var int $total
 * @var string $query_string
 */
$qs = static function (array $over) use ($filters): string {
    $base = array_filter([
        'radar' => $filters['radar'], 'marketplace' => $filters['marketplace'], 'faixa' => $filters['faixa'],
        'status' => $filters['status'], 'category' => $filters['category'],
        'has_video' => $filters['has_video'], 'min_discount' => $filters['min_discount'],
        'min_rating' => $filters['min_rating'], 'q' => $filters['q'], 'sort' => $filters['sort'] ?? '',
    ], static fn ($v) => $v !== '');
    return '?r=products&' . http_build_query(array_merge($base, $over));
};
$cur = static fn (string $k, string $v): string => ($filters[$k] ?? '') === $v ? 'on' : '';
$exportQs = $query_string !== '' ? ('&' . $query_string) : '';
$radarQs = $filters['radar'] ? '&radar=' . rawurlencode((string) $filters['radar']) : '';
?>
<h1>Curadoria <span class="muted" style="font-size:14px">— <?= $total ?> produto(s)</span></h1>

<div class="pills" style="margin-bottom:10px">
  <a class="<?= $cur('radar','') ?>" href="<?= $qs(['radar'=>'']) ?>">Todos os radares</a>
  <?php foreach ($radars as $rd): ?>
    <a class="<?= $cur('radar',$rd->slug) ?>" href="<?= $qs(['radar'=>$rd->slug]) ?>"><?= View::e($rd->name) ?></a>
  <?php endforeach; ?>
</div>
<div class="pills" style="margin-bottom:16px">
  <a class="<?= $cur('faixa','') ?>" href="<?= $qs(['faixa'=>'']) ?>">Todas as classificações</a>
  <a class="<?= $cur('faixa','muito_quente') ?>" href="<?= $qs(['faixa'=>'muito_quente']) ?>">🔥 Muito quente</a>
  <a class="<?= $cur('faixa','bom') ?>" href="<?= $qs(['faixa'=>'bom']) ?>">🟠 Bom candidato</a>
  <a class="<?= $cur('faixa','analisar') ?>" href="<?= $qs(['faixa'=>'analisar']) ?>">🟡 Analisar</a>
  <a class="<?= $cur('faixa','baixo') ?>" href="<?= $qs(['faixa'=>'baixo']) ?>">⚪ Baixa prioridade</a>
</div>

<form class="filters" method="get">
  <input type="hidden" name="r" value="products">
  <input type="hidden" name="radar" value="<?= View::e($filters['radar']) ?>">
  <div class="f"><label>Marketplace</label>
    <select name="marketplace">
      <option value="">todos</option>
      <option value="mercado_livre" <?= $filters['marketplace']==='mercado_livre'?'selected':'' ?>>Mercado Livre</option>
      <option value="shopee" <?= $filters['marketplace']==='shopee'?'selected':'' ?>>Shopee</option>
    </select>
  </div>
  <div class="f"><label>Ordenar por</label>
    <select name="sort">
      <option value="hot_desc" <?= ($filters['sort'] ?? 'hot_desc')==='hot_desc'?'selected':'' ?>>Hot Score — maior para menor</option>
      <option value="hot_asc" <?= ($filters['sort'] ?? '')==='hot_asc'?'selected':'' ?>>Hot Score — menor para maior</option>
    </select>
  </div>
  <div class="f"><label>Nicho</label>
    <select name="category">
      <option value="">todos</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= View::e($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= View::e($c) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="f"><label>Vídeo</label>
    <select name="has_video">
      <option value="">tanto faz</option>
      <option value="1" <?= $filters['has_video']==='1'?'selected':'' ?>>só com vídeo</option>
      <option value="0" <?= $filters['has_video']==='0'?'selected':'' ?>>só sem vídeo</option>
    </select>
  </div>
  <div class="f"><label>Desconto mín. %</label><input type="number" name="min_discount" value="<?= View::e($filters['min_discount']) ?>" style="width:80px"></div>
  <div class="f"><label>Avaliação mín.</label><input type="number" step="0.1" name="min_rating" value="<?= View::e($filters['min_rating']) ?>" style="width:80px"></div>
  <div class="f"><label>Status</label>
    <select name="status">
      <option value="">todos</option>
      <?php foreach (EditorialStatus::active() as $s): ?>
        <option value="<?= $s ?>" <?= $filters['status']===$s?'selected':'' ?>><?= View::e(EditorialStatus::label($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="f"><label>Descoberto desde</label><input type="date" name="discovered_since" value="<?= View::e($filters['discovered_since']) ?>"></div>
  <div class="f"><label>Busca</label><input type="text" name="q" value="<?= View::e($filters['q']) ?>" placeholder="nome do produto…"></div>
  <button class="primary" type="submit">Filtrar</button>
  <a class="btn" href="?r=products<?= $radarQs ?>">limpar</a>
</form>

<div class="toolbar">
  <span class="muted"><?= $total ?> produto(s) neste filtro</span>
  <div class="spacer"></div>
  <a class="btn" href="?r=export.products<?= $exportQs ?>">⬇ Exportar CSV</a>
  <a class="btn" href="?r=export.products&aprovados=1<?= $radarQs ?>">⬇ CSV só aprovados</a>
  <a class="btn" href="?r=print.pack<?= $radarQs ?>&auto=1" target="_blank">📄 Pack PDF (deste filtro)</a>
</div>

<div class="bulkbar" id="bulkbar" hidden>
  <span class="count"><span id="bulkn">0</span> selecionado(s)</span>
  <form method="post" action="?r=product.bulk" id="bulkform">
    <?= View::csrf() ?>
    <input type="hidden" name="back" value="<?= View::e($qs([])) ?>">
    <span id="bulkids"></span>
    <button class="btn" name="to" value="aprovado" type="submit">Aprovar selecionados</button>
    <button class="btn" name="to" value="analisar" type="submit">Marcar para analisar</button>
    <button class="btn" name="to" value="descartado" type="submit"
      onclick="var r=prompt('Motivo do descarte (opcional):');if(r===null)return false;document.getElementById('bulkreason').value=r;">Descartar selecionados</button>
    <input type="hidden" name="reason" id="bulkreason" value="">
  </form>
  <a href="#" onclick="clearSel();return false" class="muted" style="font-size:12px">limpar seleção</a>
  <label style="font-size:12px;margin-left:8px"><input type="checkbox" id="selall"> selecionar todos da página</label>
</div>

<div class="cards" id="cards">
  <?php foreach ($rows as $p):
    $back = View::e($qs([]));
    $radNames = array_map(static fn ($s) => $radar_names[$s] ?? $s, $radar_slugs_by_product[$p['id']] ?? []);
  ?>
  <div class="card pcard">
    <input type="checkbox" class="pick" value="<?= (int) $p['id'] ?>" onchange="onPick()" title="selecionar">
    <div class="thumb">
      <span class="sc"><?= View::faixaEmoji($p['hot_faixa']) ?> <?= (int) $p['hot_score'] ?></span>
      <?php if ($p['has_video']): ?><span class="vid">🎬 vídeo</span><?php endif; ?>
      <?php if ($p['image_url']): ?><img loading="lazy" src="<?= View::e($p['image_url']) ?>" alt=""><?php endif; ?>
    </div>
    <div class="body">
      <div class="meta">
        <span class="badge mp"><?= View::e(View::marketplaceLabel((string) $p['marketplace'])) ?></span>
        <?php foreach ($radNames as $rn): ?><span class="badge q" title="radar">📡 <?= View::e($rn) ?></span><?php endforeach; ?>
      </div>
      <div class="title"><a href="?r=product&id=<?= (int) $p['id'] ?>"><?= View::e($p['title']) ?></a></div>
      <div class="price">
        <span class="cur"><?= View::money($p['price_current']) ?></span>
        <?php if ($p['price_previous']): ?><span class="old"><?= View::money($p['price_previous']) ?></span><?php endif; ?>
        <?php if ($p['discount_pct']): ?><span class="off">-<?= (int) $p['discount_pct'] ?>%</span><?php endif; ?>
      </div>
      <div class="meta">
        <span><?= $p['rating'] !== null ? '★ ' . rtrim(rtrim(number_format((float) $p['rating'],1,'.',''),'0'),'.') : 'sem avaliação' ?></span>
        <span>· Procura: <?= View::e(View::vendasNome($p['sales_signal'])) ?></span>
      </div>
      <div class="meta"><span><?= View::e(View::faixaEmoji($p['hot_faixa'])) ?> <?= View::e(View::faixaNome($p['hot_faixa'])) ?></span>
        <span>· <span class="badge <?= $p['status']==='descartado'?'baixo':'q' ?>"><?= View::e(EditorialStatus::label((string) $p['status'])) ?></span></span>
        <span>· há <?= View::ago($p['discovered_at']) ?></span>
      </div>
      <div class="actions">
        <?php foreach ([['aprovado','Aprovar','ok'],['analisar','Analisar','warn']] as [$to,$lbl,$cls]): ?>
          <form method="post" action="?r=product.status"><?= View::csrf() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="to" value="<?= $to ?>">
            <input type="hidden" name="back" value="<?= $back ?>">
            <button class="<?= $cls ?>" type="submit"><?= $lbl ?></button>
          </form>
        <?php endforeach; ?>
        <form method="post" action="?r=product.status" onsubmit="var r=prompt('Motivo do descarte (opcional):');if(r===null)return false;this.reason.value=r;"><?= View::csrf() ?>
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="to" value="descartado">
          <input type="hidden" name="reason" value="">
          <input type="hidden" name="back" value="<?= $back ?>">
          <button class="danger" type="submit">Descartar</button>
        </form>
      </div>
      <div class="actions">
        <a class="btn" style="flex:1;text-align:center" href="<?= View::e($p['url_original']) ?>" target="_blank" rel="noopener">Abrir no site ↗</a>
        <a class="btn" style="flex:1;text-align:center" href="?r=product&id=<?= (int) $p['id'] ?>">Ver ficha →</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">Nenhum produto para estes filtros.</p><?php endif; ?>
</div>

<script>
function picks(){return [...document.querySelectorAll('.pick:checked')];}
function onPick(){
  var sel=picks();
  document.getElementById('bulkbar').hidden = sel.length===0;
  document.getElementById('bulkn').textContent = sel.length;
  document.getElementById('bulkids').innerHTML = sel.map(c=>'<input type=hidden name="ids[]" value="'+c.value+'">').join('');
}
function clearSel(){document.querySelectorAll('.pick').forEach(c=>c.checked=false);document.getElementById('selall').checked=false;onPick();}
document.getElementById('selall').addEventListener('change',function(){
  document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked);onPick();
});
</script>
