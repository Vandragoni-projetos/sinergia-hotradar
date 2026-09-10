<?php
declare(strict_types=1);

namespace HotRadar\Db;

use PDO;
use RuntimeException;

/**
 * Fábrica/wrapper PDO. Suporta sqlite (dev local) e mysql/MariaDB (produção).
 * O schema é escrito para os dois dialetos (ver migrations/).
 */
final class Connection
{
    private PDO $pdo;
    private string $driver;

    /** @param array<string,mixed> $cfg */
    public function __construct(array $cfg)
    {
        $this->driver = (string) ($cfg['driver'] ?? 'sqlite');

        if ($this->driver === 'sqlite') {
            $path = (string) $cfg['sqlite_path'];
            if (!str_contains($path, ':') && !str_starts_with($path, '/')) {
                $path = HR_ROOT . '/' . $path;
            }
            @mkdir(dirname($path), 0775, true);
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } elseif ($this->driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                (int) $cfg['port'],
                $cfg['name']
            );
            $this->pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['password']);
        } else {
            throw new RuntimeException("Driver de banco não suportado: {$this->driver}");
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Executa $fn dentro de uma transação. Commit no sucesso, rollback em exceção.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $ownsTx = !$this->pdo->inTransaction();
        if ($ownsTx) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($ownsTx) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
