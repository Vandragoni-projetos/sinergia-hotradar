<?php
declare(strict_types=1);

namespace HotRadar\Score;

use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;

/**
 * Calculadora do HOT SCORE V2 (REVISADA) — SHADOW MODE.
 *
 * NÃO é usada por nenhuma tela nem pela coleta ainda — só pelo comando
 * `hotscore:shadow-v2` e pela tela de comparação (?r=hotscore.compare).
 * A V1 (HotScore) continua sendo a fonte oficial em todo o resto do sistema.
 *
 * Diferença estrutural para a V1: o score é calculado em duas etapas
 * separadas, porque a aderência deixou de ser um atributo global do
 * produto e passou a ser uma relação (produto × radar):
 *
 *   evaluateBase()      → 5 blocos que são FATOS do produto (não dependem
 *                         de qual radar está olhando: desconto, vendas,
 *                         avaliação, destaque, visual). Máximo 82.
 *   resolveAdherence()  → aderência NO CONTEXTO de um radar específico,
 *                         calculada a partir da própria configuração
 *                         daquele radar (categorias + extra_keywords do
 *                         usuário) — nunca de um classificador global
 *                         hardcoded para um único nicho. Máximo 18.
 *   evaluateForRadar()  → BASE + aderência(radar), classificado. Máximo 100.
 *
 * Mesma regra de ouro da V1: nunca inventa valor para dado ausente.
 */
final class HotScoreV2
{
    public function __construct(private readonly HotScoreV2Config $cfg)
    {
    }

    /**
     * Componentes independentes de radar (fatos do produto). Não classifica
     * ainda — falta somar a aderência contextual de um radar específico.
     */
    public function evaluateBase(NormalizedProduct $p): ScoreBreakdown
    {
        $b = new ScoreBreakdown();
        $b->version = $this->cfg->version();

        $this->scoreDesconto($p, $b);
        $this->scoreVendas($p, $b);
        $this->scoreAvaliacao($p, $b);
        $this->scoreDestaque($p, $b);
        $this->scoreVisual($p, $b);

        return $b;
    }

    /**
     * Aderência do produto a ESTE radar específico. Não usa nenhum
     * vocabulário fixo de outro nicho — usa só o que o próprio radar tem
     * configurado (categorias escolhidas pelo usuário + extra_keywords).
     *
     * @return array{level:string, points:int, detail:string, hits:int}
     */
    public function resolveAdherence(NormalizedProduct $p, Radar $radar): array
    {
        $cfg = $this->cfg->aderencia();
        $levels = (array) ($cfg['levels'] ?? []);
        $hitsForAlta = (int) ($cfg['keyword_hits_for_alta'] ?? 2);

        $title = mb_strtolower($p->title, 'UTF-8');
        $hits = 0;
        foreach ($radar->extraKeywords as $kw) {
            $kw = mb_strtolower(trim((string) $kw), 'UTF-8');
            if ($kw !== '' && mb_strpos($title, $kw) !== false) {
                $hits++;
            }
        }

        $hasCategories = $radar->mlCategoryIds() !== [];
        $smallVocabulary = count($radar->extraKeywords) > 0 && count($radar->extraKeywords) <= $hitsForAlta - 1;

        if ($hits >= $hitsForAlta) {
            $level = 'alta';
        } elseif ($hits >= 1 && ($hasCategories === false || $smallVocabulary)) {
            // 1 hit já é forte quando o radar tem poucas keywords cadastradas,
            // ou quando o radar não tem categoria (só teria as keywords como critério).
            $level = 'alta';
        } elseif ($hasCategories) {
            // PISO: o produto só está associado a este radar porque passou
            // pelo filtro de categoria/exclusão dele (Radar::accepts()) —
            // por definição do próprio usuário, ele pertence a este nicho.
            $level = 'media';
        } else {
            // radar sem nenhuma categoria configurada (ex.: associação
            // manual/"Analisar por URL") — não há critério objetivo do
            // radar para afirmar aderência.
            $level = 'baixa';
        }

        $points = (int) ($levels[$level] ?? 0);
        $detail = match ($level) {
            'alta' => "encaixe forte com \"{$radar->name}\" ({$hits} palavra(s)-chave do radar no título)",
            'media' => "produto está na(s) categoria(s) configurada(s) do radar \"{$radar->name}\"",
            'baixa' => "radar \"{$radar->name}\" sem categoria configurada — aderência não confirmada",
            default => "sem encaixe identificado com \"{$radar->name}\"",
        };

        return ['level' => $level, 'points' => $points, 'detail' => $detail, 'hits' => $hits];
    }

    /**
     * BASE + aderência no contexto de UM radar, já classificado (faixa).
     * Recalcula a BASE internamente (barato, sem I/O) para nunca correr o
     * risco de reaproveitar um ScoreBreakdown já mutado por outro radar.
     */
    public function evaluateForRadar(NormalizedProduct $p, Radar $radar): ScoreBreakdown
    {
        $b = $this->evaluateBase($p);
        $ad = $this->resolveAdherence($p, $radar);
        $adMax = (int) ($this->cfg->aderencia()['max'] ?? 0);
        $b->addComponent('aderencia', (string) ($this->cfg->aderencia()['label'] ?? 'Aderência ao radar'), $ad['points'], $adMax, $ad['detail'], $ad['points'] > 0);

        $this->classify($b);
        return $b;
    }

    // ------------------------------------------------------------- blocos BASE

