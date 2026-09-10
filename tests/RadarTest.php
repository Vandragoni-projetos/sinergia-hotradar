<?php
declare(strict_types=1);

use HotRadar\Model\NormalizedProduct;
use HotRadar\Radar\Radar;
use HotRadar\Radar\RadarRepository;
use HotRadar\Repository\AuditRepository;

T::group('Radar — CRUD, filtros e não-hardcoding de categorias');

$db = TestDb::fresh('radar');
$repo = new RadarRepository($db, new AuditRepository($db));

// migrations semeiam 2 radares: "Casa & Organização" (003) e "Análises manuais" (006, pausado)
$base = $repo->count();
T::eq(2, $base, 'radares semeados pelas migrations (casa-organizacao + analises-manuais)');
$seed = $repo->findBySlug('casa-organizacao');
T::ok($seed !== null, 'radar semente existe');
T::eq(['MLB1574', 'MLB5726'], $seed->mlCategoryIds(), 'categorias do radar vêm do BANCO, não do código');

// create
$mk = static fn (array $o): Radar => new Radar(
    id: null, slug: '', name: $o['name'] ?? 'R', enabled: $o['enabled'] ?? true,
    marketplaces: $o['mp'] ?? ['mercado_livre'],
    mlCategories: $o['cats'] ?? [['id' => 'MLB1246', 'label' => 'Beleza']],
    shopeeKeywords: [], extraKeywords: $o['kw'] ?? [], excludedWords: $o['ex'] ?? [],
    pagesPerCategory: $o['pages'] ?? 3, minDiscount: $o['mind'] ?? null,
    priceMin: $o['pmin'] ?? null, priceMax: $o['pmax'] ?? null, requireVideo: $o['vid'] ?? false,
);

$id = $repo->create($mk(['name' => 'Beleza & Skincare', 'kw' => ['skincare'], 'ex' => ['usado']]));
T::eq($base + 1, $repo->count(), 'radar criado');
$b = $repo->find($id);
T::eq('beleza-skincare', $b->slug, 'slug gerado a partir do nome');

// slug único
$id2 = $repo->create($mk(['name' => 'Beleza & Skincare']));
T::eq('beleza-skincare-2', $repo->find($id2)->slug, 'slug colidente recebe sufixo');

// update (slug imutável)
$upd = $mk(['name' => 'Beleza PRO', 'pages' => 5]);
$repo->update($id, $upd);
$b = $repo->find($id);
T::eq('Beleza PRO', $b->name, 'nome atualizado');
T::eq('beleza-skincare', $b->slug, 'slug NÃO muda no update');
T::eq(5, $b->pagesPerCategory, 'páginas atualizadas');

// enable/disable
$repo->setEnabled($id, false);
T::ok($repo->find($id)->enabled === false, 'radar desativado');
T::eq(1, count(array_filter($repo->enabled(), static fn ($r) => $r->slug === 'beleza-skincare')) === 0 ? 1 : 0, 'radar off não aparece em enabled()');

// delete
$repo->delete($id2);
T::eq($base + 1, $repo->count(), 'radar excluído');

// ---- filtros do radar ----
$radar = $mk(['ex' => ['usado', 'reposição'], 'mind' => 30, 'pmin' => 50.0, 'pmax' => 500.0, 'vid' => true]);

$np = static fn (array $o): NormalizedProduct => new NormalizedProduct(
    marketplace: 'mercado_livre', marketplaceProductId: 'MLB1', title: $o['t'] ?? 'Produto bom',
    urlOriginal: 'https://x', priceCurrent: $o['price'] ?? 120.0, discountPct: $o['disc'] ?? 40,
    hasVideo: $o['video'] ?? true,
);

T::ok($radar->accepts($np([]))['ok'] === true, 'produto dentro de todos os limites: aceito');
T::ok($radar->accepts($np(['t' => 'Cadeira usado (semi-nova)']))['ok'] === false, 'palavra excluída "usado": rejeitado');
T::ok($radar->accepts($np(['disc' => 10]))['ok'] === false, 'desconto abaixo do mínimo: rejeitado');
T::ok($radar->accepts($np(['price' => 20.0]))['ok'] === false, 'preço abaixo do mínimo: rejeitado');
T::ok($radar->accepts($np(['price' => 900.0]))['ok'] === false, 'preço acima do máximo: rejeitado');
T::ok($radar->accepts($np(['video' => false]))['ok'] === false, 'sem vídeo com require_video: rejeitado');
T::ok(str_contains((string) $radar->accepts($np(['t' => 'peça de reposição']))['reason'], 'reposição'), 'motivo da rejeição é legível');

TestDb::cleanup('radar');
