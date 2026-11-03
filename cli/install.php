<?php

/**
 * 安装脚本：创建数据库并导入表结构（幂等，可重复执行）
 *
 * 用法（Windows 下需要动态加载扩展）:
 *   php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/install.php
 *
 * Linux 服务器上通常 php.ini 已启用扩展，直接:
 *   php cli/install.php
 */

declare(strict_types=1);

use App\Core\Config;

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): void
{
    fwrite(STDERR, '[错误] ' . $msg . PHP_EOL);
    exit(1);
}

// ---- 扩展检查 ----
$required = ['pdo_mysql', 'openssl', 'mbstring', 'curl'];
$missing  = array_values(array_filter($required, static fn($e) => !extension_loaded($e)));
if ($missing) {
    fail('缺少 PHP 扩展: ' . implode(', ', $missing) . PHP_EOL
        . '  Windows 可这样运行: php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/install.php');
}

// ---- 配置 ----
Config::load();
$cfg = Config::get('db');

out('ZC Mail Service 安装程序');
out('-------------------------------');
out(sprintf('目标: %s:%d / 数据库 `%s` / 用户 %s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['username']));

// ---- 连接（不指定库） ----
try {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], $cfg['port'], $cfg['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fail('连接 MySQL 失败: ' . $e->getMessage() . PHP_EOL . '  请检查 config/config.php 中的 db 配置。');
}

// ---- 建库 ----
$dbName = (string) $cfg['database'];
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("USE `{$dbName}`");
} catch (PDOException $e) {
    fail('创建/切换数据库失败: ' . $e->getMessage());
}
out("数据库 `{$dbName}` 就绪");

// ---- 导入表结构 ----
$schemaFile = $root . '/sql/schema.sql';
if (!is_file($schemaFile)) {
    fail('找不到 sql/schema.sql');
}
$sql = (string) file_get_contents($schemaFile);

// 表前缀替换
$prefix = (string) ($cfg['prefix'] ?? 'zc_');
if ($prefix !== 'zc_') {
    $sql = preg_replace('/`zc_/', '`' . $prefix, $sql);
}

// 拆分语句逐条执行（避免依赖 MULTI_STATEMENTS）
$statements = [];
foreach (preg_split('/;\s*[\r\n]/', $sql) as $chunk) {
    $lines = array_filter(
        explode("\n", (string) $chunk),
        static fn($l) => !preg_match('/^\s*--/', $l) && trim($l) !== ''
    );
    $stmt = trim(implode("\n", $lines));
    if ($stmt !== '') {
        $statements[] = $stmt;
    }
}

$ok = 0;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        fail('执行 SQL 失败: ' . $e->getMessage() . PHP_EOL . '  语句: ' . mb_substr($stmt, 0, 200));
    }
}
out("表结构导入完成（执行 {$ok} 条语句）");

// ---- 校验 ----
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
out('当前库内表 (' . count($tables) . '): ' . implode(', ', $tables));

// ---- 输出默认密钥 ----
$key = $pdo->query("SELECT api_key FROM `{$prefix}api_keys` ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($key) {
    out('');
    out('默认 API Key: ' . $key);
    out('请妥善保存，生产环境请通过 POST /api/v1/keys/{id}/rotate 轮换。');
}

// ---- 检查关键配置 ----
$secret = (string) Config::get('security.app_secret');
if ($secret === 'change-me-to-a-random-64-char-string' || strlen($secret) < 32) {
    out('');
    out('[警告] config/config.php 的 security.app_secret 仍是默认值或过短，请改为 64 位随机字符串。');
    out('       否则 SMTP 密码加密强度不足，且 Webhook 签名可被伪造。');
}

out('');
out('安装完成 ✔');
out('启动服务: php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl -S 0.0.0.0:8000 -t public public/index.php');
