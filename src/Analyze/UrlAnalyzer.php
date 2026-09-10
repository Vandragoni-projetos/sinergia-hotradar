<?php
declare(strict_types=1);

namespace HotRadar\Analyze;

use HotRadar\Collector\MercadoLivre\MlCategories;
use HotRadar\Collector\MercadoLivre\OfertasJsonParser;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Model\ProductHydrator;
use HotRadar\Repository\ProductRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\ScoreBreakdown;
use HotRadar\Support\Http;

/**
 * "Analisar produto por URL".
 *
 * Mercado Livre: a página do produto (PDP) é bloqueada para acesso automatizado
 * (redireciona para verificação). Portanto SEM inventar nada:
 *   1) se o produto já está no HotRadar (mesmo id) → usa os dados que temos;
 *   2) senão, procura o produto nas OFERTAS ativas do ML (fonte já usada pelo coletor);
 *   3) se não estiver em oferta → informa amigavelmente que não é possível analisar agora.
 *
 * Shopee: identificada, porém sem scraping — informa que depende da Open API.
 * Nunca usa OpenAI. Usa EXATAMENTE o mesmo HotScore do coletor.
 */
final class UrlAnalyzer
{
    public function __construct(
        private readonly Http $http,
        private readonly OfertasJsonParser $parser,
        private readonly ProductRepository $products,
        private readonly HotScore $hotScore,
        /** @var array<int,string> categorias ML a varrer nas ofertas (dos radares ativos) */
        private readonly array $mlSearchCategories = [],
    ) {
    }

    /**
     * @return array{
     *   marketplace:?string, valid:bool, message:?string,
     *   product:?NormalizedProduct, breakdown:?ScoreBreakdown,
     *   existing_id:?int, source:string
     * }
     */
    public function analyze(string $rawUrl): array
    {
        $url = trim($rawUrl);
        $out = [
            'marketplace' => null, 'valid' => false, 'message' => null,
            'product' => null, 'breakdown' => null, 'existing_id' => null, 'source' => 'nenhuma',
        ];

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $out['message'] = 'Cole o endereço completo do produto (começando com https://).';
            return $out;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        // ---------------- Mercado Livre ----------------
        if (str_contains($host, 'mercadolivre.com') || str_contains($host, 'mercadolibre.com') || str_contains($host, 'meli.la') || str_contains($host, 'mercadoliv.re')) {
            $out['marketplace'] = 'mercado_livre';
            return $this->analyzeMercadoLivre($url, $out);
        }

        // ---------------- Shopee (identificada, sem scraping) ----------------
        if (str_contains($host, 'shopee.com') || str_contains($host, 's.shopee')) {
            $out['marketplace'] = 'shopee';
            $out['valid'] = true;
            $out['message'] = 'Reconhecemos que é um link da Shopee. A análise de produtos da Shopee '
                . 'depende do acesso à API oficial (ainda não liberado para esta conta). '
                . 'Assim que o acesso for concedido, esta análise passa a funcionar automaticamente.';
            return $out;
        }

        $out['message'] = 'Por enquanto o HotRadar analisa links do Mercado Livre. '
            . 'Este endereço parece ser de outro site.';
        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @return array<string,mixed>
     */
    private function analyzeMercadoLivre(string $url, array $out): array
    {
        $ids = OfertasJsonParser::extractMlbIds($url);
        if ($ids === []) {
            // pode ser link curto meli.la — tenta seguir o redirect p/ achar o id
            $res = $this->http->get($url);
            if ($res['final_url'] !== '' && $res['final_url'] !== $url) {
                $ids = OfertasJsonParser::extractMlbIds($res['final_url']);
            }
        }
        if ($ids === []) {
            $out['message'] = 'Não consegui identificar o código do produto neste link do Mercado Livre. '
                . 'Use o link da página do produto (que contém algo como "MLB1234567890").';
            return $out;
        }
        $out['valid'] = true;

        // 1) já está no HotRadar?
        foreach ($ids as $id) {
            $row = $this->products->findByMarketplaceId('mercado_livre', $id);
            if ($row !== null) {
                $np = ProductHydrator::fromRow($row);
                $out['product'] = $np;
                $out['breakdown'] = $this->hotScore->evaluate($np);
                $out['existing_id'] = (int) $row['id'];
                $out['source'] = 'ja_no_hotradar';
                return $out;
            }
        }

        // 2) procura nas OFERTAS ativas (mesma fonte do coletor)
        $found = $this->findInOfertas($ids);
        if ($found !== null) {
            $out['product'] = $found;
            $out['breakdown'] = $this->hotScore->evaluate($found);
            $out['source'] = 'ofertas_ativas';
            return $out;
        }

        // 3) não está em oferta → sem inventar dados
        $out['message'] = 'Este produto não está em uma oferta ativa do Mercado Livre agora. '
            . 'O HotRadar analisa automaticamente produtos que estão nas ofertas ou que já foram '
            . 'capturados pelos seus radares. Você ainda pode adicioná-lo manualmente a um radar '
            . 'e aguardar a próxima coleta.';
        return $out;
    }

    /** @param array<int,string> $ids @return NormalizedProduct|null */
    private function findInOfertas(array $ids): ?NormalizedProduct
    {
        $wanted = array_flip(array_map('strtoupper', $ids));
        $cats = $this->mlSearchCategories !== [] ? $this->mlSearchCategories : array_keys(MlCategories::catalog());
        $cats = array_slice($cats, 0, 8); // limite educado

        foreach ($cats as $catId) {
            for ($page = 1; $page <= 2; $page++) {
                $u = 'https://www.mercadolivre.com.br/ofertas?category=' . rawurlencode($catId)
                    . ($page > 1 ? '&page=' . $page : '');
                $res = $this->http->get($u);
                if ($res['status'] !== 200 || stripos($res['final_url'], 'account-verification') !== false) {
                    continue;
                }
                $parsed = $this->parser->parse($res['body'], $catId, MlCategories::label($catId));
                foreach ($parsed['items'] as $np) {
                    if (isset($wanted[strtoupper($np->marketplaceProductId)])) {
                        return $np;
                    }
                    foreach (OfertasJsonParser::extractMlbIds($np->urlOriginal) as $u2) {
                        if (isset($wanted[strtoupper($u2)])) {
                            return $np;
                        }
                    }
                }
                usleep(1200 * 1000);
            }
        }
        return null;
    }
}
