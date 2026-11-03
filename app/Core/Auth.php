<?php

namespace App\Core;

use App\Core\Exceptions\AuthException;

/**
 * API Key 鉴权：支持 Header / Query / Bearer 三种携带方式
 * 附加能力：IP 白名单（支持通配）、每分钟频率限制
 */
class Auth
{
    /**
     * @throws AuthException
     */
    public static function authenticate(Request $req): array
    {
        // 全局 IP 白名单
        $globalIps = (array) Config::get('security.allow_ips', []);
        if ($globalIps && !self::ipMatch($req->ip(), $globalIps)) {
            throw new AuthException('来源 IP 不在全局白名单内');
        }

        if (!Config::get('security.require_api_key', true)) {
            return ['id' => 0, 'name' => 'anonymous', 'rate_limit' => 0, 'allow_ips' => ''];
        }

        $key = self::extractKey($req);
        if ($key === '') {
            throw new AuthException('缺少 API Key，请通过 X-API-Key 请求头或 api_key 参数提供');
        }

        $db  = Database::instance();
        $row = $db->fetch(
            'SELECT * FROM `' . $db->table('api_keys') . '` WHERE api_key = ? LIMIT 1',
            [$key]
        );

        if (!$row) {
            throw new AuthException('API Key 无效');
        }
        if ((int) $row['status'] !== 1) {
            throw new AuthException('API Key 已被禁用');
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            throw new AuthException('API Key 已过期');
        }
        if (trim((string) $row['allow_ips']) !== '' && !self::ipMatch($req->ip(), explode(',', (string) $row['allow_ips']))) {
            throw new AuthException('来源 IP 不在该密钥的白名单内');
        }

        $limit = (int) $row['rate_limit'];
        if ($limit > 0 && !self::rateLimitOk((int) $row['id'], $limit)) {
            throw new AuthException("请求过于频繁，每分钟上限 {$limit} 次");
        }

        // 更新使用统计（非关键路径，失败不影响主流程）
        try {
            $db->query(
                'UPDATE `' . $db->table('api_keys') . '` SET last_used_at = NOW(), use_count = use_count + 1 WHERE id = ?',
                [$row['id']]
            );
        } catch (\Throwable $e) {
            Logger::warning('更新 API Key 使用统计失败: ' . $e->getMessage());
        }

        RequestContext::set('api_key_id', (int) $row['id']);
        return $row;
    }

    private static function extractKey(Request $req): string
    {
        $key = $req->header('x-api-key')
            ?? $req->header('x-zc-key')
            ?? $req->input('api_key')
            ?? '';

        if ($key === '') {
            $auth = (string) ($req->header('authorization') ?? '');
            if (stripos($auth, 'bearer ') === 0) {
                $key = substr($auth, 7);
            }
        }

        return trim((string) $key);
    }

    /** 支持精确 IP 与 192.168.1.* 通配 */
    private static function ipMatch(string $ip, array $whitelist): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($whitelist as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === '*') {
                return true;
            }
            if (str_contains($rule, '*')) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#';
                if (preg_match($regex, $ip)) {
                    return true;
                }
            } elseif ($rule === $ip) {
                return true;
            }
        }
        return false;
    }

    /** 基于文件的分钟级计数限流，无需额外表 */
    private static function rateLimitOk(int $keyId, int $limit): bool
    {
        $dir = Config::basePath('storage/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/rate_' . $keyId . '_' . date('YmdHi') . '.txt';

        $count = 0;
        if (is_file($file)) {
            $count = (int) file_get_contents($file);
        }
        if ($count >= $limit) {
            return false;
        }
        @file_put_contents($file, (string) ($count + 1), LOCK_EX);
        return true;
    }
}
