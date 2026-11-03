<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Mail\SmtpClient;
use RuntimeException;

/**
 * 多邮箱管理 + 智能选箱
 *
 * 选箱策略（config: mail.selection）：
 *   least_sent   默认。优先选择累计发件数最少的邮箱，天然负载均衡，
 *                避免单一邮箱发太多被服务商限流/封禁
 *   round_robin  按 last_used_at 最早优先
 *   random       随机
 *   weight       按 sent_total / weight 最小优先（权重越大越常被选中）
 */
class MailboxService
{
    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db    = Database::instance();
        $this->table = $this->db->table('mailboxes');
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    public function list(int $page = 1, int $size = 20, string $keyword = '', ?int $status = null): array
    {
        $page = max(1, $page);
        $size = min(200, max(1, $size));
        $where  = ['1'];
        $params = [];

        if ($keyword !== '') {
            $where[] = '(email LIKE :kw OR name LIKE :kw OR smtp_host LIKE :kw)';
            $params['kw'] = '%' . $keyword . '%';
        }
        if ($status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `{$this->table}` WHERE {$whereSql}", $params);

        $offset = ($page - 1) * $size;
        $rows = $this->db->fetchAll(
            "SELECT * FROM `{$this->table}` WHERE {$whereSql} ORDER BY status DESC, sent_total ASC, id ASC LIMIT {$size} OFFSET {$offset}",
            $params
        );

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map([$this, 'toApi'], $rows),
        ];
    }

    public function get(int $id): ?array
    {
        $row = $this->db->fetch("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1", [$id]);
        return $row ?: null;
    }

    public function getByEmail(string $email): ?array
    {
        $row = $this->db->fetch("SELECT * FROM `{$this->table}` WHERE email = ? LIMIT 1", [$email]);
        return $row ?: null;
    }

    /** 对外输出：脱敏密码 */
    public function toApi(array $row): array
    {
        $row['smtp_pass'] = $row['smtp_pass'] !== '' ? Crypto::mask('************') : '';
        $row['has_password'] = ($row['smtp_pass'] ?? '') !== '';
        return $row;
    }

    /** 内部使用：返回明文密码 */
    public function credentials(array $row): array
    {
        return [
            'host'   => (string) $row['smtp_host'],
            'port'   => (int) $row['smtp_port'],
            'secure' => (string) $row['smtp_secure'],
            'user'   => (string) ($row['smtp_user'] !== '' ? $row['smtp_user'] : $row['email']),
            'pass'   => Crypto::decrypt((string) $row['smtp_pass']),
        ];
    }

    // ------------------------------------------------------------------
    // 写入
    // ------------------------------------------------------------------

    public function create(array $data): int
    {
        $row = $this->normalize($data, true);

        if ($this->getByEmail($row['email'])) {
            throw new RuntimeException('该邮箱已存在: ' . $row['email']);
        }

        return $this->db->insert('mailboxes', $row);
    }

