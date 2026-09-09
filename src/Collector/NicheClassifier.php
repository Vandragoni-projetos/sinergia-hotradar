<?php
declare(strict_types=1);

namespace HotRadar\Collector;

/**
 * Classificador de nicho por palavra-chave (sem IA nesta etapa — E5 pode plugar OpenAI).
 * Devolve [slug, confiança] onde confiança ∈ alta|media|baixa|fora.
 * Usado para o bloco "aderência ao nicho" do HOT SCORE.
 *
 * V1 mira o nicho-alvo "casa_cozinha_organizacao".
 */
final class NicheClassifier
{
    /** nicho-alvo da V1 */
    public const TARGET = 'casa_cozinha_organizacao';

    /** @var array<string,array<int,string>> slug => termos */
    private const KEYWORDS = [
        'casa_cozinha_organizacao' => [
            'organizador', 'organizadora', 'porta ', 'cesto', 'caixa organizadora', 'nicho',
            'prateleira', 'cabide', 'gaveteiro', 'armário', 'armario', 'sapateira',
            'pote', 'potes', 'hermético', 'hermetico', 'marmita', 'tupperware', 'vasilha',
            'panela', 'panelas', 'frigideira', 'assadeira', 'forma ', 'talher', 'talheres',
            'faca', 'facas', 'utensílio', 'utensilio', 'escorredor', 'ralador', 'espremedor',
            'jarra', 'garrafa', 'copo', 'copos', 'xícara', 'xicara', 'caneca',
            'air fryer', 'airfryer', 'fritadeira', 'liquidificador', 'batedeira', 'mixer',
            'cafeteira', 'sanduicheira', 'grill', 'panela elétrica', 'panela eletrica',
            'toalha', 'jogo americano', 'cortina', 'tapete', 'edredom', 'lençol', 'lencol',
            'travesseiro', 'almofada', 'varal', 'cabideiro', 'lixeira', 'rodo', 'vassoura',
            'mop', 'balde', 'pano de', 'dispenser', 'saboneteira', 'suporte', 'ganchos',
            'decoração', 'decoracao', 'quadro', 'vaso', 'luminária', 'luminaria', 'abajur',
            'cozinha', 'banheiro', 'lavanderia', 'closet', 'guarda-roupa', 'guarda roupa',
        ],
        'eletro_geral' => [
            'geladeira', 'fogão', 'fogao', 'micro-ondas', 'microondas', 'lava e seca',
            'máquina de lavar', 'maquina de lavar', 'ar condicionado', 'ventilador', 'aspirador',
            'purificador', 'bebedouro', 'freezer', 'adega', 'cooktop', 'coifa', 'depurador',
        ],
    ];

    /** @return array{slug:?string, confidence:?string} */
    public function classify(string $title, ?string $sourceCategoryLabel = null): array
    {
        $t = ' ' . mb_strtolower($title, 'UTF-8') . ' ';

        $best = null;
        $bestHits = 0;
        foreach (self::KEYWORDS as $slug => $terms) {
            $hits = 0;
            foreach ($terms as $term) {
                if (mb_strpos($t, $term) !== false) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $slug;
            }
        }

        // Sinal fraco vindo da categoria de origem da coleta
        $catHint = $sourceCategoryLabel !== null
            && preg_match('/casa|cozinha|eletro|decora|m[oó]vel|m[oó]veis/i', $sourceCategoryLabel) === 1;

        if ($best === self::TARGET && $bestHits >= 2) {
            return ['slug' => self::TARGET, 'confidence' => 'alta'];
        }
        if ($best === self::TARGET && $bestHits === 1) {
            return ['slug' => self::TARGET, 'confidence' => 'media'];
        }
        if ($best === 'eletro_geral' && $bestHits >= 1) {
            // eletro grande: relacionado, mas não é o foco "cozinha/organização"
            return ['slug' => 'eletro_geral', 'confidence' => $catHint ? 'media' : 'baixa'];
        }
        if ($catHint) {
            return ['slug' => self::TARGET, 'confidence' => 'baixa'];
        }
        return ['slug' => null, 'confidence' => 'fora'];
    }
}
