<?php
declare(strict_types=1);

namespace HotRadar\Report;

use HotRadar\Integration\OpenAi\OpenAiClient;

/**
 * "Gerar análise inteligente": monta um payload de dados ESTRUTURADOS derivados
 * do banco e pede à OpenAI um resumo INTERPRETATIVO.
 *
 * Garantias:
 *  - A IA recebe só números já calculados por ReportService (nunca dados brutos soltos).
 *  - Instrução explícita: não inventar preço/desconto/venda/rating/score; dado ausente = "indisponível".
 *  - A IA NÃO altera o HOT SCORE nem grava nada. Saída é texto para exibição.
 */
final class AiReportService
{
    private const SYSTEM = <<<TXT
        Você é um analista de e-commerce. Recebe um JSON com métricas JÁ CALCULADAS do
        painel SINERGIA HOTRADAR (descoberta de produtos em marketplaces).
        Escreva um resumo interpretativo curto em português do Brasil (máx. ~250 palavras),
        em tópicos, destacando o que mudou e o que merece atenção da curadoria humana.

        REGRAS ABSOLUTAS:
        - Use SOMENTE os números presentes no JSON. NÃO invente nem estime preço, desconto,
          quantidade de vendas, avaliação, HOT SCORE ou qualquer valor.
        - Se um campo vier como null / ausente, escreva "indisponível" — nunca chute.
        - Não recalcule o HOT SCORE nem sugira fórmula; ele é definido em outro lugar.
        - Não recomende publicar/postar automaticamente; a decisão é humana.
        - Se o JSON tiver poucos dados, diga isso claramente em vez de encher linguiça.
        TXT;

    public function __construct(
        private readonly ReportService $reports,
        private readonly OpenAiClient $openai,
    ) {
    }

    public function isReady(): bool
    {
        return $this->openai->isReady();
    }

    /**
     * @param array<string,mixed> $filters radar/marketplace
     * @return array{ok:bool, text:?string, error:?string, payload:array<string,mixed>}
     */
    public function analyze(array $filters = []): array
    {
        $payload = $this->buildPayload($filters);

        if (!$this->openai->isReady()) {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'OpenAI não configurada. Defina OPENAI_API_KEY no Environment para habilitar a análise inteligente.',
                'payload' => $payload,
            ];
        }

        $res = $this->openai->summarize(self::SYSTEM, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return [
            'ok' => $res['ok'],
            'text' => $res['text'],
            'error' => $res['error'],
            'payload' => $payload,
        ];
    }

    /**
     * Dados estruturados que vão para a IA (e que também podem ser mostrados "crus" na tela).
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    public function buildPayload(array $f = []): array
    {
        $last = $this->reports->lastRun();
        $movers = $this->reports->scoreMovers($f, 10);
        $drops = $this->reports->priceDrops($f, 10);
        $top = $this->reports->topHotScores($f, 10);

        $slim = static fn (array $rows, array $keys): array => array_map(
            static fn ($r) => array_intersect_key($r, array_flip($keys)),
            $rows
        );

        return [
            'gerado_em' => date('c'),
            'filtro' => [
                'radar' => $f['radar'] ?? null,
                'marketplace' => $f['marketplace'] ?? null,
            ],
            'ultima_coleta' => $last['exists'] ? [
                'quando' => $last['run']['started_at'] ?? null,
                'marketplace' => $last['run']['marketplace'] ?? null,
                'radar' => $last['run']['radar_slug'] ?? null,
                'status' => $last['run']['status'] ?? null,
                'paginas' => (int) ($last['run']['pages_fetched'] ?? 0),
                'cards' => (int) ($last['run']['cards_seen'] ?? 0),
                'novos' => (int) ($last['run']['products_new'] ?? 0),
                'atualizados' => (int) ($last['run']['products_updated'] ?? 0),
                'snapshots' => (int) ($last['run']['snapshots_written'] ?? 0),
                'erros' => $last['errors'],
            ] : null,
            'distribuicao_faixa' => $this->reports->faixaDistribution($f),
            'distribuicao_status' => $this->reports->statusDistribution($f),
            'por_nicho' => $this->reports->byCategory($f),
            'por_radar' => $this->reports->byRadar(),
            'top_hot_scores' => $slim($top, ['title', 'marketplace', 'hot_score', 'hot_faixa', 'price_current', 'discount_pct', 'rating', 'sales_signal', 'has_video']),
            'subiram_de_score' => $slim($movers['up'], ['title', 'marketplace', 'prev_hot', 'last_hot', 'delta']),
            'cairam_de_score' => $slim($movers['down'], ['title', 'marketplace', 'prev_hot', 'last_hot', 'delta']),
            'quedas_de_preco' => $slim($drops, ['title', 'marketplace', 'prev_price', 'last_price', 'drop_abs', 'drop_pct']),
        ];
    }
}
