<?php
declare(strict_types=1);

use HotRadar\Model\NormalizedProduct;
use HotRadar\Repository\ProductRepository;
use HotRadar\Score\ScoreBreakdown;

T::group('ProductRepository::search — ordenação explícita por Hot Score');

$db = TestDb::fresh('sort');
$products = new ProductRepository($db);

$mkScore = static function (int $score): ScoreBreakdown {
    $b = new ScoreBreakdown();
    $b->total = $score;
    $b->maxTotal = 100;
    $b->faixaKey = 'analisar';
    return $b;
};

$pHigh = new NormalizedProduct(marketplace: 'mercado_livre', marketplaceProductId: 'MLB_SORT_HIGH', title: 'Alto', urlOriginal: 'https://ml/high');
$pLow  = new NormalizedProduct(marketplace: 'mercado_livre', marketplaceProductId: 'MLB_SORT_LOW', title: 'Baixo', urlOriginal: 'https://ml/low');
$pNull = new NormalizedProduct(marketplace: 'mercado_livre', marketplaceProductId: 'MLB_SORT_NULL', title: 'SemScore', urlOriginal: 'https://ml/null');

$idHigh = $products->upsert($pHigh, $mkScore(90))['id'];
$idLow  = $products->upsert($pLow, $mkScore(40))['id'];
$idNull = $products->upsert($pNull, $mkScore(0))['id'];
// simula produto ainda não pontuado de verdade (hot_score NULL, não 0)
$db->run('UPDATE hr_products SET hot_score = NULL WHERE id = ?', [$idNull]);

// ---- default (sem sort) preserva o comportamento histórico: maior primeiro, sem-score por último ----
$rowsDefault = $products->search([]);
$idsDefault = array_column($rowsDefault, 'id');
T::ok(array_search($idHigh, $idsDefault, true) < array_search($idLow, $idsDefault, true), 'default: maior score vem antes do menor');
T::eq(count($idsDefault) - 1, array_search($idNull, $idsDefault, true), 'default: produto sem score continua por último');

// ---- hot_desc explícito é idêntico ao default (não muda comportamento existente) ----
$rowsDesc = $products->search(['sort' => 'hot_desc']);
T::eq($idsDefault, array_column($rowsDesc, 'id'), 'hot_desc explícito é idêntico ao default (compatibilidade preservada)');

// ---- hot_asc: menor primeiro, sem-score AINDA por último (nunca vira "primeiro" por acidente) ----
$rowsAsc = $products->search(['sort' => 'hot_asc']);
$idsAsc = array_column($rowsAsc, 'id');
T::ok(array_search($idLow, $idsAsc, true) < array_search($idHigh, $idsAsc, true), 'hot_asc: menor score vem antes do maior');
T::eq(count($idsAsc) - 1, array_search($idNull, $idsAsc, true), 'hot_asc: produto sem score continua por último');

// ---- sort desconhecido/hostil cai no default com segurança (whitelist fixa, sem interpolação) ----
$rowsHostile = $products->search(['sort' => "hot_asc'; DROP TABLE hr_products; --"]);
T::eq($idsDefault, array_column($rowsHostile, 'id'), 'sort desconhecido/hostil cai no default (whitelist protege)');
$stillThere = $db->first('SELECT COUNT(*) n FROM hr_products', []);
T::ok(((int) $stillThere['n']) >= 3, 'tabela hr_products intacta após tentativa de sort hostil (whitelist, nunca concatena SQL)');

// ---- combina com um filtro já existente sem quebrar nenhum dos dois ----
$rowsFiltered = $products->search(['status' => 'descoberto', 'sort' => 'hot_asc']);
T::ok(count($rowsFiltered) >= 3, 'sort combinado com filtro de status continua retornando os produtos esperados');

TestDb::cleanup('sort');
