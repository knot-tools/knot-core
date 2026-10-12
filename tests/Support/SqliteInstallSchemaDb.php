<?php

declare(strict_types=1);

namespace Knot\Tests\Support;

use DoliDB;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Minimal DoliDB backed by SQLite, for running MetricsCollector (and similar)
 * against tables created from the real install SQL files.
 *
 * Rewrites a few MySQL-only functions used by Knot reporting queries.
 */
final class SqliteInstallSchemaDb extends DoliDB
{
    private PDO $pdo;

    /** @var list<object> */
    private array $fetchQueue = [];

    public string $lastSql = '';

    public string $lastError = '';

    public bool $lastQueryOk = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function fromInstallSql(string $sqlDir, string ...$shortTables): self
    {
        $pdo = new PDO('sqlite::memory:');
        $db = new self($pdo);
        foreach ($shortTables as $short) {
            $path = rtrim($sqlDir, '/') . '/llx_knot_' . $short . '.sql';
            if (!is_file($path)) {
                throw new \RuntimeException('Missing install SQL: ' . $path);
            }
            $raw = (string) file_get_contents($path);
            if (preg_match('/CREATE\s+TABLE\b.*?;/is', $raw, $m) !== 1) {
                throw new \RuntimeException('No CREATE TABLE in ' . $path);
            }
            $sqlite = InstallSqlSchema::mysqlCreateToSqlite($m[0]);
            $pdo->exec($sqlite);
        }

        return $db;
    }

    public function query(string $sql)
    {
        $this->lastSql = $sql;
        $this->lastError = '';
        $this->fetchQueue = [];
        $rewritten = $this->rewriteMysqlFunctions($sql);
        try {
            $stmt = $this->pdo->query($rewritten);
            if (!$stmt instanceof PDOStatement) {
                $this->lastQueryOk = false;
                $this->lastError = 'PDO query returned false';

                return false;
            }
            while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
                $this->fetchQueue[] = $row;
            }
            $this->lastQueryOk = true;

            return true;
        } catch (PDOException $e) {
            $this->lastQueryOk = false;
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    public function fetch_object($resource): ?object
    {
        if ($this->fetchQueue === []) {
            return null;
        }

        return array_shift($this->fetchQueue);
    }

    public function idate(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    private function rewriteMysqlFunctions(string $sql): string
    {
        // MySQL DAYOFWEEK: 1=Sunday … 7=Saturday. SQLite strftime('%w'): 0=Sunday.
        $sql = preg_replace(
            '/DAYOFWEEK\s*\(\s*([a-z_][a-z0-9_]*)\s*\)/i',
            '(CAST(strftime(\'%w\', $1) AS INTEGER) + 1)',
            $sql
        ) ?? $sql;
        $sql = preg_replace(
            '/HOUR\s*\(\s*([a-z_][a-z0-9_]*)\s*\)/i',
            'CAST(strftime(\'%H\', $1) AS INTEGER)',
            $sql
        ) ?? $sql;

        return $sql;
    }
}
