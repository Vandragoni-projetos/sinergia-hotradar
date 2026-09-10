<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Collector\CollectorContext;
use HotRadar\Collector\CollectorInterface;
use HotRadar\Collector\CollectorReport;
use HotRadar\Model\NormalizedProduct;

T::group('OpenAI é 100% OPCIONAL — tudo funciona sem OPENAI_API_KEY');

// garante que NÃO há chave no ambiente do teste
foreach (['OPENAI_API_KEY', 'OPENAI_MODEL'] as $k) {
    putenv($k);
    unset($_ENV[$k], $_SERVER[$k]);
}

// App real sobre um banco de teste
$config = hr_config();
$config['db'] = ['driver' => 'sqlite', 'sqlite_path' => 'storage/test_openai_optional.sqlite'];
foreach (['', '-wal', '-shm'] as $s) {
    @unlink(HR_ROOT . '/storage/test_openai_optional.sqlite' . $s);
}
$app = App::boot($config);
$app->migrator()->migrate();

T::ok($app->openAiConfig()->isConfigured() === false, 'OpenAI não configurada (sem chave)');
T::ok($app->openAiClient()->isReady() === false, 'OpenAiClient não pronto');
T::ok($app->aiReports()->isReady() === false, 'AiReportService não pronto');

// ---- COLETA funciona ----
$fake = new class implements CollectorInterface {
    public array $out = [];
    public function marketplace(): string { return 'mercado_livre'; }
    public function source(): string { return 'ofertas_ml'; }
    public function isAvailable(): bool { return true; }
    public function unavailableReason(): ?string { return null; }
    public function collect(CollectorContext $ctx): CollectorReport
    {
        $r = new CollectorReport();
        $r->pagesFetched = 1;
        foreach ($this->out as $p) {
            if ($ctx->radar) { $p->radarSlug = $ctx->radar->slug; $p->radarId = $ctx->radar->id; }
            $r->add($p);
            $r->cardsSeen++;
        }
        return $r;
    }
};
$radar = $app->radars()->findBySlug('casa-organizacao');
$fake->out = [
    new NormalizedProduct(marketplace: 'mercado_livre', marketplaceProductId: 'MLB1', title: 'A',
        urlOriginal: 'https://x', priceCurrent: 100.0, discountPct: 40, salesSignal: 'alto', rating: 4.8,
        source: 'ofertas_ml', nicheConfidence: 'alta'),
];
$res = $app->discovery()->run($fake, new CollectorContext(radar: $radar));
T::eq(1, $res['new'], 'COLETA funciona sem OpenAI');

// ---- HOT SCORE funciona ----
$row = $app->products()->find(1);
T::ok($row !== null && $row['hot_score'] !== null, 'HOT SCORE calculado sem OpenAI (' . ($row['hot_score'] ?? 'null') . ')');

// ---- CURADORIA / busca funciona ----
T::eq(1, count($app->products()->search([])), 'Curadoria (search) funciona sem OpenAI');

// ---- RADARES funcionam ----
T::ok($app->radars()->count() >= 1, 'Radares funcionam sem OpenAI');

// ---- RELATÓRIOS TRADICIONAIS funcionam ----
$reports = $app->reports();
T::ok($reports->lastRun()['exists'] === true, 'Relatório "última coleta" funciona');
T::ok(is_array($reports->topHotScores([], 5)), 'Relatório "top HOT SCORES" funciona');
T::ok(is_array($reports->faixaDistribution([])), 'Relatório "distribuição por faixa" funciona');
T::ok(is_array($reports->byRadar()), 'Relatório "por radar" funciona');

// ---- editor de HOT SCORE funciona ----
$hs = $app->hotScoreConfig();
T::eq(100, $hs->totalMax(), 'Editor de HOT SCORE acessível sem OpenAI');

// ---- a AÇÃO de IA degrada com elegância, sem custo, sem quebrar ----
$ai = $app->aiReports()->analyze([]);
T::ok($ai['ok'] === false, 'analyze() não executa sem chave');
T::ok(str_contains((string) $ai['error'], 'OPENAI_API_KEY'), 'mensagem clara instruindo configurar a variável');
T::ok(is_array($ai['payload']) && $ai['payload'] !== [], 'payload estruturado é montado localmente (sem chamar API, sem custo)');

// ---- nenhuma chamada de rede é feita sem ação explícita: summarize() nem é invocado ----
// (analyze() retorna antes de tocar em curl quando isReady() == false)
T::ok($ai['text'] === null, 'nenhum texto de IA gerado');

foreach (['', '-wal', '-shm'] as $s) {
    @unlink(HR_ROOT . '/storage/test_openai_optional.sqlite' . $s);
}
