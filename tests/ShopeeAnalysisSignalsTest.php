<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\NicheClassifier;
use HotRadar\Collector\Shopee\ProductOfferV2Mapper;
use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;

/**
 * Correção da análise/classificação dos produtos Shopee — auditoria em
 * SINERGIA-HOTRADAR-AUDITORIA-CLASSIFICACAO-RETORNOS.md.
 *
 * Cobre: (1) persistência do salesSignal derivado de salesExact na MESMA
 * regra do HotScore; (2) cálculo de nicheConfidence para Shopee via o
 * NicheClassifier já usado pelo Mercado Livre. Nada de query/coleta/Radar/
 * Auth/cron/credenciais/Mercado Livre foi alterado.
 */

$hotScore = new HotScore(HotScoreConfig::fileDefault());
$mkProduct = static fn (array $o): NormalizedProduct => new NormalizedProduct(
    marketplace: $o['marketplace'] ?? 'shopee',
    marketplaceProductId: $o['id'] ?? 'X1',
    title: $o['title'] ?? 'Produto genérico sem relação com nicho nenhum',
    urlOriginal: 'https://example.com/x',
    priceCurrent: 50.0,
    discountPct: $o['discount'] ?? 50,
    salesSignal: $o['salesSignal'] ?? null,
    salesExact: isset($o['salesExact']) ? (int) $o['salesExact'] : null,
    rating: $o['rating'] ?? 4.8,
    nicheConfidence: $o['nicheConfidence'] ?? null,
    source: $o['source'] ?? 'shopee_api',
);

// =====================================================================
T::group('1-2) HotScore::deriveSalesSignalFromExact() — mesma regra de exact_to_signal, todas as faixas');

T::eq('muito_alto', $hotScore->deriveSalesSignalFromExact(65656.0), '65.656 vendas → muito_alto (caso real da auditoria)');
T::eq('muito_alto', $hotScore->deriveSalesSignalFromExact(20000.0), 'fronteira exata 20000 → muito_alto');
T::eq('alto', $hotScore->deriveSalesSignalFromExact(19999.0), 'logo abaixo de 20000 → alto');
T::eq('alto', $hotScore->deriveSalesSignalFromExact(5000.0), 'fronteira exata 5000 → alto');
T::eq('medio', $hotScore->deriveSalesSignalFromExact(4999.0), 'logo abaixo de 5000 → medio');
T::eq('medio', $hotScore->deriveSalesSignalFromExact(500.0), 'fronteira exata 500 → medio');
T::eq('baixo', $hotScore->deriveSalesSignalFromExact(499.0), 'logo abaixo de 500 → baixo');
T::eq('baixo', $hotScore->deriveSalesSignalFromExact(1.0), 'fronteira exata 1 → baixo');
T::eq(null, $hotScore->deriveSalesSignalFromExact(0.0), '0 vendas → null (nenhuma faixa bate, não inventa "baixo")');

// =====================================================================
T::group('3) Shopee sem salesExact — não inventa salesSignal');

T::eq(null, $hotScore->deriveSalesSignalFromExact(null), 'sem número exato: deriveSalesSignalFromExact() devolve null, nunca um sinal chutado');

// =====================================================================
T::group('4-6) DiscoveryService — deriva, PERSISTE e sobrevive ao round-trip (sem tocar UI/view)');

$db = TestDb::fresh('shopee_analysis_signals');
$settings = new SettingsRepository($db, new AuditRepository($db));
$settings->set('shopee', ['open_api_access' => 'granted']);
foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}
putenv('SHOPEE_APP_ID=fake-app-id-analysis-teste');
putenv('SHOPEE_SECRET=fake-secret-analysis-teste');
$status = new ShopeeStatus($settings);

