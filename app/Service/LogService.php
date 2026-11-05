<?php

namespace App\Service;

use App\Core\Database;

/**
 * 发送日志与统计
 */
class LogService
{
    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db    = Database::instance();
        $this->table = $this->db->table('mail_logs');
    }

    public function create(array $data): int
    {
        return $this->db->insert('mail_logs', $data);
    }

    public function update(int $id, array $data): int
    {
        return $this->db->update('mail_logs', $data, 'id = :wid', ['wid' => $id]);
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    public function list(array $filter = [], int $page = 1, int $size = 20): array
    {
        $page = max(1, $page);
        $size = min(200, max(1, $size));

        $where  = ['1'];
        $params = [];

        if (!empty($filter['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filter['status'];
        }
        if (!empty($filter['mailbox_id'])) {
            $where[] = 'mailbox_id = :mailbox_id';
            $params['mailbox_id'] = (int) $filter['mailbox_id'];
        }
        if (!empty($filter['request_id'])) {
            $where[] = 'request_id = :request_id';
            $params['request_id'] = $filter['request_id'];
        }
        if (!empty($filter['keyword'])) {
            // 命名参数在原生预处理（EMULATE_PREPARES=false）下不能重复出现，
            // 两个字段必须用两个名字，否则 MySQL 报 HY093 Invalid parameter number
            $where[] = '(to_email LIKE :kw1 OR subject LIKE :kw2)';
            $kw = '%' . $filter['keyword'] . '%';
            $params['kw1'] = $kw;
            $params['kw2'] = $kw;
        }
        if (!empty($filter['start'])) {
            $where[] = 'created_at >= :start';
            $params['start'] = $filter['start'];
        }
        if (!empty($filter['end'])) {
            $where[] = 'created_at <= :end';
            $params['end'] = $filter['end'];
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `{$this->table}` WHERE {$whereSql}", $params);

        $offset = ($page - 1) * $size;
        // 列表不返回 payload（含原始请求体，可能有验证码/收件人明细）
        $rows = $this->db->fetchAll(
            "SELECT id, request_id, mailbox_id, from_email, to_email, cc_email, bcc_email,
                    subject, template_code, status, smtp_code, smtp_message, error,
                    duration_ms, source_ip, user_agent, created_at, sent_at
             FROM `{$this->table}` WHERE {$whereSql} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}",
            $params
        );

        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $rows];
    }

    /**
     * 汇总统计
     */
    public function stats(string $range = 'today'): array
    {
        [$start, $end] = $this->rangeToDate($range);

        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'success') AS success,
                SUM(status = 'failed')  AS failed,
                SUM(status = 'pending') AS pending,
                ROUND(AVG(duration_ms)) AS avg_duration_ms
             FROM `{$this->table}`
             WHERE created_at >= ? AND created_at <= ?",
            [$start, $end]
        );

        $byMailbox = $this->db->fetchAll(
            "SELECT mailbox_id, from_email, COUNT(*) AS total, SUM(status='success') AS success
             FROM `{$this->table}`
             WHERE created_at >= ? AND created_at <= ?
             GROUP BY mailbox_id, from_email
             ORDER BY total DESC
             LIMIT 20",
            [$start, $end]
        );

        $byHour = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS total
             FROM `{$this->table}`
             WHERE created_at >= ? AND created_at <= ?
             GROUP BY hour ORDER BY hour ASC",
            [$start, $end]
        );

        return [
            'range'      => $range,
            'start'      => $start,
            'end'        => $end,
            'total'      => (int) ($row['total'] ?? 0),
            'success'    => (int) ($row['success'] ?? 0),
            'failed'     => (int) ($row['failed'] ?? 0),
            'pending'    => (int) ($row['pending'] ?? 0),
            'avg_duration_ms' => (int) ($row['avg_duration_ms'] ?? 0),
            'by_mailbox' => $byMailbox,
            'by_hour'    => $byHour,
        ];
    }

    private function rangeToDate(string $range): array
    {
        $now = time();
        return match ($range) {
            'today' => [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')],
            '7d'    => [date('Y-m-d 00:00:00', $now - 6 * 86400), date('Y-m-d 23:59:59')],
            '30d'   => [date('Y-m-d 00:00:00', $now - 29 * 86400), date('Y-m-d 23:59:59')],
            'all'   => ['1970-01-01 00:00:00', date('Y-m-d 23:59:59', $now + 86400)],
            default => [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')],
        };
    }
}
