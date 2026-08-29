<?php

declare(strict_types=1);

namespace KitAudit;

use PDO;
use PDOException;

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new PDOException('Unable to create the SQLite storage directory.');
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        chmod($path, 0600);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
    }

    public function migrate(string $migrationDirectory): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );

        $files = glob(rtrim($migrationDirectory, '/') . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $version = basename($file);
            $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
            $statement->execute(['version' => $version]);
            if ($statement->fetchColumn()) {
                continue;
            }

            $this->transaction(function () use ($file, $version): void {
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new PDOException('Unable to read migration: ' . $version);
                }
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );
                $statement->execute([
                    'version' => $version,
                    'applied_at' => gmdate('c'),
                ]);
            });
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
