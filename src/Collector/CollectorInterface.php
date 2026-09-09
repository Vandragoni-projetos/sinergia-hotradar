<?php
declare(strict_types=1);

namespace HotRadar\Collector;

/**
 * Contrato comum de coleta. Um adapter por marketplace.
 *   CollectorInterface
 *     ├── MercadoLivreCollector   (ativo)
 *     └── ShopeeCollector         (preparado, inativo — sem Open API)
 *
 * O DiscoveryService só conhece esta interface; nunca estruturas de um marketplace.
 */
interface CollectorInterface
{
    /** Identificador do marketplace: 'mercado_livre', 'shopee', ... */
    public function marketplace(): string;

    /** Fonte usada: 'ofertas_ml', 'mais_vendidos_ml', 'shopee_api', ... */
    public function source(): string;

    /** true se o collector tem tudo que precisa para rodar agora. */
    public function isAvailable(): bool;

    /** Motivo legível quando isAvailable() == false (ex.: "aguardando credenciais da Open API"). */
    public function unavailableReason(): ?string;

    /**
     * Executa a coleta e devolve produtos normalizados + diagnóstico.
     * NÃO grava no banco — isso é responsabilidade do DiscoveryService.
     */
    public function collect(CollectorContext $ctx): CollectorReport;
}
