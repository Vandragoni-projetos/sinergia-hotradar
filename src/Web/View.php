<?php
declare(strict_types=1);

namespace HotRadar\Web;

final class View
{
    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = []): string
    {
        $file = HR_ROOT . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View não encontrada: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    public static function page(string $template, array $data, string $title): void
    {
        $content = self::render($template, $data);
        echo self::render('layout', ['content' => $content, 'title' => $title, 'active' => $data['active'] ?? '']);
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** Campo hidden com o token CSRF — usar em TODO formulário POST. */
    public static function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e(Auth::csrfToken()) . '">';
    }

    /** Renderiza uma página "solta" (sem o layout do painel) — usado no login e nas telas de impressão. */
    public static function bare(string $template, array $data, string $title): void
    {
        echo self::render('bare', ['content' => self::render($template, $data), 'title' => $title]);
    }

    public static function money(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    public static function faixaBadge(?string $faixa): string
    {
        return match ($faixa) {
            'muito_quente' => '🔥 MUITO QUENTE',
            'bom' => '🟠 BOM',
            'analisar' => '🟡 ANALISAR',
            'baixo' => '⚪ BAIXO',
            default => '—',
        };
    }

    public static function salesLabel(?string $s): string
    {
        return match ($s) {
            'muito_alto' => 'Vendas: muito alto',
            'alto' => 'Vendas: alto',
            'medio' => 'Vendas: médio',
            'baixo' => 'Vendas: baixo',
            default => 'Vendas: n/d',
        };
    }

    // -------- vocabulário comercial (sem jargão técnico) --------

    public static function faixaNome(?string $faixa): string
    {
        return match ($faixa) {
            'muito_quente' => 'Muito quente',
            'bom' => 'Bom candidato',
            'analisar' => 'Analisar',
            'baixo' => 'Baixa prioridade',
            default => '—',
        };
    }

    public static function faixaEmoji(?string $faixa): string
    {
        return match ($faixa) {
            'muito_quente' => '🔥',
            'bom' => '🟠',
            'analisar' => '🟡',
            'baixo' => '⚪',
            default => '·',
        };
    }

    public static function vendasNome(?string $s): string
    {
        return match ($s) {
            'muito_alto' => 'Muito alto',
            'alto' => 'Alto',
            'medio' => 'Médio',
            'baixo' => 'Baixo',
            default => 'Não informado',
        };
    }

    /**
     * Igual a vendasNome(), mas também mostra o número exato de vendas quando
     * disponível (ex.: Shopee, que fornece salesExact — nunca reconstrói um
     * número a partir do sinal qualitativo, nem estima nada).
     *   sinal + exato  -> "Muito alto · 65.656 vendas"
     *   só sinal        -> "Muito alto"
     *   só exato         -> "65.656 vendas"
     *   nenhum dos dois -> "Não informado"
     */
    public static function vendasTexto(?string $signal, ?int $exact): string
    {
        $nome = $signal !== null ? self::vendasNome($signal) : null;
        $qtd = $exact !== null ? number_format($exact, 0, ',', '.') . ' vendas' : null;

        if ($nome !== null && $qtd !== null) {
            return $nome . ' · ' . $qtd;
        }
        return $nome ?? $qtd ?? 'Não informado';
    }

    public static function simNao(mixed $v): string
    {
        return $v ? 'Sim' : 'Não';
    }

    /** "confiança do dado" em linguagem de usuário (esconde scrape_json/scrape_html). */
    public static function confiancaDado(?string $dq): string
    {
        return match ($dq) {
            'api' => 'Completo (API oficial)',
            'scrape_json' => 'Completo',
            'feed' => 'Parcial (catálogo)',
            'manual' => 'Informado manualmente',
            'scrape_html' => 'Reduzido (site sob limitação no momento da coleta)',
            default => '—',
        };
    }

    public static function radarStatusNome(bool $enabled): string
    {
        return $enabled ? 'Ativo' : 'Pausado';
    }

    /** Limpa jargão do texto de detalhe de um fator do Hot Score, para telas comerciais. */
    public static function fatorDetalhe(string $detail): string
    {
        $map = [
            'DEAL_OF_THE_DAY' => 'oferta do dia',
            'LIGHTNING_DEAL' => 'oferta relâmpago',
            'BUY_BOX_WINNER' => 'mais vendido',
            'sinal "muito_alto"' => 'procura muito alta',
            'sinal "alto"' => 'procura alta',
            'sinal "medio"' => 'procura média',
            'sinal "baixo"' => 'procura baixa',
            'has_published_clips' => 'com vídeo',
            'good_quality_picture' => 'foto de qualidade',
            'brand_verified' => 'marca verificada',
            'best_seller_candidate' => 'candidato a mais vendido',
            'scrape_json' => 'coleta completa',
            'scrape_html' => 'coleta reduzida',
            'nicho: confiança alta' => 'combina muito com o nicho',
            'nicho: confiança media' => 'combina com o nicho',
            'nicho: confiança baixa' => 'combina pouco com o nicho',
        ];
        return strtr($detail, $map);
    }

    public static function dataCurta(?string $dt): string
    {
        if (!$dt) {
            return '—';
        }
        $ts = strtotime($dt);
        return $ts === false ? self::e($dt) : date('d/m/Y H:i', $ts);
    }

    public static function marketplaceLabel(string $m): string
    {
        return match ($m) {
            'mercado_livre' => 'Mercado Livre',
            'shopee' => 'Shopee',
            default => ucfirst($m),
        };
    }

    public static function ago(?string $dt): string
    {
        if (!$dt) {
            return '—';
        }
        $ts = strtotime($dt);
        if ($ts === false) {
            return self::e($dt);
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'agora';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' min';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' h';
        }
        return date('d/m/Y H:i', $ts);
    }
}
