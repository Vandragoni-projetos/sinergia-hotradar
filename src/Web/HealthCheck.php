<?php
declare(strict_types=1);

namespace HotRadar\Web;

use HotRadar\App;
use HotRadar\Support\EnvironmentValidator;

/**
 * Lógica do health-check (E5) — separada de public/health.php para ser
 * testável sem precisar de uma requisição HTTP real. Somente leitura:
 * nunca cria arquivo, nunca roda migration, nunca escreve no banco.
 *
 * NUNCA inclui na resposta: senha, usuário, DSN completo, stack trace.
 */
final class HealthCheck
{
    /**
     * @param array<string,mixed> $config
     * @return array{http:int, body:array<string,mixed>}
     */
    public static function run(array $config): array
    {
        $base = [
            'environment' => (string) ($config['env'] ?? 'local'),
            'db_driver' => (string) ($config['db']['driver'] ?? ''),
            'db_host' => (string) ($config['db']['host'] ?? ''),
            'db_name' => (string) ($config['db']['name'] ?? ''),
        ];

        $problems = EnvironmentValidator::validate($config);
        if ($problems !== []) {
            return [
                'http' => 503,
                'body' => $base + [
                    'status' => 'error',
                    'db_connection' => 'invalid_config',
                    'problems' => $problems, // só nomes de variáveis, nunca valores
                ],
            ];
        }

        try {
            $app = App::bootOrFail($config, 'health');
            $app->db->first('SELECT 1 AS ok'); // prova de leitura real, além da conexão em si

            $migrationsApplied = null;
            try {
                $migrationsApplied = (int) ($app->db->first('SELECT COUNT(*) n FROM hr_migrations')['n'] ?? 0);
            } catch (\Throwable) {
                // tabela pode não existir ainda (banco recém-criado) — não é uma falha de health-check.
            }

            return [
                'http' => 200,
                'body' => $base + [
                    'status' => 'ok',
                    'db_connection' => 'ok',
                    'migrations_applied' => $migrationsApplied,
                ],
            ];
        } catch (\Throwable) {
            // a causa detalhada já foi para o error_log dentro de App::bootOrFail() — aqui só o essencial.
            return [
                'http' => 503,
                'body' => $base + [
                    'status' => 'error',
                    'db_connection' => 'failed',
                ],
            ];
        }
    }
}
