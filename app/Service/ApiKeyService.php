<?php

namespace App\Service;

use App\Core\Crypto;
use App\Core\Database;
use RuntimeException;

/**
 * API Key 管理
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
        $name = trim((string) ($data['name'] ?? ''));
        $apiKey = trim((string) ($data['api_key'] ?? '')) ?: Crypto::randomKey('zc_');
        $secret = trim((string) ($data['api_secret'] ?? '')) ?: bin2hex(random_bytes(16));

        $row = [
            'name'       => $name !== '' ? $name : '未命名密钥',
            'api_key'    => $apiKey,
            'api_secret' => $secret,
            'status'     => isset($data['status']) ? (int) $data['status'] : 1,
            'allow_ips'  => trim((string) ($data['allow_ips'] ?? '')),
            'rate_limit' => (int) ($data['rate_limit'] ?? 0),
            'expires_at' => !empty($data['expires_at']) ? (string) $data['expires_at'] : null,
        ];

        $id = $this->db->insert('api_keys', $row);
        $row['id'] = $id;

        return $row; // 明文返回，便于一次性保存
    }

    public function update(int $id, array $data): int
    {
        if (!$this->get($id)) {
            throw new RuntimeException('密钥不存在: #' . $id);
        }
        $fields = ['name', 'status', 'allow_ips', 'rate_limit', 'expires_at'];
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
        $this->db->update('api_keys', ['api_key' => $apiKey, 'api_secret' => $secret], 'id = :wid', ['wid' => $id]);
        return ['id' => $id, 'api_key' => $apiKey, 'api_secret' => $secret];
    }

    public function toApi(array $row): array
    {
        $row['api_key']    = Crypto::mask((string) $row['api_key']);
        $row['api_secret'] = Crypto::mask((string) $row['api_secret']);
        return $row;
    }
}
