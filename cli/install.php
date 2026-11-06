<?php

/**
 * 安装脚本：创建数据库并导入表结构（幂等，可重复执行）
 *
 * 用法（Windows 下需要动态加载扩展）:
 *   php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/install.php
 *
 * Linux 服务器上通常 php.ini 已启用扩展，直接:
 *   php cli/install.php
 *
 * 没有 SSH 权限的虚拟主机请用网页安装器：public/zc-install.php
 * （两者共用 App\Service\InstallerService，行为一致）
 */

declare(strict_types=1);

use App\Core\Config;
use App\Service\InstallerService;

$root = dirname(__DIR__);

require $root . '/bootstrap/requirements.php';
zc_php_guard();

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
$missing = InstallerService::missingExtensions();
if ($missing) {
    fail('缺少 PHP 扩展: ' . implode(', ', $missing) . PHP_EOL
        . '  Windows 可这样运行: php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/install.php');
}

// ---- 配置 ----
try {
    Config::load();
} catch (\Throwable $e) {
    fail($e->getMessage());
}

$cfg = (array) Config::get('db');

out('ZC Mail Service 安装程序');
out('-------------------------------');
out(sprintf('目标: %s:%s / 数据库 `%s` / 用户 %s', $cfg['host'] ?? '', $cfg['port'] ?? '', $cfg['database'] ?? '', $cfg['username'] ?? ''));

try {
    $report = (new InstallerService())->run();
    InstallerService::markInstalled($report);
} catch (\Throwable $e) {
    fail($e->getMessage());
}

out('数据库 `' . $report['database'] . '` 就绪');
foreach ($report['notes'] as $n) {
    out($n);
}
out('表结构导入完成（执行 ' . $report['statements'] . ' 条语句）');
out('当前库内表 (' . count($report['tables']) . '): ' . implode(', ', $report['tables']));

foreach ($report['warnings'] as $w) {
    out('');
    out('[警告] ' . $w);
}

out('');
if ($report['seed_key']) {
    out('默认 API Key: ' . $report['seed_key']);
    out('请妥善保存（数据库只存摘要，丢失后无法找回，只能轮换）。');
} else {
    out('默认 API Key 已哈希存储（明文不再回显）。');
    out('如已遗失，请用 POST /api/v1/keys/{id}/rotate 轮换，或直接重置该行 api_key_hash。');
}

out('');
out('安装完成 ✔');
out('启动服务: php -S 0.0.0.0:8000 -t public public/index.php');
