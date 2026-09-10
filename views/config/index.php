<?php
use HotRadar\Web\View;
use HotRadar\Score\HotScoreConfig;
/**
 * @var string $sub
 * @var array<string,mixed> $general @var array<string,mixed> $general_defaults
 * @var array<string,mixed> $ml
 * @var string $shopee_state @var string $shopee_label
 * @var bool $shopee_appid_present @var bool $shopee_secret_present @var bool $shopee_granted
 * @var bool $openai_configured @var string $openai_status @var string $openai_model
 * @var HotScoreConfig $hs_config @var HotScoreConfig $hs_default @var string $hs_source
 * @var array<int,array<string,mixed>> $audit
 * @var int $radars_count
 */
$tabs = [
    'geral' => 'Geral', 'ml' => 'Mercado Livre', 'shopee' => 'Shopee',
    'openai' => 'OpenAI', 'hotscore' => 'Hot Score',
];
$badge = static fn (bool $ok, string $y, string $n) =>
    '<span class="badge" style="' . ($ok ? 'background:var(--ok);color:#fff;border-color:transparent' : '') . '">' . ($ok ? $y : $n) . '</span>';
?>
<h1>Configurações</h1>
<div class="pills" style="margin-bottom:18px">
  <?php foreach ($tabs as $k => $lbl): ?>
    <a class="<?= $sub === $k ? 'on' : '' ?>" href="?r=config&sub=<?= $k ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($sub === 'geral'): ?>
  <form method="post" action="?r=settings.general" class="panel" style="max-width:720px">
    <h2 style="margin-top:0">Geral</h2>
    <div class="f" style="margin-bottom:12px"><label>Timezone</label>
      <input type="text" name="timezone" value="<?= View::e($general['timezone'] ?? $general_defaults['timezone']) ?>" style="width:280px"></div>
    <div class="f" style="margin-bottom:12px"><label>Máx. de produtos por coleta</label>
      <input type="number" name="max_products_per_collect" min="10" max="5000" value="<?= (int) ($general['max_products_per_collect'] ?? 500) ?>" style="width:160px"></div>
    <div class="f" style="margin-bottom:12px"><label>Marketplaces habilitados</label>
      <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="marketplaces_enabled[]" value="mercado_livre" <?= in_array('mercado_livre', (array) ($general['marketplaces_enabled'] ?? []), true) ? 'checked' : '' ?>> Mercado Livre</label>
      <label style="display:flex;gap:8px;align-items:center;opacity:.6"><input type="checkbox" name="marketplaces_enabled[]" value="shopee" <?= in_array('shopee', (array) ($general['marketplaces_enabled'] ?? []), true) ? 'checked' : '' ?>> Shopee</label>
    </div>
    <div class="f" style="margin-bottom:16px"><label>Expirar oferta automaticamente após N dias sem reaparecer (0 = desligado)</label>
      <input type="number" name="auto_expire_days" min="0" max="90" value="<?= (int) ($general['editorial']['auto_expire_days'] ?? 0) ?>" style="width:120px">
      <span class="muted" style="font-size:12px">Parâmetro editorial não-secreto (aplicado numa fase futura).</span></div>
    <p class="muted" style="font-size:12px">Nichos e categorias monitoradas ficam em <a href="?r=radars">Radares</a> (<?= (int) $radars_count ?> configurado(s)). Guardado em <code>hr_settings['general']</code>.</p>
    <button class="primary" type="submit">Salvar</button>
  </form>

<?php elseif ($sub === 'ml'): ?>
  <div class="panel" style="max-width:720px">
    <h2 style="margin-top:0">Mercado Livre</h2>
    <p><strong>Mercado Livre — ATIVO / coletor público.</strong> Usa a página pública <code>/ofertas</code>; <strong>não depende de API key</strong>.</p>
    <div class="kv" style="font-size:13px">
      <div>Modo</div><div>coletor público (scraping do JSON estruturado de <code>/ofertas</code>)</div>
      <div>Timeout HTTP</div><div><?= (int) $ml['http_timeout'] ?>s</div>
      <div>Pausa entre requisições</div><div><?= (int) $ml['request_delay_ms'] ?> ms</div>
      <div>User-Agent</div><div class="muted" style="font-size:11px"><?= View::e($ml['user_agent']) ?></div>
    </div>
    <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
    <h3 style="margin:0 0 6px">API oficial do Mercado Livre (futuro)</h3>
    <div class="kv" style="font-size:13px">
      <div>Status</div><div><?= $badge(false, '', 'Não configurada') ?> <span class="muted">— opcional; o coletor atual não precisa</span></div>
      <div>Variáveis previstas</div><div><code>ML_API_CLIENT_ID</code>, <code>ML_API_CLIENT_SECRET</code> (Environment) — <strong>não</strong> obrigatórias nesta fase</div>
    </div>
    <p class="muted" style="font-size:12px">Nada de bypass de anti-bot, PDP bloqueada ou Link Builder automático nesta fase.</p>
  </div>

