<?php
declare(strict_types=1);

use HotRadar\Db\Connection;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\EditorialRepository;
use HotRadar\Repository\ProductRadarRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;
use HotRadar\Score\HotScoreV2;
use HotRadar\Score\HotScoreV2Config;

T::group('HOT SCORE V2 — shadow mode (produto × radar)');

$db = TestDb::fresh('hotscore_v2');
$products = new ProductRepository($db);
$snapshots = new SnapshotRepository($db);
$editorial = new EditorialRepository($db);
$audit = new AuditRepository($db);
$radars = new RadarRepository($db, $audit);
$pr = new ProductRadarRepository($db);

$v1 = new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php'));
$v2 = new HotScoreV2(HotScoreV2Config::load(require HR_ROOT . '/config/hotscore_v2.php'));

$mkRadar = static fn (string $name, array $cats, array $kw = []): Radar => $radars->find($radars->create(new Radar(
    id: null, slug: '', name: $name, enabled: true, marketplaces: ['mercado_livre'],
    mlCategories: array_map(static fn ($c) => ['id' => $c, 'label' => $c], $cats),
    shopeeKeywords: [], extraKeywords: $kw, excludedWords: [],
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
)));

// =====================================================================
T::group('1) Migration 007 — colunas contextuais aditivas, idempotente');

$cols = array_column($db->all("PRAGMA table_info(hr_product_radars)"), 'name');
foreach (['adherence_level', 'adherence_points', 'context_score', 'context_faixa', 'context_breakdown', 'context_score_version', 'context_score_updated_at'] as $c) {
    T::ok(in_array($c, $cols, true), "coluna $c existe em hr_product_radars");
}
// rodar a migration de novo (função crua) não deve quebrar nem duplicar coluna
(require HR_ROOT . '/migrations/007_hotscore_v2_context.php')($db, 'sqlite');
$cols2 = array_column($db->all("PRAGMA table_info(hr_product_radars)"), 'name');
T::eq(count($cols), count($cols2), 're-executar a migration 007 não duplica colunas');

// =====================================================================
T::group('2) Thresholds 85/70/50 preservados na V2');

$faixas = HotScoreV2Config::fileDefault()->faixas();
$mins = array_column($faixas, 'min');
sort($mins);
T::eq([0, 50, 70, 85], $mins, 'faixas V2: mesmos cortes 0/50/70/85 da V1');
T::eq(100, HotScoreV2Config::fileDefault()->totalMax(), 'soma dos máximos (BASE + aderência) = 100');

// =====================================================================
T::group('3) Vídeo é bônus — ausência não impede "Muito quente"');

$semVideo = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB700001', title: 'Panela de excelente qualidade',
    urlOriginal: 'https://ml/1', discountPct: 55, salesSignal: 'muito_alto', rating: 4.9,
    rankPosition: 3, campaign: 'DEAL_OF_THE_DAY', hasVideo: false, source: 'ofertas_ml',
);
$rCozinha = $mkRadar('Cozinha Excelente', ['MLB1574'], ['panela', 'excelente']);
$bdSemVideo = $v2->evaluateForRadar($semVideo, $rCozinha);
T::ok($bdSemVideo->total >= 85, "sem vídeo, produto excelente ainda bate 85+ (obtido {$bdSemVideo->total})");
T::eq('muito_quente', $bdSemVideo->faixaKey, 'faixa = muito_quente mesmo sem vídeo');
foreach ($bdSemVideo->components as $c) {
    if ($c['key'] === 'visual') {
        T::eq(0, $c['points'], 'bloco visual contribuiu 0 (não penalizou, só deixou de bonificar)');
    }
}

T::group('4) Vídeo sozinho não sustenta "Muito quente"');

$soVideo = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB700002', title: 'Produto mediano qualquer',
    urlOriginal: 'https://ml/2', discountPct: 15, salesSignal: 'medio', rating: 4.2, hasVideo: true,
    specialSignals: ['good_quality_picture'], source: 'ofertas_ml',
);
$rGenerico = $mkRadar('Genérico', ['MLB1574']);
$bdSoVideo = $v2->evaluateForRadar($soVideo, $rGenerico);
T::ok($bdSoVideo->total < 85, "vídeo+foto sozinhos não bastam para 85+ (obtido {$bdSoVideo->total})");

// =====================================================================
T::group('5) Desconto contínuo — sem degraus artificiais');

