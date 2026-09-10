<?php
declare(strict_types=1);

namespace HotRadar\Integration\OpenAi;

use HotRadar\Support\Env;

/**
 * Configuração da OpenAI — SOMENTE por Environment. Nunca vai para banco, Git,
 * log ou HTML. A UI só pode consultar isConfigured()/modelLabel(), jamais a chave.
 *
 * Variáveis:
 *   OPENAI_API_KEY   (obrigatória para ativar)
 *   OPENAI_MODEL     (opcional; default abaixo)
 */
final class OpenAiConfig
{
    public const DEFAULT_MODEL = 'gpt-4o-mini';

    public function apiKeyPresent(): bool
    {
        return trim((string) (Env::get('OPENAI_API_KEY', '') ?? '')) !== '';
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyPresent();
    }

    public function model(): string
    {
        $m = trim((string) (Env::get('OPENAI_MODEL', '') ?? ''));
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    /** Para uso INTERNO do cliente HTTP apenas. Nunca renderize/loge o retorno. */
    public function apiKey(): string
    {
        return trim((string) (Env::get('OPENAI_API_KEY', '') ?? ''));
    }

    public function statusLabel(): string
    {
        return $this->isConfigured()
            ? 'Configurada · modelo ' . $this->model()
            : 'Não configurada';
    }
}
