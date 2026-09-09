<?php
declare(strict_types=1);

namespace HotRadar\Collector\MercadoLivre;

use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Support\Http;

/**
 * Collector do Mercado Livre — fonte /ofertas (auditada como utilizável).
 *
 * NÃO faz: PDP, API com token, contorno de anti-bot, download de vídeo.
 * FAZ: GET público em https://www.mercadolivre.com.br/ofertas?category=<MLB>&page=<n>
 *      e parsing do JSON estruturado embutido.
 */
final class MercadoLivreCollector implements CollectorInterface
{
    private const BASE = 'https://www.mercadolivre.com.br/ofertas';

    public function __construct(
        private readonly Http $http,
        private readonly OfertasJsonParser $parser,
        private readonly int $requestDelayMs = 1500,
        /** @var array<int,string> */
        private readonly array $defaultCategories = [],
        private readonly string $storageDir = '',
    ) {
    }

    public function marketplace(): string
    {
        return 'mercado_livre';
    }

    public function source(): string
    {
        return 'ofertas_ml';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function collect(CollectorContext $ctx): CollectorReport
    {
        $report = new CollectorReport();

        $categories = $ctx->categories !== [] ? $ctx->categories : $this->defaultCategories;
        if ($categories === []) {
            $categories = MlCategories::presetCasaCozinhaOrganizacao();
        }

        $seen = [];
        foreach ($categories as $catId) {
            $catId = strtoupper(trim($catId));
            $catLabel = MlCategories::label($catId);

            for ($page = 1; $page <= max(1, $ctx->maxPages); $page++) {
                $url = self::BASE . '?category=' . rawurlencode($catId);
                if ($page > 1) {
                    $url .= '&page=' . $page;
                }

                $res = $this->http->get($url);
                $report->pagesFetched++;

                $entry = [
                    'url' => $url,
                    'status' => $res['status'],
                    'bytes' => $res['bytes'],
                    'cards' => 0,
                    'error' => $res['error'],
                ];

                if ($res['error'] !== null) {
                    $report->addError("ML {$catId} p{$page}: cURL {$res['error']}");
                    $report->requests[] = $entry;
                    break;
                }
                if ($res['status'] !== 200) {
                    $report->addError("ML {$catId} p{$page}: HTTP {$res['status']}");
                    $report->requests[] = $entry;
                    break;
                }
                if (stripos($res['final_url'], 'account-verification') !== false || stripos($res['final_url'], '/gz/') !== false) {
                    $report->addError("ML {$catId} p{$page}: bloqueio anti-bot (redirect para {$res['final_url']})");
                    $report->requests[] = $entry;
                    break;
                }

                if ($ctx->saveRawToDisk && $this->storageDir !== '') {
                    @file_put_contents(
                        $this->storageDir . "/ofertas_{$catId}_p{$page}_" . date('Ymd_His') . '.html',
                        $res['body']
                    );
                }

                $parsed = $this->parser->parse($res['body'], $catId, $catLabel);
                $entry['cards'] = $parsed['cards'];
                $report->cardsSeen += $parsed['cards'];
                $report->requests[] = $entry;

                if (!$parsed['ok']) {
                    $report->addError("ML {$catId} p{$page}: " . ($parsed['reason'] ?? 'parsing falhou'));
                    break;
                }

                $newInPage = 0;
                foreach ($parsed['items'] as $np) {
                    $key = $np->dedupeKey();
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $report->add($np);
                    $newInPage++;
                }

                // Página sem cards novos = provavelmente fim do pool desta categoria.
                if ($parsed['cards'] === 0 || $newInPage === 0) {
                    break;
                }

                if ($this->requestDelayMs > 0 && $page < $ctx->maxPages) {
                    usleep($this->requestDelayMs * 1000);
                }
            }

            if ($this->requestDelayMs > 0) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        return $report;
    }
}
