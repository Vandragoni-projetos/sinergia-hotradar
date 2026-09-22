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

    /** Valores aceitos para $desiredWordsMode — qualquer outra coisa normaliza para 'any'. */
    public const DESIRED_WORDS_MODES = ['any', 'all'];

    /** 'any' (pelo menos um termo) | 'all' (todos os termos) — sempre um destes dois, nunca outro valor. */
    public string $desiredWordsMode;

    /** Página do feed Shopee em que a coleta começa — sempre ≥1. Não usado pelo Mercado Livre. */
    public int $shopeePageStart;

    /**
     * @param array<int,string>                        $marketplaces
     * @param array<int,array{id:string,label:string}> $mlCategories
     * @param array<int,string>                        $shopeeKeywords
     * @param array<int,string>                        $extraKeywords
     * @param array<int,string>                        $excludedWords
     * @param array<int,string>                        $desiredWords
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
        public array $desiredWords = [],
        string $desiredWordsMode = 'any',
        int $shopeePageStart = 1,
    ) {
        $this->desiredWordsMode = self::normalizeDesiredWordsMode($desiredWordsMode);
        $this->shopeePageStart = self::normalizeShopeePageStart($shopeePageStart);
    }

    /** Só 'any'/'all' passam; qualquer valor ausente/inválido normaliza para 'any' — nunca 'all' por acidente. */
    public static function normalizeDesiredWordsMode(?string $mode): string
    {
        return in_array($mode, self::DESIRED_WORDS_MODES, true) ? $mode : 'any';
    }

    /**
     * "Começar na página" do feed Shopee — mínimo 1, ausente/inválido/≤0 vira 1
     * (mesmo comportamento de hoje, que sempre começa em 1). Sem teto máximo:
     * a auditoria não encontrou nenhum limite comprovado da própria Shopee, só
     * o limite de QUANTIDADE de páginas (pages_per_category, continua 1–10).
     */
    public static function normalizeShopeePageStart(?int $page): int
    {
        return max(1, $page ?? 1);
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
            desiredWords: array_values(array_map('strval', $j('desired_words'))),
            desiredWordsMode: self::normalizeDesiredWordsMode(
                isset($row['desired_words_mode']) ? (string) $row['desired_words_mode'] : null
            ),
            shopeePageStart: isset($row['shopee_page_start']) ? (int) $row['shopee_page_start'] : 1,
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
     *
     * $videoFilterSupported: alguns marketplaces não informam se o produto tem
     * vídeo (ex.: Shopee, cujo mapper sempre grava hasVideo=false — a API de
     * afiliados não expõe isso). Passar false faz o filtro "Exigir vídeo" ser
     * ignorado nesse coletor específico, em vez de zerar a coleta inteira por
     * um dado que o marketplace nunca poderia satisfazer. Default true
     * preserva exatamente o comportamento já existente (Mercado Livre).
     *
     * @return array{ok:bool, reason:?string}
     */
    public function accepts(NormalizedProduct $p, bool $videoFilterSupported = true): array
    {
        $title = mb_strtolower($p->title, 'UTF-8');

        foreach ($this->excludedWords as $w) {
            $w = mb_strtolower(trim($w), 'UTF-8');
            if ($w !== '' && mb_strpos($title, $w) !== false) {
                return ['ok' => false, 'reason' => 'palavra excluída: "' . $w . '"'];
            }
        }

        $desired = array_values(array_filter(array_map(
            static fn ($w) => mb_strtolower(trim((string) $w), 'UTF-8'),
            $this->desiredWords
        ), static fn ($w) => $w !== ''));
        if ($desired !== []) {
            $hits = 0;
            foreach ($desired as $w) {
                if (mb_strpos($title, $w) !== false) {
                    $hits++;
                }
            }
            $matched = $this->desiredWordsMode === 'all' ? ($hits === count($desired)) : ($hits > 0);
            if (!$matched) {
                return ['ok' => false, 'reason' => 'não contém as palavras desejadas'];
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

        if ($this->requireVideo && $videoFilterSupported && !$p->hasVideo) {
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
            'desired_words' => json_encode(array_values($this->desiredWords), JSON_UNESCAPED_UNICODE),
            'desired_words_mode' => $this->desiredWordsMode,
            'shopee_page_start' => $this->shopeePageStart,
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
