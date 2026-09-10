<?php
declare(strict_types=1);

use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;

T::group('Migration 006 — radar "Análises manuais" (idempotente, pausado)');

$db = TestDb::fresh('m006');
$radars = new RadarRepository($db, new AuditRepository($db));

$r = $radars->findBySlug('analises-manuais');
T::ok($r !== null, 'radar "Análises manuais" semeado');
T::eq('Análises manuais', $r->name, 'nome correto');
T::ok($r->enabled === false, 'nasce PAUSADO (não entra em "Coletar tudo")');
T::eq([], $r->mlCategoryIds(), 'sem categorias (só recebe produtos salvos por URL)');

// idempotência: rodar a 006 de novo não duplica
$before = $radars->count();
(require HR_ROOT . '/migrations/006_seed_manual_analysis_radar.php')($db, 'sqlite');
T::eq($before, $radars->count(), 're-executar 006 não cria outro radar');

// não interfere no radar padrão
T::ok($radars->findBySlug('casa-organizacao') !== null, 'radar padrão intacto');

TestDb::cleanup('m006');
