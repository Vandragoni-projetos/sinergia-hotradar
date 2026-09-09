<?php
declare(strict_types=1);

namespace HotRadar\Model;

/**
 * Modelo interno NEUTRO de produto — não depende de nenhum marketplace específico.
 * Todo collector produz uma lista destes. Adicionar Amazon/AliExpress no futuro
 * significa só um novo collector que preenche este DTO — o banco não muda.
 *
 * Convenção obrigatória (auditoria): distinguir "não disponível" (null) de zero.
 * Um campo null NUNCA vira 0 automaticamente.
 */
final class NormalizedProduct
{
    /**
     * @param array<int,string>   $specialSignals  tags/sinais (best_seller_candidate, deal_of_the_day, has_published_clips...)
     * @param array<string,mixed> $marketplaceExtra dados específicos do marketplace (vão para coluna JSON)
     */
    public function __construct(
        public string $marketplace,
        public string $marketplaceProductId,
        public string $title,
        public string $urlOriginal,
        public ?string $shopId = null,
        public ?string $category = null,
        public ?string $subcategory = null,
        public ?string $urlAffiliate = null,
        public ?string $imageUrl = null,
        public ?float $priceCurrent = null,
        public ?float $pricePrevious = null,
        public ?int $discountPct = null,
        public ?string $salesSignal = null,   // muito_alto|alto|medio|baixo|null
        public ?int $salesExact = null,
        public ?float $rating = null,
        public ?int $ratingCount = null,
        public ?int $rankPosition = null,
        public array $specialSignals = [],
        public bool $hasVideo = false,
        public ?float $commissionPct = null,
        public ?float $commissionEstimated = null,
        public ?string $campaign = null,
        public array $marketplaceExtra = [],
        public string $dataQuality = 'scrape_json',
        public string $source = '',
        public ?string $nicheConfidence = null, // alta|media|baixa|fora|null
    ) {
        // Normalização da chave de dedup: sempre MAIÚSCULA e sem espaços.
        // Remove divergência entre SQLite (índice case-sensitive/BINARY) e
        // MariaDB (collation utf8mb4 case-insensitive) no UNIQUE (marketplace, marketplace_product_id).
        $this->marketplace = strtolower(trim($this->marketplace));
        $this->marketplaceProductId = strtoupper(trim($this->marketplaceProductId));
    }

    /** Chave estável de deduplicação. */
    public function dedupeKey(): string
    {
        return $this->marketplace . ':' . $this->marketplaceProductId;
    }

    /** @return array<string,mixed> */
    public function toRow(): array
    {
        return [
            'marketplace' => $this->marketplace,
            'marketplace_product_id' => $this->marketplaceProductId,
            'shop_id' => $this->shopId,
            'title' => mb_substr($this->title, 0, 300),
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'url_original' => $this->urlOriginal,
            'url_affiliate' => $this->urlAffiliate,
            'image_url' => $this->imageUrl,
            'price_current' => $this->priceCurrent,
            'price_previous' => $this->pricePrevious,
            'discount_pct' => $this->discountPct,
            'sales_signal' => $this->salesSignal,
            'sales_exact' => $this->salesExact,
            'rating' => $this->rating,
            'rating_count' => $this->ratingCount,
            'rank_position' => $this->rankPosition,
            'special_signals' => json_encode(array_values($this->specialSignals), JSON_UNESCAPED_UNICODE),
            'has_video' => $this->hasVideo ? 1 : 0,
            'commission_pct' => $this->commissionPct,
            'commission_estimated' => $this->commissionEstimated,
            'campaign' => $this->campaign,
            'marketplace_extra' => json_encode($this->marketplaceExtra, JSON_UNESCAPED_UNICODE),
            'data_quality' => $this->dataQuality,
            'source' => $this->source,
        ];
    }
}
