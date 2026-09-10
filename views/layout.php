<?php
/** @var string $content @var string $title @var string $active */
use HotRadar\Web\View;
$nav = [
    'dashboard' => ['?r=dashboard', 'Dashboard'],
    'products' => ['?r=products', 'Curadoria'],
    'radars' => ['?r=radars', 'Radares'],
    'reports' => ['?r=reports', 'Relatórios'],
    'config' => ['?r=config', 'Configurações'],
];
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?> · SINERGIA HOTRADAR</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="topbar">
  <div class="brand">SINERGIA <span>HOTRADAR</span></div>
  <nav class="nav">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
</div>
<div class="wrap">
  <?php if (!empty($_GET['flash'])): ?>
    <div class="flash"><?= View::e($_GET['flash']) ?></div>
  <?php endif; ?>
  <?= $content ?>
</div>
</body>
</html>
