<?php
declare(strict_types=1);

namespace HotRadar\Collector;

/**
 * Parâmetros de uma execução de coleta.
 */
final class CollectorContext
{
    /**
     * @param array<int,string> $categories ids/keywords de categoria-alvo (vazio = preset do collector)
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly int $maxPages = 3,
        public readonly array $categories = [],
        public readonly bool $saveRawToDisk = false,
    ) {
    }
}
