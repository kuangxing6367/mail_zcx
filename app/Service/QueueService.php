<?php

namespace App\Service;

use App\Core\Database;

/**
 * 发信队列：入队 / 原子认领 / 完成 / 重试 / 回收
 *
 * 并发安全：基于 InnoDB 行锁（SELECT ... FOR UPDATE）+ 状态机
 *   pending → processing（worker 认领）→ done / failed
 *   processing 超时（reserved_at 过旧）由 reclaim() 回收为 pending，防 worker 崩溃丢任务
 */
class QueueService
{
    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db    = Database::instance();
        $this->table = $this->db->table('send_jobs');
    }

    /**
     * 入队一封信。返回 job id。
     */
    public function enqueue(int $logId, array $payload, int $maxAttempts = 3): int
    {
        return $this->db->insert('send_jobs', [
            'log_id'         => $logId,
            'mailbox_id'     => (int) ($payload['mailbox_id'] ?? 0),
            'status'         => 'pending',
            'payload'        => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempts'       => 0,
            'max_attempts'   => max(1, $maxAttempts),
            'next_attempt_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 原子认领一批待处理任务（MySQL 5.7+ InnoDB 行锁）
     * 返回已 decode payload 的任务数组；无任务返回空数组。
     */
    public function claim(int $limit, string $workerId): array
    {
        $limit = max(1, $limit);

        return $this->db->transaction(function (Database $db) use ($limit, $workerId) {
            $rows = $db->fetchAll(
                "SELECT * FROM `{$this->table}`
                 WHERE status = 'pending' AND next_attempt_at <= NOW()
                 ORDER BY id ASC
                 LIMIT {$limit} FOR UPDATE"
            );
            if (!$rows) {
                return [];
            }

            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $db->query(
                "UPDATE `{$this->table}` SET status = 'processing', reserved_at = NOW(), reserved_by = ?
                 WHERE id IN ({$ph})",
                array_merge([$workerId], $ids)
            );

            foreach ($rows as &$r) {
                $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
            }
            return $rows;
        });
    }

    /** 标记投递成功 */
    public function markDone(int $jobId, string $smtpCode, string $smtpMessage, string $error, int $durationMs): void
    {
        $this->db->update('send_jobs', [
            'status'        => 'done',
            'smtp_code'     => mb_substr($smtpCode, 0, 16),
            'smtp_message'  => $smtpMessage,
            'last_error'    => $error !== '' ? mb_substr($error, 0, 2000) : null,
            'duration_ms'   => $durationMs,
            'finished_at'   => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $jobId]);
    }

    /** 标记最终失败（用尽重试） */
    public function markFailed(int $jobId, string $error): void
    {
        $this->db->update('send_jobs', [
            'status'       => 'failed',
            'last_error'   => mb_substr($error, 0, 2000),
            'finished_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $jobId]);
    }

    /** 标记需重试：attempts +1，并推后 next_attempt_at */
    public function markRetry(int $jobId, string $error, int $nextDelaySec): void
    {
        $this->db->query(
            "UPDATE `{$this->table}`
             SET status = 'pending', attempts = attempts + 1,
                 last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 reserved_at = NULL, reserved_by = ''
             WHERE id = ?",
            [mb_substr($error, 0, 2000), max(1, $nextDelaySec), $jobId]
        );
    }

    /** 回收卡死任务（processing 超过阈值），返回回收数量 */
    public function reclaim(int $stuckSeconds): int
    {
        return $this->db->update(
            'send_jobs',
            [
                'status'          => 'pending',
                'reserved_at'     => null,
                'reserved_by'     => '',
                'next_attempt_at' => date('Y-m-d H:i:s'),
            ],
            "status = 'processing' AND reserved_at IS NOT NULL AND reserved_at < NOW() - INTERVAL {$stuckSeconds} SECOND",
            []
        );
    }

    /** 队列积压数量（pending + processing），供仪表盘展示 */
    public function backlogCount(): int
    {
        return $this->db->count('send_jobs', "status IN ('pending','processing')");
    }
}
