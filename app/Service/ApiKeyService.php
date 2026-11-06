<?php

namespace App\Service;

use App\Core\Crypto;
use App\Core\Database;
use RuntimeException;

/**
 * API Key 管理
 *
 * 数据库只保存 sha256(api_key)；api_key 列仅存脱敏展示串。
 * 明文只在创建 / 轮换的响应里返回一次。
 */
class ApiKeyService
{
    private Database $db;
    private string $table;

    public function __construct()
    {
        $this->db    = Database::instance();
        $this->table = $this->db->table('api_keys');
    }

    public function list(int $page = 1, int $size = 50): array
    {
        $page = max(1, $page);
        $size = min(200, max(1, $size));
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `{$this->table}`");
        $offset = ($page - 1) * $size;
        $items = $this->db->fetchAll("SELECT * FROM `{$this->table}` ORDER BY id DESC LIMIT {$size} OFFSET {$offset}");

        return [
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'items' => array_map([$this, 'toApi'], $items),
        ];
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch("SELECT * FROM `{$this->table}` WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    /**
     * 创建密钥，返回含明文的完整信息（仅此一次可见）
     */
    public function create(array $data): array
    {
        $name   = trim((string) ($data['name'] ?? ''));
        $custom = trim((string) ($data['api_key'] ?? ''));
        if ($custom !== '' && strlen($custom) < 32) {
            throw new RuntimeException('自定义 API Key 至少 32 个字符');
        }
        // '*' 是脱敏展示串的占位符，鉴权侧一律拒绝含 '*' 的值，避免「展示串 == 凭证」
        if ($custom !== '' && str_contains($custom, '*')) {
            throw new RuntimeException('自定义 API Key 不能包含 * 字符');
        }
        $apiKey = $custom !== '' ? $custom : Crypto::randomKey('zc_');
        $secret = bin2hex(random_bytes(16));
        $scopes = $this->normalizeScopes($data['scopes'] ?? '*');

        $row = [
            'name'         => $name !== '' ? $name : '未命名密钥',
            'api_key'      => Crypto::mask($apiKey),
            'api_key_hash' => hash('sha256', $apiKey),
            'scopes'       => $scopes,
            'api_secret'   => $secret,
            'status'       => isset($data['status']) ? (int) $data['status'] : 1,
            'allow_ips'    => trim((string) ($data['allow_ips'] ?? '')),
            'rate_limit'   => (int) ($data['rate_limit'] ?? 0),
            'expires_at'   => !empty($data['expires_at']) ? (string) $data['expires_at'] : null,
        ];

        $id = $this->db->insert('api_keys', $row);

        return [
            'id'         => $id,
            'name'       => $row['name'],
            'api_key'    => $apiKey,   // 明文，仅此一次
            'api_secret' => $secret,
            'scopes'     => $scopes,
            'status'     => $row['status'],
        ];
    }

    public function update(int $id, array $data): int
    {
        if (!$this->get($id)) {
            throw new RuntimeException('密钥不存在: #' . $id);
        }
        $fields = ['name', 'status', 'allow_ips', 'rate_limit', 'expires_at', 'scopes'];
        $row = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $row[$f] = $data[$f];
            }
        }
        if (isset($row['status'])) {
            $row['status'] = (int) $row['status'];
        }
        if (isset($row['rate_limit'])) {
            $row['rate_limit'] = (int) $row['rate_limit'];
        }
        if (isset($row['scopes'])) {
            $row['scopes'] = $this->normalizeScopes($row['scopes']);
        }
        return $row ? $this->db->update('api_keys', $row, 'id = :wid', ['wid' => $id]) : 0;
    }

    public function delete(int $id): int
    {
        return $this->db->delete('api_keys', 'id = :id', ['id' => $id]);
    }

    /** 轮换密钥：生成新的 api_key / api_secret */
    public function rotate(int $id): array
    {
        if (!$this->get($id)) {
            throw new RuntimeException('密钥不存在: #' . $id);
        }
        $apiKey = Crypto::randomKey('zc_');
        $secret = bin2hex(random_bytes(16));
        $this->db->update('api_keys', [
            'api_key'      => Crypto::mask($apiKey),
            'api_key_hash' => hash('sha256', $apiKey),
            'api_secret'   => $secret,
        ], 'id = :wid', ['wid' => $id]);
        return ['id' => $id, 'api_key' => $apiKey, 'api_secret' => $secret];
    }

    public function toApi(array $row): array
    {
        // 永远不把摘要返回给前端
        unset($row['api_key_hash']);
        $row['api_key']    = Crypto::mask((string) $row['api_key']);
        $row['api_secret'] = Crypto::mask((string) $row['api_secret']);
        return $row;
    }

    /** 归一化 scope 列表，空或 * 表示全部权限 */
    private function normalizeScopes(mixed $raw): string
    {
        if (is_array($raw)) {
            $list = array_map('trim', $raw);
        } else {
            $list = array_map('trim', explode(',', (string) $raw));
        }
        $list = array_values(array_filter($list, static fn($v) => $v !== ''));
        if (!$list || in_array('*', $list, true)) {
            return '*';
        }
        $allowed = ['send', 'logs', 'mailboxes', 'templates', 'webhooks', 'keys'];
        foreach ($list as $s) {
            if (!in_array($s, $allowed, true)) {
                throw new RuntimeException('未知的 scope: ' . $s . '（可选：' . implode(', ', $allowed) . ' 或 *）');
            }
        }
        return implode(',', array_unique($list));
    }
}
