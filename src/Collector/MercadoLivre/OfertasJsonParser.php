<?php
declare(strict_types=1);

namespace HotRadar\Collector\MercadoLivre;

use HotRadar\Collector\NicheClassifier;
use HotRadar\Model\NormalizedProduct;
use HotRadar\Support\Arr;

/**
 * Extrai produtos da página /ofertas do Mercado Livre a partir do JSON
 * estruturado embutido (`_n.ctx.r={…}`) — fonte classificada como "B" na auditoria.
 *
 * NÃO acessa PDP, NÃO usa API com token, NÃO contorna anti-bot.
 * Só lê o que a própria página de ofertas entrega.
 *
 * Campos capturados (comprovadamente disponíveis):
 *   id do item (MLB), id de catálogo, título, url original, imagem,
 *   preço atual, preço anterior, % desconto, nota, faixa de vendas (texto),
 *   posição/rank, vendedor, loja oficial, promo_type, tags, has_published_clips.
 */
final class OfertasJsonParser
{
    private const IMG_TEMPLATE = 'https://http2.mlstatic.com/D_NQ_NP_2X_%s-F.webp';

    public function __construct(private readonly NicheClassifier $niche = new NicheClassifier())
    {
    }

    /**
     * @return array{ok:bool, items:array<int,NormalizedProduct>, cards:int, reason:?string}
     */
    public function parse(string $html, string $sourceCategoryId, string $sourceCategoryLabel): array
    {
        $json = self::extractCtxJson($html);
        if ($json !== null) {
            $viaJson = $this->parseDecoded($json, $sourceCategoryId, $sourceCategoryLabel);
            if ($viaJson['ok']) {
                return $viaJson;
            }
        }
        // Fallback: a página às vezes vem só com HTML SSR (sem o JSON rico), tipicamente
        // sob rate-limit. Ainda dá para extrair o essencial dos cards .poly-card.
        return $this->parseHtmlFallback($html, $sourceCategoryId, $sourceCategoryLabel);
    }

    /**
     * Mesma lógica de parse(), mas a partir do JSON já decodificado (usado nos testes).
     * @param array<string,mixed> $json
     * @return array{ok:bool, items:array<int,NormalizedProduct>, cards:int, reason:?string}
     */
    public function parseDecoded(array $json, string $sourceCategoryId, string $sourceCategoryLabel): array
    {
        $data = Arr::get($json, 'appProps.pageProps.data');
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return ['ok' => false, 'items' => [], 'cards' => 0, 'reason' => 'appProps.pageProps.data.items ausente'];
        }

        $tracking = is_array($data['trackingAdditionalData'] ?? null) ? $data['trackingAdditionalData'] : [];

