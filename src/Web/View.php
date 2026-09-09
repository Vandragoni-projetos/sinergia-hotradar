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
