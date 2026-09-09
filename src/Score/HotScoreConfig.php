<?php
declare(strict_types=1);

namespace HotRadar\Score;

use HotRadar\Db\Connection;

/**
 * Carrega a config do HOT SCORE. Default = config/hotscore.php.
 * Override opcional (JSON) na tabela hr_settings, chave 'hotscore' — permite
 * ajustar pesos em produção sem editar código nem espalhar constantes.
 */
final class HotScoreConfig
{
    /** @param array<string,mixed> $data */
    private function __construct(public readonly array $data)
    {
    }

    /** @param array<string,mixed> $fileDefault */
    public static function load(array $fileDefault, ?Connection $db = null): self
    {
        $data = $fileDefault;
        if ($db !== null) {
            try {
                $row = $db->first('SELECT svalue FROM hr_settings WHERE skey = ?', ['hotscore']);
                if ($row && is_string($row['svalue']) && $row['svalue'] !== '') {
                    $override = json_decode($row['svalue'], true);
                    if (is_array($override) && $override !== []) {
                        $data = array_replace_recursive($fileDefault, $override);
                    }
                }
            } catch (\Throwable) {
                // hr_settings pode não existir ainda; usa default
            }
        }
        return new self($data);
    }

    public function version(): string
    {
        return (string) ($this->data['version'] ?? 'v1');
    }

    /** @return array<string,mixed> */
    public function block(string $key): array
    {
        return $this->data['blocks'][$key] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function faixas(): array
    {
        return $this->data['faixas'] ?? [];
    }
}
