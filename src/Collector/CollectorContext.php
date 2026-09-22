<?php
declare(strict_types=1);

namespace HotRadar\Collector;

use HotRadar\Radar\Radar;

/**
 * Parâmetros de uma execução de coleta.
 *
 * Quando $radar é informado, ele MANDA: categorias, nº de páginas e filtros
 * (palavras excluídas, desconto mínimo, faixa de preço, exigência de vídeo)
 * saem todos do radar. $categories/$maxPages só valem no modo legado (CLI/testes).
 */
final class CollectorContext
{
    /**
     * @param array<int,string> $categories ids de categoria (modo legado — ignorado se houver $radar)
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly int $maxPages = 3,
        public readonly array $categories = [],
        public readonly bool $saveRawToDisk = false,
        public readonly ?Radar $radar = null,
    ) {
    }

    /** @return array<int,string> */
    public function effectiveCategories(): array
    {
        return $this->radar !== null ? $this->radar->mlCategoryIds() : $this->categories;
    }

    public function effectiveMaxPages(): int
    {
        return $this->radar !== null ? $this->radar->pagesPerCategory : $this->maxPages;
    }

    /**
     * Página em que o feed da Shopee começa a ser lido (só a Shopee usa isto —
     * o Mercado Livre sempre percorre cada categoria a partir da página 1,
     * sem nenhuma relação com este valor). Sem radar (modo legado/CLI), 1.
     */
    public function effectiveShopeePageStart(): int
    {
        return $this->radar !== null ? $this->radar->shopeePageStart : 1;
    }
}
