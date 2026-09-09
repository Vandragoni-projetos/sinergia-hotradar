<?php
declare(strict_types=1);

namespace HotRadar\Collector;

use HotRadar\Model\NormalizedProduct;

/**
 * Resultado de uma coleta: produtos normalizados + diagnóstico da execução.
 */
final class CollectorReport
{
    /** @var array<int,NormalizedProduct> */
    public array $products = [];
    /** @var array<int,string> */
    public array $errors = [];
    public int $pagesFetched = 0;
    public int $cardsSeen = 0;
    /** @var array<int,array{url:string,status:int,bytes:int,cards:int,error:?string}> */
    public array $requests = [];

    public function addError(string $msg): void
    {
        $this->errors[] = $msg;
    }

    public function add(NormalizedProduct $p): void
    {
        $this->products[] = $p;
    }
}
