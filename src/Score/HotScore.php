<?php
declare(strict_types=1);

namespace HotRadar\Score;

use HotRadar\Model\NormalizedProduct;

/**
 * Calculadora do HOT SCORE V1. Toda a matemática vem de HotScoreConfig
 * (config/hotscore.php + override em hr_settings). Nada hardcoded aqui.
 *
 * Regra: campo ausente => 0 no bloco (exceto 'avaliacao', que tem neutro).
 * Nunca inventa valor. O detalhamento fica em ScoreBreakdown.
 */
final class HotScore
{
    public function __construct(private readonly HotScoreConfig $cfg)
    {
    }

    public function evaluate(NormalizedProduct $p): ScoreBreakdown
    {
        $b = new ScoreBreakdown();
        $b->version = $this->cfg->version();

        $this->scoreDesconto($p, $b);
        $this->scoreVendas($p, $b);
        $this->scoreAvaliacao($p, $b);
        $this->scoreDestaque($p, $b);
        $this->scoreVisual($p, $b);
        $this->scoreAderencia($p, $b);

        $this->classify($b);

        return $b;
    }

    private function scoreDesconto(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('desconto');
        $max = (int) $cfg['max'];
        if ($p->discountPct === null) {
            $b->addComponent('desconto', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem desconto informado', false);
            return;
        }
        $pts = 0;
        $hit = 'menos de ' . ($cfg['tiers'][count($cfg['tiers']) - 1]['min'] ?? 1) . '%';
        foreach ($cfg['tiers'] as $tier) {
            if ($p->discountPct >= $tier['min']) {
                $pts = (int) $tier['points'];
                $hit = $p->discountPct . '% OFF (faixa ≥' . $tier['min'] . '%)';
                break;
            }
        }
        $b->addComponent('desconto', $cfg['label'], $pts, $max, $hit, true);
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
            $b->addComponent(
                'avaliacao',
                $cfg['label'],
                (int) $cfg['absent_points'],
                $max,
                'sem avaliação (neutro, não penaliza)',
                false
            );
            return;
        }
        $pts = 2;
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

        if (!$hasPos && !$hasPromo) {
            $b->addComponent('destaque', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem posição/promoção', false);
            return;
        }

        $pts = 0;
        $parts = [];
        if ($hasPos) {
            foreach ($cfg['position_tiers'] as $tier) {
                if ($p->rankPosition <= $tier['max']) {
                    $pts += (int) $tier['points'];
                    $parts[] = 'posição ' . $p->rankPosition;
                    break;
                }
            }
        }
        if ($hasPromo) {
            $bonus = (int) $cfg['promo_bonus'][$p->campaign];
            $pts += $bonus;
            $parts[] = $p->campaign . ' (+' . $bonus . ')';
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
            $b->addComponent('visual', $cfg['label'], (int) $cfg['absent_points'], $max, 'sem vídeo nem sinal de foto de qualidade', false);
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

    private function scoreAderencia(NormalizedProduct $p, ScoreBreakdown $b): void
    {
        $cfg = $this->cfg->block('aderencia');
        $max = (int) $cfg['max'];
        if ($p->nicheConfidence === null) {
            $b->addComponent('aderencia', $cfg['label'], (int) $cfg['absent_points'], $max, 'nicho não classificado', false);
            return;
        }
        $pts = (int) ($cfg['confidence_points'][$p->nicheConfidence] ?? 0);
        $b->addComponent('aderencia', $cfg['label'], $pts, $max, 'nicho: confiança ' . $p->nicheConfidence, true);
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
