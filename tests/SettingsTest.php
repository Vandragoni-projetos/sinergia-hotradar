<?php
declare(strict_types=1);

use HotRadar\Repository\AuditRepository;
use HotRadar\Repository\SettingsRepository;
use HotRadar\Score\HotScoreConfig;

T::group('Settings — leitura/escrita + alteração de pesos Hot Score + audit');

$db = TestDb::fresh('settings');
$audit = new AuditRepository($db);
$settings = new SettingsRepository($db, $audit);

// leitura com default
T::eq(['x' => 1], $settings->get('inexistente', ['x' => 1]), 'get de chave ausente devolve o default');
T::ok($settings->has('geral') === false, 'has() falso antes de gravar');

// escrita + releitura
$settings->set('geral', ['timezone' => 'America/Sao_Paulo', 'max' => 500]);
T::ok($settings->has('geral'), 'has() verdadeiro após set');
T::eq('America/Sao_Paulo', $settings->get('geral')['timezone'], 'valor relido bate');

// update mantém 1 linha e audita
$settings->set('geral', ['timezone' => 'UTC', 'max' => 800]);
$n = (int) $db->first('SELECT COUNT(*) n FROM hr_settings WHERE skey = ?', ['geral'])['n'];
T::eq(1, $n, 'update não cria linha nova');
T::eq('UTC', $settings->get('geral')['timezone'], 'update aplicado');

$logs = $audit->recent('settings', 10);
T::ok(count($logs) >= 2, 'audit registrou create + update (' . count($logs) . ')');
T::eq('settings', $logs[0]['area'], 'audit area = settings');

// ---- Hot Score: alteração de pesos ----
$before = HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php', $db);
T::eq(30, (int) $before->block('desconto')['max'], 'peso de fábrica: desconto = 30');
T::eq('arquivo (config/hotscore.php)', HotScoreConfig::activeSource($db), 'fonte ativa = arquivo antes de editar');

$new = $before->data;
$new['blocks']['desconto']['max'] = 26;
$new['blocks']['vendas']['max'] = 26;
$saved = HotScoreConfig::save($new, $settings);
T::eq(26, (int) $saved->block('desconto')['max'], 'peso salvo: desconto = 26');

$reloaded = HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php', $db);
T::eq(26, (int) $reloaded->block('desconto')['max'], 'peso persistido em hr_settings vence o arquivo');
T::eq('painel (hr_settings)', HotScoreConfig::activeSource($db), 'fonte ativa = painel após editar');
T::eq(100, $reloaded->totalMax(), 'soma dos máximos continua 100 (26+26+14+10+14+10)');

// reset volta ao arquivo
HotScoreConfig::reset($settings);
$afterReset = HotScoreConfig::load(require HR_ROOT . '/config/hotscore.php', $db);
T::eq(30, (int) $afterReset->block('desconto')['max'], 'reset restaura o padrão de fábrica');

// sanitização: peso negativo vira 0, faixa fora de 0..100 é clampada
$weird = $before->data;
$weird['blocks']['desconto']['max'] = -5;
$weird['faixas'][0]['min'] = 999;
$san = HotScoreConfig::save($weird, $settings);
T::eq(0, (int) $san->block('desconto')['max'], 'peso negativo saneado para 0');
T::eq(100, (int) $san->faixas()[0]['min'], 'faixa > 100 clampada para 100');

TestDb::cleanup('settings');
