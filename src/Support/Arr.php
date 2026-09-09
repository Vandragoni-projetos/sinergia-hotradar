<?php
declare(strict_types=1);

namespace HotRadar\Support;

final class Arr
{
    /**
     * Acesso seguro por caminho "a.b.c" em arrays aninhados.
     * @param array<mixed> $arr
     */
    public static function get(array $arr, string $path, mixed $default = null): mixed
    {
        $node = $arr;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return $default;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    /**
     * Primeiro componente de $components cujo type == $type.
     * @param array<int,array<string,mixed>> $components
     * @return array<string,mixed>|null
     */
    public static function firstOfType(array $components, string $type): ?array
    {
        foreach ($components as $c) {
            if (is_array($c) && ($c['type'] ?? null) === $type) {
                return $c;
            }
        }
        return null;
    }
}
