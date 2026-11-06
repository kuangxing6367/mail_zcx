<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Logger;
use App\Mail\Mailer;

/**
 * 队列投递运行器
 *
 * 之所以从 cli/worker.php 抽出来：无 CLI 权限的虚拟主机要靠 Web Cron
 * （面板「计划任务 → 访问 URL」）驱动队列，两条入口必须共用同一套状态机逻辑，
 * 否则 CLI 与 Web 的行为会分叉。
 *
 * 状态机顺序（不要改）：
 *   deliver → finalizeLog（关键副作用）→ markDone → finalizeCallback（非关键）
 * 反过来会出现「回调抛异常把 done 改回 pending」导致重复投递。
 */
class WorkerRunner
{
    private QueueService $queue;
    private Mailer $mailer;
    private int $maxAttempts;
    private int $batch;
    private int $stuckTimeout;
    private int $retryBase;
    /** @var callable|null */
    private $logger;

    public function __construct(?callable $logger = null)
    {
        $this->queue        = new QueueService();
        $this->mailer       = new Mailer();
        $this->maxAttempts  = max(1, (int) Config::get('mail.queue.max_attempts', 3));
        $this->batch        = max(1, (int) Config::get('mail.queue.batch', 10));
        $this->retryBase    = max(1, (int) Config::get('mail.queue.retry_base', 30));
        $this->logger       = $logger;

        // 回收阈值必须 > 一批任务的最坏耗时，否则「还在正常投递」的任务会被当成卡死：
        // 一批 batch 封 × 每封 total_timeout，正好等于默认的 300s。
        // 一旦被别的实例回收并重发，就是重复发信。这里取「批次上限 + 60s 余量」与配置的较大值。
        $perJob     = max(1, (int) Config::get('mail.total_timeout', 30)) + 5;
        $minStuck   = $this->batch * $perJob + 60;
        $this->stuckTimeout = max($minStuck, (int) Config::get('mail.queue.stuck_timeout', 300));

        // 投递发生在后台/计划任务里，回调必须同步做完，
        // 否则 fastcgi_finish_request 之后进程被回收会把回调截断。
        Config::set('webhook.async', false);
    }

    private function log(string $msg, bool $verboseOnly = false): void
    {
        if ($this->logger) {
            ($this->logger)($msg, $verboseOnly);
        }
    }

    /**
     * 处理若干封邮件后返回统计。
     *
     * @param int $maxJobs >0 时最多处理这么多封（Web Cron 用，防止请求超时）
     * @return array{claimed:int,done:int,failed:int,retry:int,reclaimed:int}
     */
    public function runOnce(int $maxJobs = 0): array
    {
        $stat = ['claimed' => 0, 'done' => 0, 'failed' => 0, 'retry' => 0, 'reclaimed' => 0];

        $stat['reclaimed'] = $this->queue->reclaim($this->stuckTimeout, $this->maxAttempts);
        if ($stat['reclaimed'] > 0) {
            $this->log("回收卡死任务 {$stat['reclaimed']} 个", true);
        }

        $limit = $this->batch;
        if ($maxJobs > 0) {
            $limit = min($this->batch, $maxJobs);
        }

        $workerId = $this->workerId();
        $jobs = $this->queue->claim($limit, $workerId);
        $stat['claimed'] = count($jobs);

        foreach ($jobs as $job) {
            $jobId    = (int) $job['id'];
            $payload  = (array) ($job['payload'] ?? []);
            $attempts = (int) $job['attempts'];

            try {
                $result = $this->mailer->deliver($payload);

                if ($result['status'] === 'success') {
                    $this->mailer->finalizeLog($payload, $result);
                    if (!$this->queue->markDone(
                        $jobId,
                        $workerId,
                        (string) $result['smtp_code'],
                        (string) $result['smtp_message'],
                        (string) ($result['error'] ?? ''),
                        (int) $result['duration_ms']
                    )) {
                        // 任务在投递期间被回收并派给了别的实例：邮件已经发出去了，
                        // 这里不能覆盖状态，只留痕，避免「重复发信 + 状态打架」
                        Logger::warning('任务已被回收，放弃回写终态（邮件可能已重复投递）', [
                            'job_id' => $jobId, 'worker' => $workerId,
                        ]);
                    }
                    try {
                        $this->mailer->finalizeCallback($payload, $result);
                    } catch (\Throwable $e) {
                        // 邮件已经发出去了，回调失败只记日志，绝不能因此重投
                        Logger::error('回调派发失败（投递已完成）: ' . $e->getMessage(), [
                            'job_id' => $jobId,
                            'log_id' => (int) $payload['log_id'],
                        ]);
                    } finally {
                        // 终态通知：与同步路径 Mailer::send() 语义一致，只触发一次
                        \App\Core\Plugin\Hooks::afterSend($payload, $result);
                    }
                    $stat['done']++;
                    $this->log("job#{$jobId} 投递成功 log#{$payload['log_id']} ({$result['duration_ms']}ms)");
                } else {
                    $stat[$this->retryOrFail($jobId, $workerId, $payload, $result, $attempts)]++;
                }
            } catch (\Throwable $e) {
                $result = [
                    'status'       => 'failed',
                    'smtp_code'    => '',
                    'smtp_message' => '',
                    'error'        => $e->getMessage(),
                    'duration_ms'  => 0,
                ];
                $stat[$this->retryOrFail($jobId, $workerId, $payload, $result, $attempts)]++;
            }
        }

        return $stat;
    }

    /** 常驻模式（仅 CLI） */
    public function runDaemon(int $idleSleep, int $maxRounds = 0): void
    {
        $rounds = 0;
        while (true) {
            $rounds++;
            $stat = $this->runOnce();
            if ($stat['claimed'] === 0) {
                if ($maxRounds > 0 && $rounds >= $maxRounds) {
                    $this->log('无待处理任务，退出');
                    return;
                }
                sleep(max(1, $idleSleep));
            } elseif ($maxRounds > 0 && $rounds >= $maxRounds) {
                return;
            }
        }
    }

    /** @return string done|failed|retry */
    private function retryOrFail(int $jobId, string $workerId, array $payload, array $result, int $attempts): string
    {
        $nextAttempts = $attempts + 1;
        $error        = (string) ($result['error'] ?? '投递失败');

        if ($nextAttempts >= $this->maxAttempts) {
            $written = $this->queue->markFailed($jobId, $workerId, $error);
            if (!$written) {
                Logger::warning('任务已被回收，放弃写失败终态', ['job_id' => $jobId, 'worker' => $workerId]);
                return 'failed';
            }
            try {
                $this->mailer->finalize($payload, $result);
            } finally {
                \App\Core\Plugin\Hooks::afterSend($payload, $result);
            }
            $this->log("job#{$jobId} 最终失败: {$error}");
            return 'failed';
        }

        $delay = max(1, (int) ($this->retryBase * (2 ** ($nextAttempts - 1))));
        if (!$this->queue->markRetry($jobId, $workerId, $error, $delay)) {
            Logger::warning('任务已被回收，放弃写重试状态', ['job_id' => $jobId, 'worker' => $workerId]);
            return 'retry';
        }
        $this->log("job#{$jobId} 投递失败，{$delay}s 后重试（第 {$nextAttempts}/{$this->maxAttempts} 次）: {$error}");
        return 'retry';
    }

    private function workerId(): string
    {
        $host = function_exists('gethostname') ? (string) gethostname() : 'web';
        return substr($host . ':' . getmypid(), 0, 64);
    }

    public function queue(): QueueService
    {
        return $this->queue;
    }
}
