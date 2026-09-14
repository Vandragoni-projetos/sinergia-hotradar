<?php
declare(strict_types=1);

namespace HotRadar\Integration\OpenAi;

use HotRadar\Repository\SettingsRepository;
use HotRadar\Support\Env;

/**
 * Configuração da OpenAI. A API Key SOMENTE por Environment — nunca vai para
 * banco, Git, log ou HTML. A UI só pode consultar apiKeyPresent()/isConfigured()/
 * statusLabel(), jamais a chave (ver apiKey(), documentado como uso interno).
 *
 * "Ativado" e "modelo" NÃO são segredo — podem ficar em hr_settings['openai']
 * (chave→JSON, editável pela UI em Configurações → Inteligência Artificial).
 *
 * Variáveis de ambiente:
 *   OPENAI_API_KEY   (obrigatória para a IA funcionar; nunca configurável pela UI)
 *   OPENAI_MODEL     (opcional; usado como padrão de fábrica quando hr_settings não tem modelo salvo)
 */
final class OpenAiConfig
{
    public const DEFAULT_MODEL = 'gpt-4o-mini';
    private const SETTINGS_KEY = 'openai';

    public function __construct(private readonly ?SettingsRepository $settings = null)
    {
    }

    public function apiKeyPresent(): bool
    {
        return trim((string) (Env::get('OPENAI_API_KEY', '') ?? '')) !== '';
    }

    /** @return array<string,mixed> configuração NÃO secreta salva (enabled/model) */
    private function settingsData(): array
    {
        return $this->settings?->get(self::SETTINGS_KEY, []) ?? [];
    }

    /**
     * Uso da IA ligado/desligado (não secreto, editável na UI). Default FALSE
     * quando hr_settings['openai'] nunca foi salvo — a presença da API Key
     * só significa "Configurada"; a IA só fica "Ativa" depois que alguém
     * marcar o toggle explicitamente em Configurações e salvar.
     */
    public function enabled(): bool
    {
        $s = $this->settingsData();
        return array_key_exists('enabled', $s) ? (bool) $s['enabled'] : false;
    }

    /**
     * Pronta para USO REAL (relatórios/resumo): exige chave presente E o toggle
     * ligado. Usado por OpenAiClient::isReady() para decidir se summarize()
     * tenta chamar a API. NÃO é o mesmo que "Testar conexão", que só exige a
     * chave (ver OpenAiClient::testConnection()).
     */
    public function isConfigured(): bool
    {
        return $this->apiKeyPresent() && $this->enabled();
    }

    public function model(): string
    {
        $s = $this->settingsData();
        $fromSettings = trim((string) ($s['model'] ?? ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }
        $m = trim((string) (Env::get('OPENAI_MODEL', '') ?? ''));
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    /** Para uso INTERNO do cliente HTTP apenas. Nunca renderize/loge o retorno. */
    public function apiKey(): string
    {
        return trim((string) (Env::get('OPENAI_API_KEY', '') ?? ''));
    }

    /**
     * Rótulo de STATUS DA CHAVE — reflete só se OPENAI_API_KEY está definida,
     * independente do toggle "ativado/desativado" (que é exibido separadamente
     * na UI, para não confundir "sem chave" com "temporariamente desligado").
     */
    public function statusLabel(): string
    {
        return $this->apiKeyPresent()
            ? 'Configurada · modelo ' . $this->model()
            : 'Não configurada';
    }
}
