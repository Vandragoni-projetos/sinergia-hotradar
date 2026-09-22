<?php
declare(strict_types=1);

use HotRadar\App;
use HotRadar\Radar\Radar;
use HotRadar\Web\Actions;

T::group('Radar — validação de marketplaces na criação/edição (whitelist, sem fallback silencioso)');

$db = TestDb::fresh('radar_marketplace');
$app = new App(['env' => 'local', 'panel' => ['user' => 'x', 'password_hash' => '', 'password' => '']], $db);

$countRadars = static fn () => (int) $db->first('SELECT COUNT(*) n FROM hr_radars')['n'];

// ---- 5) formulário salva Shopee corretamente (só Shopee marcado, ML NÃO entra sozinho) ----
$_POST = ['name' => 'Radar Shopee Teste', 'marketplaces' => ['shopee']];
@Actions::radarSave($app);
$row = $db->first("SELECT * FROM hr_radars WHERE name = 'Radar Shopee Teste'");
T::ok($row !== null, 'radar Shopee foi criado');
$saved = Radar::fromRow($row);
T::eq(['shopee'], $saved->marketplaces, 'marketplaces salvos = exatamente ["shopee"], sem mercado_livre entrar sozinho');

// ---- 6) edição preserva Shopee (recarregar e salvar de novo não reverte para ML) ----
$_POST = ['id' => (string) $saved->id, 'name' => 'Radar Shopee Teste', 'marketplaces' => ['shopee']];
@Actions::radarSave($app);
$row2 = $db->first('SELECT * FROM hr_radars WHERE id = ?', [$saved->id]);
$saved2 = Radar::fromRow($row2);
T::eq(['shopee'], $saved2->marketplaces, 'edição preserva Shopee — não volta para Mercado Livre sozinho');

// ---- ambos marcados: salva os dois, sem perder nenhum ----
$_POST = ['id' => (string) $saved->id, 'name' => 'Radar Shopee Teste', 'marketplaces' => ['mercado_livre', 'shopee']];
@Actions::radarSave($app);
$row3 = $db->first('SELECT * FROM hr_radars WHERE id = ?', [$saved->id]);
$saved3 = Radar::fromRow($row3);
sort($saved3->marketplaces);
T::eq(['mercado_livre', 'shopee'], $saved3->marketplaces, 'os dois marcados: os dois são salvos, nenhum é descartado');

// ---- 4) marketplace desconhecido (whitelist) — nunca é salvo, nunca vira "mercado_livre" por fallback ----
$before = $countRadars();
$_POST = ['name' => 'Radar Bogus', 'marketplaces' => ['bogus_marketplace']];
@Actions::radarSave($app);
T::eq($before, $countRadars(), 'marketplace desconhecido sozinho: NADA é criado (whitelist rejeita, sem fallback pra ML)');

// ---- nenhum marketplace marcado: mesmo resultado — erro explícito, não fallback silencioso ----
$_POST = ['name' => 'Radar Sem Marketplace'];
@Actions::radarSave($app);
T::eq($before, $countRadars(), 'nenhum marketplace marcado: NADA é criado (era aqui que o fallback silencioso para ML acontecia antes)');

// ---- valor bogus MISTURADO com um válido: só o válido é salvo, o lixo é descartado ----
$_POST = ['name' => 'Radar Misto', 'marketplaces' => ['shopee', 'bogus_marketplace', 'outro_invalido']];
@Actions::radarSave($app);
$rowMisto = $db->first("SELECT * FROM hr_radars WHERE name = 'Radar Misto'");
T::ok($rowMisto !== null, 'radar com mistura válido+inválido foi criado');
$savedMisto = Radar::fromRow($rowMisto);
T::eq(['shopee'], $savedMisto->marketplaces, 'só o valor válido (shopee) é salvo; valores desconhecidos são descartados pela whitelist, não geram erro nem viram ML');

TestDb::cleanup('radar_marketplace');
