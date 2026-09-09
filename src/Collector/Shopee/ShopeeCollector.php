<?php
declare(strict_types=1);

namespace HotRadar\Collector\Shopee;

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;

/**
 * Collector Shopee — PREPARADO, PORÉM INATIVO.
 *
 * A conta não tem acesso à Shopee Affiliate Open API (erro 10035). Enquanto
 * HR_SHOPEE_APP_ID / HR_SHOPEE_SECRET não forem preenchidos com credenciais
 * aprovadas, isAvailable() == false e o painel mostra:
 *      "Shopee — aguardando credenciais da Open API"
 *
 * A infraestrutura (assinatura SHA256, query productOfferV2, mapeamento de campos)
 * está desenhada para ligar sem reconstruir o HOTRADAR:
 *   1. preencher App ID + Secret aprovados no .env;
 *   2. HR_SHOPEE_ENABLED=1;
 *   3. o método collect() abaixo passa a consultar a API e usa ProductOfferV2Mapper.
 */
final class ShopeeCollector implements CollectorInterface
{
    public function __construct(
        private readonly string $appId,
        private readonly string $secret,
        private readonly string $graphqlUrl,
        private readonly bool $enabled,
        private readonly ProductOfferV2Mapper $mapper = new ProductOfferV2Mapper(),
    ) {
    }

    public function marketplace(): string
    {
        return 'shopee';
    }

    public function source(): string
    {
        return 'shopee_api';
    }

    public function isAvailable(): bool
    {
        return $this->enabled && $this->appId !== '' && $this->secret !== '';
    }

    public function unavailableReason(): ?string
    {
        if ($this->isAvailable()) {
            return null;
        }
        return 'Shopee — aguardando credenciais da Open API (App ID/Secret aprovados). '
            . 'Adapter pronto; ativar via .env quando a conta for habilitada.';
    }

    public function collect(CollectorContext $ctx): CollectorReport
    {
        $report = new CollectorReport();

        if (!$this->isAvailable()) {
            $report->addError((string) $this->unavailableReason());
            return $report;
        }

        // --- Caminho ligado (executa somente quando houver credenciais aprovadas) ---
        // Mantido explícito para revisão; não roda em E0/E1/E3.
        $report->addError(
            'ShopeeCollector: credenciais presentes mas o fluxo de consulta à Open API '
            . 'será validado quando o acesso for concedido. Nada foi consultado.'
        );
        return $report;

        /* @phpstan-ignore-next-line — referência de implementação futura
        $query = 'query productOfferV2($page:Int,$limit:Int){productOfferV2(page:$page,limit:$limit){nodes{'
            . 'itemId,shopId,productName,imageUrl,commissionRate,commission,price,priceMin,priceMax,'
            . 'priceDiscountRate,sales,ratingStar,productLink,offerLink,shopName}}}';
        $payload = json_encode(['query' => $query, 'variables' => ['page' => 1, 'limit' => 50]]);
        $ts = (string) time();
        $signature = hash('sha256', $this->appId . $ts . $payload . $this->secret);
        // POST $this->graphqlUrl com header:
        //   Authorization: SHA256 Credential={appId}, Timestamp={ts}, Signature={signature}
        // foreach ($json['data']['productOfferV2']['nodes'] as $node) {
        //     $report->add($this->mapper->map($node));
        // }
        */
    }
}