<?php elseif ($sub === 'shopee'): ?>
  <div class="panel" style="max-width:720px">
    <h2 style="margin-top:0">Shopee</h2>
    <p>Estado atual: <strong><?= View::e($shopee_label) ?></strong></p>
    <div class="kv" style="font-size:13px">
      <div><code>SHOPEE_APP_ID</code> (Environment)</div><div><?= $badge($shopee_appid_present, 'presente', 'ausente') ?></div>
      <div><code>SHOPEE_SECRET</code> (Environment)</div><div><?= $badge($shopee_secret_present, 'presente', 'ausente') ?></div>
      <div>Valor do segredo</div><div class="muted">nunca exibido — fica só no EasyPanel Environment</div>
    </div>
    <hr style="border:none;border-top:1px solid var(--border);margin:14px 0">
    <form method="post" action="?r=settings.shopee">
      <label style="display:flex;gap:8px;align-items:start">
        <input type="checkbox" name="open_api_access" value="granted" <?= $shopee_granted ? 'checked' : '' ?> <?= (!$shopee_appid_present || !$shopee_secret_present) ? 'disabled' : '' ?>>
        <span>Acesso à Open API <strong>concedido</strong> pela Shopee para esta conta.<br>
          <span class="muted" style="font-size:12px">Marque só depois que a Shopee habilitar a conta. Enquanto desmarcado, o coletor Shopee fica desligado e nenhuma chamada é feita.</span></span>
      </label>
      <button class="primary" type="submit" style="margin-top:12px" <?= (!$shopee_appid_present || !$shopee_secret_present) ? 'disabled' : '' ?>>Salvar status</button>
    </form>
    <p class="muted" style="font-size:12px;margin-bottom:0">O adapter <code>ProductOfferV2Mapper</code> já está pronto; ligar = credenciais no Environment + acesso concedido acima.</p>
  </div>

<?php elseif ($sub === 'openai'): ?>
  <div class="panel" style="max-width:720px">
    <h2 style="margin-top:0">OpenAI</h2>
    <p>Status: <strong><?= View::e($openai_status) ?></strong></p>
    <div class="kv" style="font-size:13px">
      <div><code>OPENAI_API_KEY</code> (Environment)</div><div><?= $badge($openai_configured, 'presente', 'ausente') ?></div>
      <div><code>OPENAI_MODEL</code></div><div><?= View::e($openai_model) ?> <span class="muted">(default se não definido)</span></div>
      <div>Valor da chave</div><div class="muted">nunca exibido, nunca gravado em banco/Git/log</div>
      <div>Uso nesta fase</div><div><strong>somente</strong> resumos de relatório (Relatórios → “Gerar análise inteligente”)</div>
      <div>Efeito no HOT SCORE</div><div>nenhum — o HOT SCORE é 100% determinístico (<code>HotScore</code>/<code>HotScoreConfig</code>)</div>
    </div>
  </div>

<?php elseif ($sub === 'hotscore'): ?>
  <div class="panel" style="max-width:820px">
    <h2 style="margin-top:0">HOT SCORE — pesos e faixas</h2>
    <p class="muted" style="font-size:12px">Fonte de verdade ativa: <strong><?= View::e($hs_source) ?></strong> · versão <?= View::e($hs_config->version()) ?> · soma dos máximos: <strong><?= $hs_config->totalMax() ?></strong> (ideal 100).</p>
    <form method="post" action="?r=hotscore.save">
      <table>
        <tr><th>Componente</th><th>Regra</th><th>Peso máx. (padrão)</th><th style="width:120px">Peso atual</th></tr>
        <?php foreach ($hs_config->blocks() as $key => $blk):
          $def = $hs_default->block($key); ?>
          <tr>
            <td><strong><?= View::e($blk['label'] ?? $key) ?></strong></td>
            <td class="muted" style="font-size:12px"><?= View::e(hotscoreRuleText($key)) ?></td>
            <td class="muted"><?= (int) ($def['max'] ?? 0) ?></td>
            <td><input type="number" name="block_max[<?= View::e($key) ?>]" min="0" max="100" value="<?= (int) ($blk['max'] ?? 0) ?>" style="width:90px"></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <h3 style="margin:18px 0 6px">Faixas de classificação</h3>
      <table>
        <tr><th>Faixa</th><th style="width:140px">Nota mínima</th></tr>
        <?php foreach ($hs_config->faixas() as $i => $f): ?>
          <tr>
            <td><?= View::e($f['emoji'] ?? '') ?> <?= View::e($f['label'] ?? '') ?></td>
            <td><input type="number" name="faixa_min[<?= (int) $i ?>]" min="0" max="100" value="<?= (int) ($f['min'] ?? 0) ?>" style="width:90px"></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <p class="muted" style="font-size:12px">Salvar recalcula o HOT SCORE de todos os produtos e registra a mudança no audit log. A OpenAI nunca escreve aqui.</p>
      <button class="primary" type="submit">Salvar e recalcular</button>
      <button class="ghost" type="submit" formaction="?r=hotscore.reset" style="margin-left:8px">Restaurar padrão de fábrica</button>
    </form>
  </div>

  <div class="panel" style="max-width:820px;margin-top:14px">
    <h2 style="margin-top:0">Audit log (configurações)</h2>
    <table class="hist">
      <tr><th>Quando</th><th>Área</th><th>Ação</th><th>Ref</th><th>Ator</th></tr>
      <?php foreach ($audit as $a): ?>
        <tr>
          <td><?= View::e(date('d/m H:i', strtotime((string) $a['created_at']))) ?></td>
          <td><?= View::e($a['area']) ?></td>
          <td><?= View::e($a['action']) ?></td>
          <td><?= View::e($a['ref'] ?? '—') ?></td>
          <td class="muted"><?= View::e($a['actor']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$audit): ?><tr><td colspan="5" class="muted">Sem alterações registradas.</td></tr><?php endif; ?>
    </table>
  </div>
<?php endif; ?>

<?php
function hotscoreRuleText(string $key): string
{
    return match ($key) {
        'desconto' => 'faixas de % de desconto',
        'vendas' => 'faixa textual de vendas (ML) / número exato (Shopee)',
        'avaliacao' => 'nota; neutro quando ausente',
        'destaque' => 'posição na página + bônus por promoção',
        'visual' => 'tem vídeo (+) e foto de qualidade (+)',
        'aderencia' => 'confiança do classificador de nicho / keywords do radar',
        default => '—',
    };
}
?>
