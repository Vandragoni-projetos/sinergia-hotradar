<?php
declare(strict_types=1);

/**
 * SINERGIA HOTRADAR — health-check de banco (E5, auditoria 2026-09-11).
 *
 * Endpoint mínimo, SOMENTE LEITURA, sem autenticação (para uso por
 * health-check de plataforma). NUNCA retorna senha, usuário, DSN completo
 * ou stack trace. Não cria arquivo, não roda migration, não recolhe nada.
 *
 *   GET /health.php
 *   200 {"status":"ok",...}      banco saudável
 *   503 {"status":"error",...}   config inválida ou conexão falhou
 */

require dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$result = \HotRadar\Web\HealthCheck::run(hr_config());

http_response_code($result['http']);
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
