<?php

namespace App\Service;

use App\Core\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * 安装服务：建表 + 老库增量迁移
 *
 * CLI（cli/install.php）与网页安装器（public/zc-install.php）共用，
 * 避免两条安装路径的 schema 行为出现分叉。
 *
 * 与早期版本的差异：虚拟主机用户通常没有 CREATE DATABASE 权限，
 * 因此建库失败会降级为「直接使用面板里已建好的库」，而不是直接中断。
 */
class InstallerService
{
    private array $cfg;
    private string $prefix;

    public function __construct()
    {
        $this->cfg    = (array) Config::get('db', []);
        $this->prefix = (string) ($this->cfg['prefix'] ?? 'zc_');
    }

    public static function lockFile(): string
    {
        return Config::basePath('storage/installed.lock');
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    public static function markInstalled(array $summary = []): void
    {
        $file = self::lockFile();
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode([
            'installed_at' => date('c'),
            'tables'       => $summary['tables'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** 检查 PHP 扩展，返回缺失项 */
    public static function missingExtensions(): array
    {
        $required = ['pdo_mysql', 'openssl', 'mbstring', 'curl'];
        return array_values(array_filter($required, static fn($e) => !extension_loaded($e)));
    }

    public function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            (string) ($this->cfg['host'] ?? '127.0.0.1'),
            (int) ($this->cfg['port'] ?? 3306),
            (string) ($this->cfg['charset'] ?? 'utf8mb4')
        );

        return new PDO($dsn, (string) ($this->cfg['username'] ?? ''), (string) ($this->cfg['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * 执行安装（幂等）
     * @return array{database:string,statements:int,tables:array,seed_key:?string,notes:array,warnings:array}
     */
    public function run(): array
    {
        $notes    = [];
        $warnings = [];
        $pdo      = $this->connect();
        $dbName   = (string) ($this->cfg['database'] ?? '');

        // 建库：共享主机一般无权限，失败不算致命
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        } catch (PDOException $e) {
            $warnings[] = '建库被拒绝（虚拟主机常见），将直接使用面板中已创建的库：' . $e->getMessage();
        }

        try {
            $pdo->exec("USE `{$dbName}`");
        } catch (PDOException $e) {
            throw new RuntimeException('无法切换到数据库 `' . $dbName . '`：' . $e->getMessage()
                . '（请先在主机面板创建该库，并把库名/账号写进 config/config.local.php）');
        }

        // 增量迁移（老库升级，幂等）
        //
        // ⚠ 顺序很重要：sql/schema.sql 里的种子 INSERT 已经带上 api_key_hash 列。
        // 旧库（v1.0 之前）没有这一列，若先把 schema 整个跑一遍，
        // INSERT 会以 "Unknown column 'api_key_hash'" 失败，升级直接断在这里。
        // 所以：表已存在时先补列，再导 schema（CREATE TABLE IF NOT EXISTS 本身幂等）。
        $migrations = [
            'api_key_hash' => "ALTER TABLE `{$this->prefix}api_keys` ADD COLUMN `api_key_hash` CHAR(64) DEFAULT NULL COMMENT 'sha256(api_key)' AFTER `api_key`, ADD UNIQUE KEY `uk_hash` (`api_key_hash`)",
            'scopes'       => "ALTER TABLE `{$this->prefix}api_keys` ADD COLUMN `scopes` VARCHAR(255) NOT NULL DEFAULT '*' COMMENT '逗号分隔权限，*=全部' AFTER `api_key_hash`",
        ];
        $apiKeysExists = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}api_keys'"
        )->fetchColumn();

        if ($apiKeysExists) {
            $this->migrate($pdo, $migrations, $notes);
        }

        // 导入 schema（逐条执行，不依赖 MULTI_STATEMENTS）
        $schemaFile = Config::basePath('sql/schema.sql');
        if (!is_file($schemaFile)) {
            throw new RuntimeException('找不到 sql/schema.sql');
        }
        $sql = (string) file_get_contents($schemaFile);

        $statements = $this->splitStatements($sql);
        $executed   = 0;
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
                $executed++;
            } catch (PDOException $e) {
                throw new RuntimeException('执行 SQL 失败：' . $e->getMessage()
                    . '｜语句开头：' . mb_substr($stmt, 0, 120));
            }
        }

        // 新库直接由 schema 建出带列的表，这里再跑一次做兜底（已存在则跳过）
        $this->migrate($pdo, $migrations, $notes);

        // 老库明文 Key 回填摘要
        $backfilled = $pdo->exec(
            "UPDATE `{$this->prefix}api_keys` SET api_key_hash = SHA2(api_key, 256)
             WHERE (api_key_hash IS NULL OR api_key_hash = '') AND api_key LIKE 'zc!_%' ESCAPE '!'"
        );
        if ($backfilled) {
            $notes[] = "迁移：回填 {$backfilled} 条 API Key 摘要";
            $pdo->exec(
                "UPDATE `{$this->prefix}api_keys`
                 SET api_key = CONCAT(LEFT(api_key, 8), '******', RIGHT(api_key, 4))
                 WHERE api_key_hash IS NOT NULL AND api_key NOT LIKE '%*%'"
            );
        }

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        // 读取可用的种子 Key（仅首次安装时是明文）
        $seed = $pdo->query("SELECT api_key FROM `{$this->prefix}api_keys` ORDER BY id ASC LIMIT 1")->fetchColumn();
        $seedKey = (is_string($seed) && $seed !== '' && !str_contains($seed, '*')) ? $seed : null;

        // 根密钥：留空或出厂值时，Crypto 会在 storage/app_secret.local 自动生成
        // 一台机器一份的随机密钥。只有 storage 不可写时才真的有问题。
        $secret  = (string) Config::get('security.app_secret');
        $keyDir  = Config::basePath('storage');
        $keyFile = $keyDir . '/app_secret.local';
        $autoKey = $secret === '' || $secret === 'change-me-to-a-random-64-char-string' || strlen($secret) < 32;
        if ($autoKey && (!is_dir($keyDir) || !is_writable($keyDir)) && !is_file($keyFile)) {
            $warnings[] = 'storage 目录不可写，无法生成本机加密密钥，SMTP 密码将退回出厂密钥保护（不安全）';
        }

        return [
            'database'   => $dbName,
            'statements' => $executed,
            'tables'     => array_values($tables),
            'seed_key'   => $seedKey,
            'notes'      => $notes,
            'warnings'   => $warnings,
        ];
    }

    /**
     * 按需补列：逐列查 information_schema，缺哪列补哪列。
     * 表不存在时 information_schema.COLUMNS 自然查不到，不会误 ALTER。
     */
    private function migrate(PDO $pdo, array $migrations, array &$notes): void
    {
        foreach ($migrations as $col => $ddl) {
            $exists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->prefix}api_keys'
                   AND COLUMN_NAME = '{$col}'"
            )->fetchColumn();
            if (!$exists) {
                $pdo->exec($ddl);
                $notes[] = "迁移：新增列 {$col}";
            }
        }
    }

    /** @return string[] */
    private function splitStatements(string $sql): array
    {
        if ($this->prefix !== 'zc_') {
            $sql = (string) preg_replace('/`zc_/', '`' . $this->prefix, $sql);
        }

        $out = [];
        foreach (preg_split('/;\s*[\r\n]/', $sql) ?: [] as $chunk) {
            $lines = array_filter(
                explode("\n", $chunk),
                static fn($l) => !preg_match('/^\s*--/', $l) && trim($l) !== ''
            );
            $stmt = trim(implode("\n", $lines));
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }
}
