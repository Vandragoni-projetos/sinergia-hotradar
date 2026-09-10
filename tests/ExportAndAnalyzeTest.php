<?php
declare(strict_types=1);

use HotRadar\Analyze\UrlAnalyzer;
use HotRadar\Collector\MercadoLivre\OfertasJsonParser;
use HotRadar\Export\ProductCsvExporter;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Repository\ProductRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;
use HotRadar\Support\Http;

T::group('Exportação CSV + Analisar por URL');

// ---------------- CSV ----------------
$exp = new ProductCsvExporter();
$rows = [
    [
        'id' => 1, 'title' => 'Produto com ; ponto e vírgula', 'marketplace' => 'mercado_livre',
        'price_current' => 89.9, 'price_previous' => 159.9, 'discount_pct' => 44, 'hot_score' => 88,
        'hot_faixa' => 'muito_quente', 'rating' => 4.9, 'sales_signal' => 'muito_alto', 'rank_position' => 2,
        'has_video' => 1, 'status' => 'aprovado', 'discovered_at' => '2026-09-01 10:00:00',
        'last_collected_at' => '2026-09-02 10:00:00', 'data_quality' => 'scrape_json',
        'url_original' => 'https://ml/p/MLB1', 'url_affiliate' => null,
    ],
    [
        'id' => 2, 'title' => 'Sem dados ricos', 'marketplace' => 'mercado_livre',
        'price_current' => 10.0, 'price_previous' => null, 'discount_pct' => null, 'hot_score' => 40,
        'hot_faixa' => 'baixo', 'rating' => null, 'sales_signal' => null, 'rank_position' => null,
        'has_video' => 0, 'status' => 'descoberto', 'discovered_at' => '2026-09-01 10:00:00',
        'last_collected_at' => '2026-09-01 10:00:00', 'data_quality' => 'scrape_html',
        'url_original' => 'https://ml/p/MLB2', 'url_affiliate' => null,
    ],
];
$csv = $exp->toString($rows, [1 => ['Casa'], 2 => []]);
$lines = explode("\n", trim($csv));
T::ok(str_starts_with($lines[0], "\xEF\xBB\xBF"), 'CSV começa com BOM UTF-8 (Excel abre com acento)');
T::ok(str_contains($lines[0], 'Produto;Marketplace;Radar'), 'cabeçalho em português');
T::ok(str_contains($lines[1], '"Produto com ; ponto e vírgula"'), 'campo com ; é escapado entre aspas');
T::ok(str_contains($lines[1], 'Muito quente'), 'classificação em texto (não "muito_quente")');
T::ok(str_contains($lines[1], ';Sim;'), 'possui vídeo = Sim');
// linha 2: campos ausentes ficam VAZIOS, nunca inventados
$c2 = str_getcsv($lines[2], ';');
T::eq('', $c2[4], 'preço anterior ausente → vazio');
T::eq('', $c2[5], 'desconto ausente → vazio');
T::eq('', $c2[8], 'avaliação ausente → vazio');
T::eq('', $c2[9], 'sinal de vendas ausente → vazio');
T::eq('Não', $c2[11], 'sem vídeo → Não');
T::ok(str_contains($exp->filename('radar-x'), 'hotradar-radar-x-'), 'nome de arquivo previsível');

// ---------------- Analisar por URL ----------------
$db = TestDb::fresh('analyze');
$productsRepo = new ProductRepository($db);
$hotScore = new HotScore(HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php'));

// Http falso: qualquer /ofertas devolve vazio (produto não está em oferta)
$fakeHttp = new class extends Http {
    public function __construct() {}
    public function get(string $url, array $headers = []): array
    {
        return ['status' => 200, 'body' => '<html>nada</html>', 'error' => null, 'bytes' => 15, 'final_url' => $url];
    }
};
$analyzer = new UrlAnalyzer($fakeHttp, new OfertasJsonParser(), $productsRepo, $hotScore, ['MLB1574']);

// URL inválida
$r = $analyzer->analyze('não é url');
T::ok($r['valid'] === false && $r['product'] === null, 'texto sem http → inválido');

// URL de outro site
$r = $analyzer->analyze('https://www.amazon.com.br/dp/B00X');
T::eq(null, $r['marketplace'], 'amazon → marketplace não reconhecido');
T::ok(str_contains((string) $r['message'], 'Mercado Livre'), 'mensagem amigável indicando ML');

// URL Shopee → identifica mas não faz scraping
$r = $analyzer->analyze('https://shopee.com.br/product/123/456');
T::eq('shopee', $r['marketplace'], 'shopee reconhecida');
T::ok($r['valid'] === true && $r['product'] === null, 'shopee: válida mas sem dados (sem scraping)');
T::ok(str_contains((string) $r['message'], 'API oficial'), 'shopee: explica dependência da API');

// URL ML de produto que NÃO está em oferta nem no banco
$r = $analyzer->analyze('https://www.mercadolivre.com.br/algum-produto/p/MLB99999999');
T::eq('mercado_livre', $r['marketplace'], 'ML reconhecido');
T::ok($r['valid'] === true, 'id MLB extraído da URL');
T::eq(null, $r['product'], 'sem dados (PDP bloqueada, não está em oferta) → nada inventado');
T::ok(str_contains((string) $r['message'], 'oferta ativa'), 'mensagem amigável explicando');

// URL ML de produto QUE JÁ ESTÁ no HotRadar → usa os dados existentes
$np = new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB12345678', title: 'Já capturado',
    urlOriginal: 'https://ml/p/MLB12345678', priceCurrent: 50.0, discountPct: 20, salesSignal: 'alto',
    rating: 4.7, source: 'ofertas_ml', nicheConfidence: 'alta',
);
$productsRepo->upsert($np, $hotScore->evaluate($np));
$r = $analyzer->analyze('https://www.mercadolivre.com.br/x/p/MLB12345678');
T::ok($r['product'] !== null, 'produto existente: ficha montada');
T::eq('ja_no_hotradar', $r['source'], 'origem: já no HotRadar');
T::ok($r['breakdown'] !== null && $r['breakdown']->total > 0, 'Hot Score calculado com a MESMA lógica');
T::ok($r['existing_id'] !== null, 'aponta para o produto existente');

// extractMlbIds
T::eq(['MLB63904966'], OfertasJsonParser::extractMlbIds('https://www.mercadolivre.com.br/x/p/MLB63904966'), 'extrai id de /p/MLB…');
T::eq(['MLB4071998331'], OfertasJsonParser::extractMlbIds('https://produto.mercadolivre.com.br/MLB-4071998331-slug'), 'extrai id de MLB-… com hífen');
T::eq([], OfertasJsonParser::extractMlbIds('https://www.mercadolivre.com.br/ofertas'), 'sem id → array vazio');

TestDb::cleanup('analyze');
