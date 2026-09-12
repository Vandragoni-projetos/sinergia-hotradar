<?php
declare(strict_types=1);

namespace HotRadar\Support;

/**
 * Validação CENTRAL de ambiente/banco antes de qualquer conexão ser aberta.
 *
 * Usada por App::bootOrFail() — o ÚNICO ponto de entrada de boot tanto do
 * painel web (public/index.php) quanto de QUALQUER comando CLI
 * (bin/hr.php: migrate, coletores, hotscore:shadow-v2, stats, test...) e do
 * health-check (public/health.php). Não existe (e não deve existir) nenhum
 * outro caminho no projeto que construa uma conexão de banco fora deste
 * ponto — ver App::bootOrFail() e o teste EnvironmentValidationTest que
 * prova isso (auditoria de 2026-09-11: "split-brain" entre CLI e web).
 *
 * Regra de ouro: `env` === 'local' é o ÚNICO valor que permite SQLite e
 * permite variáveis de banco ausentes (uso local/dev/teste, zero infra).
 * Qualquer outro valor de `env` (produção, ou qualquer valor não previsto —
 * fail-safe: trata o desconhecido como produção, nunca o contrário) exige
 * TUDO presente e exige `driver === 'mysql'` (o único driver de produção
 * suportado hoje).
 */
final class EnvironmentValidator
{
    /**
     * @param array<string,mixed> $config o array retornado por config/config.php
     * @return array<int,string> lista de problemas encontrados (nomes de variáveis, nunca valores).
     *                           Vazia = configuração válida para este ambiente.
     */
    public static function validate(array $config): array
    {
        if (self::isLocal($config)) {
            return [];
        }

        $db = is_array($config['db'] ?? null) ? $config['db'] : [];
        $problems = [];

        $driver = trim((string) ($db['driver'] ?? ''));
        if ($driver === '') {
            $problems[] = 'HR_DB_DRIVER ausente (obrigatório fora de ambiente local)';
        } elseif ($driver !== 'mysql') {
            $problems[] = "HR_DB_DRIVER=\"$driver\" não é um driver de produção suportado (só \"mysql\")";
        }

        $required = [
            'host' => 'HR_DB_HOST',
            'port' => 'HR_DB_PORT',
            'name' => 'HR_DB_NAME',
            'user' => 'HR_DB_USER',
            'password' => 'HR_DB_PASSWORD',
        ];
        foreach ($required as $key => $envName) {
            if (trim((string) ($db[$key] ?? '')) === '') {
                $problems[] = "$envName ausente ou vazio";
            }
        }

        return $problems;
    }

    /** @param array<string,mixed> $config */
    public static function isLocal(array $config): bool
    {
        return (string) ($config['env'] ?? 'local') === 'local';
    }

    /**
     * Contexto seguro para log/health-check: NUNCA inclui senha, nem usuário,
     * nem DSN completo — só o suficiente para diagnosticar (item auditado:
     * "nunca logar senha").
     * @param array<string,mixed> $config
     * @return array<string,string>
     */
    public static function safeContext(array $config): array
    {
        $db = is_array($config['db'] ?? null) ? $config['db'] : [];
        return [
            'environment' => (string) ($config['env'] ?? 'local'),
            'driver' => (string) ($db['driver'] ?? ''),
            'host' => (string) ($db['host'] ?? ''),
            'database' => (string) ($db['name'] ?? ''),
        ];
    }
}