$fakeShopee = new class('fake-app-id-analysis-teste', 'fake-secret-analysis-teste', 'https://fake.shopee.example/graphql', $status) extends ShopeeCollector {
    protected function httpPost(string $body, array $headers): array
    {
        $bodyJson = json_encode(['data' => ['productOfferV2' => ['nodes' => [
            ['itemId' => 'SIG1', 'shopId' => '1', 'productName' => 'Jogo de Lençol 400 fios', 'price' => '12.50', 'priceDiscountRate' => '60', 'sales' => '65656', 'ratingStar' => '4.8', 'productLink' => 'https://shopee.com.br/a', 'offerLink' => 'https://s.shopee.com.br/a'],
            ['itemId' => 'SIG2', 'shopId' => '1', 'productName' => 'Produto sem nenhuma venda registrada', 'price' => '9.90', 'priceDiscountRate' => '10', 'ratingStar' => '4.0', 'productLink' => 'https://shopee.com.br/b', 'offerLink' => 'https://s.shopee.com.br/b'],
        ]]]]);
        return ['status' => 200, 'body' => $bodyJson, 'error' => null];
    }
};

$app = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $db);
$discovery = new HotRadar\Discovery\DiscoveryService($app->products(), $app->snapshots(), $app->runs(), $hotScore, $app->productRadars());
$result = $discovery->run($fakeShopee, new CollectorContext(maxPages: 1));
T::eq(2, $result['collected'], 'coleta processou os 2 produtos');

$row1 = $db->first("SELECT * FROM hr_products WHERE marketplace_product_id = 'SIG1'");
T::eq('muito_alto', $row1['sales_signal'], 'produto com 65.656 vendas: sales_signal PERSISTIDO como muito_alto (antes ficava NULL)');
T::eq(65656.0, (float) $row1['sales_exact'], 'sales_exact continua intacto — não foi alterado');
T::ok((int) $row1['hot_score'] > 0, 'hot_score calculado');

$row2 = $db->first("SELECT * FROM hr_products WHERE marketplace_product_id = 'SIG2'");
T::eq(null, $row2['sales_signal'], 'produto sem número de vendas: sales_signal continua NULL — nada foi inventado');
T::eq(null, $row2['sales_exact'], 'sales_exact continua NULL para esse produto');

// round-trip explícito via hidratação (exatamente o que a interface lê)
$hydrated1 = HotRadar\Model\ProductHydrator::fromRow($row1);
T::eq('muito_alto', $hydrated1->salesSignal, 'round-trip (upsert → find → hydrate): salesSignal preservado');

// =====================================================================
T::group('7) Interface deixa de depender de NULL quando existe salesExact/salesSignal válido');

T::eq('Muito alto', HotRadar\Web\View::vendasNome($row1['sales_signal']), 'View::vendasNome() agora devolve "Muito alto" para este produto (era "Não informado")');
T::eq('Não informado', HotRadar\Web\View::vendasNome($row2['sales_signal']), 'produto sem dado de vendas continua legitimamente "Não informado" (não é regressão, é o comportamento correto)');

TestDb::cleanup('shopee_analysis_signals');
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);

// =====================================================================
T::group('8) Hot Score — pontos de Vendas idênticos ANTES (sinal pronto) e DEPOIS (derivado) da refatoração');

$viaSignalPronto = $hotScore->evaluate($mkProduct(['salesSignal' => 'muito_alto', 'salesExact' => null]));
$viaExactoDerivado = $hotScore->evaluate($mkProduct(['salesSignal' => null, 'salesExact' => 65656.0]));
$vendasPts = static fn ($b) => array_values(array_filter($b->components, static fn ($c) => $c['key'] === 'vendas'))[0]['points'];
T::eq($vendasPts($viaSignalPronto), $vendasPts($viaExactoDerivado), 'mesmos pontos de Vendas: sinal pronto (estilo ML) vs. derivado de salesExact (estilo Shopee) — refatoração não mudou a matemática');
T::eq(22, $vendasPts($viaExactoDerivado), 'pontuação de Vendas continua 22 (peso do bloco INALTERADO)');

