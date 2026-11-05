<?php

/**
 * 发信队列 Worker（常驻进程）
 *
 * 用法：
 *   常驻：  php cli/worker.php
 *   单次：  php cli/worker.php --once          （配合 cron / 宝塔计划任务兜底）
 *   调试：  php cli/worker.php --verbose
 *   限轮：  php cli/worker.php --max=5         （跑 5 轮后退出，便于测试）
 *
 * 生产建议用 supervisor 守护，配置见 deploy/supervisor-mail-worker.conf.example
 *
 * Windows 本地调试需要动态加载扩展：
 *   php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/worker.php --once
 */

declare(strict_types=1);

use App\Core\Config;
use App\Mail\Mailer;
use App\Service\QueueService;

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

/**
 * 输出一行日志
 */
function worker_out(string $msg, bool $verboseOnly = false): void
{
    if ($verboseOnly && empty($GLOBALS['ZC_WORKER_VERBOSE'])) {
        return;
    }
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

/**
 * 投递失败时决定重试还是最终失败
 */
function worker_retry_or_fail(
    QueueService $queue,
    Mailer $mailer,
    int $jobId,
    array $payload,
    array $result,
    int $attempts,
    int $maxAttempts,
    int $retryBase
): void {
    $nextAttempts = $attempts + 1;
    $error        = (string) ($result['error'] ?? '投递失败');

    if ($nextAttempts >= $maxAttempts) {
        $queue->markFailed($jobId, $error);
        $mailer->finalize($payload, $result);
        worker_out("job#{$jobId} 最终失败: {$error}");
        return;
    }

    $delay = max(1, (int) ($retryBase * (2 ** ($nextAttempts - 1))));
    $queue->markRetry($jobId, $error, $delay);
    worker_out("job#{$jobId} 投递失败，{$delay}s 后重试（第 {$nextAttempts}/{$maxAttempts} 次）: {$error}");
}

// ---------------- 参数 ----------------
$opts = getopt('', ['once', 'verbose', 'max:']);
$GLOBALS['ZC_WORKER_VERBOSE'] = isset($opts['verbose']);
$once       = isset($opts['once']);
$maxRounds  = isset($opts['max']) ? max(0, (int) $opts['max']) : 0;

// ---------------- 扩展检查 ----------------
$required = ['pdo_mysql', 'openssl', 'mbstring', 'curl'];
$missing  = array_values(array_filter($required, static fn($e) => !extension_loaded($e)));
if ($missing) {
    fwrite(STDERR, '[错误] 缺少 PHP 扩展: ' . implode(', ', $missing) . PHP_EOL
        . '  Windows 可这样运行: php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl cli/worker.php' . PHP_EOL);
    exit(1);
}

Config::load();

// worker 是后台进程，Webhook 直接同步派发即可（不占用 Web 请求），
// 同时避免 dispatchAsync 在非 FPM 环境下每次都记一条“异步不可用”的 warning。
Config::set('webhook.async', false);

if (!(bool) Config::get('mail.queue.enabled', false)) {
    fwrite(STDERR, '[警告] mail.queue.enabled 为 false：API 会走同步发送，worker 不会收到任务。' . PHP_EOL);
}

// ---------------- 初始化 ----------------
$queue        = new QueueService();
$mailer       = new Mailer();
$workerId     = gethostname() . ':' . getmypid();
$maxAttempts  = max(1, (int) Config::get('mail.queue.max_attempts', 3));
$batch        = max(1, (int) Config::get('mail.queue.batch', 10));
$idleSleep    = max(1, (int) Config::get('mail.queue.idle_sleep', 2));
$stuckTimeout = max(30, (int) Config::get('mail.queue.stuck_timeout', 300));
$retryBase    = max(1, (int) Config::get('mail.queue.retry_base', 30));

worker_out("Worker 启动 pid={$workerId} batch={$batch} max_attempts={$maxAttempts} mode=" . ($once ? 'once' : 'daemon'));

$rounds = 0;

while (true) {
    $rounds++;

    // 回收卡死任务（worker 崩溃 / 进程被杀）
    $reclaimed = $queue->reclaim($stuckTimeout, $maxAttempts);
    if ($reclaimed > 0) {
        worker_out("回收卡死任务 {$reclaimed} 个", true);
    }

    $jobs = $queue->claim($batch, $workerId);

    if (!$jobs) {
        if ($once || ($maxRounds > 0 && $rounds >= $maxRounds)) {
            worker_out('无待处理任务，退出');
            break;
        }
        sleep($idleSleep);
        continue;
    }

    foreach ($jobs as $job) {
        $jobId    = (int) $job['id'];
        $payload  = (array) ($job['payload'] ?? []);
        $attempts = (int) $job['attempts'];

        try {
            $result = $mailer->deliver($payload);

            if ($result['status'] === 'success') {
                // 顺序很关键：先写日志/计数（关键副作用），再 markDone，最后派发回调。
                // 反过来的话，回调异常会把 done 改回 pending 造成重复投递；
                // 进程若在中途崩溃，reclaim 也只会回收 processing 状态的任务。
                $mailer->finalizeLog($payload, $result);
                $queue->markDone(
                    $jobId,
                    (string) $result['smtp_code'],
                    (string) $result['smtp_message'],
                    (string) ($result['error'] ?? ''),
                    (int) $result['duration_ms']
                );
                try {
                    $mailer->finalizeCallback($payload, $result);
                } catch (\Throwable $e) {
                    // 邮件已投递，回调失败只记录，不重试整封邮件
                    \App\Core\Logger::error('回调派发失败（投递已完成）: ' . $e->getMessage(), [
                        'job_id' => $jobId,
                        'log_id' => (int) $payload['log_id'],
                    ]);
                }
                worker_out("job#{$jobId} 投递成功 log#{$payload['log_id']} ({$result['duration_ms']}ms)");
            } else {
                worker_retry_or_fail($queue, $mailer, $jobId, $payload, $result, $attempts, $maxAttempts, $retryBase);
            }
        } catch (\Throwable $e) {
            // 邮箱被删、凭据解密失败等前置异常也按失败处理
            $result = [
                'status'       => 'failed',
                'smtp_code'    => '',
                'smtp_message' => '',
                'error'        => $e->getMessage(),
                'duration_ms'  => 0,
            ];
            worker_retry_or_fail($queue, $mailer, $jobId, $payload, $result, $attempts, $maxAttempts, $retryBase);
        }
    }

    if ($once || ($maxRounds > 0 && $rounds >= $maxRounds)) {
        worker_out('单次模式结束');
        break;
    }
}

worker_out('Worker 退出');
exit(0);
