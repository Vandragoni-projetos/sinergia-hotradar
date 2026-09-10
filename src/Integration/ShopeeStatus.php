<?php
declare(strict_types=1);

namespace HotRadar\Integration;

use HotRadar\Repository\SettingsRepository;
use HotRadar\Support\Env;

/**
 * Estado da integração Shopee para a UI. NUNCA expõe o valor do segredo —
 * só diz se as variáveis de ambiente estão presentes.
 *
 * Variáveis (somente Environment do EasyPanel):
 *   SHOPEE_APP_ID
 *   SHOPEE_SECRET
 */
final class ShopeeStatus
{
    public const NOT_CONFIGURED = 'nao_configurado';
    public const WAITING_OPEN_API = 'aguardando_open_api';
    public const CONFIGURED = 'configurado';

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function appIdPresent(): bool
    {
        return trim((string) (Env::get('SHOPEE_APP_ID', '') ?? '')) !== '';
    }

    public function secretPresent(): bool
    {
        return trim((string) (Env::get('SHOPEE_SECRET', '') ?? '')) !== '';
    }

    public function credentialsPresent(): bool
    {
        return $this->appIdPresent() && $this->secretPresent();
    }

    /** Flag NÃO SECRETA que o usuário marca quando o acesso à Open API for concedido. */
    public function openApiGranted(): bool
    {
        return ($this->settings->get('shopee', ['open_api_access' => 'pending'])['open_api_access'] ?? 'pending') === 'granted';
    }

    public function state(): string
    {
        if (!$this->credentialsPresent()) {
            return self::NOT_CONFIGURED;
        }
        return $this->openApiGranted() ? self::CONFIGURED : self::WAITING_OPEN_API;
    }

    public function label(): string
    {
        return match ($this->state()) {
            self::CONFIGURED => 'Configurado',
            self::WAITING_OPEN_API => 'Aguardando acesso Open API',
            default => 'Não configurado',
        };
    }

    /** O collector só pode rodar se tudo estiver pronto. */
    public function collectorEnabled(): bool
    {
        return $this->state() === self::CONFIGURED;
    }
}
