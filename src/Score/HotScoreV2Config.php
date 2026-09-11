<?php
declare(strict_types=1);

namespace HotRadar\Score;

use HotRadar\Db\Connection;

/**
 * FONTE ÚNICA DA VERDADE do HOT SCORE V2 (shadow mode).
 *
 * Mesmo padrão da V1 (HotScoreConfig): default em config/hotscore_v2.php,
 * override opcional em hr_settings['hotscore_v2']. Totalmente independente
 * da chave 'hotscore' (V1) — nunca colide, nunca é lida por engano no lugar
 * da V1.
 */
final class HotScoreV2Config
{
    public const SETTINGS_KEY = 'hotscore_v2';

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
                $row = $db->first('SELECT svalue FROM hr_settings WHERE skey = ?', [self::SETTINGS_KEY]);
                if ($row && is_string($row['svalue']) && $row['svalue'] !== '') {
                    $override = json_decode($row['svalue'], true);
                    if (is_array($override) && $override !== []) {
                        $data = array_replace_recursive($fileDefault, $override);
                    }
                }
            } catch (\Throwable) {
                // hr_settings pode não existir ainda → usa default
            }
        }
        return new self($data);
    }

    public static function fileDefault(): self
    {
        return new self(require HR_ROOT . '/config/hotscore_v2.php');
    }

    public function version(): string
    {
        return (string) ($this->data['version'] ?? 'v2');
    }

    /** @return array<string,mixed> */
    public function block(string $key): array
    {
        return $this->data['blocks'][$key] ?? [];
    }

    /** @return array<string,array<string,mixed>> */
    public function blocks(): array
    {
        return $this->data['blocks'] ?? [];
    }

    /** @return array<string,mixed> */
    public function aderencia(): array
    {
        return $this->data['aderencia'] ?? [];
    }

    /** Soma dos "max" dos blocos BASE + aderência (deveria ser 100). */
    public function totalMax(): int
    {
        $t = (int) ($this->aderencia()['max'] ?? 0);
        foreach ($this->blocks() as $b) {
            $t += (int) ($b['max'] ?? 0);
        }
        return $t;
    }

    /** @return array<int,array<string,mixed>> */
    public function faixas(): array
    {
        return $this->data['faixas'] ?? [];
    }

    /** Origem ativa: 'arquivo' ou 'painel (hr_settings)'. */
    public static function activeSource(?Connection $db): string
    {
        if ($db === null) {
            return 'arquivo';
        }
        try {
            $row = $db->first('SELECT 1 FROM hr_settings WHERE skey = ?', [self::SETTINGS_KEY]);
            return $row ? 'painel (hr_settings)' : 'arquivo (config/hotscore_v2.php)';
        } catch (\Throwable) {
            return 'arquivo (config/hotscore_v2.php)';
        }
    }
}
