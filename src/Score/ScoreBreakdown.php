<?php
declare(strict_types=1);

namespace HotRadar\Score;

/**
 * Resultado explicável do HOT SCORE: total + faixa + cada componente com
 * "quanto de quanto" e a justificativa.
 */
final class ScoreBreakdown
{
    /** @var array<int,array{key:string,label:string,points:int,max:int,detail:string,available:bool}> */
    public array $components = [];
    public int $total = 0;
    public int $maxTotal = 0;
    public string $faixaKey = 'baixo';
    public string $faixaLabel = 'BAIXA PRIORIDADE';
    public string $faixaEmoji = '⚪';
    public string $version = 'v1';

    public function addComponent(string $key, string $label, int $points, int $max, string $detail, bool $available): void
    {
        $this->components[] = compact('key', 'label', 'points', 'max', 'detail', 'available');
        $this->total += $points;
        $this->maxTotal += $max;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'total' => $this->total,
            'max' => $this->maxTotal,
            'faixa' => $this->faixaKey,
            'faixa_label' => $this->faixaLabel,
            'faixa_emoji' => $this->faixaEmoji,
            'components' => $this->components,
        ];
    }

    public static function fromJson(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return null;
        }
        $b = new self();
        $b->version = (string) ($d['version'] ?? 'v1');
        $b->total = (int) ($d['total'] ?? 0);
        $b->maxTotal = (int) ($d['max'] ?? 100);
        $b->faixaKey = (string) ($d['faixa'] ?? 'baixo');
        $b->faixaLabel = (string) ($d['faixa_label'] ?? '');
        $b->faixaEmoji = (string) ($d['faixa_emoji'] ?? '');
        $b->components = $d['components'] ?? [];
        return $b;
    }
}
