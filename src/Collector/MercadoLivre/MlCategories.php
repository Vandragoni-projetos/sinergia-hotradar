<?php
declare(strict_types=1);

namespace HotRadar\Collector\MercadoLivre;

/**
 * Acesso ao CATÁLOGO DE REFERÊNCIA de categorias raiz do Mercado Livre
 * (config/ml_categories.php). É só a lista para o seletor da tela de Radar —
 * a configuração ativa de monitoramento vive em hr_radars (banco).
 *
 * Nenhum id de categoria é hardcoded neste arquivo.
 */
final class MlCategories
{
    /** @var array<string,string>|null */
    private static ?array $catalog = null;

    /** @return array<string,string> id => rótulo */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $file = HR_ROOT . '/config/ml_categories.php';
            $data = is_file($file) ? require $file : [];
            self::$catalog = is_array($data) ? $data : [];
        }
        return self::$catalog;
    }

    public static function label(string $id): string
    {
        $id = strtoupper(trim($id));
        return self::catalog()[$id] ?? $id;
    }

    public static function isKnown(string $id): bool
    {
        return isset(self::catalog()[strtoupper(trim($id))]);
    }
}