    private function scoreDesconto(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('desconto');
        $max = (int) $cfg['max'];
        if ($p->discountPct === null) {
            $b->addComponent('desconto', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem desconto informado', false);
            return;
        }
        $factor = (float) ($cfg['factor'] ?? 0.52);
        $pts = min($max, (int) round($factor * $p->discountPct));
        $pts = max(0, $pts);
        $b->addComponent('desconto', $cfg['label'], $pts, $max, $p->discountPct . '% OFF (fórmula contínua, sem degraus)', true);
    }

    private function scoreVendas(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('vendas');
        $max = (int) $cfg['max'];

        $signal = $p->salesSignal;
        $detail = '';
        if ($signal === null && $p->salesExact !== null) {
            foreach ($cfg['exact_to_signal'] as $rule) {
                if ($p->salesExact >= $rule['min']) {
                    $signal = $rule['signal'];
                    break;
                }
            }
            $detail = number_format((float) $p->salesExact, 0, ',', '.') . ' vendas → ' . ($signal ?? '—') . '. ';
        }

        if ($signal === null) {
            $floorSignals = (array) ($cfg['absent_floor_signals'] ?? []);
            $hasFloorSignal = array_intersect($floorSignals, $p->specialSignals) !== []
                || (in_array('official_store', $floorSignals, true) && (bool) ($p->marketplaceExtra['official_store'] ?? false));
            if ($hasFloorSignal) {
                $pts = (int) ($cfg['absent_floor_points'] ?? 0);
                $b->addComponent('vendas', $cfg['label'], $pts, $max, 'sem faixa de vendas declarada, mas com sinal indireto de tração (loja oficial/candidato a mais vendido)', true);
                return;
            }
            $b->addComponent('vendas', $cfg['label'], (int) $cfg['absent_points'], $max, 'sinal de vendas não disponível', false);
            return;
        }

        $pts = (int) ($cfg['signal_points'][$signal] ?? 0);
        $detail .= 'sinal "' . $signal . '"';
        $b->addComponent('vendas', $cfg['label'], $pts, $max, $detail, true);
    }

    private function scoreAvaliacao(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('avaliacao');
        $max = (int) $cfg['max'];
        if ($p->rating === null) {
            $b->addComponent('avaliacao', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem avaliação (neutro, não penaliza)', false);
            return;
        }
        $pts = 3;
        foreach ($cfg['bands'] as $band) {
            if ($p->rating >= $band['min']) {
                $pts = (int) $band['points'];
                break;
            }
        }
        $rc = $p->ratingCount !== null ? " ({$p->ratingCount} avaliações)" : '';
        $b->addComponent('avaliacao', $cfg['label'], $pts, $max, 'nota ' . rtrim(rtrim(number_format($p->rating, 1, '.', ''), '0'), '.') . $rc, true);
    }

    private function scoreDestaque(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('destaque');
        $max = (int) $cfg['max'];

        $hasPos = $p->rankPosition !== null;
        $hasPromo = $p->campaign !== null && isset($cfg['promo_bonus'][$p->campaign]);
        $hasOfficialStore = in_array('official_store', $p->specialSignals, true)
            || (bool) ($p->marketplaceExtra['official_store'] ?? false);

        if (!$hasPos && !$hasPromo && !$hasOfficialStore) {
            $b->addComponent('destaque', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem posição, promoção ou loja oficial', false);
            return;
        }

        $pts = 0;
        $parts = [];
        if ($hasPromo) {
            $bonus = (int) $cfg['promo_bonus'][$p->campaign];
            $pts += $bonus;
            $parts[] = $p->campaign . ' (+' . $bonus . ')';
        }
        if ($hasPos) {
            foreach ($cfg['position_tiers'] as $tier) {
                if ($p->rankPosition <= $tier['max']) {
                    if ((int) $tier['points'] > 0) {
                        $pts += (int) $tier['points'];
                        $parts[] = 'posição ' . $p->rankPosition . ' (+' . $tier['points'] . ')';
                    }
                    break;
                }
            }
        }
        if ($hasOfficialStore) {
            $bonus = (int) ($cfg['official_store_points'] ?? 0);
            $pts += $bonus;
            $parts[] = 'loja oficial (+' . $bonus . ')';
        }
        $pts = min($pts, $max);
        $b->addComponent('destaque', $cfg['label'], $pts, $max, implode(', ', $parts), true);
    }

    private function scoreVisual(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('visual');
        $max = (int) $cfg['max'];

        $goodPic = array_intersect(['good_quality_picture', 'brand_verified'], $p->specialSignals) !== [];

        if (!$p->hasVideo && !$goodPic) {
            $b->addComponent('visual', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem vídeo nem sinal de foto de qualidade (não penaliza — bônus, não pré-requisito)', false);
            return;
        }
        $pts = 0;
        $parts = [];
        if ($p->hasVideo) {
            $pts += (int) $cfg['has_video_points'];
            $parts[] = '🎬 vídeo';
        }
        if ($goodPic) {
            $pts += (int) $cfg['good_picture_points'];
            $parts[] = 'foto de qualidade';
        }
        $pts = min($pts, $max);
        $b->addComponent('visual', $cfg['label'], $pts, $max, implode(', ', $parts), true);
    }

    private function classify(ScoreBreakdown $b): void
    {
        foreach ($this->cfg->faixas() as $faixa) {
            if ($b->total >= (int) $faixa['min']) {
                $b->faixaKey = (string) $faixa['key'];
                $b->faixaLabel = (string) $faixa['label'];
                $b->faixaEmoji = (string) $faixa['emoji'];
                return;
            }
        }
    }
}
