<?php
declare(strict_types=1);

namespace HotRadar\Score;

use HotRadar\Db\Connection;
use HotRadar\Repository\SettingsRepository;

/**
 * FONTE ÚNICA DA VERDADE do HOT SCORE.
 *
 * - Default: config/hotscore.php (semente, versionada).
 * - Ativo: se houver hr_settings['hotscore'], ele vence (editável no painel).
 * - HotScore (a calculadora) só consome ESTE objeto. Nada de peso hardcoded
 *   em nenhum outro arquivo, e a OpenAI NUNCA escreve aqui.
 * - Toda alteração passa por save() e vai para hr_audit_log.
 */
final class HotScoreConfig
{
    public const SETTINGS_KEY = 'hotscore';

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

    /** Config de fábrica (só o arquivo). */
    public static function fileDefault(): self
    {
        return new self(require HR_ROOT . '/config/hotscore.php');
    }

    /**
     * Persiste uma nova config (merge sobre o default de arquivo) + audita.
     *
     * @param array<string,mixed> $newData  config COMPLETA desejada
     */
    public static function save(array $newData, SettingsRepository $settings, string $actor = 'humano:painel'): self
    {
        $file = require HR_ROOT . '/config/hotscore.php';
        $merged = array_replace_recursive($file, self::sanitize($newData));
        $settings->set(self::SETTINGS_KEY, $merged, $actor); // set() já grava em hr_audit_log (area 'settings')
        return new self($merged);
    }

    /** Volta ao default de arquivo (remove o override). */
    public static function reset(SettingsRepository $settings, string $actor = 'humano:painel'): self
    {
        $file = require HR_ROOT . '/config/hotscore.php';
        $settings->set(self::SETTINGS_KEY, $file, $actor);
        return new self($file);
    }

    /**
     * Garante tipos/limites sãos: pesos inteiros >= 0, faixas ordenadas 0..100.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function sanitize(array $data): array
    {
        if (isset($data['blocks']) && is_array($data['blocks'])) {
            foreach ($data['blocks'] as $k => $blk) {
                if (is_array($blk) && isset($blk['max'])) {
                    $data['blocks'][$k]['max'] = max(0, (int) $blk['max']);
                }
            }
        }
        if (isset($data['faixas']) && is_array($data['faixas'])) {
            foreach ($data['faixas'] as $i => $f) {
                if (is_array($f) && isset($f['min'])) {
                    $data['faixas'][$i]['min'] = max(0, min(100, (int) $f['min']));
                }
            }
        }
        return $data;
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

    /** @return array<string,array<string,mixed>> */
    public function blocks(): array
    {
        return $this->data['blocks'] ?? [];
    }

    /** Soma dos "max" dos blocos (deveria ser 100). */
    public function totalMax(): int
    {
        $t = 0;
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
            return $row ? 'painel (hr_settings)' : 'arquivo (config/hotscore.php)';
        } catch (\Throwable) {
            return 'arquivo (config/hotscore.php)';
        }
    }
}
