<?php
use HotRadar\Web\View;
/**
 * @var array<string,mixed>|null $result
 * @var string $url
 * @var array<int,\HotRadar\Radar\Radar> $radars
 */
$np = $result['product'] ?? null;
$bd = $result['breakdown'] ?? null;
?>
<h1>Analisar produto por URL</h1>
<p class="muted">Cole o endereço de um produto do Mercado Livre. O HotRadar identifica o produto, calcula o Hot Score com a mesma lógica dos radares e mostra uma ficha. Nada é inventado — dado que a fonte não fornecer aparece como "não informado".</p>

<form method="post" action="?r=analyze.url" class="panel" style="max-width:720px">
  <?= View::csrf() ?>
  <label class="f"><span style="font-size:12px;color:var(--text-dim)">Endereço do produto</span>
    <input type="url" name="url" value="<?= View::e($url) ?>" placeholder="https://www.mercadolivre.com.br/..." style="width:100%" required>
  </label>
  <button class="primary" type="submit" style="margin-top:10px">Analisar</button>
</form>

<?php if ($result !== null): ?>
  <?php if ($np === null): ?>
    <div class="panel" style="max-width:720px;margin-top:14px">
      <h2 style="margin-top:0"><?= $result['valid'] ? 'Não foi possível analisar agora' : 'Link não reconhecido' ?></h2>
      <p><?= View::e($result['message'] ?? 'Tente outro link.') ?></p>
    </div>
  <?php else: ?>
    <div class="panel commercial" style="max-width:820px;margin-top:14px">
      <div style="display:flex;gap:18px">
        <?php if ($np->imageUrl): ?><img src="<?= View::e($np->imageUrl) ?>" style="width:170px;height:170px;object-fit:contain;border:1px solid var(--border);border-radius:8px" alt=""><?php endif; ?>
        <div style="flex:1">
          <div class="big-score"><?= View::faixaEmoji($bd->faixaKey) ?> <?= $bd->total ?><span class="muted" style="font-size:15px">/100</span></div>
          <div class="muted"><?= View::e(View::faixaNome($bd->faixaKey)) ?></div>
          <h2 style="margin:8px 0"><?= View::e($np->title) ?></h2>
          <div class="kv2">
            <div>Marketplace</div><div><?= View::e(View::marketplaceLabel($np->marketplace)) ?></div>
            <div>Preço atual</div><div><strong><?= View::money($np->priceCurrent) ?></strong>
              <?= $np->pricePrevious ? ' <s class="muted">' . View::money($np->pricePrevious) . '</s>' : '' ?>
              <?= $np->discountPct !== null ? ' · ' . $np->discountPct . '% OFF' : '' ?></div>
            <div>Avaliação</div><div><?= $np->rating !== null ? '★ ' . number_format($np->rating, 1, ',', '') : 'Não informada' ?></div>
            <div>Procura / vendas</div><div><?= View::e(View::vendasNome($np->salesSignal)) ?></div>
            <div>Tem vídeo</div><div><?= $np->hasVideo ? 'Sim' : 'Não' ?></div>
          </div>
          <p class="muted" style="font-size:12px;margin-top:10px">
            Origem dos dados:
            <?= $result['source'] === 'ja_no_hotradar' ? 'este produto já está no seu HotRadar.'
                : ($result['source'] === 'ofertas_ativas' ? 'encontrado nas ofertas ativas do Mercado Livre.' : '—') ?>
          </p>
        </div>
      </div>

      <?php if ($bd): ?>
        <h3 style="margin:16px 0 6px">Por que este Hot Score</h3>
        <?php foreach ($bd->components as $c): ?>
          <div class="scoreline <?= $c['available'] ? '' : 'absent' ?>">
            <div><?= View::e($c['label']) ?></div>
            <div><div class="bar"><i style="width:<?= $c['max'] ? round($c['points'] / $c['max'] * 100) : 0 ?>%"></i></div>
              <div class="muted" style="font-size:11px"><?= View::e(View::fatorDetalhe((string) $c["detail"])) ?></div></div>
            <div class="pts"><?= (int) $c['points'] ?>/<?= (int) $c['max'] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
      <?php if ($result['existing_id']): ?>
        <p><a class="btn primary" href="?r=product&id=<?= (int) $result['existing_id'] ?>">Abrir ficha completa no HotRadar →</a></p>
      <?php else: ?>
        <form method="post" action="?r=analyze.save" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
          <?= View::csrf() ?>
          <input type="hidden" name="url" value="<?= View::e($url) ?>">
          <label class="f"><span style="font-size:12px;color:var(--text-dim)">Guardar no radar</span>
            <select name="radar_id" required>
              <option value="">escolha…</option>
              <?php foreach ($radars as $r): ?><option value="<?= (int) $r->id ?>"><?= View::e($r->name) ?></option><?php endforeach; ?>
            </select>
          </label>
          <button class="primary" type="submit">Salvar no HotRadar</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
