<?php
declare(strict_types=1);

namespace HotRadar\Collector\Shopee;

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Integration\ShopeeStatus;

/**
 * Collector Shopee — Shopee Affiliate Open API (GraphQL), recurso `productOfferV2`.
 *
 * Sem credenciais ou sem acesso à Open API concedido → isAvailable() == false e
 * NENHUMA chamada é feita (ver ShopeeStatus). Com tudo pronto, pagina o feed de
 * ofertas (`page`/`limit`, sem parâmetro de busca — a API não documenta filtro
 * por palavra-chave neste recurso) e normaliza cada node via ProductOfferV2Mapper.
 *
 * httpPost() é um seam protegido só para permitir teste com fake/mock (sem
 * chamada de rede real) — mesmo padrão já usado em OpenAiClient::httpPost().
 */
class ShopeeCollector implements CollectorInterface
{
    /**
     * Verbatim a mesma query já sketchada (comentada) na versão anterior deste
     * arquivo — não adiciono nem invento campo novo. Alguns campos que o
     * ProductOfferV2Mapper já sabe ler (productCatIds, periodStartTime/EndTime)
     * NÃO estão aqui porque não estavam na query original; o mapper já trata
     * a ausência deles com segurança (vira null, nunca inventa valor).
     */
    private const GRAPHQL_QUERY = 'query productOfferV2($page:Int,$limit:Int){productOfferV2(page:$page,limit:$limit){nodes{'
        . 'itemId,shopId,productName,imageUrl,commissionRate,commission,price,priceMin,priceMax,'
        . 'priceDiscountRate,sales,ratingStar,productLink,offerLink,shopName}}}';
    private const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly string $appId,
        private readonly string $secret,
        private readonly string $graphqlUrl,
        private readonly ShopeeStatus $status,
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
        return $this->status->collectorEnabled() && $this->appId !== '' && $this->secret !== '';
    }

    public function unavailableReason(): ?string
    {
        if ($this->isAvailable()) {
            return null;
        }
        return match ($this->status->state()) {
            ShopeeStatus::WAITING_OPEN_API =>
                'Shopee — credenciais presentes, aguardando acesso à Open API ser concedido pela Shopee. '
                . 'Marque "acesso concedido" em Configurações → Shopee quando a conta for habilitada.',
            default =>
                'Shopee — não configurado. Defina SHOPEE_APP_ID e SHOPEE_SECRET no Environment do EasyPanel.',
        };
    }

    public function collect(CollectorContext $ctx): CollectorReport
    {
        $report = new CollectorReport();

        if (!$this->isAvailable()) {
            $report->addError((string) $this->unavailableReason());
            return $report;
        }

        $radar = $ctx->radar;
        $startPage = max(1, $ctx->effectiveShopeePageStart());
        $endPage = max($startPage, $ctx->effectiveShopeePageEnd());
        $filtered = 0;
        $seen = [];

        for ($page = $startPage; $page <= $endPage; $page++) {
            $payload = json_encode([
                'query' => self::GRAPHQL_QUERY,
                'variables' => ['page' => $page, 'limit' => self::DEFAULT_LIMIT],
            ], JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                $report->addError('Shopee: falha ao montar o payload da requisição.');
                break;
            }

            $ts = (string) time();
            $signature = hash('sha256', $this->appId . $ts . $payload . $this->secret);
            $headers = [
                'Content-Type: application/json',
                'Authorization: SHA256 Credential=' . $this->appId . ', Timestamp=' . $ts . ', Signature=' . $signature,
            ];

            $res = $this->httpPost($payload, $headers);
            $report->pagesFetched++;
            $entry = ['url' => $this->graphqlUrl, 'status' => $res['status'], 'bytes' => strlen((string) $res['body']), 'cards' => 0, 'error' => $res['error']];

            if ($res['error'] !== null) {
                $report->addError("Shopee p{$page}: falha de rede — {$res['error']}");
                $report->requests[] = $entry;
                break;
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                // NÃO ecoa o corpo da resposta (pode conter detalhes internos da conta). Mensagem genérica.
                $report->addError("Shopee p{$page}: HTTP {$res['status']}.");
                $report->requests[] = $entry;
                break;
            }

            $json = json_decode((string) $res['body'], true);
            if (!is_array($json)) {
                $report->addError("Shopee p{$page}: resposta não é JSON válido.");
                $report->requests[] = $entry;
                break;
            }
            if (isset($json['errors'])) {
                $report->addError("Shopee p{$page}: a API retornou erro (ver detalhes no painel de desenvolvedor da Shopee).");
                $report->requests[] = $entry;
                break;
            }

            $nodes = $json['data']['productOfferV2']['nodes'] ?? null;
            if (!is_array($nodes)) {
                $report->addError("Shopee p{$page}: resposta sem 'data.productOfferV2.nodes'.");
                $report->requests[] = $entry;
                break;
            }

            $entry['cards'] = count($nodes);
            $report->cardsSeen += count($nodes);
            $report->requests[] = $entry;

            $newInPage = 0;
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $np = $this->mapper->map($node);
                $key = $np->dedupeKey();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $newInPage++;

                if ($radar !== null) {
                    // videoFilterSupported: false — a Shopee (API de afiliados) nunca informa
                    // se o produto tem vídeo; "Exigir vídeo" nunca pode zerar a coleta Shopee
                    // por um dado que este marketplace estruturalmente não fornece.
                    $verdict = $radar->accepts($np, videoFilterSupported: false);
                    if (!$verdict['ok']) {
                        $filtered++;
                        continue;
                    }
                    $np->radarSlug = $radar->slug;
                    $np->radarId = $radar->id;
                }

                $report->add($np);
            }

            // Página sem nodes novos = fim do feed disponível.
            if (count($nodes) === 0 || $newInPage === 0) {
                break;
            }
        }

        $report->filteredByRadar = $filtered;
        return $report;
    }

    /**
     * Único ponto que fala com a rede. Protegido para poder ser substituído
     * por uma fake em teste (sem chamada real). Nunca loga/retorna app_id/secret.
     * @param array<int,string> $headers
     * @return array{status:int, body:?string, error:?string}
     */
    protected function httpPost(string $body, array $headers): array
    {
        $ch = curl_init($this->graphqlUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($res) ? $res : null, 'error' => $error];
    }
}
