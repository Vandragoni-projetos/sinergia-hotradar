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

        $categories = $ctx->effectiveCategories();
        if ($categories === []) {
            $categories = $this->defaultCategories;
        }
        if ($categories === []) {
            $report->addError('ML: nenhuma categoria configurada. Configure um Radar com ao menos uma categoria do Mercado Livre.');
            return $report;
        }

        $radar = $ctx->radar;
        $maxPages = $ctx->effectiveMaxPages();
        $filtered = 0;

        $seen = [];
        foreach ($categories as $catId) {
            $catId = strtoupper(trim($catId));
            $catLabel = MlCategories::label($catId);

            for ($page = 1; $page <= max(1, $maxPages); $page++) {
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
                if (($parsed['reason'] ?? null) !== null && str_contains((string) $parsed['reason'], 'html_fallback')) {
                    $report->addError("AVISO ML {$catId} p{$page}: " . $parsed['reason']);
                }

                $newInPage = 0;
                foreach ($parsed['items'] as $np) {
                    $key = $np->dedupeKey();
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $newInPage++;

                    // Filtros do radar (palavras excluídas, desconto/preço mín-máx, exigir vídeo)
                    if ($radar !== null) {
                        $verdict = $radar->accepts($np);
                        if (!$verdict['ok']) {
                            $filtered++;
                            continue;
                        }
                        $np->radarSlug = $radar->slug;
                        $np->radarId = $radar->id;
                        // reforça keywords do radar na aderência ao nicho
                        $np = $this->applyRadarNicheBoost($np, $radar->extraKeywords);
                    }

                    $report->add($np);
                }

                // Página sem cards novos = provavelmente fim do pool desta categoria.
                if ($parsed['cards'] === 0 || $newInPage === 0) {
                    break;
                }

                if ($this->requestDelayMs > 0 && $page < $maxPages) {
                    $this->politeSleep();
                }
            }

            if ($this->requestDelayMs > 0) {
                $this->politeSleep();
            }
        }

        $report->filteredByRadar = $filtered;
        return $report;
    }

    /** Pausa com jitter (±40%) — reduz o padrão "robô" que o ML penaliza. */
    private function politeSleep(): void
    {
        $base = $this->requestDelayMs;
        $jittered = (int) ($base * (0.8 + (random_int(0, 800) / 1000)));
        usleep($jittered * 1000);
    }

    /**
     * Se o título casa com as keywords adicionais do radar, sobe a confiança de nicho
     * (impacta só o bloco "aderência" do HOT SCORE — que segue determinístico).
     */
    private function applyRadarNicheBoost(\HotRadar\Model\NormalizedProduct $p, array $keywords): \HotRadar\Model\NormalizedProduct
    {
        if ($keywords === []) {
            return $p;
        }
        $t = mb_strtolower($p->title, 'UTF-8');
        $hits = 0;
        foreach ($keywords as $kw) {
            $kw = mb_strtolower(trim($kw), 'UTF-8');
            if ($kw !== '' && mb_strpos($t, $kw) !== false) {
                $hits++;
            }
        }
        if ($hits >= 2 && $p->nicheConfidence !== 'alta') {
            $p->nicheConfidence = 'alta';
        } elseif ($hits === 1 && in_array($p->nicheConfidence, [null, 'fora', 'baixa'], true)) {
            $p->nicheConfidence = 'media';
        }
        return $p;
    }
}
