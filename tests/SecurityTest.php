<?php
declare(strict_types=1);

use HotRadar\Integration\OpenAi\OpenAiClient;
use HotRadar\Integration\OpenAi\OpenAiConfig;
use HotRadar\Integration\ShopeeStatus;
use HotRadar\Report\AiReportService;
use HotRadar\Report\ReportService;
use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;

T::group('Segurança — nenhum segredo exposto (repo público)');

// 1) Nenhum segredo em arquivos versionados
$root = HR_ROOT;
$tracked = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files', $tracked);
$patterns = [
    '/sk-[A-Za-z0-9]{20,}/',            // OpenAI key
    '/AIza[0-9A-Za-z\-_]{30,}/',        // Google key
    '/-----BEGIN (RSA |EC )?PRIVATE KEY-----/',
    '/xox[baprs]-[0-9A-Za-z-]{10,}/',   // Slack
];
$hits = [];
foreach ($tracked as $rel) {
    if ($rel === '' || !is_file("$root/$rel")) {
        continue;
    }
    // não escanear binários óbvios
    if (preg_match('/\.(png|jpg|jpeg|webp|ico|zip|pem|dll|exe)$/i', $rel)) {
        continue;
    }
    $content = (string) file_get_contents("$root/$rel");
    foreach ($patterns as $p) {
        if (preg_match($p, $content)) {
            $hits[] = "$rel :: $p";
        }
    }
}
T::ok($hits === [], 'nenhum padrão de segredo em arquivos versionados' . ($hits ? ' — ' . implode('; ', $hits) : ''));

// 2) .env e .tools ignorados pelo git
$ignored = static function (string $path) use ($root): bool {
    exec('git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($path), $o, $code);
    return $code === 0;
};
T::ok($ignored('.env'), '.env é gitignored');
T::ok($ignored('.env.production'), '.env.* é gitignored');
T::ok($ignored('.tools/php/php.exe'), '.tools/ é gitignored');
T::ok($ignored('storage/hotradar.sqlite'), 'banco SQLite é gitignored');

// 3) .env.example NÃO contém valores reais de credencial
$example = (string) file_get_contents("$root/.env.example");
foreach (['SHOPEE_APP_ID', 'SHOPEE_SECRET', 'OPENAI_API_KEY'] as $k) {
    T::ok((bool) preg_match('/^' . $k . '\s*=\s*$/m', $example), ".env.example tem $k vazio (só o nome)");
}
T::ok(!str_contains($example, 'sk-'), '.env.example não tem chave OpenAI');

// 4) A chave nunca aparece no erro do cliente OpenAI
putenv('OPENAI_API_KEY=sk-super-secret-shouldnotleak-123456789');
$client = new OpenAiClient(new OpenAiConfig());
$res = $client->summarize('s', '{}'); // vai dar 401
T::ok(!str_contains((string) $res['error'], 'sk-super-secret'), 'erro da OpenAI NÃO contém a chave');
T::ok(!str_contains((string) ($res['text'] ?? ''), 'sk-super-secret'), 'texto da OpenAI NÃO contém a chave');
putenv('OPENAI_API_KEY');

// 5) Payload da IA não carrega credencial
$db = TestDb::fresh('security');
$ai = new AiReportService(new ReportService($db), $client);
$payload = json_encode($ai->buildPayload([]), JSON_UNESCAPED_UNICODE);
T::ok(!str_contains($payload, 'sk-'), 'payload da IA sem chave');
T::ok(!str_contains($payload, 'SECRET') && !str_contains($payload, 'PASSWORD'), 'payload da IA sem segredos');

// 6) ShopeeStatus expõe só presença, nunca valor
putenv('SHOPEE_SECRET=meu-segredo-shopee-xyz');
$settings = new SettingsRepository($db, new AuditRepository($db));
$st = new ShopeeStatus($settings);
$reflect = json_encode([
    'appIdPresent' => $st->appIdPresent(),
    'secretPresent' => $st->secretPresent(),
    'label' => $st->label(),
    'state' => $st->state(),
]);
T::ok(!str_contains($reflect, 'meu-segredo-shopee'), 'ShopeeStatus não vaza o secret');
T::ok($st->secretPresent() === true, 'ShopeeStatus só informa presença (true)');
putenv('SHOPEE_SECRET');

TestDb::cleanup('security');
