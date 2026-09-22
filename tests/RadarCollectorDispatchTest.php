<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Radar\Radar;
use HotRadar\Web\Actions;

T::group('Actions::radarCollect() / collectRun() — despacho por marketplace, sem fallback silencioso para ML');

// Config real (hr_config), com os pontos que poderiam gerar chamada de rede
// real neutralizados de propósito:
//   - ML: sem categorias configuradas — MercadoLivreCollector::collect() já
//     recusa rodar e retorna ANTES de qualquer HTTP quando não há categoria.
//   - Shopee: precisa ficar "disponível" (senão DiscoveryService::run() nem
//     chega a criar o registro do run — não daria pra provar o despacho por
//     ausência de dado) — então damos credenciais FAKE (só strings, nunca
//     tocam rede) + settings "acesso concedido", MAS com graphql_url = ''.
//     curl com URL vazia falha instantaneamente e 100% local (confirmado:
//     CURLE_URL_MALFORMED em <1ms, sem DNS/socket) — isAvailable() fica
//     true (prova o despacho), a chamada em si nunca sai da máquina.
$config = hr_config();
$dbPath = 'storage/test_radar_dispatch.sqlite';
$config['db'] = ['driver' => 'sqlite', 'sqlite_path' => $dbPath];
$config['marketplaces']['mercado_livre']['target_categories'] = [];
$config['marketplaces']['shopee']['app_id'] = 'fake-app-id-dispatch-teste';
$config['marketplaces']['shopee']['secret'] = 'fake-secret-dispatch-teste';
$config['marketplaces']['shopee']['graphql_url'] = '';
foreach (['', '-wal', '-shm'] as $s) {
    @unlink(HR_ROOT . '/' . $dbPath . $s);
}
putenv('SHOPEE_APP_ID=fake-app-id-dispatch-teste');
putenv('SHOPEE_SECRET=fake-secret-dispatch-teste');

$app = App::boot($config);
$app->migrator()->migrate();
$app->settings()->set('shopee', ['open_api_access' => 'granted']);
T::ok($app->shopeeCollector()->isAvailable(), 'setup: ShopeeCollector fica "disponível" (credenciais fake + acesso concedido), mas graphql_url vazio garante zero rede real');

$mkRadar = static function (array $marketplaces, string $name): Radar {
    return new Radar(
        id: null, slug: '', name: $name, enabled: true, marketplaces: $marketplaces,
        mlCategories: [], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
        pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
    );
}; // sem ml_categories: se o coletor ML fosse mesmo chamado, ele recusaria ANTES de qualquer rede.

// ---- 1) Radar SÓ Mercado Livre → cria run(s) só de mercado_livre, nunca de shopee ----
$radarMl = $app->radars()->find($app->radars()->create($mkRadar(['mercado_livre'], 'Radar Só ML')));
$_POST = ['id' => (string) $radarMl->id];
@Actions::radarCollect($app);
$runsMl = $app->db->all('SELECT marketplace, source FROM hr_collection_runs WHERE radar_id = ?', [$radarMl->id]);
T::eq(1, count($runsMl), 'radar só-ML: exatamente 1 run criado');
T::eq('mercado_livre', $runsMl[0]['marketplace'], 'run é de mercado_livre');
T::eq('ofertas_ml', $runsMl[0]['source'], 'source = ofertas_ml (comportamento igual ao de antes desta correção)');

// ---- 2) Radar SÓ Shopee → cria run de shopee, e NUNCA de mercado_livre ----
$radarSh = $app->radars()->find($app->radars()->create($mkRadar(['shopee'], 'Radar Só Shopee')));
$_POST = ['id' => (string) $radarSh->id];
@Actions::radarCollect($app);
$runsSh = $app->db->all('SELECT marketplace, source FROM hr_collection_runs WHERE radar_id = ?', [$radarSh->id]);
T::eq(1, count($runsSh), 'radar só-Shopee: exatamente 1 run criado');
T::eq('shopee', $runsSh[0]['marketplace'], 'run é de shopee — não de mercado_livre');
T::eq('shopee_api', $runsSh[0]['source'], 'source = shopee_api');

// ---- 3) Confirmação explícita: nenhum run de mercado_livre foi criado para o radar Shopee (nunca cai em fallback) ----
$mlRunsForShopeeRadar = $app->db->all(
    "SELECT id FROM hr_collection_runs WHERE radar_id = ? AND marketplace = 'mercado_livre'",
    [$radarSh->id]
);
T::eq(0, count($mlRunsForShopeeRadar), 'radar Shopee NUNCA gerou um run de mercado_livre como fallback');

// ---- Radar com os DOIS marketplaces → cada um roda explicitamente, nenhum no lugar do outro ----
$radarBoth = $app->radars()->find($app->radars()->create($mkRadar(['mercado_livre', 'shopee'], 'Radar ML + Shopee')));
$_POST = ['id' => (string) $radarBoth->id];
@Actions::radarCollect($app);
$runsBoth = $app->db->all('SELECT marketplace FROM hr_collection_runs WHERE radar_id = ? ORDER BY marketplace', [$radarBoth->id]);
T::eq(2, count($runsBoth), 'radar com 2 marketplaces: 2 runs criados (um por marketplace)');
T::eq(['mercado_livre', 'shopee'], array_column($runsBoth, 'marketplace'), 'um run de cada marketplace configurado, nenhum substitui o outro');

// ---- 4) Marketplace desconhecido (dado legado/corrompido) → erro explícito, NUNCA cai para ML ----
$radarBogus = $app->radars()->find($app->radars()->create($mkRadar(['marketplace_inexistente'], 'Radar Marketplace Bogus')));
$runsBeforeBogus = (int) $app->db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'];
$_POST = ['id' => (string) $radarBogus->id];
@Actions::radarCollect($app);
$runsAfterBogus = (int) $app->db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'];
T::eq($runsBeforeBogus, $runsAfterBogus, 'marketplace desconhecido: NENHUM run é criado (nem de ML, nem de nada) — erro seguro, sem fallback');

// =====================================================================
T::group('Actions::collectRun() ("Coletar tudo") — reconhece radar Shopee e não deixa 1 falha derrubar os demais');

// radares já criados acima continuam no mesmo banco/app
$_POST = [];
@Actions::collectRun($app);
$shopeeRunsTotal = (int) $app->db->first("SELECT COUNT(*) n FROM hr_collection_runs WHERE marketplace = 'shopee'")['n'];
T::ok($shopeeRunsTotal >= 1, '"Coletar tudo" gerou pelo menos 1 run de shopee — não pula mais o radar Shopee em silêncio');

$mlRunsTotal = (int) $app->db->first("SELECT COUNT(*) n FROM hr_collection_runs WHERE marketplace = 'mercado_livre'")['n'];
T::ok($mlRunsTotal >= 1, '"Coletar tudo" continua gerando runs de mercado_livre normalmente (radar ML não regrediu)');

// o radar com marketplace desconhecido não pode ter gerado run nenhum, mesmo dentro de "Coletar tudo"
$bogusRuns = (int) $app->db->first('SELECT COUNT(*) n FROM hr_collection_runs WHERE radar_id = ?', [$radarBogus->id])['n'];
T::eq(0, $bogusRuns, '"Coletar tudo": radar com marketplace desconhecido continua sem nenhum run — erro seguro, não fallback');

foreach (['', '-wal', '-shm'] as $s) {
    @unlink(HR_ROOT . '/' . $dbPath . $s);
}
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);
