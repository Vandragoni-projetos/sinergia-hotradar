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
        $unknown = [];
        foreach ($radars as $radar) {
            foreach ($radar->marketplaces as $mp) {
                $collector = self::collectorFor($app, $mp);
                if ($collector === null) {
                    $unknown[$mp] = true;
                    continue;
                }
                try {
                    $r = $app->discovery()->run($collector, new CollectorContext(dryRun: $dry, radar: $radar));
                } catch (\Throwable) {
                    // uma falha (rede, API de um marketplace) nunca pode derrubar os demais radares/marketplaces.
                    $tot['errors']++;
                    continue;
                }
                $tot['collected'] += $r['collected'];
                $tot['new'] += $r['new'];
                $tot['updated'] += $r['updated'];
                $tot['filtered'] += $r['filtered'];
                $tot['errors'] += count($r['errors']);
            }
        }
        $flash = sprintf(
            '%s — %d radar(es): %d coletados, %d novos, %d atualizados, %d filtrados%s%s',
            $dry ? 'DRY-RUN' : 'Coleta',
            count($radars),
            $tot['collected'],
            $tot['new'],
            $tot['updated'],
            $tot['filtered'],
            $tot['errors'] ? ', ' . $tot['errors'] . ' erro(s)' : '',
            $unknown ? ' · marketplace desconhecido ignorado: ' . implode(', ', array_keys($unknown)) : ''
        );
        self::redirect('?r=dashboard&flash=' . rawurlencode($flash));
    }

    /**
     * Único ponto que decide qual collector atende um marketplace. Marketplace
     * fora de Radar::KNOWN_MARKETPLACES (ou sem match aqui) devolve null —
     * quem chama trata isso como erro explícito, NUNCA como "usa ML então".
     */
    private static function collectorFor(App $app, string $marketplace): ?\HotRadar\Collector\CollectorInterface
    {
        return match ($marketplace) {
            'mercado_livre' => $app->mercadoLivreCollector(),
            'shopee' => $app->shopeeCollector(),
            default => null,
        };
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

        $parts = [];
        $unknown = [];
        $tot = ['collected' => 0, 'new' => 0, 'updated' => 0, 'filtered' => 0];
        $firstError = null;

        foreach ($radar->marketplaces as $mp) {
            $collector = self::collectorFor($app, $mp);
            if ($collector === null) {
                $unknown[] = $mp;
                continue;
            }
            $r = $app->discovery()->run($collector, new CollectorContext(dryRun: $dry, radar: $radar));
            $tot['collected'] += $r['collected'];
            $tot['new'] += $r['new'];
            $tot['updated'] += $r['updated'];
            $tot['filtered'] += $r['filtered'];
            $firstError ??= $r['errors'][0] ?? null;
            $parts[] = View::marketplaceLabel($mp) . ": {$r['collected']} coletados, {$r['new']} novos";
        }

        if ($parts === [] && $unknown !== []) {
            self::redirect('?r=radars&flash=' . rawurlencode(
                'Radar "' . $radar->name . '": marketplace desconhecido (' . implode(', ', $unknown) . ') — nenhuma coleta foi executada.'
            ));
            return;
        }

        $flash = sprintf(
            '%s "%s" — %s%s%s',
            $dry ? 'DRY-RUN' : 'Coleta',
            $radar->name,
            implode(' · ', $parts),
            $firstError ? ' — ' . $firstError : '',
            $unknown ? ' · marketplace desconhecido ignorado: ' . implode(', ', $unknown) : ''
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

        // Validação: só aceita valores conhecidos (whitelist), nunca completa
        // silenciosamente com Mercado Livre quando nada válido foi enviado —
        // isso é tratado como erro explícito, não como default.
        $marketplaces = array_values(array_unique(array_intersect(
            array_map('strval', (array) ($_POST['marketplaces'] ?? [])),
            Radar::KNOWN_MARKETPLACES
        )));
        if ($marketplaces === []) {
            $back = $id === null ? '?r=radar.edit' : ('?r=radar.edit&id=' . $id);
            self::redirect($back . '&flash=' . rawurlencode('Selecione ao menos um marketplace (Mercado Livre e/ou Shopee) — nada foi salvo.'));
            return;
        }

        $radar = new Radar(
            id: $id,
            slug: (string) ($_POST['slug'] ?? ''),
            name: trim((string) ($_POST['name'] ?? '')) ?: 'Radar sem nome',
            enabled: !empty($_POST['enabled']),
            marketplaces: $marketplaces,
            mlCategories: $mlCategories,
            shopeeKeywords: $listFrom('shopee_keywords'),
            extraKeywords: $listFrom('extra_keywords'),
            excludedWords: $listFrom('excluded_words'),
            pagesPerCategory: max(1, min(10, (int) ($_POST['pages_per_category'] ?? 3))),
            minDiscount: $numOrNull('min_discount') !== null ? (int) $numOrNull('min_discount') : null,
            priceMin: $numOrNull('price_min') !== null ? (float) $numOrNull('price_min') : null,
            priceMax: $numOrNull('price_max') !== null ? (float) $numOrNull('price_max') : null,
            requireVideo: !empty($_POST['require_video']),
            desiredWords: $listFrom('desired_words'),
            desiredWordsMode: Radar::normalizeDesiredWordsMode(
                is_string($_POST['desired_words_mode'] ?? null) ? $_POST['desired_words_mode'] : null
            ),
            shopeePageStart: Radar::normalizeShopeePageStart(
                is_numeric($_POST['shopee_page_start'] ?? null) ? (int) $_POST['shopee_page_start'] : null
            ),
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
        $radar = $app->radars()->find($id);
        if ($radar === null || ($_POST['confirm'] ?? '') !== 'EXCLUIR') {
            self::redirect('?r=radar.delete&id=' . $id . '&flash=' . rawurlencode('Confirmação inválida — nada foi excluído.'));
            return;
        }
        $mode = (string) ($_POST['mode'] ?? 'somente_radar');
        $svc = $app->radarLifecycle();
        $done = $mode === 'radar_e_exclusivos'
            ? $svc->deleteWithExclusive($radar, 'humano:painel')
            : $svc->deleteRadarOnly($radar, 'humano:painel');

        $flash = $mode === 'radar_e_exclusivos'
            ? sprintf(
                'Radar "%s" excluído. %d produto(s) exclusivo(s) e %d registro(s) de histórico removidos. Produtos compartilhados foram preservados.',
                $radar->name,
                $done['produtos_removidos'],
                $done['snapshots_removidos']
            )
            : sprintf(
                'Radar "%s" excluído. Todos os produtos, o histórico e as decisões foram preservados (produtos de outros radares continuam normalmente).',
                $radar->name
            );
        self::redirect('?r=radars&flash=' . rawurlencode($flash));
    }

    public static function radarClear(App $app): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        $radar = $app->radars()->find($id);
        if ($radar === null || ($_POST['confirm'] ?? '') !== 'LIMPAR') {
            self::redirect('?r=radar.delete&id=' . $id . '&flash=' . rawurlencode('Confirmação inválida — nada foi limpo.'));
            return;
        }
        $done = $app->radarLifecycle()->clearData($radar, 'humano:painel');
        $flash = sprintf(
            'Dados do radar "%s" limpos. %d produto(s) exclusivo(s) removido(s). O radar e a configuração continuam. Rode uma nova coleta quando quiser.',
            $radar->name,
            $done['produtos_removidos']
        );
        self::redirect('?r=radars&flash=' . rawurlencode($flash));
    }

    // ------------------------------------------------------- reset global ("zerar tudo")

    /**
     * "Zerar tudo" — reset GLOBAL, ação SEPARADA de excluir/limpar radar
     * (ver HotRadar\System\SystemResetService). Exige o texto exato
     * "ZERAR TUDO" digitado pelo usuário — qualquer outra coisa e NADA é apagado.
     */
    public static function systemReset(App $app): void
    {
        if (($_POST['confirm'] ?? '') !== 'ZERAR TUDO') {
            self::redirect('?r=system.reset&flash=' . rawurlencode('Confirmação inválida — nada foi apagado. Digite exatamente "ZERAR TUDO".'));
            return;
        }
        $done = $app->systemReset()->factoryReset('humano:painel');
        $flash = sprintf(
            'Sistema zerado: %d produto(s), %d snapshot(s), %d associação(ões), %d evento(s) editorial(is), %d coleta(s) e %d radar(es) removidos. Configurações, autenticação e o histórico de auditoria foram preservados.',
            $done['hr_products'],
            $done['hr_product_snapshots'],
            $done['hr_product_radars'],
            $done['hr_editorial_events'],
            $done['hr_collection_runs'],
            $done['hr_radars'],
        );
        self::redirect('?r=dashboard&flash=' . rawurlencode($flash));
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

    /**
     * Salva SÓ configuração não secreta da OpenAI (ativado/modelo) em hr_settings.
     * A API Key NUNCA passa por aqui — só existe em Environment (OPENAI_API_KEY),
     * este form não tem (e não deve ganhar) campo para ela.
     */
    public static function settingsOpenAi(App $app): void
    {
        $enabled = !empty($_POST['enabled']);
        $model = mb_substr(trim((string) ($_POST['model'] ?? '')), 0, 100);
        $app->settings()->set('openai', ['enabled' => $enabled, 'model' => $model]);
        self::redirect('?r=config&sub=ia&flash=' . rawurlencode('Configuração de IA salva.'));
    }

    /**
     * "Testar conexão": chama a OpenAI de verdade (se a chave existir), mas
     * NUNCA recebe/exibe a chave nem o corpo bruto da resposta — só um
     * resultado ok/erro genérico, via flash message.
     */
    public static function openaiTest(App $app): void
    {
        $res = $app->openAiClient()->testConnection();
        $msg = $res['ok']
            ? 'Conexão com a OpenAI OK.'
            : ('Falha no teste de conexão: ' . ($res['error'] ?? 'erro desconhecido.'));
        self::redirect('?r=config&sub=ia&flash=' . rawurlencode($msg));
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
            $np = \HotRadar\Model\ProductHydrator::fromRow($row);
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

    // --------------------------------------------------------------------- login

    public static function login(App $app): void
    {
        // CSRF do login: token da sessão já existente (formulário renderizado antes)
        if (!Auth::csrfValid($_POST['_csrf'] ?? null)) {
            self::redirect('?r=login&e=' . rawurlencode('Sessão expirada. Tente de novo.'));
            return;
        }
        $ok = Auth::attempt(
            (string) ($_POST['user'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            !empty($_POST['remember'])
        );
        if (!$ok) {
            self::redirect('?r=login&e=' . rawurlencode('Usuário ou senha incorretos.'));
            return;
        }
        self::redirect('?r=dashboard');
    }

    // ----------------------------------------------------------- curadoria em lote

    public static function productBulk(App $app): void
    {
        $to = (string) ($_POST['to'] ?? '');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: null;
        $back = (string) ($_POST['back'] ?? '?r=products');

        if (!in_array($to, [EditorialStatus::APROVADO, EditorialStatus::ANALISAR, EditorialStatus::DESCARTADO], true) || $ids === []) {
            self::redirect($back . (str_contains($back, '?') ? '&' : '?') . 'flash=' . rawurlencode('Selecione produtos e uma ação válida.'));
            return;
        }
        $n = 0;
        foreach ($ids as $id) {
            $row = $app->products()->find($id);
            if ($row === null) {
                continue;
            }
            $from = (string) $row['status'];
            if ($from === $to) {
                continue;
            }
            $app->products()->setStatus($id, $to, $to === EditorialStatus::DESCARTADO ? $reason : null);
            $app->editorial()->log($id, $from, $to, $reason, 'humano:painel(lote)');
            $n++;
        }
        $label = ['aprovado' => 'aprovado(s)', 'analisar' => 'marcado(s) para analisar', 'descartado' => 'descartado(s)'][$to];
        self::redirect($back . (str_contains($back, '?') ? '&' : '?') . 'flash=' . rawurlencode("$n produto(s) $label."));
    }

    // ------------------------------------------------------------------ exportação

    public static function exportProducts(App $app): void
    {
        $filters = [
            'radar' => $_GET['radar'] ?? '',
            'marketplace' => $_GET['marketplace'] ?? '',
            'faixa' => $_GET['faixa'] ?? '',
            'status' => $_GET['status'] ?? '',
            'category' => $_GET['category'] ?? '',
            'has_video' => $_GET['has_video'] ?? '',
            'min_discount' => $_GET['min_discount'] ?? '',
            'min_rating' => $_GET['min_rating'] ?? '',
            'discovered_since' => $_GET['discovered_since'] ?? '',
            'q' => $_GET['q'] ?? '',
            'limit' => 5000,
        ];
        if (isset($_GET['aprovados'])) {
            $filters['status'] = 'aprovado';
        }
        $rows = $app->products()->search(array_filter($filters, static fn ($v) => $v !== ''));

        $slugMap = $app->productRadars()->slugsByProductIds(array_column($rows, 'id'));
        $names = [];
        foreach ($app->radars()->all() as $r) {
            $names[$r->slug] = $r->name;
        }
        $radarNamesByProduct = [];
        foreach ($slugMap as $pid => $slugs) {
            $radarNamesByProduct[$pid] = array_map(static fn ($s) => $names[$s] ?? $s, $slugs);
        }

        $exp = $app->productCsvExporter();
        $scope = !empty($_GET['radar']) ? ('radar-' . $_GET['radar']) : (isset($_GET['aprovados']) ? 'aprovados' : 'produtos');
        $csv = $exp->toString($rows, $radarNamesByProduct);

        self::sendFile($csv, $exp->filename($scope), 'text/csv; charset=utf-8');
    }

    public static function reportExport(App $app): void
    {
        $f = array_filter([
            'radar' => $_GET['radar'] ?? '',
            'marketplace' => $_GET['marketplace'] ?? '',
        ], static fn ($v) => $v !== '');
        $reports = $app->reports();
        $movers = $reports->scoreMovers($f, 50);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        $sec = static function ($title, array $rows) use ($fh): void {
            fputcsv($fh, [$title], ';');
            fputcsv($fh, ['Produto', 'Marketplace', 'Hot Score', 'Detalhe'], ';');
            foreach ($rows as $r) {
                fputcsv($fh, [
                    (string) ($r['title'] ?? ''),
                    \HotRadar\Web\View::marketplaceLabel((string) ($r['marketplace'] ?? '')),
                    (string) ($r['hot_score'] ?? $r['last_hot'] ?? ''),
                    isset($r['delta']) ? ('Δ ' . $r['delta'])
                        : (isset($r['drop_pct']) ? ('-' . $r['drop_pct'] . '% de preço') : ''),
                ], ';');
            }
            fputcsv($fh, [], ';');
        };
        $sec('TOP HOT SCORES', $reports->topHotScores($f, 30));
        $sec('SUBIRAM DE SCORE', $movers['up']);
        $sec('CAÍRAM DE SCORE', $movers['down']);
        $sec('MAIORES QUEDAS DE PREÇO', $reports->priceDrops($f, 30));
        $sec('PRODUTOS COM VÍDEO', $reports->withVideo($f, 100));
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        $scope = !empty($f['radar']) ? ('relatorio-' . $f['radar']) : 'relatorio';
        self::sendFile($csv, 'hotradar-' . $scope . '-' . date('Ymd-His') . '.csv', 'text/csv; charset=utf-8');
    }

    private static function sendFile(string $content, string $filename, string $mime): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('X-Content-Type-Options: nosniff');
        echo $content;
    }

    // ------------------------------------------------------------ analisar por URL

    public static function analyzeUrl(App $app): void
    {
        $url = (string) ($_POST['url'] ?? '');
        $result = $app->urlAnalyzer()->analyze($url);

        View::page('analyze/index', [
            'active' => 'analyze',
            'result' => $result,
            'url' => $url,
            'radars' => $app->radars()->all(),
        ], 'Analisar por URL');
    }

    public static function analyzeSave(App $app): void
    {
        $url = (string) ($_POST['url'] ?? '');
        $radarId = (int) ($_POST['radar_id'] ?? 0);
        $radar = $radarId > 0 ? $app->radars()->find($radarId) : null;
        if ($radar === null) {
            self::redirect('?r=analyze&flash=' . rawurlencode('Escolha um radar para guardar o produto.'));
            return;
        }
        $result = $app->urlAnalyzer()->analyze($url);
        if ($result['product'] === null) {
            self::redirect('?r=analyze&flash=' . rawurlencode('Não há dados suficientes para salvar este produto.'));
            return;
        }
        $np = $result['product'];
        $np->radarSlug = $radar->slug;
        $np->radarId = $radar->id;
        $breakdown = $app->hotScore()->evaluate($np);
        $res = $app->products()->upsert($np, $breakdown);
        $app->productRadars()->link($res['id'], (int) $radar->id);

        self::redirect('?r=product&id=' . $res['id'] . '&flash=' . rawurlencode(
            'Produto salvo no radar "' . $radar->name . '".'
        ));
    }
}
