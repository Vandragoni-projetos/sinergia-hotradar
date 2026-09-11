<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  HOT SCORE V2 (REVISADA) — SHADOW MODE — FONTE ÚNICA DA FÓRMULA
 * ============================================================================
 * NÃO é usada em nenhuma tela/coleta ainda. É calculada só pelo comando
 * `php bin/hr.php hotscore:shadow-v2` e gravada nas colunas contextuais de
 * hr_product_radars, para comparação (ver ?r=hotscore.compare). A V1
 * (config/hotscore.php) continua sendo a fonte oficial em todo o resto do
 * sistema.
 *
 * Diferença estrutural para a V1: o HOT SCORE V2 é dividido em duas partes:
 *
 *   BASE (blocks abaixo)  — fatos do produto, independentes de radar.
 *                           soma dos "max" = 82.
 *   ADERÊNCIA (aderencia) — quanto o produto encaixa em CADA radar
 *                           (contextual — não é mais um classificador global
 *                           hardcoded para um único nicho). max = 18.
 *
 *   HOT SCORE V2 (produto, radar) = BASE(produto) + ADERÊNCIA(produto, radar)
 *   Total: 0–100.
 *
 * Regra de ouro (auditoria) mantida: NÃO inventar valores para campos
 * ausentes. Campo ausente => aquele bloco contribui 0, exceto 'avaliacao'
 * (neutro) e o piso residual de 'vendas' quando há sinal indireto de tração
 * já coletado (official_store / best_seller_candidate).
 * ============================================================================
 * @return array<string,mixed>
 */
