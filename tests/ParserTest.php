<?php
declare(strict_types=1);

use HotRadar\Collector\MercadoLivre\OfertasJsonParser;
use HotRadar\Model\NormalizedProduct;

T::group('OfertasJsonParser — dados reais do ML (fixture)');

$json = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ofertas_MLB1574.slim.json'), true);
$parser = new OfertasJsonParser();
$result = $parser->parseDecoded($json, 'MLB1574', 'Casa, Móveis e Decoração');

T::ok($result['ok'] === true, 'parsing retornou ok');
T::ok($result['cards'] >= 10, "leu os cards do fixture ({$result['cards']})");
T::ok(count($result['items']) >= 8, 'produziu produtos normalizados (' . count($result['items']) . ')');

/** @var array<int,NormalizedProduct> $items */
$items = $result['items'];
$first = $items[0];

T::ok($first instanceof NormalizedProduct, 'item é NormalizedProduct');
T::eq('mercado_livre', $first->marketplace, 'marketplace');
T::ok(str_starts_with($first->marketplaceProductId, 'MLB'), 'id do marketplace começa com MLB (' . $first->marketplaceProductId . ')');
T::ok($first->title !== '', 'título não vazio');
T::ok(str_starts_with($first->urlOriginal, 'https://'), 'url absoluta');
T::ok($first->imageUrl !== null && str_contains($first->imageUrl, 'mlstatic.com'), 'imagem do CDN do ML');
T::ok($first->priceCurrent === null || $first->priceCurrent > 0, 'preço atual positivo ou null (nunca 0 falso)');
T::eq('scrape_json', $first->dataQuality, 'data_quality');
T::eq('ofertas_ml', $first->source, 'source');
T::eq(null, $first->salesExact, 'ML nunca preenche vendas exatas');
T::eq(null, $first->ratingCount, 'ML nunca preenche contagem de reviews');

// pelo menos um produto do fixture tem vídeo, nota e sinal de vendas
$comVideo = array_filter($items, static fn (NormalizedProduct $p) => $p->hasVideo);
$comNota = array_filter($items, static fn (NormalizedProduct $p) => $p->rating !== null);
$comVendas = array_filter($items, static fn (NormalizedProduct $p) => $p->salesSignal !== null);
T::ok(count($comVideo) >= 1, 'detectou has_published_clips em ao menos 1 produto (' . count($comVideo) . ')');
T::ok(count($comNota) >= 3, 'extraiu nota de avaliação (' . count($comNota) . ')');
T::ok(count($comVendas) >= 3, 'extraiu faixa de vendas (' . count($comVendas) . ')');

// sinal de vendas só assume valores do enum
$enum = ['muito_alto', 'alto', 'medio', 'baixo'];
$okEnum = true;
foreach ($items as $p) {
    if ($p->salesSignal !== null && !in_array($p->salesSignal, $enum, true)) {
        $okEnum = false;
    }
}
T::ok($okEnum, 'sales_signal sempre no enum ou null');

// rank/posição coerente
$comRank = array_filter($items, static fn (NormalizedProduct $p) => $p->rankPosition !== null);
T::ok(count($comRank) >= 5, 'capturou posição/rank (' . count($comRank) . ')');

// extractCtxJson tolera lixo
T::eq(null, OfertasJsonParser::extractCtxJson('<html>sem json</html>'), 'extractCtxJson retorna null sem marcador');
