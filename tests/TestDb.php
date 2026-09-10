<?php
declare(strict_types=1);

use HotRadar\Db\Connection;
use HotRadar\Db\Migrator;

/**
 * Banco SQLite isolado e efêmero para os testes.
 */
final class TestDb
{
    public static function fresh(string $name): Connection
    {
        $path = HR_ROOT . '/storage/test_' . $name . '.sqlite';
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($path . $s);
        }
        $db = new Connection(['driver' => 'sqlite', 'sqlite_path' => $path]);
        (new Migrator($db, HR_ROOT . '/migrations'))->migrate();
        return $db;
    }

    public static function cleanup(string $name): void
    {
        $path = HR_ROOT . '/storage/test_' . $name . '.sqlite';
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($path . $s);
        }
    }
}
