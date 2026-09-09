<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  HOT SCORE V1 — FONTE ÚNICA DA FÓRMULA
 * ============================================================================
 * Toda a matemática do HOT SCORE sai daqui. Nenhum peso deve ser hardcoded em
 * outro arquivo. Em produção este arquivo é o default; um override em JSON pode
 * ser gravado em hr_settings (chave 'hotscore') sem editar código.
 *
 * Regra de ouro (auditoria): NÃO inventar valores para campos ausentes.
 *  - Campo ausente => aquele bloco contribui com 0 pontos...
 *  - ...EXCETO 'avaliacao', que tem um neutro explícito (produto sem review não
 *    é penalizado como se fosse mal avaliado).
 * A soma dos "max" dos blocos é 100.
 * ============================================================================
 * @return array<string,mixed>
 */
return [
    'version' => 'v1',

    'blocks' => [

        // -------- Desconto (até 30) --------
        'desconto' => [
            'max' => 30,
            'label' => 'Desconto',
            // faixa de desconto % => pontos (primeira faixa cujo "min" o valor atinge)
            'tiers' => [
                ['min' => 50, 'points' => 30],
                ['min' => 40, 'points' => 25],
                ['min' => 30, 'points' => 19],
                ['min' => 20, 'points' => 12],
                ['min' => 10, 'points' => 6],
                ['min' => 1,  'points' => 2],
            ],
            'absent_points' => 0,
        ],

        // -------- Sinal de vendas (até 22) --------
        // Vem da faixa textual do ML ("+100mil vendidos") normalizada em sales_signal,
        // ou do número exato (Shopee) normalizado para a mesma escala.
        'vendas' => [
            'max' => 22,
            'label' => 'Vendas',
            'signal_points' => [
                'muito_alto' => 22,   // +50mil / +100mil
                'alto' => 16,         // +10mil / +25mil
                'medio' => 10,        // +1000 / +5mil
                'baixo' => 6,         // +100 / +500 / apenas tag best_seller_candidate
            ],
            // se só houver número exato (Shopee), mapear para signal por estes cortes:
            'exact_to_signal' => [
                ['min' => 20000, 'signal' => 'muito_alto'],
                ['min' => 5000,  'signal' => 'alto'],
                ['min' => 500,   'signal' => 'medio'],
                ['min' => 1,     'signal' => 'baixo'],
            ],
            'absent_points' => 0,
        ],

        // -------- Avaliação (até 14) --------
        'avaliacao' => [
            'max' => 14,
            'label' => 'Avaliação',
            'bands' => [
                ['min' => 4.8, 'points' => 14],
                ['min' => 4.5, 'points' => 11],
                ['min' => 4.0, 'points' => 7],
                ['min' => 0.0, 'points' => 2],
            ],
            // NEUTRO explícito quando não há nota (não penaliza):
            'absent_points' => 6,
            'absent_is_neutral' => true,
        ],

        // -------- Posição / destaque (até 10) --------
        'destaque' => [
            'max' => 10,
            'label' => 'Destaque',
            'position_tiers' => [
                ['max' => 5,  'points' => 8],
                ['max' => 15, 'points' => 6],
                ['max' => 30, 'points' => 3],
                ['max' => 99999, 'points' => 1],
            ],
            // bônus por promo especial (somado, com teto no "max" do bloco)
            'promo_bonus' => [
                'DEAL_OF_THE_DAY' => 2,
                'LIGHTNING_DEAL' => 2,
                'BUY_BOX_WINNER' => 1,
            ],
            'absent_points' => 0,
        ],

        // -------- Potencial visual (até 14) --------
        'visual' => [
            'max' => 14,
            'label' => 'Potencial visual',
            'has_video_points' => 9,          // tag has_published_clips
            'good_picture_points' => 5,       // tag good_quality_picture / brand_verified
            'absent_points' => 0,
        ],

        // -------- Aderência ao nicho (até 10) --------
        'aderencia' => [
            'max' => 10,
            'label' => 'Aderência',
            'confidence_points' => [
                'alta' => 10,
                'media' => 6,
                'baixa' => 2,
                'fora' => 0,
            ],
            'absent_points' => 0,
        ],
    ],

    // Classificação final
    'faixas' => [
        ['min' => 85, 'key' => 'muito_quente', 'emoji' => '🔥', 'label' => 'MUITO QUENTE'],
        ['min' => 70, 'key' => 'bom',          'emoji' => '🟠', 'label' => 'BOM CANDIDATO'],
        ['min' => 50, 'key' => 'analisar',     'emoji' => '🟡', 'label' => 'ANALISAR'],
        ['min' => 0,  'key' => 'baixo',        'emoji' => '⚪', 'label' => 'BAIXA PRIORIDADE'],
    ],
];
