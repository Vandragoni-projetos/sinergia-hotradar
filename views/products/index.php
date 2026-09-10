<?php
use HotRadar\Web\View;
use HotRadar\Editorial\EditorialStatus;
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,mixed> $filters
 * @var array<int,string> $categories
 * @var array<int,\HotRadar\Radar\Radar> $radars
 * @var int $total
 */
$qs = static function (array $over) use ($filters): string {
    $base = array_filter([
        'radar' => $filters['radar'], 'marketplace' => $filters['marketplace'], 'faixa' => $filters['faixa'],
        'status' => $filters['status'], 'category' => $filters['category'],
        'has_video' => $filters['has_video'], 'min_discount' => $filters['min_discount'],
        'min_rating' => $filters['min_rating'], 'q' => $filters['q'],
    ], static fn ($v) => $v !== '');
    return '?r=products&' . http_build_query(array_merge($base, $over));
};
$cur = static fn (string $k, string $v): string => ($filters[$k] ?? '') === $v ? 'on' : '';
?>
<h1>Curadoria <span class="muted" style="font-size:14px">— <?= $total ?> produto(s)</span></h1>

<div class="pills" style="margin-bottom:10px">
  <a class="<?= $cur('radar','') ?>" href="<?= $qs(['radar'=>'']) ?>">TODOS OS RADARES</a>
  <?php foreach ($radars as $rd): ?>
    <a class="<?= $cur('radar',$rd->slug) ?>" href="<?= $qs(['radar'=>$rd->slug]) ?>"><?= View::e($rd->name) ?></a>
  <?php endforeach; ?>
</div>
<div class="pills" style="margin-bottom:10px">
  <a class="<?= $cur('marketplace','') ?>" href="<?= $qs(['marketplace'=>'']) ?>">TODOS</a>
  <a class="<?= $cur('marketplace','mercado_livre') ?>" href="<?= $qs(['marketplace'=>'mercado_livre']) ?>">MERCADO LIVRE</a>
  <a class="<?= $cur('marketplace','shopee') ?>" href="<?= $qs(['marketplace'=>'shopee']) ?>">SHOPEE</a>
</div>
<div class="pills" style="margin-bottom:16px">
  <a class="<?= $cur('faixa','') ?>" href="<?= $qs(['faixa'=>'']) ?>">TODOS</a>
  <a class="<?= $cur('faixa','muito_quente') ?>" href="<?= $qs(['faixa'=>'muito_quente']) ?>">🔥 MUITO QUENTE</a>
  <a class="<?= $cur('faixa','bom') ?>" href="<?= $qs(['faixa'=>'bom']) ?>">🟠 BOM</a>
  <a class="<?= $cur('faixa','analisar') ?>" href="<?= $qs(['faixa'=>'analisar']) ?>">🟡 ANALISAR</a>
  <a class="<?= $cur('faixa','baixo') ?>" href="<?= $qs(['faixa'=>'baixo']) ?>">⚪ BAIXO</a>
</div>

