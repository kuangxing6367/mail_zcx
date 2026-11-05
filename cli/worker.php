<?php

/**
 * 发信队列 Worker（CLI 常驻 / cron 单次）
 *
 * 用法：
 *   常驻：  php cli/worker.php
 *   单次：  php cli/worker.php --once          （配合 cron / 宝塔计划任务兜底）
 *   调试：  php cli/worker.php --verbose
 *   限轮：  php cli/worker.php --max=5         （跑 5 轮后退出，便于测试）
 *
 * 生产建议用 supervisor 守护，配置见 deploy/supervisor-mail-worker.conf.example
 *
 * 没有 SSH / CLI 权限的虚拟主机请改用 Web Cron：public/zc-cron.php（见 docs/deploy-shared-hosting.md）
 *
 * Windows 本地调试需要动态加载扩展：
 *   php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/worker.php --once
 */

declare(strict_types=1);

use App\Core\Config;
use App\Service\WorkerRunner;

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

$GLOBALS['ZC_WORKER_VERBOSE'] = false;

function worker_out(string $msg, bool $verboseOnly = false): void
{
    if ($verboseOnly && empty($GLOBALS['ZC_WORKER_VERBOSE'])) {
        return;
    }
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

// ---------------- 参数 ----------------
$opts = getopt('', ['once', 'verbose', 'max:']);
$GLOBALS['ZC_WORKER_VERBOSE'] = isset($opts['verbose']);
$once      = isset($opts['once']);
$maxRounds = isset($opts['max']) ? max(0, (int) $opts['max']) : 0;

// ---------------- 扩展检查 ----------------
$required = ['pdo_mysql', 'openssl', 'mbstring', 'curl'];
$missing  = array_values(array_filter($required, static fn($e) => !extension_loaded($e)));
if ($missing) {
    fwrite(STDERR, '[错误] 缺少 PHP 扩展: ' . implode(', ', $missing) . PHP_EOL
        . '  Windows 可这样运行: php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/worker.php' . PHP_EOL);
    exit(1);
}

Config::load();

if (!(bool) Config::get('mail.queue.enabled', false)) {
    fwrite(STDERR, '[警告] mail.queue.enabled 为 false：API 会走同步发送，worker 不会收到任务。' . PHP_EOL);
}

$idleSleep = max(1, (int) Config::get('mail.queue.idle_sleep', 2));
$runner    = new WorkerRunner('worker_out');

worker_out(sprintf(
    'Worker 启动 pid=%s:%d batch=%d max_attempts=%d mode=%s',
    gethostname() ?: 'cli',
    getmypid(),
    max(1, (int) Config::get('mail.queue.batch', 10)),
    max(1, (int) Config::get('mail.queue.max_attempts', 3)),
    $once ? 'once' : 'daemon'
));

if ($once) {
    $stat = $runner->runOnce();
    worker_out(sprintf(
        '单次模式结束：认领 %d / 成功 %d / 失败 %d / 待重试 %d',
        $stat['claimed'],
        $stat['done'],
        $stat['failed'],
        $stat['retry']
    ));
} else {
    $runner->runDaemon($idleSleep, $maxRounds);
}

worker_out('Worker 退出');
exit(0);
