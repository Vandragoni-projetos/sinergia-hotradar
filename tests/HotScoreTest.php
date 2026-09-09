<?php
declare(strict_types=1);

use HotRadar\Model\NormalizedProduct;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

T::group('HotScore V1 — fórmula explicável e sem valores inventados');

$cfg = HotScoreConfig::load(require __DIR__ . '/../config/hotscore.php');
$score = new HotScore($cfg);

$make = static function (array $over): NormalizedProduct {
    $args = array_merge([
        'marketplace' => 'mercado_livre',
        'marketplaceProductId' => 'MLB1',
        'title' => 't',
        'urlOriginal' => 'https://x',
    ], $over);
    return new NormalizedProduct(...$args);
};

// produto "muito quente" completo
$hot = $make([
    'discountPct' => 55, 'salesSignal' => 'muito_alto', 'rating' => 4.9,
    'rankPosition' => 2, 'hasVideo' => true, 'specialSignals' => ['good_quality_picture'],
    'campaign' => 'DEAL_OF_THE_DAY', 'nicheConfidence' => 'alta',
]);
$b = $score->evaluate($hot);
T::ok($b->total >= 85, "produto completo cai em MUITO QUENTE ({$b->total})");
T::eq('muito_quente', $b->faixaKey, 'faixa muito_quente');
T::eq(6, count($b->components), '6 componentes no detalhamento');
T::eq(100, $b->maxTotal, 'soma dos máximos = 100');

// componente desconto
$cDesc = array_values(array_filter($b->components, static fn ($c) => $c['key'] === 'desconto'))[0];
T::eq(30, $cDesc['points'], 'desconto 55% => 30/30');

// produto sem NENHUM sinal: tudo 0 exceto avaliação (neutro 6)
$vazio = $make([]);
$bv = $score->evaluate($vazio);
$cAval = array_values(array_filter($bv->components, static fn ($c) => $c['key'] === 'avaliacao'))[0];
T::eq(6, $cAval['points'], 'sem nota => neutro 6 (não penaliza)');
T::ok($cAval['available'] === false, 'avaliacao marcada como não disponível');
T::eq(6, $bv->total, 'produto sem dados = só o neutro da avaliação (6)');
T::eq('baixo', $bv->faixaKey, 'sem dados => BAIXA PRIORIDADE');

// campo ausente nunca vira 0 "de verdade" com aparência de dado
$cDescV = array_values(array_filter($bv->components, static fn ($c) => $c['key'] === 'desconto'))[0];
T::ok($cDescV['available'] === false && $cDescV['points'] === 0, 'desconto ausente = 0 e marcado indisponível');

// vendas por número exato (caminho Shopee) mapeia para signal
$shopee = $make(['salesExact' => 25000, 'discountPct' => 10]);
$bs = $score->evaluate($shopee);
$cV = array_values(array_filter($bs->components, static fn ($c) => $c['key'] === 'vendas'))[0];
T::ok($cV['points'] === 22, 'sales_exact 25000 => muito_alto => 22 pts');

// faixas — combinação média (25+16+11+6 = 58) => ANALISAR
T::eq('analisar', $score->evaluate($make(['discountPct' => 40, 'salesSignal' => 'alto', 'rating' => 4.6, 'rankPosition' => 10]))->faixaKey, 'combinação média (58) => ANALISAR');
// combinação forte sem ser completa (25+16+14+8+9 = 72) => BOM
T::eq('bom', $score->evaluate($make(['discountPct' => 40, 'salesSignal' => 'alto', 'rating' => 4.9, 'rankPosition' => 3, 'hasVideo' => true]))->faixaKey, 'combinação forte (72) => BOM');

// determinismo
T::eq($score->evaluate($hot)->total, $score->evaluate($hot)->total, 'score é determinístico');
