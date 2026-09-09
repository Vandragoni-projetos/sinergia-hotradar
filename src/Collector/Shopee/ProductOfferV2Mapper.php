<?php
declare(strict_types=1);

namespace HotRadar\Collector\Shopee;

use HotRadar\Model\NormalizedProduct;

/**
 * Mapeia um "node" de productOfferV2 / shopeeOfferV2 (Shopee Affiliate Open API)
 * para o modelo interno neutro.
 *
 * ESTE CÓDIGO ESTÁ PRONTO MAS NÃO É EXECUTADO — a conta não tem acesso à Open API
 * (erro 10035). No dia em que houver App ID/Secret aprovados, o ShopeeCollector
 * passa a chamar a API e usa este mapper. Nenhuma reconstrução necessária.
 *
 * Campos confirmados pela auditoria (productOfferV2):
 *   itemId, shopId, productName, price, priceMin, priceMax, priceDiscountRate,
 *   sales, ratingStar, commissionRate, commission, offerLink, productLink,
 *   imageUrl, shopName, período de campanha.
 * NÃO há campo de vídeo na Shopee para afiliados — has_video sempre false.
 */
final class ProductOfferV2Mapper
{
    /** @param array<string,mixed> $node */
    public function map(array $node): NormalizedProduct
    {
        $itemId = (string) ($node['itemId'] ?? $node['item_id'] ?? '');
        $shopId = (string) ($node['shopId'] ?? $node['shop_id'] ?? '');

        $price = $this->num($node['price'] ?? $node['priceMin'] ?? null);
        $priceMax = $this->num($node['priceMax'] ?? null);
        $discountRate = $this->num($node['priceDiscountRate'] ?? null); // 0..100

        $previous = null;
        if ($discountRate !== null && $discountRate > 0 && $price !== null) {
            $previous = round($price / (1 - $discountRate / 100), 2);
        } elseif ($priceMax !== null && $price !== null && $priceMax > $price) {
            $previous = $priceMax;
        }

        $sales = isset($node['sales']) && is_numeric($node['sales']) ? (int) $node['sales'] : null;
        $rating = $this->num($node['ratingStar'] ?? null);
        $commissionRate = $this->num($node['commissionRate'] ?? null); // fração 0..1 ou %
        if ($commissionRate !== null && $commissionRate <= 1) {
            $commissionRate *= 100;
        }
        $commission = $this->num($node['commission'] ?? null);

        $offerLink = trim((string) ($node['offerLink'] ?? ''));
        $productLink = trim((string) ($node['productLink'] ?? ''));

        $campaign = null;
        if (!empty($node['periodStartTime']) || !empty($node['periodEndTime'])) {
            $campaign = 'campanha';
        }

        return new NormalizedProduct(
            marketplace: 'shopee',
            marketplaceProductId: $itemId !== '' ? $itemId : ($shopId . '_' . ($node['productName'] ?? '')),
            title: (string) ($node['productName'] ?? ''),
            urlOriginal: $productLink !== '' ? $productLink : $offerLink,
            shopId: $shopId !== '' ? $shopId : null,
            category: null,
            subcategory: null,
            urlAffiliate: $offerLink !== '' ? $offerLink : null,   // Shopee já entrega o link afiliado
            imageUrl: trim((string) ($node['imageUrl'] ?? '')) ?: null,
            priceCurrent: $price,
            pricePrevious: $previous,
            discountPct: $discountRate !== null ? (int) round($discountRate) : null,
            salesSignal: null,               // deriva do número exato no HotScore
            salesExact: $sales,
            rating: $rating,
            ratingCount: null,               // productOfferV2 não devolve contagem
            rankPosition: null,
            specialSignals: [],
            hasVideo: false,                 // Shopee não expõe vídeo ao afiliado
            commissionPct: $commissionRate,
            commissionEstimated: $commission,
            campaign: $campaign,
            marketplaceExtra: array_filter([
                'shop_name' => $node['shopName'] ?? null,
                'price_min' => $this->num($node['priceMin'] ?? null),
                'price_max' => $priceMax,
                'period_start' => $node['periodStartTime'] ?? null,
                'period_end' => $node['periodEndTime'] ?? null,
                'product_catids' => $node['productCatIds'] ?? null,
            ], static fn ($v) => $v !== null),
            dataQuality: 'api',
            source: 'shopee_api',
            nicheConfidence: null,
        );
    }

    private function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