$mk = static fn (int $d): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLBD' . $d, title: 'Item teste desconto',
    urlOriginal: 'https://ml/d' . $d, discountPct: $d, source: 'ofertas_ml',
);
$b39 = $v2->evaluateBase($mk(39));
$b40 = $v2->evaluateBase($mk(40));
$b49 = $v2->evaluateBase($mk(49));
$b50 = $v2->evaluateBase($mk(50));
$get = static fn ($b) => (int) array_values(array_filter($b->components, static fn ($c) => $c['key'] === 'desconto'))[0]['points'];
T::ok(abs($get($b40) - $get($b39)) <= 1, '39% -> 40%: variação de no máximo 1 ponto (era um degrau de vários pontos na V1)');
T::ok(abs($get($b50) - $get($b49)) <= 1, '49% -> 50%: variação de no máximo 1 ponto');
T::eq(26, $get($b50), 'desconto ≥50% satura no teto de 26');
T::eq(0, $get($v2->evaluateBase(new NormalizedProduct(marketplace: 'mercado_livre', marketplaceProductId: 'MLBD0', title: 'x', urlOriginal: 'https://ml/d0', source: 'ofertas_ml'))), 'desconto ausente = 0');

// =====================================================================
T::group('6) Aderência por radar — piso, elevação e ausência de vocabulário fixo');

// radar com vocabulário "normal" (3 keywords) — precisa de 2 hits para "alta"
$rFerramentas = $mkRadar('Ferramentas', ['MLB1499'], ['furadeira', 'parafusadeira', 'serra']);

$doisHits = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB800001', title: 'Furadeira e Serra Circular Kit Profissional',
    urlOriginal: 'https://ml/f1', discountPct: 20, source: 'ofertas_ml',
);
$adDoisHits = $v2->resolveAdherence($doisHits, $rFerramentas);
T::eq('alta', $adDoisHits['level'], '2 keywords do próprio radar batem no título → alta');
T::ok($adDoisHits['points'] > 0, 'furadeira/serra no radar Ferramentas NÃO recebe aderência zero (defeito do NicheClassifier antigo corrigido)');

$semKeyword = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB800002', title: 'Lixadeira Orbital Profissional 1400W',
    urlOriginal: 'https://ml/f2', discountPct: 20, source: 'ofertas_ml',
);
$adPiso = $v2->resolveAdherence($semKeyword, $rFerramentas);
T::eq('media', $adPiso['level'], 'produto na categoria do radar sem bater keyword específica → PISO média (nunca "fora")');
T::ok($adPiso['points'] > 0, 'piso média nunca é zero');

// radar com vocabulário PEQUENO (1 keyword) — 1 hit já é forte o suficiente para "alta"
$rFuradeiras = $mkRadar('Furadeiras', ['MLB1499'], ['furadeira']);
$umHit = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB800003', title: 'Furadeira de Impacto Bosch 750W',
    urlOriginal: 'https://ml/f3', discountPct: 20, source: 'ofertas_ml',
);
$adUmHit = $v2->resolveAdherence($umHit, $rFuradeiras);
T::eq('alta', $adUmHit['level'], 'radar com vocabulário pequeno (1 keyword): 1 hit já basta para alta');

$rSemCategoria = $mkRadar('Analises manuais teste', [], []);
$adSemCategoria = $v2->resolveAdherence($semKeyword, $rSemCategoria);
T::eq('baixa', $adSemCategoria['level'], 'radar sem categoria configurada (ex.: associação manual) não afirma aderência média');

// =====================================================================
T::group('7) Mesmo produto em 2 radares — aderências diferentes, sem last-writer-wins');

$airFryer = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB900001', title: 'Air Fryer Fritadeira Elétrica 4L',
    urlOriginal: 'https://ml/af', discountPct: 45, salesSignal: 'alto', rating: 4.7, source: 'ofertas_ml',
);
$rEletro = $mkRadar('Eletrodomésticos Cozinha', ['MLB5726'], ['air fryer', 'fritadeira']);
$rOfertasGerais = $mkRadar('Ofertas acima de 40%', ['MLB1574', 'MLB5726'], []);

$idProd = $products->upsert($airFryer, $v1->evaluate($airFryer))['id'];
$pr->link($idProd, $rEletro->id);
$pr->link($idProd, $rOfertasGerais->id);
$pairs = array_values(array_filter($pr->allPairIds(), static fn ($p) => $p['product_id'] === $idProd));
T::eq(2, count($pairs), 'produto associado a 2 radares (M2M intacto)');

foreach ($pairs as $pair) {
    $radar = $radars->find($pair['radar_id']);
    $bd = $v2->evaluateForRadar($airFryer, $radar);
    $ad = $v2->resolveAdherence($airFryer, $radar);
    $pr->saveShadowContext($pair['id'], $ad['level'], $ad['points'], $bd->total, $bd->faixaKey, json_encode($bd->toArray()), $bd->version, $db->now());
}

$rows = $db->all('SELECT radar_id, adherence_level, context_score FROM hr_product_radars WHERE product_id = ? ORDER BY radar_id', [$idProd]);
T::eq(2, count($rows), '2 linhas de contexto gravadas, uma por radar');
T::ok($rows[0]['adherence_level'] !== $rows[1]['adherence_level'] || $rows[0]['context_score'] !== $rows[1]['context_score'],
    'os dois radares têm aderência/score de contexto DIFERENTES (prova de que não há last-writer-wins)');
