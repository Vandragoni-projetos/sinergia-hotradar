<?php
declare(strict_types=1);

namespace HotRadar\Db;

/**
 * Runner de migrations minimalista. Cada arquivo migrations/NNN_*.php retorna
 * uma closure: function (Connection $db, string $driver): void
 * Aplicadas em ordem; registro em hr_migrations.
 */
final class Migrator
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $migrationsDir,
    ) {
    }

    public function ensureTable(): void
    {
        $driver = $this->db->driver();
        if ($driver === 'sqlite') {
            $this->db->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS hr_migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    applied_at TEXT NOT NULL
                )'
            );
        } else {
            $this->db->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS hr_migrations (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(191) NOT NULL UNIQUE,
                    applied_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    }

    /** @return array<int,string> nomes aplicados nesta execução */
    public function migrate(): array
    {
        $this->ensureTable();

        $applied = [];
        foreach ($this->db->all('SELECT name FROM hr_migrations') as $row) {
            $applied[(string) $row['name']] = true;
        }

        $files = glob($this->migrationsDir . '/*.php') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (isset($applied[$name])) {
                continue;
            }
            /** @var callable $fn */
            $fn = require $file;
            $fn($this->db, $this->db->driver());
            $this->db->run(
                'INSERT INTO hr_migrations (name, applied_at) VALUES (?, ?)',
                [$name, $this->db->now()]
            );
            $ran[] = $name;
        }
        return $ran;
    }

    /** @return array<int,string> */
    public function status(): array
    {
        $this->ensureTable();
        $applied = [];
        foreach ($this->db->all('SELECT name FROM hr_migrations ORDER BY name') as $row) {
            $applied[] = (string) $row['name'];
        }
        return $applied;
    }
}
