<?php
declare(strict_types=1);

namespace HotRadar\Web;

use HotRadar\Model\NormalizedProduct;

/**
 * Reconstrói um NormalizedProduct a partir de uma linha de hr_products
 * (para recalcular o HOT SCORE sem recoletar). Usado pela CLI e pelo painel.
 */
final class ProductHydrator
{
    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): NormalizedProduct
    {
        $extra = json_decode((string) ($row['marketplace_extra'] ?? '{}'), true);
        $extra = is_array($extra) ? $extra : [];
        $signals = json_decode((string) ($row['special_signals'] ?? '[]'), true);
        $signals = is_array($signals) ? $signals : [];

        return new NormalizedProduct(
            marketplace: (string) $row['marketplace'],
            marketplaceProductId: (string) $row['marketplace_product_id'],
            title: (string) $row['title'],
            urlOriginal: (string) $row['url_original'],
            shopId: self::s($row['shop_id'] ?? null),
            category: self::s($row['category'] ?? null),
            subcategory: self::s($row['subcategory'] ?? null),
            urlAffiliate: self::s($row['url_affiliate'] ?? null),
            imageUrl: self::s($row['image_url'] ?? null),
            priceCurrent: self::f($row['price_current'] ?? null),
            pricePrevious: self::f($row['price_previous'] ?? null),
            discountPct: self::i($row['discount_pct'] ?? null),
            salesSignal: self::s($row['sales_signal'] ?? null),
            salesExact: self::i($row['sales_exact'] ?? null),
            rating: self::f($row['rating'] ?? null),
            ratingCount: self::i($row['rating_count'] ?? null),
            rankPosition: self::i($row['rank_position'] ?? null),
            specialSignals: $signals,
            hasVideo: (bool) ($row['has_video'] ?? 0),
            commissionPct: self::f($row['commission_pct'] ?? null),
            commissionEstimated: self::f($row['commission_estimated'] ?? null),
            campaign: self::s($row['campaign'] ?? null),
            marketplaceExtra: $extra,
            dataQuality: (string) ($row['data_quality'] ?? 'scrape_json'),
            source: (string) ($row['source'] ?? ''),
            nicheConfidence: $extra['niche_confidence'] ?? null,
            radarSlug: self::s($row['radar_slug'] ?? null),
            radarId: self::i($row['radar_id'] ?? null),
        );
    }

    private static function s(mixed $v): ?string
    {
        return ($v === null || $v === '') ? null : (string) $v;
    }

    private static function f(mixed $v): ?float
    {
        return ($v === null || $v === '') ? null : (float) $v;
    }

    private static function i(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }
}
