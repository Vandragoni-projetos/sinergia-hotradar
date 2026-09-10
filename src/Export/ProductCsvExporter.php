<?php
declare(strict_types=1);

namespace HotRadar\Export;

use HotRadar\Web\View;

/**
 * Exporta produtos para CSV (sem dependência externa; usa fputcsv).
 * Colunas em português, sem jargão técnico. Dado ausente = vazio (nunca inventado).
 */
final class ProductCsvExporter
{
    /**
     * @param array<int,array<string,mixed>> $rows              linhas de hr_products
     * @param array<int,array<int,string>>   $radarNamesByProduct product_id => [nome do radar, ...]
     */
    public function toString(array $rows, array $radarNamesByProduct): string
    {
        $fh = fopen('php://temp', 'r+');
        // BOM UTF-8 para o Excel abrir com acentos corretos
        fwrite($fh, "\xEF\xBB\xBF");

        fputcsv($fh, [
            'Produto',
            'Marketplace',
            'Radar(es)',
            'Preço atual',
            'Preço anterior',
            'Desconto (%)',
            'Hot Score',
            'Classificação',
            'Avaliação',
            'Sinal de vendas',
            'Posição/ranking',
            'Possui vídeo',
            'Status editorial',
            'Data da descoberta',
            'Última coleta',
            'Confiança do dado',
            'URL original',
            'URL afiliada',
        ], ';');

        foreach ($rows as $p) {
            $pid = (int) ($p['id'] ?? 0);
            fputcsv($fh, [
                (string) ($p['title'] ?? ''),
                View::marketplaceLabel((string) ($p['marketplace'] ?? '')),
                implode(', ', $radarNamesByProduct[$pid] ?? []),
                $this->money($p['price_current'] ?? null),
                $this->money($p['price_previous'] ?? null),
                $p['discount_pct'] !== null ? (string) (int) $p['discount_pct'] : '',
                $p['hot_score'] !== null ? (string) (int) $p['hot_score'] : '',
                View::faixaNome($p['hot_faixa'] ?? null),
                $p['rating'] !== null ? number_format((float) $p['rating'], 1, ',', '') : '',
                $p['sales_signal'] !== null ? View::vendasNome((string) $p['sales_signal']) : '',
                $p['rank_position'] !== null ? (string) (int) $p['rank_position'] : '',
                ((int) ($p['has_video'] ?? 0)) ? 'Sim' : 'Não',
                \HotRadar\Editorial\EditorialStatus::label((string) ($p['status'] ?? 'descoberto')),
                View::dataCurta($p['discovered_at'] ?? null),
                View::dataCurta($p['last_collected_at'] ?? null),
                View::confiancaDado($p['data_quality'] ?? null),
                (string) ($p['url_original'] ?? ''),
                (string) ($p['url_affiliate'] ?? ''),
            ], ';');
        }

        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }

    private function money(mixed $v): string
    {
        return ($v === null || $v === '') ? '' : number_format((float) $v, 2, ',', '.');
    }

    public function filename(string $scope): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $scope) ?: 'produtos';
        return 'hotradar-' . trim(strtolower($slug), '-') . '-' . date('Ymd-His') . '.csv';
    }
}
