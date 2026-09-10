<?php
declare(strict_types=1);

namespace HotRadar\Web;

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Editorial\EditorialStatus;
use HotRadar\Radar\Radar;
use HotRadar\Score\HotScoreConfig;

/**
 * Ações (POST). Mudam estado e redirecionam. NENHUMA credencial é aceita por
 * formulário — segredos só via Environment.
 */
final class Actions
{
    private static function back(string $fallback): void
    {
        $to = $_POST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? $fallback);
        header('Location: ' . $to);
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url);
    }

    public static function notFound(): void
    {
        http_response_code(404);
        echo 'Rota não encontrada.';
    }

    // --------------------------------------------------------------- editorial

    public static function productStatus(App $app): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $to = (string) ($_POST['to'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: null;

        if (!in_array($to, EditorialStatus::active(), true)) {
            http_response_code(422);
            echo 'Status inválido.';
            return;
        }
        $row = $app->products()->find($id);
        if ($row === null) {
            http_response_code(404);
            echo 'Produto não encontrado.';
            return;
        }
        $from = (string) $row['status'];
        $app->products()->setStatus($id, $to, $to === EditorialStatus::DESCARTADO ? $reason : null);
        $app->editorial()->log($id, $from, $to, $reason, 'humano:painel');
        self::back('?r=product&id=' . $id);
    }

    // ----------------------------------------------------------------- coletas

    public static function collectRun(App $app): void
    {
        // "Coletar agora" do Dashboard: roda TODOS os radares ativos.
        $dry = !empty($_POST['dry_run']);
        $radars = $app->radars()->enabled();
        if ($radars === []) {
            self::redirect('?r=dashboard&flash=' . rawurlencode('Nenhum radar ativo. Crie e ative um radar em Radares.'));
            return;
        }
        $tot = ['collected' => 0, 'new' => 0, 'updated' => 0, 'filtered' => 0, 'errors' => 0];
        foreach ($radars as $radar) {
            if (!$radar->hasMarketplace('mercado_livre')) {
                continue;
            }
            $r = $app->discovery()->run(
                $app->mercadoLivreCollector(),
                new CollectorContext(dryRun: $dry, radar: $radar)
            );
            $tot['collected'] += $r['collected'];
            $tot['new'] += $r['new'];
            $tot['updated'] += $r['updated'];
            $tot['filtered'] += $r['filtered'];
            $tot['errors'] += count($r['errors']);
        }
        $flash = sprintf(
            '%s — %d radar(es): %d coletados, %d novos, %d atualizados, %d filtrados%s',
            $dry ? 'DRY-RUN' : 'Coleta',
            count($radars),
            $tot['collected'],
            $tot['new'],
            $tot['updated'],
            $tot['filtered'],
            $tot['errors'] ? ', ' . $tot['errors'] . ' erro(s)' : ''
        );
        self::redirect('?r=dashboard&flash=' . rawurlencode($flash));
    }

    public static function radarCollect(App $app): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $dry = !empty($_POST['dry_run']);
        $radar = $app->radars()->find($id);
        if ($radar === null) {
            self::redirect('?r=radars&flash=' . rawurlencode('Radar não encontrado.'));
            return;
        }
        $r = $app->discovery()->run(
            $app->mercadoLivreCollector(),
            new CollectorContext(dryRun: $dry, radar: $radar)
        );
        $flash = sprintf(
            '%s "%s": %d coletados, %d novos, %d atualizados, %d filtrados%s',
            $dry ? 'DRY-RUN' : 'Coleta',
            $radar->name,
            $r['collected'],
            $r['new'],
            $r['updated'],
            $r['filtered'],
            $r['errors'] ? ' — ' . $r['errors'][0] : ''
        );
        self::redirect('?r=radars&flash=' . rawurlencode($flash));
    }

    // ------------------------------------------------------------------ radares

    public static function radarSave(App $app): void
    {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;

        $catIds = array_values(array_filter(array_map('trim', (array) ($_POST['ml_categories'] ?? []))));
        $catalog = \HotRadar\Collector\MercadoLivre\MlCategories::catalog();
        $mlCategories = array_map(
            static fn ($cid) => ['id' => strtoupper($cid), 'label' => $catalog[strtoupper($cid)] ?? $cid],
            $catIds
        );

        $listFrom = static fn (string $key): array => array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', (string) ($_POST[$key] ?? '')) ?: []
        )));

        $numOrNull = static fn (string $key) =>
            ($_POST[$key] ?? '') === '' ? null : (is_numeric($_POST[$key]) ? $_POST[$key] + 0 : null);

        $radar = new Radar(
            id: $id,
            slug: (string) ($_POST['slug'] ?? ''),
            name: trim((string) ($_POST['name'] ?? '')) ?: 'Radar sem nome',
            enabled: !empty($_POST['enabled']),
            marketplaces: array_values(array_filter((array) ($_POST['marketplaces'] ?? ['mercado_livre']))),
            mlCategories: $mlCategories,
            shopeeKeywords: $listFrom('shopee_keywords'),
            extraKeywords: $listFrom('extra_keywords'),
            excludedWords: $listFrom('excluded_words'),
            pagesPerCategory: max(1, min(10, (int) ($_POST['pages_per_category'] ?? 3))),
            minDiscount: $numOrNull('min_discount') !== null ? (int) $numOrNull('min_discount') : null,
            priceMin: $numOrNull('price_min') !== null ? (float) $numOrNull('price_min') : null,
            priceMax: $numOrNull('price_max') !== null ? (float) $numOrNull('price_max') : null,
            requireVideo: !empty($_POST['require_video']),
        );

        if ($id === null) {
            $radar->slug = Radar::slugify($radar->name);
            $newId = $app->radars()->create($radar);
            self::redirect('?r=radar.edit&id=' . $newId . '&flash=' . rawurlencode('Radar criado.'));
            return;
        }
        $app->radars()->update($id, $radar);
        self::redirect('?r=radar.edit&id=' . $id . '&flash=' . rawurlencode('Radar salvo.'));
    }

    public static function radarToggle(App $app): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $radar = $app->radars()->find($id);
        if ($radar !== null) {
            $app->radars()->setEnabled($id, !$radar->enabled);
        }
        self::redirect('?r=radars');
    }

    public static function radarDelete(App $app): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        if (($_POST['confirm'] ?? '') === 'DELETE') {
            $app->radars()->delete($id);
            self::redirect('?r=radars&flash=' . rawurlencode('Radar excluído (produtos coletados permanecem no histórico).'));
            return;
        }
        self::redirect('?r=radar.edit&id=' . $id);
    }

    // --------------------------------------------------------------- settings

    public static function settingsGeneral(App $app): void
    {
        $cur = $app->settings()->get('general', Screens::generalDefaults());
        $cur['max_products_per_collect'] = max(10, min(5000, (int) ($_POST['max_products_per_collect'] ?? 500)));
        $cur['timezone'] = trim((string) ($_POST['timezone'] ?? $cur['timezone']));
        $cur['marketplaces_enabled'] = array_values(array_filter((array) ($_POST['marketplaces_enabled'] ?? [])));
        $cur['editorial']['auto_expire_days'] = max(0, (int) ($_POST['auto_expire_days'] ?? 0));
        $app->settings()->set('general', $cur);
        self::redirect('?r=config&sub=geral&flash=' . rawurlencode('Configurações gerais salvas.'));
    }

    public static function settingsShopee(App $app): void
    {
        // SÓ a flag não-secreta de "acesso Open API concedido". Nunca recebe App ID/Secret.
        $granted = ($_POST['open_api_access'] ?? 'pending') === 'granted';
        $app->settings()->set('shopee', ['open_api_access' => $granted ? 'granted' : 'pending']);
        self::redirect('?r=config&sub=shopee&flash=' . rawurlencode('Status da Shopee atualizado.'));
    }

    // --------------------------------------------------------------- hot score

    public static function hotscoreSave(App $app): void
    {
        $default = HotScoreConfig::fileDefault()->data;
        $new = $default;

        foreach (($_POST['block_max'] ?? []) as $key => $val) {
            if (isset($new['blocks'][$key])) {
                $new['blocks'][$key]['max'] = max(0, (int) $val);
            }
        }
        foreach (($_POST['faixa_min'] ?? []) as $i => $val) {
            if (isset($new['faixas'][$i])) {
                $new['faixas'][$i]['min'] = max(0, min(100, (int) $val));
            }
        }
        HotScoreConfig::save($new, $app->settings());

        // Recalcula todos os produtos com a nova config (mantém uma fonte de verdade)
        self::recomputeAll($app);

        self::redirect('?r=config&sub=hotscore&flash=' . rawurlencode('Pesos do HOT SCORE salvos e produtos recalculados.'));
    }

    public static function hotscoreReset(App $app): void
    {
        HotScoreConfig::reset($app->settings());
        self::recomputeAll($app);
        self::redirect('?r=config&sub=hotscore&flash=' . rawurlencode('HOT SCORE restaurado ao padrão de fábrica.'));
    }

    private static function recomputeAll(App $app): void
    {
        $hotScore = $app->hotScore(); // já carrega a config recém-salva
        foreach ($app->db->all('SELECT * FROM hr_products') as $row) {
            $np = \HotRadar\Web\ProductHydrator::fromRow($row);
            $b = $hotScore->evaluate($np);
            $app->db->run(
                'UPDATE hr_products SET hot_score=?, hot_faixa=?, hot_score_breakdown=?, hot_score_version=?, updated_at=? WHERE id=?',
                [$b->total, $b->faixaKey, json_encode($b->toArray(), JSON_UNESCAPED_UNICODE), $b->version, $app->db->now(), $row['id']]
            );
        }
    }

    // --------------------------------------------------------------- relatório IA

    public static function reportAi(App $app): void
    {
        $f = array_filter([
            'radar' => $_POST['radar'] ?? '',
            'marketplace' => $_POST['marketplace'] ?? '',
        ], static fn ($v) => $v !== '');

        $res = $app->aiReports()->analyze($f);
        $qs = ['r' => 'reports', 'tab' => 'ia'];
        if (!empty($f['radar'])) {
            $qs['radar'] = $f['radar'];
        }
        $qs['ai'] = $res['ok'] ? $res['text'] : ('⚠️ ' . $res['error']);
        self::redirect('?' . http_build_query($qs));
    }
}
