<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Radar\Radar;
use HotRadar\Web\Actions;

/**
 * Testa a MESMA expressão usada em views/radars/index.php para decidir se o
 * botão "Coletar" aparece: array_intersect($rd->marketplaces,
 * Radar::KNOWN_MARKETPLACES) !== []. Este projeto não tem infraestrutura de
 * teste de HTML renderizado (nenhum outro teste do repositório testa view
 * diretamente) — testar a expressão isoladamente, na mesma forma exata usada
 * na view, é o que prova a correção sem introduzir um padrão de teste novo.
 */
T::group('Botão "Coletar" na lista de Radares — visível para qualquer marketplace reconhecido, não só ML');

$showsCollect = static fn (array $marketplaces): bool => array_intersect($marketplaces, Radar::KNOWN_MARKETPLACES) !== [];

T::ok($showsCollect(['mercado_livre']), 'radar só Mercado Livre: botão aparece');
T::ok($showsCollect(['shopee']), 'radar só Shopee: botão aparece (não depende mais de marketplace==mercado_livre)');
T::ok($showsCollect(['mercado_livre', 'shopee']), 'radar com os dois: botão aparece');
T::ok(!$showsCollect(['marketplace_desconhecido']), 'radar só com marketplace não suportado: botão NÃO aparece (nada pra rodar)');
T::ok($showsCollect(['mercado_livre', 'marketplace_desconhecido']), 'radar com 1 conhecido + 1 desconhecido: botão aparece (a parte válida ainda é coletável)');

// =====================================================================
T::group('Shopee indisponível: comportamento explícito ao clicar "Coletar", NUNCA fallback para ML');

$config = hr_config();
$dbPath = 'storage/test_radar_collect_button.sqlite';
$config['db'] = ['driver' => 'sqlite', 'sqlite_path' => $dbPath];
$config['marketplaces']['shopee']['app_id'] = '';
$config['marketplaces']['shopee']['secret'] = '';
foreach (['', '-wal', '-shm'] as $s) {
    @unlink(HR_ROOT . '/' . $dbPath . $s);
}
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
unset($_ENV['SHOPEE_APP_ID'], $_ENV['SHOPEE_SECRET'], $_SERVER['SHOPEE_APP_ID'], $_SERVER['SHOPEE_SECRET']);

$app = App::boot($config);
$app->migrator()->migrate();
T::ok($app->shopeeCollector()->isAvailable() === false, 'setup: Shopee genuinamente indisponível (sem credenciais)');

// prova, sem passar pela Action, que a mensagem que o usuário veria é clara e amigável
// (o mesmo valor que Actions::radarCollect() usaria no flash message)
$directReport = $app->discovery()->run($app->shopeeCollector(), new CollectorContext());
T::ok($directReport['available'] === false, 'Shopee indisponível: available=false, explícito');
T::ok(str_contains((string) $directReport['errors'][0], 'SHOPEE_APP_ID'), 'mensagem instrui o que falta configurar — não é um erro genérico/confuso');

$radarShopeeOff = new Radar(
    id: null, slug: '', name: 'Radar Shopee Sem Credenciais', enabled: true, marketplaces: ['shopee'],
    mlCategories: [], shopeeKeywords: [], extraKeywords: [], excludedWords: [],
    pagesPerCategory: 1, minDiscount: null, priceMin: null, priceMax: null, requireVideo: false,
);
$radarShopeeOff = $app->radars()->find($app->radars()->create($radarShopeeOff));

$runsBefore = (int) $app->db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'];
$_POST = ['id' => (string) $radarShopeeOff->id];
@Actions::radarCollect($app);
$runsAfter = (int) $app->db->first('SELECT COUNT(*) n FROM hr_collection_runs')['n'];
T::eq($runsBefore, $runsAfter, 'clicar "Coletar" num radar Shopee indisponível não cria nenhum run (nem de shopee, que falhou antes de começar, nem — o ponto crítico — de mercado_livre)');

$mlRunsForThisRadar = (int) $app->db->first(
    "SELECT COUNT(*) n FROM hr_collection_runs WHERE radar_id = ? AND marketplace = 'mercado_livre'",
    [$radarShopeeOff->id]
)['n'];
T::eq(0, $mlRunsForThisRadar, 'confirmação direta: NENHUM run de mercado_livre foi criado para este radar Shopee — sem fallback silencioso');

foreach (['', '-wal', '-shm'] as $s) {
    @unlink(HR_ROOT . '/' . $dbPath . $s);
}
putenv('SHOPEE_APP_ID');
putenv('SHOPEE_SECRET');
