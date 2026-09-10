<?php
declare(strict_types=1);

namespace HotRadar;

use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\MercadoLivre\MercadoLivreCollector;
use HotRadar\Collector\MercadoLivre\OfertasJsonParser;
use HotRadar\Collector\Shopee\ShopeeCollector;
use HotRadar\Db\Connection;
use HotRadar\Db\Migrator;
use HotRadar\Discovery\DiscoveryService;
use HotRadar\Integration\OpenAi\OpenAiClient;
use HotRadar\Integration\OpenAi\OpenAiConfig;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Radar\RadarRepository;
use HotRadar\Report\AiReportService;
use HotRadar\Report\ReportService;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\EditorialRepository;
use HotRadar\Repository\ProductRepository;
use HotRadar\Repository\RunRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Repository\SnapshotRepository;
use HotRadar\Score\HotScore;
use HotRadar\Score\HotScoreConfig;
use HotRadar\Support\Http;

/**
 * Composition root. Monta os objetos a partir da config. Sem framework.
 */
final class App
{
    /** @param array<string,mixed> $config */
    public function __construct(
        public readonly array $config,
        public readonly Connection $db,
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function boot(array $config): self
    {
        return new self($config, new Connection($config['db']));
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->db, HR_ROOT . '/migrations');
    }

    public function products(): ProductRepository
    {
        return new ProductRepository($this->db);
    }

    public function snapshots(): SnapshotRepository
    {
        return new SnapshotRepository($this->db);
    }

    public function runs(): RunRepository
    {
        return new RunRepository($this->db);
    }

    public function editorial(): EditorialRepository
    {
        return new EditorialRepository($this->db);
    }

    public function audit(): AuditRepository
    {
        return new AuditRepository($this->db);
    }

    public function settings(): SettingsRepository
    {
        return new SettingsRepository($this->db, $this->audit());
    }

    public function radars(): RadarRepository
    {
        return new RadarRepository($this->db, $this->audit());
    }

    public function hotScoreConfig(): HotScoreConfig
    {
        return HotScoreConfig::load($this->config['hotscore'], $this->db);
    }

    public function hotScore(): HotScore
    {
        return new HotScore($this->hotScoreConfig());
    }

    public function discovery(): DiscoveryService
    {
        return new DiscoveryService($this->products(), $this->snapshots(), $this->runs(), $this->hotScore());
    }

    public function shopeeStatus(): ShopeeStatus
    {
        return new ShopeeStatus($this->settings());
    }

    public function openAiConfig(): OpenAiConfig
    {
        return new OpenAiConfig();
    }

    public function openAiClient(): OpenAiClient
    {
        return new OpenAiClient($this->openAiConfig());
    }

    public function reports(): ReportService
    {
        return new ReportService($this->db);
    }

    public function aiReports(): AiReportService
    {
        return new AiReportService($this->reports(), $this->openAiClient());
    }

    private function mlHttp(): Http
    {
        $ml = $this->config['marketplaces']['mercado_livre'];
        return new Http((string) $ml['user_agent'], (int) $ml['http_timeout']);
    }

    public function mercadoLivreCollector(): MercadoLivreCollector
    {
        $ml = $this->config['marketplaces']['mercado_livre'];
        return new MercadoLivreCollector(
            $this->mlHttp(),
            new OfertasJsonParser(),
            (int) $ml['request_delay_ms'],
            (array) ($ml['target_categories'] ?? []),
            HR_ROOT . '/storage/collect',
        );
    }

    public function shopeeCollector(): ShopeeCollector
    {
        $sh = $this->config['marketplaces']['shopee'];
        return new ShopeeCollector(
            (string) $sh['app_id'],
            (string) $sh['secret'],
            (string) $sh['graphql_url'],
            $this->shopeeStatus(),
        );
    }

    /** @return array<string,CollectorInterface> */
    public function collectors(): array
    {
        return [
            'mercado_livre' => $this->mercadoLivreCollector(),
            'shopee' => $this->shopeeCollector(),
        ];
    }
}