<form class="filters" method="get">
  <input type="hidden" name="r" value="products">
  <input type="hidden" name="radar" value="<?= View::e($filters['radar']) ?>">
  <div class="f"><label>Nicho/categoria</label>
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
  <div class="f"><label>Desconto mín. %</label><input type="number" name="min_discount" value="<?= View::e($filters['min_discount']) ?>" style="width:90px"></div>
  <div class="f"><label>Avaliação mín.</label><input type="number" step="0.1" name="min_rating" value="<?= View::e($filters['min_rating']) ?>" style="width:90px"></div>
  <div class="f"><label>Status editorial</label>
    <select name="status">
      <option value="">todos</option>
      <?php foreach (EditorialStatus::active() as $s): ?>
        <option value="<?= $s ?>" <?= $filters['status']===$s?'selected':'' ?>><?= View::e(EditorialStatus::label($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="f"><label>Descoberto desde</label><input type="date" name="discovered_since" value="<?= View::e($filters['discovered_since']) ?>"></div>
  <div class="f"><label>Busca</label><input type="text" name="q" value="<?= View::e($filters['q']) ?>" placeholder="título…"></div>
  <button class="primary" type="submit">Filtrar</button>
  <a class="ghost" href="?r=products" style="padding:7px 9px;border:1px solid var(--border);border-radius:7px">limpar</a>
</form>

<div class="cards">
  <?php foreach ($rows as $p):
    $back = View::e($qs([]));
    $signals = json_decode((string) ($p['special_signals'] ?? '[]'), true) ?: [];
  ?>
  <div class="card pcard">
    <div class="thumb">
      <span class="sc"><?php
        echo match ($p['hot_faixa']) {'muito_quente'=>'🔥','bom'=>'🟠','analisar'=>'🟡',default=>'⚪'};
        echo ' ' . (int) $p['hot_score']; ?></span>
      <?php if ($p['has_video']): ?><span class="vid">🎬 vídeo</span><?php endif; ?>
      <?php if ($p['image_url']): ?><img loading="lazy" src="<?= View::e($p['image_url']) ?>" alt=""><?php endif; ?>
    </div>
    <div class="body">
      <div class="meta">
        <span class="badge mp"><?= View::e(View::marketplaceLabel((string) $p['marketplace'])) ?></span>
        <?php if (!empty($p['radar_slug'])): ?><span class="badge q" title="radar de origem">📡 <?= View::e($p['radar_slug']) ?></span><?php endif; ?>
        <?php if ($p['category']): ?><span class="badge q"><?= View::e($p['category']) ?></span><?php endif; ?>
        <span class="badge q" title="qualidade do dado"><?= View::e($p['data_quality']) ?></span>
      </div>
      <div class="title"><a href="?r=product&id=<?= (int) $p['id'] ?>"><?= View::e($p['title']) ?></a></div>
      <div class="price">
        <span class="cur"><?= View::money($p['price_current']) ?></span>
        <?php if ($p['price_previous']): ?><span class="old"><?= View::money($p['price_previous']) ?></span><?php endif; ?>
        <?php if ($p['discount_pct']): ?><span class="off">-<?= (int) $p['discount_pct'] ?>%</span><?php endif; ?>
      </div>
      <div class="meta">
        <span><?= $p['rating'] !== null ? '★ ' . rtrim(rtrim(number_format((float) $p['rating'],1,'.',''),'0'),'.') : '★ n/d' ?></span>
        <span>· <?= View::e(View::salesLabel($p['sales_signal'])) ?></span>
        <?php if ($p['rank_position'] !== null): ?><span>· #<?= (int) $p['rank_position'] ?></span><?php endif; ?>
      </div>
      <div class="meta"><span>📥 <?= View::ago($p['discovered_at']) ?></span>
        <span>· <span class="badge <?= $p['status']==='descartado'?'baixo':'q' ?>"><?= View::e(EditorialStatus::label((string) $p['status'])) ?></span></span>
      </div>
      <div class="actions">
        <form method="post" action="?r=product.status">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="to" value="aprovado">
          <input type="hidden" name="back" value="<?= $back ?>">
          <button class="ok" type="submit">Aprovar</button>
        </form>
        <form method="post" action="?r=product.status">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="to" value="analisar">
          <input type="hidden" name="back" value="<?= $back ?>">
          <button class="warn" type="submit">Analisar</button>
        </form>
        <form method="post" action="?r=product.status" onsubmit="var r=prompt('Motivo do descarte (opcional):');if(r===null)return false;this.reason.value=r;">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input type="hidden" name="to" value="descartado">
          <input type="hidden" name="reason" value="">
          <input type="hidden" name="back" value="<?= $back ?>">
          <button class="danger" type="submit">Descartar</button>
        </form>
      </div>
      <div class="actions">
        <a class="badge q" style="flex:1;text-align:center;padding:6px" href="<?= View::e($p['url_original']) ?>" target="_blank" rel="noopener">Abrir produto ↗</a>
        <a class="badge q" style="flex:1;text-align:center;padding:6px" href="?r=product&id=<?= (int) $p['id'] ?>">Ficha / por quê →</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="muted">Nenhum produto para estes filtros.</p><?php endif; ?>
</div>
