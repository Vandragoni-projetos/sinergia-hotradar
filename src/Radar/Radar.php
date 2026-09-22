<?php
declare(strict_types=1);

namespace HotRadar\Radar;

use HotRadar\Model\NormalizedProduct;

/**
 * Um Radar/Nicho: configuração PRÓPRIA e independente de monitoramento.
 * Vários radares podem coexistir sem misturar produtos (cada produto guarda radar_slug).
 */
final class Radar
{
    /** Marketplaces reconhecidos pelo sistema — única lista canônica, usada na
     *  validação de entrada (Actions::radarSave) e no despacho de coleta
     *  (Actions::radarCollect/collectRun). Um valor fora daqui nunca é salvo
     *  nem executado silenciosamente como se fosse outro marketplace. */
    public const KNOWN_MARKETPLACES = ['mercado_livre', 'shopee'];

    /**
     * @param array<int,string>                        $marketplaces
     * @param array<int,array{id:string,label:string}> $mlCategories
     * @param array<int,string>                        $shopeeKeywords
     * @param array<int,string>                        $extraKeywords
     * @param array<int,string>                        $excludedWords
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public string $name,
        public bool $enabled,
        public array $marketplaces,
        public array $mlCategories,
        public array $shopeeKeywords,
        public array $extraKeywords,
        public array $excludedWords,
        public int $pagesPerCategory,
        public ?int $minDiscount,
        public ?float $priceMin,
        public ?float $priceMax,
        public bool $requireVideo,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $j = static fn (string $k): array => is_array($d = json_decode((string) ($row[$k] ?? '[]'), true)) ? $d : [];
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            enabled: (bool) ($row['enabled'] ?? 0),
            marketplaces: array_values(array_map('strval', $j('marketplaces'))),
            mlCategories: array_values(array_filter($j('ml_categories'), 'is_array')),
            shopeeKeywords: array_values(array_map('strval', $j('shopee_keywords'))),
            extraKeywords: array_values(array_map('strval', $j('extra_keywords'))),
            excludedWords: array_values(array_map('strval', $j('excluded_words'))),
            pagesPerCategory: max(1, (int) ($row['pages_per_category'] ?? 3)),
            minDiscount: $row['min_discount'] !== null ? (int) $row['min_discount'] : null,
            priceMin: $row['price_min'] !== null ? (float) $row['price_min'] : null,
            priceMax: $row['price_max'] !== null ? (float) $row['price_max'] : null,
            requireVideo: (bool) ($row['require_video'] ?? 0),
        );
    }

    /** @return array<int,string> ids MLB configurados neste radar */
    public function mlCategoryIds(): array
    {
        return array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) ($c['id'] ?? ''))),
            $this->mlCategories
        )));
    }

    public function hasMarketplace(string $mp): bool
    {
        return in_array($mp, $this->marketplaces, true);
    }

    /**
     * Aplica os filtros do radar a um produto normalizado.
     * @return array{ok:bool, reason:?string}
     */
    public function accepts(NormalizedProduct $p): array
    {
        $title = mb_strtolower($p->title, 'UTF-8');

        foreach ($this->excludedWords as $w) {
            $w = mb_strtolower(trim($w), 'UTF-8');
            if ($w !== '' && mb_strpos($title, $w) !== false) {
                return ['ok' => false, 'reason' => 'palavra excluída: "' . $w . '"'];
            }
        }

        if ($this->minDiscount !== null) {
            if ($p->discountPct === null || $p->discountPct < $this->minDiscount) {
                return ['ok' => false, 'reason' => 'desconto < ' . $this->minDiscount . '%'];
            }
        }

        if ($this->priceMin !== null && ($p->priceCurrent === null || $p->priceCurrent < $this->priceMin)) {
            return ['ok' => false, 'reason' => 'preço < R$ ' . $this->priceMin];
        }
        if ($this->priceMax !== null && ($p->priceCurrent === null || $p->priceCurrent > $this->priceMax)) {
            return ['ok' => false, 'reason' => 'preço > R$ ' . $this->priceMax];
        }

        if ($this->requireVideo && !$p->hasVideo) {
            return ['ok' => false, 'reason' => 'sem vídeo (radar exige vídeo)'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /** @return array<string,mixed> para gravar em hr_radars */
    public function toRow(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'enabled' => $this->enabled ? 1 : 0,
            'marketplaces' => json_encode(array_values($this->marketplaces), JSON_UNESCAPED_UNICODE),
            'ml_categories' => json_encode(array_values($this->mlCategories), JSON_UNESCAPED_UNICODE),
            'shopee_keywords' => json_encode(array_values($this->shopeeKeywords), JSON_UNESCAPED_UNICODE),
            'extra_keywords' => json_encode(array_values($this->extraKeywords), JSON_UNESCAPED_UNICODE),
            'excluded_words' => json_encode(array_values($this->excludedWords), JSON_UNESCAPED_UNICODE),
            'pages_per_category' => $this->pagesPerCategory,
            'min_discount' => $this->minDiscount,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'require_video' => $this->requireVideo ? 1 : 0,
        ];
    }

    public static function slugify(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-') ?: 'radar';
    }
}