    public function update(int $id, array $data): int
    {
        if (!$this->get($id)) {
            throw new RuntimeException('邮箱不存在: #' . $id);
        }

        $row = $this->normalize($data, false);
        if (isset($row['email'])) {
            $exists = $this->getByEmail($row['email']);
            if ($exists && (int) $exists['id'] !== $id) {
                throw new RuntimeException('该邮箱已被其他账号占用: ' . $row['email']);
            }
        }

        if (!$row) {
            return 0;
        }
        return $this->db->update('mailboxes', $row, 'id = :wid', ['wid' => $id]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete('mailboxes', 'id = :id', ['id' => $id]);
    }

    public function resetQuota(int $id): int
    {
        return $this->db->update('mailboxes', [
            'sent_today' => 0,
            'quota_date' => date('Y-m-d'),
            'last_error' => null,
        ], 'id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------
    // 智能选箱
    // ------------------------------------------------------------------

    /**
     * 挑选一个可用邮箱
     *
     * @param int|null    $id    显式指定邮箱 ID
     * @param string|null $email 显式指定邮箱地址
     */
    public function selectForSend(?int $id = null, ?string $email = null): array
    {
        return $this->db->transaction(function () use ($id, $email) {
            if ($id) {
                $row = $this->db->fetch("SELECT * FROM `{$this->table}` WHERE id = ? AND status = 1 LIMIT 1 FOR UPDATE", [$id]);
                if (!$row) {
                    throw new RuntimeException('指定的发件邮箱不存在或已禁用: #' . $id);
                }
                $this->assertQuota($row);
                return $row;
            }

            if ($email) {
                $row = $this->db->fetch("SELECT * FROM `{$this->table}` WHERE email = ? AND status = 1 LIMIT 1 FOR UPDATE", [$email]);
                if (!$row) {
                    throw new RuntimeException('指定的发件邮箱不存在或已禁用: ' . $email);
                }
                $this->assertQuota($row);
                return $row;
            }

            $strategy = (string) Config::get('mail.selection', 'least_sent');
            $order = match ($strategy) {
                'round_robin' => 'last_used_at IS NULL DESC, last_used_at ASC, id ASC',
                'random'      => 'RAND()',
                'weight'      => '(sent_total / GREATEST(weight, 1)) ASC, id ASC',
                default       => 'sent_total ASC, id ASC',
            };

            $row = $this->db->fetch(
                "SELECT * FROM `{$this->table}`
                 WHERE status = 1
                   AND (daily_limit = 0 OR quota_date IS NULL OR quota_date <> CURDATE() OR sent_today < daily_limit)
                 ORDER BY {$order}
                 LIMIT 1 FOR UPDATE"
            );

            if (!$row) {
                throw new RuntimeException('没有可用的发件邮箱：可能全部被禁用或已达每日限额');
            }
            return $row;
        });
    }

    private function assertQuota(array $row): void
    {
        $limit = (int) $row['daily_limit'];
        if ($limit <= 0) {
            return;
        }
        $todaySent = ($row['quota_date'] === date('Y-m-d')) ? (int) $row['sent_today'] : 0;
        if ($todaySent >= $limit) {
            throw new RuntimeException('该邮箱今日已达发送上限（' . $limit . ' 封）');
        }
    }

    /** 发送成功后累加计数（sent_total 是智能选箱的核心依据） */
    public function recordSuccess(int $id): void
    {
        $this->db->query(
            "UPDATE `{$this->table}`
             SET sent_total = sent_total + 1,
                 sent_today = IF(quota_date = CURDATE(), sent_today + 1, 1),
                 quota_date = CURDATE(),
                 last_used_at = NOW(),
                 last_error = NULL
             WHERE id = ?",
            [$id]
        );
    }

    public function recordFailure(int $id, string $error): void
    {
        $this->db->query(
            "UPDATE `{$this->table}` SET fail_total = fail_total + 1, last_used_at = NOW(), last_error = ? WHERE id = ?",
            [mb_substr($error, 0, 1000), $id]
        );
    }

    // ------------------------------------------------------------------
    // 连通性测试
    // ------------------------------------------------------------------

    public function testConnection(array $row): array
    {
        $cred = $this->credentials($row);
        $start = microtime(true);
        $client = null;

        try {
            $client = new SmtpClient($cred['host'], $cred['port'], $cred['secure'], (int) Config::get('mail.timeout', 15));
            $client->connect();
            $client->authenticate($cred['user'], $cred['pass']);
            $caps = $client->capabilities();
            $client->quit();

            return [
                'ok'          => true,
                'message'     => 'SMTP 连接与认证成功',
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'capabilities'=> array_keys($caps),
                'session'     => $client->sessionLog(),
            ];
        } catch (\Throwable $e) {
            if ($client) {
                $client->close();
            }
            return [
                'ok'          => false,
                'message'     => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    // ------------------------------------------------------------------

    /**
     * 字段白名单与归一化
     */
    private function normalize(array $data, bool $isCreate): array
    {
        $fields = ['name', 'email', 'display_name', 'smtp_host', 'smtp_port', 'smtp_secure',
                   'smtp_user', 'smtp_pass', 'status', 'weight', 'daily_limit', 'remark'];
        $row = [];

        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $row[$f] = $data[$f];
            }
        }

        if ($isCreate) {
            foreach (['email', 'smtp_host'] as $required) {
                if (empty($row[$required])) {
                    throw new RuntimeException("缺少必填字段: {$required}");
                }
            }
        }

        if (isset($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮箱格式不正确: ' . $row['email']);
        }

        if (isset($row['smtp_port'])) {
            $row['smtp_port'] = (int) $row['smtp_port'];
            if ($row['smtp_port'] < 1 || $row['smtp_port'] > 65535) {
                throw new RuntimeException('SMTP 端口不合法');
            }
        }

        // 未指定加密方式时按端口自动推断：465→ssl, 587→tls, 其他→none
        if (!isset($row['smtp_secure']) && isset($row['smtp_port'])) {
            $row['smtp_secure'] = match ($row['smtp_port']) {
                465     => 'ssl',
                587, 2587 => 'tls',
                default => 'none',
            };
        }
        if (isset($row['smtp_secure']) && !in_array($row['smtp_secure'], ['ssl', 'tls', 'none'], true)) {
            throw new RuntimeException('smtp_secure 只能是 ssl / tls / none');
        }

        if (isset($row['smtp_pass'])) {
            if ($row['smtp_pass'] === '') {
                if ($isCreate) {
                    unset($row['smtp_pass']);
                } else {
                    // 传空字符串表示清空密码
                    $row['smtp_pass'] = '';
                }
            } else {
                $row['smtp_pass'] = Crypto::encrypt((string) $row['smtp_pass']);
            }
        }

        foreach (['status', 'weight', 'daily_limit'] as $intField) {
            if (isset($row[$intField])) {
                $row[$intField] = (int) $row[$intField];
            }
        }

        return $row;
    }
}
