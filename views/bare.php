<?php
/** @var string $content @var string $title */
use HotRadar\Web\View;
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?> · SINERGIA HOTRADAR</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bare">
<?= $content ?>
</body>
</html>
