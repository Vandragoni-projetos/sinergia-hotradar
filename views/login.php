<?php
use HotRadar\Web\View;
/** @var ?string $error */
?>
<div class="login-wrap">
  <form class="login-card" method="post" action="?r=login">
    <div class="brand" style="font-size:20px;text-align:center;margin-bottom:6px">SINERGIA <span>HOTRADAR</span></div>
    <p class="muted" style="text-align:center;margin-top:0">Acesso ao painel</p>

    <?php if ($error): ?>
      <div class="flash" style="background:#fde8e8;border-color:var(--danger);color:var(--danger)"><?= View::e($error) ?></div>
    <?php endif; ?>

    <?= View::csrf() ?>
    <label class="f"><span>Usuário</span>
      <input type="text" name="user" autocomplete="username" autofocus required>
    </label>
    <label class="f"><span>Senha</span>
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <label style="display:flex;gap:8px;align-items:center;margin:10px 0">
      <input type="checkbox" name="remember" value="1"> Lembrar acesso neste dispositivo
    </label>
    <button class="primary" type="submit" style="width:100%">Entrar</button>
  </form>
</div>
