<?php
/** @var string $content @var string $title @var string $active */
use HotRadar\Web\Auth;
use HotRadar\Web\View;
$nav = [
    'dashboard' => ['?r=dashboard', 'Início'],
    'products' => ['?r=products', 'Curadoria'],
    'radars' => ['?r=radars', 'Radares'],
    'analyze' => ['?r=analyze', 'Analisar por URL'],
    'reports' => ['?r=reports', 'Relatórios'],
    'config' => ['?r=config', 'Configurações'],
];
$user = Auth::currentUser();
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?> · SINERGIA HOTRADAR</title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/img/favicon-180.png">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="topbar">
  <div class="brand">
    <img src="/assets/img/logo-hotradar.png" alt="Sinergia HotRadar" class="brand-logo">
  </div>
  <nav class="nav">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="spacer" style="flex:1"></div>
  <?php if ($user): ?>
    <span class="muted" style="font-size:12px"><?= View::e($user) ?></span>
    <a href="?r=logout" class="muted" style="font-size:12px;margin-left:10px">Sair</a>
  <?php endif; ?>
</div>
<div class="wrap">
  <?php if (!empty($_GET['flash'])): ?>
    <div class="flash"><?= View::e($_GET['flash']) ?></div>
  <?php endif; ?>
  <?= $content ?>
</div>
</body>
</html>