return [
    'version' => 'v2',

    'blocks' => [

        // -------- Desconto (até 26) — fórmula CONTÍNUA, sem degraus --------
        // pontos = min(max, round(fator × desconto_%)) — cada 1% de desconto
        // move ~0,5 ponto; elimina os "cliffs" de 5-6 pontos por 1-2% de
        // diferença que existiam nas faixas fixas da V1.
        'desconto' => [
            'max' => 26,
            'label' => 'Desconto',
            'factor' => 0.52,
            'absent_points' => 0,
        ],

        // -------- Sinal de vendas (até 20) --------
        // Mesma lógica categórica da V1 (o ML só entrega uma faixa textual,
        // não um número — não há o que suavizar aqui). Cobertura real
        // auditada: 98,6% dos produtos têm este sinal.
        'vendas' => [
            'max' => 20,
            'label' => 'Vendas',
            'signal_points' => [
                'muito_alto' => 20,
                'alto' => 15,
                'medio' => 10,
                'baixo' => 6,
            ],
            'exact_to_signal' => [
                ['min' => 20000, 'signal' => 'muito_alto'],
                ['min' => 5000,  'signal' => 'alto'],
                ['min' => 500,   'signal' => 'medio'],
                ['min' => 1,     'signal' => 'baixo'],
            ],
            'absent_points' => 0,
            // piso residual: sem faixa declarada, mas com sinal indireto de
            // tração JÁ COLETADO (não inventa número, só evita zerar de vez).
            'absent_floor_points' => 4,
            'absent_floor_signals' => ['official_store', 'best_seller_candidate'],
        ],

        // -------- Avaliação (até 16) — MESMAS faixas da V1 (4.8/4.5/4.0) --------
        // Os cortes não mudam: o ML /ofertas não fornece rating_count, então
        // qualquer novo corte (ex.: 4.9) seria um chute sem dado para validar.
        // Só os pesos mudam (16/12/7/3, neutro 8).
        'avaliacao' => [
            'max' => 16,
            'label' => 'Avaliação',
            'bands' => [
                ['min' => 4.8, 'points' => 16],
                ['min' => 4.5, 'points' => 12],
                ['min' => 4.0, 'points' => 7],
                ['min' => 0.0, 'points' => 3],
            ],
            'absent_points' => 8,
            'absent_is_neutral' => true,
            // Evolução FUTURA (não implementada): se o coletor um dia capturar
            // rating_count, um sub-bônus de confiança poderia diferenciar
            // "4.9 com poucas avaliações" de "4.8 com milhares" — sem
            // estourar o teto de 16. Hoje esse dado não existe; não é inventado.
        ],

        // -------- Destaque (até 8) — separa sinal real de ruído --------
        // rank_position é a posição do card na página de ofertas (mistura
        // relevância/patrocínio/frescor do próprio ML) — NÃO é ranking de
        // mais vendidos. Peso reduzido de propósito (era até 8 na V1).
        // official_store nunca era usado na V1 apesar de já coletado.
        'destaque' => [
            'max' => 8,
            'label' => 'Destaque',
            'position_tiers' => [
                ['max' => 5,  'points' => 2],
                ['max' => 15, 'points' => 1],
                ['max' => 99999, 'points' => 0],
            ],
            'promo_bonus' => [
                'DEAL_OF_THE_DAY' => 4,
                'LIGHTNING_DEAL' => 4,
                'BUY_BOX_WINNER' => 2,
            ],
            'official_store_points' => 2,
            'absent_points' => 0,
        ],

        // -------- Visual / vídeo (até 12) — BÔNUS, nunca eliminatório --------
        // Ausência de vídeo contribui 0 (não penaliza) — o produto continua
        // podendo chegar a "Muito quente" só com a BASE + aderência alta
        // (26+20+16+8+0 = 70; +18 de aderência alta = 88).
        'visual' => [
            'max' => 12,
            'label' => 'Vídeo / foto',
            'has_video_points' => 8,
            'good_picture_points' => 4,
            'absent_points' => 0,
        ],
    ],

    // -------- Aderência CONTEXTUAL (produto × radar) — até 18 --------
    // Substitui o NicheClassifier hardcoded (casa/cozinha/organização).
    // Calculada a partir da PRÓPRIA configuração do radar (categorias que o
    // usuário escolheu + extra_keywords do radar), nunca de um vocabulário
    // fixo de outro nicho. Ver HotScoreV2::resolveAdherence().
    //
    //   media (piso) — garantida sempre que o produto está associado ao
    //                  radar através de uma categoria configurada nele
    //                  (ou seja: o próprio fluxo de coleta do radar já
    //                  filtrou por Radar::accepts() e pela categoria certa).
    //   alta         — quando o título bate com >= keyword_hits_for_alta
    //                  das extra_keywords configuradas NESTE radar (ou 1
    //                  hit, se o radar tiver poucas keywords cadastradas).
    //   baixa        — radar sem NENHUMA categoria configurada (ex.: radar
    //                  usado só para associações manuais/"Analisar por
    //                  URL") — não dá para afirmar aderência sem critério.
    //   fora         — reservado; não deve ocorrer no fluxo normal de coleta.
    'aderencia' => [
        'max' => 18,
        'label' => 'Aderência ao radar',
        'levels' => [
            'alta' => 18,
            'media' => 12,
            'baixa' => 5,
            'fora' => 0,
        ],
        'keyword_hits_for_alta' => 2,
    ],

    // Classificação final — MESMOS cortes da V1. A seletividade de "Muito
    // quente" passa a vir de exigência real (BASE forte + encaixe genuíno no
    // radar), não de um acidente de nicho ou de ter/não ter vídeo.
    'faixas' => [
        ['min' => 85, 'key' => 'muito_quente', 'emoji' => '🔥', 'label' => 'MUITO QUENTE'],
        ['min' => 70, 'key' => 'bom',          'emoji' => '🟠', 'label' => 'BOM CANDIDATO'],
        ['min' => 50, 'key' => 'analisar',     'emoji' => '🟡', 'label' => 'ANALISAR'],
        ['min' => 0,  'key' => 'baixo',        'emoji' => '⚪', 'label' => 'BAIXA PRIORIDADE'],
    ],
];
