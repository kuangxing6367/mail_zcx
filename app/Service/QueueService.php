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
        // 正文里可能有任意二进制/非 UTF-8 内容（尤其 SMTP 入站透传的 8bit MIME）。
        // json_encode 失败会返回 false，直接入库就是一条空载荷任务，
        // worker 认领后必然失败，最后静默丢信 —— 这里必须当场发现。
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json  = json_encode($payload, $flags);

        // 退化路径：把原始报文字节搬进 base64 字段再序列化，保住一个字节都不丢
        if ($json === false && isset($payload['message']) && is_string($payload['message'])) {
            $payload['message_b64'] = base64_encode($payload['message']);
            $payload['message']     = '';
            $json = json_encode($payload, $flags);
        }

        if ($json === false) {
            throw new \RuntimeException('作业载荷序列化失败：' . json_last_error_msg());
        }

        return $this->db->insert('send_jobs', [
            'log_id'         => $logId,
            'mailbox_id'     => (int) ($payload['mailbox_id'] ?? 0),
            'status'         => 'pending',
            'payload'        => $json,
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

    /**
     * 标记投递成功
     *
     * 只允许「当前认领者」写终态：任务若已被 reclaim 回收并派给另一个 worker，
     * 原来那个慢 worker 不能把状态覆盖成 done —— 否则两封信都发出去了，
     * 队列却只记一次，用户也只会收到一条日志。
     *
     * @return bool false = 已被他人接管/回收，本次写入未生效
     */
    public function markDone(int $jobId, string $workerId, string $smtpCode, string $smtpMessage, string $error, int $durationMs): bool
    {
        return $this->db->update('send_jobs', [
            'status'        => 'done',
            'smtp_code'     => mb_substr($smtpCode, 0, 16),
            'smtp_message'  => $smtpMessage,
            'last_error'    => $error !== '' ? mb_substr($error, 0, 2000) : null,
            'duration_ms'   => $durationMs,
            'finished_at'   => date('Y-m-d H:i:s'),
        ], "id = :id AND status = 'processing' AND reserved_by = :w", ['id' => $jobId, 'w' => $workerId]) > 0;
    }

    /** 标记最终失败（用尽重试）；同 markDone，仅当前认领者可写 */
    public function markFailed(int $jobId, string $workerId, string $error): bool
    {
        return $this->db->update('send_jobs', [
            'status'       => 'failed',
            'last_error'   => mb_substr($error, 0, 2000),
            'finished_at'  => date('Y-m-d H:i:s'),
        ], "id = :id AND status = 'processing' AND reserved_by = :w", ['id' => $jobId, 'w' => $workerId]) > 0;
    }

    /**
     * 标记需重试：attempts +1，并推后 next_attempt_at
     * 只允许当前认领者把自己手上的 processing 任务退回 pending：
     * 若任务已被 reclaim 回收并重新派发，这里不能再去改别人的状态。
     */
    public function markRetry(int $jobId, string $workerId, string $error, int $nextDelaySec): bool
    {
        return $this->db->query(
            "UPDATE `{$this->table}`
             SET status = 'pending', attempts = attempts + 1,
                 last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 reserved_at = NULL, reserved_by = ''
             WHERE id = ? AND status = 'processing' AND reserved_by = ?",
            [mb_substr($error, 0, 2000), max(1, $nextDelaySec), $jobId, $workerId]
        )->rowCount() > 0;
    }

    /**
     * 回收卡死任务（processing 超过阈值）
     * 超过最大尝试次数的直接置 failed，避免无限回收
     */
    public function reclaim(int $stuckSeconds, int $maxAttempts = 3): int
    {
        $stuck = "status = 'processing' AND reserved_at IS NOT NULL
                  AND reserved_at < NOW() - INTERVAL " . max(1, $stuckSeconds) . " SECOND";

        // 1) 已用尽重试次数 → 终结
        $this->db->query(
            "UPDATE `{$this->table}`
             SET status = 'failed',
                 last_error = CONCAT('投递超时且已达最大重试次数（attempts=', attempts, '）'),
                 finished_at = NOW(), reserved_at = NULL, reserved_by = ''
             WHERE {$stuck} AND attempts >= ?",
            [max(1, $maxAttempts)]
        );

        // 2) 还有机会的任务退回 pending，并计一次尝试，防止无限回收
        return $this->db->query(
            "UPDATE `{$this->table}`
             SET status = 'pending', attempts = attempts + 1,
                 reserved_at = NULL, reserved_by = '', next_attempt_at = NOW()
             WHERE {$stuck} AND attempts < ?",
            [max(1, $maxAttempts)]
        )->rowCount();
    }

    /** 队列积压数量（pending + processing），供仪表盘展示 */
    public function backlogCount(): int
    {
        return $this->db->count('send_jobs', "status IN ('pending','processing')");
    }
}