// gravar o contexto de um radar não altera o do outro
$eletroRowBefore = $db->first('SELECT context_score FROM hr_product_radars WHERE product_id=? AND radar_id=?', [$idProd, $rEletro->id]);
$pr->saveShadowContext(
    (int) array_values(array_filter($pairs, static fn ($p) => $p['radar_id'] === $rOfertasGerais->id))[0]['id'],
    'baixa', 5, 40, 'baixo', '{}', 'v2', $db->now()
);
$eletroRowAfter = $db->first('SELECT context_score FROM hr_product_radars WHERE product_id=? AND radar_id=?', [$idProd, $rEletro->id]);
T::eq($eletroRowBefore['context_score'], $eletroRowAfter['context_score'], 'sobrescrever o contexto de UM radar não mexe no contexto do OUTRO');

// =====================================================================
T::group('8) A V1 permanece intacta e oficial — shadow não vaza para hr_products');

$before = $products->find($idProd);
T::eq('v1', $before['hot_score_version'], 'hot_score_version do produto continua v1');
$v1ScoreBefore = (int) $before['hot_score'];
// rodar o cálculo/gravação da V2 de novo não altera a coluna oficial
foreach ($pairs as $pair) {
    $radar = $radars->find($pair['radar_id']);
    $bd = $v2->evaluateForRadar($airFryer, $radar);
    $pr->saveShadowContext($pair['id'], 'alta', 18, $bd->total, $bd->faixaKey, '{}', 'v2', $db->now());
}
$after = $products->find($idProd);
T::eq($v1ScoreBefore, (int) $after['hot_score'], 'hr_products.hot_score não muda ao gravar contexto V2');
T::eq('v1', $after['hot_score_version'], 'hot_score_version continua v1 (V2 nunca escreveu aqui)');

// =====================================================================
T::group('9) Snapshots e decisões editoriais intactos após shadow V2');

$snapshots->record($idProd, null, $airFryer, $v1->evaluate($airFryer));
$snapCountBefore = $snapshots->countForProduct($idProd);
$products->setStatus($idProd, EditorialStatus::APROVADO, null, 'humano:teste');
$editorial->log($idProd, 'descoberto', EditorialStatus::APROVADO, null, 'humano:teste');

foreach ($pairs as $pair) {
    $radar = $radars->find($pair['radar_id']);
    $bd = $v2->evaluateForRadar($airFryer, $radar);
    $ad = $v2->resolveAdherence($airFryer, $radar);
    $pr->saveShadowContext($pair['id'], $ad['level'], $ad['points'], $bd->total, $bd->faixaKey, '{}', $bd->version, $db->now());
}

T::eq($snapCountBefore, $snapshots->countForProduct($idProd), 'nº de snapshots inalterado após gravar contexto V2');
$prodAfter = $products->find($idProd);
T::eq('aprovado', $prodAfter['status'], 'status editorial (aprovado) preservado');
T::eq(1, count($editorial->timeline($idProd)), 'trilha editorial preservada (1 evento)');
$assocAfter = $pr->radarsForProduct($idProd);
T::eq(2, count($assocAfter), 'associações produto×radar preservadas (2)');

// =====================================================================
T::group('10) Flag de versão ativa — preparada, não ativada');

$settings = new SettingsRepository($db, $audit);
T::eq('v1', (string) ($settings->get('hotscore_active_version', ['value' => 'v1'])['value']), 'sem configuração salva, assume v1 por padrão');
// round-trip: trocar para v2 e voltar para v1 não perde nem corrompe nada
$settings->set('hotscore_active_version', ['value' => 'v2'], 'humano:teste');
T::eq('v2', (string) $settings->get('hotscore_active_version')['value'], 'flag aceita v2 quando setada explicitamente (preparado p/ o futuro)');
$settings->set('hotscore_active_version', ['value' => 'v1'], 'humano:teste');
T::eq('v1', (string) $settings->get('hotscore_active_version')['value'], 'rollback lógico: volta para v1 sem perder nenhum outro dado');
T::eq(2, count($pr->radarsForProduct($idProd)), 'associações continuam intactas após o round-trip do flag');
T::eq('aprovado', $products->find($idProd)['status'], 'status editorial continua intacto após o round-trip do flag');

// =====================================================================
T::group('11) Tela de comparação (compareRows) — só leitura, dados corretos');

$rowsCompare = $pr->compareRows(null, 100);
T::ok(count($rowsCompare) >= 2, 'compareRows retorna as linhas de contexto gravadas');
$found = array_values(array_filter($rowsCompare, static fn ($r) => (int) $r['product_id'] === $idProd));
T::ok(count($found) === 2, 'compareRows traz as 2 linhas do produto multi-radar');
T::ok($pr->shadowScoredCount() >= 2, 'shadowScoredCount conta as linhas já calculadas');

TestDb::cleanup('hotscore_v2');
