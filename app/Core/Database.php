<?php

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PDO 封装：单例 + 常用快捷方法 + 表前缀
 */
class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;
    private string $prefix;

    private function __construct()
    {
        $cfg = Config::get('db');
        $this->prefix = (string) ($cfg['prefix'] ?? '');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** 重置单例（测试或切换连接时用） */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** 加表前缀 */
    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /** 插入并返回自增 ID */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $holders = array_map(static fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->table($table),
            implode('`,`', $cols),
            implode(',', $holders)
        );
        $this->query($sql, $this->bindKeys($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** 按条件更新，返回受影响行数 */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = "`{$col}` = :set_{$col}";
        }
        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        foreach ($whereParams as $k => $v) {
            $params[$k] = $v;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $this->table($table), implode(', ', $sets), $where);
        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $this->table($table), $where);
        return $this->query($sql, $params)->rowCount();
    }

    public function count(string $table, string $where = '1', array $params = []): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $this->table($table), $where);
        return (int) $this->fetchColumn($sql, $params);
    }

    /**
     * 事务包装：回调抛异常自动回滚
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 把 ['a'=>1] 转成 [':a'=>1] */
    private function bindKeys(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[':' . $k] = $v;
        }
        return $out;
    }
}
