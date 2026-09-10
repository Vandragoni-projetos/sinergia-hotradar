<?php
declare(strict_types=1);

use HotRadar\Web\Auth;

T::group('Login / sessão / CSRF');

// ambiente de sessão para CLI
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION = [];

// ---- sem senha configurada = login desligado (uso local) ----
Auth::boot(['user' => 'admin', 'password' => '', 'password_hash' => '']);
T::ok(Auth::required() === false, 'sem senha/hash → login não exigido');
T::ok(Auth::check() === true, 'check() libera quando login está desligado');

// ---- senha em texto (compatibilidade) ----
Auth::boot(['user' => 'admin', 'password' => 'segredo123', 'password_hash' => '']);
T::ok(Auth::required() === true, 'com senha em texto → login exigido');
$_SESSION = [];
T::ok(Auth::check() === false, 'sem sessão nem Basic → bloqueado');
T::ok(Auth::attempt('admin', 'errada', false) === false, 'senha errada → falha');
T::ok(Auth::attempt('outro', 'segredo123', false) === false, 'usuário errado → falha');
T::ok(Auth::attempt('admin', 'segredo123', false) === true, 'credenciais certas → entra');
T::ok(Auth::check() === true, 'após attempt bem-sucedido, check() libera');
T::eq('admin', Auth::currentUser(), 'currentUser = admin');
Auth::logout();
T::ok(Auth::check() === false, 'logout → bloqueado de novo');

// ---- hash bcrypt (preferido) ----
$hash = password_hash('minhaSenhaForte', PASSWORD_BCRYPT);
Auth::boot(['user' => 'dono', 'password' => '', 'password_hash' => $hash]);
$_SESSION = [];
T::ok(Auth::attempt('dono', 'minhaSenhaForte', false) === true, 'hash bcrypt: senha certa entra');
Auth::logout();
T::ok(Auth::attempt('dono', 'outra', false) === false, 'hash bcrypt: senha errada falha');

// ---- HTTP Basic como fallback ----
Auth::boot(['user' => 'admin', 'password' => 'basicpass', 'password_hash' => '']);
$_SESSION = [];
$_SERVER['PHP_AUTH_USER'] = 'admin';
$_SERVER['PHP_AUTH_PW'] = 'basicpass';
T::ok(Auth::check() === true, 'HTTP Basic válido → libera (compatibilidade com acesso atual)');
$_SERVER['PHP_AUTH_PW'] = 'errada';
T::ok(Auth::check() === false, 'HTTP Basic inválido → bloqueia');
unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);

// ---- CSRF ----
$_SESSION = [];
$t1 = Auth::csrfToken();
T::ok(strlen($t1) >= 32, 'token CSRF gerado');
T::ok(Auth::csrfToken() === $t1, 'token estável na mesma sessão');
T::ok(Auth::csrfValid($t1) === true, 'token válido passa');
T::ok(Auth::csrfValid('xxx') === false, 'token errado falha');
T::ok(Auth::csrfValid(null) === false, 'token nulo falha');

$_SESSION = [];