        $out = [];
        $cards = 0;
        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cards++;
            $np = $this->buildProduct($item, $tracking, $sourceCategoryId, $sourceCategoryLabel);
            if ($np !== null) {
                $out[] = $np;
            }
        }

        return ['ok' => true, 'items' => $out, 'cards' => $cards, 'reason' => null];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $tracking
     */
    private function buildProduct(array $item, array $tracking, string $catId, string $catLabel): ?NormalizedProduct
    {
        $card = is_array($item['card'] ?? null) ? $item['card'] : $item;
        $meta = is_array($card['metadata'] ?? null) ? $card['metadata'] : [];

        $itemId = strtoupper(trim((string) ($meta['id'] ?? '')));
        $catalogId = strtoupper(trim((string) ($meta['product_id'] ?? '')));
        $stableId = $catalogId !== '' ? $catalogId : $itemId;
        if ($stableId === '') {
            return null;
        }

        $components = is_array($card['components'] ?? null) ? $card['components'] : [];

        // título
        $titleComp = Arr::firstOfType($components, 'title');
        $title = trim((string) Arr::get($titleComp ?? [], 'title.text', ''));
        if ($title === '') {
            return null;
        }

        // url original
        $rawUrl = (string) ($meta['url'] ?? '');
        $url = $this->absoluteUrl($rawUrl);
        if ($url === '') {
            return null;
        }

        // imagem
        $picId = (string) Arr::get($card, 'pictures.pictures.0.id', '');
        $image = $picId !== '' ? sprintf(self::IMG_TEMPLATE, $picId) : null;

        // preço
        [$priceCurrent, $pricePrevious, $discountPct] = $this->parsePrice($components);

        // nota + faixa de vendas (review_compacted)
        [$rating, $salesSignal] = $this->parseReview($components);

        // vendedor / loja oficial
        [$sellerName, $officialStore] = $this->parseSeller($components);

        // rank / posição
        $rank = isset($item['position']) && is_numeric($item['position']) ? (int) $item['position'] : null;

        // tags / promo (trackingAdditionalData é keyed por id de catálogo, às vezes por id de item)
        $tk = [];
        foreach ([$catalogId, $itemId, $stableId] as $k) {
            if ($k !== '' && isset($tracking[$k]) && is_array($tracking[$k])) {
                $tk = $tracking[$k];
                break;
            }
        }
        $tags = array_values(array_filter(array_map('strval', (array) ($tk['tags'] ?? []))));
        $promoType = isset($tk['promotion_type']) ? (string) $tk['promotion_type'] : null;
        $hasVideo = in_array('has_published_clips', $tags, true);

        if ($salesSignal === null && in_array('best_seller_candidate', $tags, true)) {
            $salesSignal = 'baixo'; // sinal fraco: é candidato a mais vendido, mas sem faixa declarada
        }

        // aderência ao nicho
        $n = $this->niche->classify($title, $catLabel);

        $extra = array_filter([
            'ml_item_id' => $itemId,
            'ml_catalog_id' => $catalogId,
            'ml_user_product_id' => isset($meta['user_product_id']) ? (string) $meta['user_product_id'] : null,
            'seller' => $sellerName,
            'official_store' => $officialStore,
            'promotion_type' => $promoType,
            'free_shipping' => isset($tk['free_shipping']) ? (bool) $tk['free_shipping'] : null,
            'source_category_id' => $catId,
            'source_category_label' => $catLabel,
            'niche_slug' => $n['slug'],
            'niche_confidence' => $n['confidence'],
        ], static fn ($v) => $v !== null);

        $signals = $tags;
        if ($officialStore) {
            $signals[] = 'official_store';
        }

        return new NormalizedProduct(
            marketplace: 'mercado_livre',
            marketplaceProductId: $stableId,
            title: $title,
            urlOriginal: $url,
            shopId: null,
            category: $n['slug'] ?? $this->slugFromLabel($catLabel),
            subcategory: null,
            urlAffiliate: null,               // E1: não capturamos link afiliado ainda
            imageUrl: $image,
            priceCurrent: $priceCurrent,
            pricePrevious: $pricePrevious,
            discountPct: $discountPct,
            salesSignal: $salesSignal,
            salesExact: null,                 // ML não fornece número exato
            rating: $rating,
            ratingCount: null,                // ML /ofertas não fornece contagem
            rankPosition: $rank,
            specialSignals: array_values(array_unique($signals)),
            hasVideo: $hasVideo,
            commissionPct: null,
            commissionEstimated: null,
            campaign: $promoType,
            marketplaceExtra: $extra,
            dataQuality: 'scrape_json',
            source: 'ofertas_ml',
            nicheConfidence: $n['confidence'],
        );
    }

    /**
     * Parser HTML puro (fallback). Cards .poly-card → título, url, preço, imagem.
     * Sem tags/vídeo/rank/vendas (só o JSON rico tem isso) → data_quality = 'scrape_html'.
     * @return array{ok:bool, items:array<int,NormalizedProduct>, cards:int, reason:?string}
     */
    public function parseHtmlFallback(string $html, string $catId, string $catLabel): array
    {
        if (stripos($html, 'poly-card') === false) {
            return [
                'ok' => false,
                'items' => [],
                'cards' => 0,
                'reason' => 'página sem JSON e sem cards .poly-card — provável bloqueio/rate-limit do ML',
            ];
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($dom);

        $cards = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' poly-card__content ')]");
        $out = [];
        $seen = [];
        $n = 0;
        foreach ($cards as $content) {
            $n++;
            $a = $xp->query(".//h3//a | .//a[.//h3] | .//a[contains(@class,'poly-component__title')]", $content)->item(0);
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $title = trim($a->textContent);
            $href = $this->absoluteUrl(trim((string) $a->getAttribute('href')));
            if ($title === '' || $href === '') {
                continue;
            }
            $ids = [];
            if (preg_match_all('#(MLB[U]?-?\d{6,})#i', $href, $m)) {
                foreach ($m[1] as $x) {
                    $ids[] = strtoupper(str_replace('-', '', $x));
                }
            }
            $stableId = $ids[0] ?? ('HREF:' . md5($href));
            if (isset($seen[$stableId])) {
                continue;
            }
            $seen[$stableId] = true;

            // preço: primeiro .andes-money-amount__fraction dentro do card (ignora "de/riscado")
            $priceCurrent = null;
            $pricePrev = null;
            $fr = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' andes-money-amount__fraction ')]", $content);
            $vals = [];
            foreach ($fr as $node) {
                $digits = preg_replace('/\D+/', '', $node->textContent);
                if ($digits !== '') {
                    $vals[] = (float) $digits;
                }
            }
            if ($vals !== []) {
                $priceCurrent = min($vals);
                if (count($vals) > 1) {
                    $pricePrev = max($vals) > $priceCurrent ? max($vals) : null;
                }
            }
            $discount = null;
            $pill = $xp->query(".//*[contains(@class,'discount') or contains(@class,'poly-price__disc')]", $content)->item(0);
            if ($pill && preg_match('/(\d{1,3})\s*%/', $pill->textContent, $mm)) {
                $discount = (int) $mm[1];
            } elseif ($pricePrev !== null && $priceCurrent !== null && $pricePrev > 0) {
                $discount = (int) round(($pricePrev - $priceCurrent) / $pricePrev * 100);
            }

            $img = null;
            $imgNode = $xp->query(".//img", $content)->item(0);
            if ($imgNode instanceof \DOMElement) {
                $img = trim((string) ($imgNode->getAttribute('data-src') ?: $imgNode->getAttribute('src')));
                $img = ($img && !str_starts_with($img, 'data:')) ? $img : null;
            }

            $niche = $this->niche->classify($title, $catLabel);

            $out[] = new NormalizedProduct(
                marketplace: 'mercado_livre',
                marketplaceProductId: $stableId,
                title: $title,
                urlOriginal: $href,
                category: $niche['slug'] ?? $this->slugFromLabel($catLabel),
                imageUrl: $img,
                priceCurrent: $priceCurrent,
                pricePrevious: $pricePrev,
                discountPct: $discount,
                dataQuality: 'scrape_html',
                source: 'ofertas_ml',
                marketplaceExtra: [
                    'source_category_id' => $catId,
                    'source_category_label' => $catLabel,
                    'niche_slug' => $niche['slug'],
                    'niche_confidence' => $niche['confidence'],
                    'parser' => 'html_fallback',
                ],
                nicheConfidence: $niche['confidence'],
            );
        }

        if ($out === []) {
            return ['ok' => false, 'items' => [], 'cards' => $n, 'reason' => 'HTML fallback não extraiu nenhum card válido'];
        }
        return ['ok' => true, 'items' => $out, 'cards' => $n, 'reason' => 'html_fallback (dados reduzidos — sem vídeo/rank/vendas)'];
    }

    // --------------------------------------------------------------- componentes

    /**
     * @param array<int,mixed> $components
     * @return array{0:?float,1:?float,2:?int}  [atual, anterior, desconto%]
     */
    private function parsePrice(array $components): array
    {
        $comp = null;
        foreach ($components as $c) {
            if (is_array($c) && (($c['type'] ?? '') === 'price' || ($c['id'] ?? '') === 'price_v2')) {
                $comp = $c;
                break;
            }
        }
        if ($comp === null) {
            return [null, null, null];
        }
        $price = is_array($comp['price'] ?? null) ? $comp['price'] : [];

        $current = Arr::get($price, 'current_price.value');
        $current = is_numeric($current) ? (float) $current : null;

        $previous = null;
        foreach ((array) ($price['price_labels'] ?? []) as $lbl) {
            foreach ((array) ($lbl['values'] ?? []) as $v) {
                if (($v['key'] ?? '') === 'previous_price') {
                    $pv = Arr::get($v, 'price.value');
                    if (is_numeric($pv)) {
                        $previous = (float) $pv;
                    }
                }
            }
        }
        if ($previous !== null && $current !== null && $previous <= $current) {
            $previous = null;
        }

        $discount = null;
        foreach ((array) Arr::get($price, 'discount_polylabel.values', []) as $v) {
            $txt = (string) Arr::get($v, 'pill.text', '');
            if (preg_match('/(\d{1,3})\s*%/', $txt, $m)) {
                $discount = (int) $m[1];
                break;
            }
        }
        if ($discount === null && !empty($price['discount_label']['text'])) {
            if (preg_match('/(\d{1,3})\s*%/', (string) $price['discount_label']['text'], $m)) {
                $discount = (int) $m[1];
            }
        }
        if ($discount === null && $previous !== null && $current !== null && $previous > 0) {
            $discount = (int) round((($previous - $current) / $previous) * 100);
        }

        return [$current, $previous, $discount];
    }

    /**
     * @param array<int,mixed> $components
     * @return array{0:?float,1:?string}  [nota, sinal_de_vendas]
     */
    private function parseReview(array $components): array
    {
        $comp = Arr::firstOfType($components, 'review_compacted');
        if ($comp === null) {
            return [null, null];
        }
        $rc = is_array($comp['review_compacted'] ?? null) ? $comp['review_compacted'] : [];
        $alt = (string) ($rc['alt_text'] ?? '');

        $rating = null;
        if (preg_match('/([0-9]+[.,][0-9]+)\s*de\s*5/u', $alt, $m)) {
            $rating = (float) str_replace(',', '.', $m[1]);
        }
        if ($rating === null) {
            foreach ((array) ($rc['values'] ?? []) as $v) {
                if (($v['key'] ?? '') === 'label' && preg_match('/^[0-9]+[.,][0-9]+$/', (string) Arr::get($v, 'label.text', ''))) {
                    $rating = (float) str_replace(',', '.', (string) Arr::get($v, 'label.text', ''));
                }
            }
        }

        $salesText = '';
        if (preg_match('/(?:mais de|\+)\s*([0-9][0-9.\s]*)\s*(mil)?\s*(?:produtos\s*)?vendidos/iu', $alt, $m)) {
            $salesText = $m[1] . ($m[2] ?? '');
        } else {
            foreach ((array) ($rc['values'] ?? []) as $v) {
                $tx = (string) Arr::get($v, 'label.text', '');
                if (stripos($tx, 'vendido') !== false) {
                    $salesText = $tx;
                }
            }
        }

        return [$rating, self::salesTextToSignal($salesText)];
    }

    /**
     * @param array<int,mixed> $components
     * @return array{0:?string,1:bool}  [nome_vendedor, loja_oficial]
     */
    private function parseSeller(array $components): array
    {
        $comp = Arr::firstOfType($components, 'seller');
        if ($comp === null) {
            return [null, false];
        }
        $name = null;
        $official = false;
        foreach ((array) Arr::get($comp, 'seller.values', []) as $v) {
            if (($v['key'] ?? '') === 'label') {
                $name = trim((string) Arr::get($v, 'label.text', '')) ?: null;
            }
            if (($v['key'] ?? '') === 'icon_cockade') {
                $official = true;
            }
        }
        return [$name, $official];
    }

    // ------------------------------------------------------------------- helpers

    private static function salesTextToSignal(string $text): ?string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return null;
        }
        $isMil = str_contains($text, 'mil');
        if (preg_match('/([0-9][0-9.\s]*)/', $text, $m)) {
            $num = (float) str_replace([' ', '.'], '', $m[1]);
            if ($isMil) {
                $num *= 1000;
            }
        } else {
            return null;
        }
        return match (true) {
            $num >= 50000 => 'muito_alto',
            $num >= 10000 => 'alto',
            $num >= 1000  => 'medio',
            $num >= 1     => 'baixo',
            default       => null,
        };
    }

    private function absoluteUrl(string $u): string
    {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        if (str_starts_with($u, '//')) {
            return 'https:' . $u;
        }
        if (str_starts_with($u, '/')) {
            return 'https://www.mercadolivre.com.br' . $u;
        }
        if (!preg_match('#^https?://#i', $u)) {
            return 'https://' . $u;
        }
        return $u;
    }

    private function slugFromLabel(?string $label): ?string
    {
        if ($label === null || $label === '') {
            return null;
        }
        $s = strtr(mb_strtolower($label, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        return trim($s, '_') ?: null;
    }

    /**
     * Extrai o objeto JSON após `_n.ctx.r=` com varredura de chaves respeitando strings.
     * @return array<string,mixed>|null
     */
    public static function extractCtxJson(string $html): ?array
    {
        $marker = '_n.ctx.r=';
        $start = strpos($html, $marker);
        if ($start === false) {
            return null;
        }
        $start += strlen($marker);
        $len = strlen($html);
        if ($start >= $len || $html[$start] !== '{') {
            return null;
        }
        $depth = 0;
        $inStr = false;
        $esc = false;
        $end = -1;
        for ($p = $start; $p < $len; $p++) {
            $ch = $html[$p];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                if (--$depth === 0) {
                    $end = $p;
                    break;
                }
            }
        }
        if ($end < 0) {
            return null;
        }
        $decoded = json_decode(substr($html, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }
}