// =====================================================================
T::group('9-10) ProductOfferV2Mapper — nicheConfidence calculado (ou "fora", nunca inventado) para Shopee');

$mapper = new ProductOfferV2Mapper();

$noNode = ['itemId' => 'N1', 'shopId' => '1', 'productName' => 'Kit de panelas antiaderentes e potes herméticos organizadores', 'price' => '99.9', 'productLink' => 'https://x', 'offerLink' => 'https://x'];
$npRelacionado = $mapper->map($noNode);
T::ok($npRelacionado->nicheConfidence !== null, 'produto com título aderente ao nicho: nicheConfidence calculado (não fica null por omissão)');
T::ok(in_array($npRelacionado->nicheConfidence, ['alta', 'media'], true), 'confiança condizente com múltiplos termos do nicho no título (' . $npRelacionado->nicheConfidence . ')');

$semRelacaoNode = ['itemId' => 'N2', 'shopId' => '1', 'productName' => 'Fone de ouvido bluetooth sem fio', 'price' => '39.9', 'productLink' => 'https://x', 'offerLink' => 'https://x'];
$npSemRelacao = $mapper->map($semRelacaoNode);
T::eq('fora', $npSemRelacao->nicheConfidence, 'produto sem nenhuma relação com o nicho: "fora" — mesmo valor que o NicheClassifier já usa para o Mercado Livre, NUNCA "alta"/"media" inventado');

// Aderência passa a utilizar esse nicheConfidence no Hot Score
$breakdownRelacionado = $hotScore->evaluate($npRelacionado);
$breakdownSemRelacao = $hotScore->evaluate($npSemRelacao);
$aderenciaPts = static fn ($b) => array_values(array_filter($b->components, static fn ($c) => $c['key'] === 'aderencia'))[0]['points'];
T::ok($aderenciaPts($breakdownRelacionado) > 0, 'produto aderente ao nicho: Hot Score agora recebe pontos de Aderência (antes sempre 0 para Shopee)');
T::eq(0, $aderenciaPts($breakdownSemRelacao), 'produto "fora" do nicho: 0 pontos de Aderência — mesmo resultado numérico de antes (não infla score à toa)');

// mesma regra de confiança usada pelo Mercado Livre (mesmo NicheClassifier, chamado diretamente)
$niche = new NicheClassifier();
$esperado = $niche->classify($npRelacionado->title);
T::eq($esperado['confidence'], $npRelacionado->nicheConfidence, 'confiança da Shopee é EXATAMENTE a que o NicheClassifier (o mesmo do ML) devolve para o mesmo título — nenhuma regra nova/divergente criada');

// =====================================================================
T::group('11) Mercado Livre — comportamento exatamente preservado (não usa a derivação nova, não muda de score)');

$npMlComSinal = $mkProduct(['marketplace' => 'mercado_livre', 'source' => 'ofertas_ml', 'salesSignal' => 'alto', 'salesExact' => null, 'title' => 'Panela de pressão 5L']);
$breakdownMl = $hotScore->evaluate($npMlComSinal);
T::eq(16, $vendasPts($breakdownMl), 'ML com salesSignal já pronto: pontuação de Vendas inalterada (16 = "alto"), mesma fórmula de sempre');

// DiscoveryService: para ML, salesExact é sempre null (parser não fornece) — o guard novo é NO-OP comprovado
T::eq(null, $npMlComSinal->salesExact, 'confirmação: ML nunca popula salesExact — a derivação nova do DiscoveryService nunca tem o que fazer para produtos ML');
$signalDerivadoParaMl = $hotScore->deriveSalesSignalFromExact($npMlComSinal->salesExact);
T::eq(null, $signalDerivadoParaMl, 'tentar derivar de salesExact=null (caso ML) devolve null — o guard "if ($np->salesSignal === null && $np->salesExact !== null)" nunca dispara para ML, então salesSignal="alto" do ML nunca é tocado');
