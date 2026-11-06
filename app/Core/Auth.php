<?php

namespace App\Core;

use App\Core\Exceptions\AuthException;

/**
 * API Key 鉴权
 *
 * 安全约定：
 * - Key 只以 SHA-256 摘要落库（api_key_hash），不再保存明文；
 *   老库中残留的明文会在首次成功鉴权时自动迁移。
 * - 对外错误文案统一为「API Key 无效」，具体原因（禁用/过期/IP 不符）只写日志，避免枚举。
 * - 同一 IP 连续鉴权失败超阈值后临时锁定，防暴力试探。
 * - 支持 scope：api_keys.scopes 为逗号分隔的资源名，`*` 表示全部；旧数据默认 `*`。
 */
class Auth
{
    /** @throws AuthException */
    public static function authenticate(Request $req): array
    {
        $ip = $req->ip();

        // 全局 IP 白名单（不做失败计数，避免白名单外扫描把缓存写爆）
        $globalIps = (array) Config::get('security.allow_ips', []);
        if ($globalIps && !self::ipMatch($ip, $globalIps)) {
            throw new AuthException('来源 IP 不在白名单内');
        }

        if (!Config::get('security.require_api_key', true)) {
            RequestContext::set('scopes', ['*']);
            return ['id' => 0, 'name' => 'anonymous', 'scopes' => '*', 'rate_limit' => 0, 'allow_ips' => ''];
        }

        $window = (int) Config::get('security.auth_fail_window', 600);
        $limit  = (int) Config::get('security.auth_fail_limit', 10);
        if ($limit > 0 && self::failCount($ip, $window) >= $limit) {
            // 锁定期间文案与普通失败一致，不暴露锁定状态
            throw new AuthException('API Key 无效');
        }

        $key = self::extractKey($req);
        if ($key === '') {
            throw new AuthException('缺少 API Key，请通过 X-API-Key 请求头提供');
        }

        $row = self::verifyKey($key, $ip);

        $scopes = self::parseScopes((string) ($row['scopes'] ?? '*'));
        $rateLimit = (int) $row['rate_limit'];
        if ($rateLimit > 0 && !self::rateLimitOk((int) $row['id'], $rateLimit)) {
            throw new AuthException("请求过于频繁，每分钟上限 {$rateLimit} 次");
        }

        // 鉴权成功：清零失败计数并更新使用统计（均非关键路径）
        self::clearFailure($ip);
        try {
            Database::instance()->query(
                'UPDATE `' . Database::instance()->table('api_keys') . '` SET last_used_at = NOW(), use_count = use_count + 1 WHERE id = ?',
                [$row['id']]
            );
        } catch (\Throwable $e) {
            Logger::warning('更新 API Key 使用统计失败: ' . $e->getMessage());
        }

        RequestContext::set('api_key_id', (int) $row['id']);
        RequestContext::set('scopes', $scopes);
        return $row;
    }

    /**
     * 路由级权限校验：scope 为 null 表示该接口不额外限制
     * @throws AuthException
     */
    public static function requireScope(?string $scope): void
    {
        if (!self::hasScope($scope)) {
            throw new AuthException('当前 API Key 缺少权限: ' . $scope);
        }
    }

    /** 当前请求的 Key 是否具备某个 scope（null/空 = 不限） */
    public static function hasScope(?string $scope): bool
    {
        if ($scope === null || $scope === '') {
            return true;
        }
        $scopes = (array) RequestContext::get('scopes', ['*']);
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    /** 供 CLI（SMTP 入站）复用：校验 Key 是否有效，成功返回行；IP 用于 Key 级白名单 */
    public static function verifyKeyOnly(string $key, string $ip = ''): ?array
    {
        try {
            return self::verifyKey($key, $ip);
        } catch (AuthException) {
            return null;
        }
    }

    /**
     * 供 SMTP 入站等非 HTTP 入口复用：该 IP 是否已触发失败锁定。
     * 不在这里返回具体失败原因，调用方一律回通用错误，避免枚举。
     */
    public static function lockedOut(string $ip): bool
    {
        $limit = (int) Config::get('security.auth_fail_limit', 10);
        if ($limit <= 0 || $ip === '') {
            return false;
        }
        return self::failCount($ip, (int) Config::get('security.auth_fail_window', 600)) >= $limit;
    }

    private static function extractKey(Request $req): string
    {
        // 只接受请求头携带，避免 Key 进 URL / access log / Referer
        $key = $req->header('x-api-key')
            ?? $req->header('x-zc-key')
            ?? '';

        if ($key === '') {
            $auth = (string) ($req->header('authorization') ?? '');
            if (stripos($auth, 'bearer ') === 0) {
                $key = substr($auth, 7);
            }
        }

        return trim((string) $key);
    }

    /**
     * @throws AuthException
     */
    private static function verifyKey(string $key, string $ip): array
    {
        $db    = Database::instance();
        $table = $db->table('api_keys');
        $hash  = self::hashKey($key);

        $row = $db->fetch("SELECT * FROM `{$table}` WHERE api_key_hash = ? LIMIT 1", [$hash]);

        // 兼容历史明文数据：命中后立即就地升级为哈希，明文替换为脱敏展示串。
        //
        // ⚠ api_key 列同时存着 zc_7d08f1******41f7 这类**展示串**（keys 列表接口会原样返回）。
        // 如果不把含 '*' 的值排除，任何人拿展示串当 Key 发过来都会命中这一支，
        // 被当作「老库明文」写回哈希，从而以该行的全部 scope 通过鉴权 —— 等于脱敏串即凭证。
        // 因此：含 '*' 的一律不走兼容分支（正常 Key 是 48 位 hex，不会有 '*'）。
        if (!$row && $key !== '' && !str_contains($key, '*')) {
            $legacy = $db->fetch(
                "SELECT * FROM `{$table}` WHERE api_key = ? AND api_key NOT LIKE '%*%' LIMIT 1",
                [$key]
            );
            if ($legacy) {
                try {
                    $db->update(
                        'api_keys',
                        ['api_key_hash' => $hash, 'api_key' => Crypto::mask($key)],
                        'id = :id',
                        ['id' => $legacy['id']]
                    );
                } catch (\Throwable $e) {
                    Logger::warning('API Key 明文迁移失败: ' . $e->getMessage());
                }
                $legacy['api_key_hash'] = $hash;
                $row = $legacy;
            }
        }

        if (!$row) {
            throw self::fail($ip, 'key 不存在');
        }

        // 命中后顺手清掉残余明文（老库手动回填 hash 时 api_key 仍是原文）
        if (!str_contains((string) $row['api_key'], '*')) {
            try {
                $db->update('api_keys', ['api_key' => Crypto::mask((string) $row['api_key'])], 'id = :id', ['id' => $row['id']]);
                $row['api_key'] = Crypto::mask((string) $row['api_key']);
            } catch (\Throwable $e) {
                Logger::warning('API Key 脱敏失败: ' . $e->getMessage());
            }
        }
        if ((int) $row['status'] !== 1) {
            throw self::fail($ip, 'key 已禁用 #' . $row['id']);
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            throw self::fail($ip, 'key 已过期 #' . $row['id']);
        }
        if (trim((string) $row['allow_ips']) !== '' && !self::ipMatch($ip, explode(',', (string) $row['allow_ips']))) {
            throw self::fail($ip, 'key IP 白名单不匹配 #' . $row['id']);
        }

        return $row;
    }

    /** Key 只以 SHA-256 形式落库（Key 本身 48 位随机十六进制，无需加盐） */
    private static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function parseScopes(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '*') {
            return ['*'];
        }
        return array_values(array_filter(array_map('trim', preg_split('/[,|\s]+/', $raw) ?: [])));
    }

    /** @return AuthException 由调用方 throw，统一文案防枚举 */
    private static function fail(string $ip, string $reason): AuthException
    {
        Logger::warning('鉴权失败: ' . $reason, ['ip' => $ip]);
        if ($ip !== '') {
            self::recordFailure($ip, (int) Config::get('security.auth_fail_window', 600));
        }
        return new AuthException('API Key 无效');
    }

    /** 支持精确 IP、192.168.1.* 通配、CIDR（10.0.0.0/8） */
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
            if (str_contains($rule, '/')) {
                if (self::cidrMatch($ip, $rule)) {
                    return true;
                }
                continue;
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

    private static function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits       = (int) $bits;
        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    // ---------------- 失败计数（防爆破） ----------------

    private static function failFile(string $ip): string
    {
        $dir = Config::basePath('storage/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/authfail_' . substr(hash('sha256', $ip), 0, 16) . '.json';
    }

    private static function failCount(string $ip, int $window): int
    {
        $file = self::failFile($ip);
        if (!is_file($file)) {
            return 0;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || (time() - (int) ($data['start'] ?? 0)) > $window) {
            return 0;
        }
        return (int) ($data['n'] ?? 0);
    }

    private static function recordFailure(string $ip, int $window): void
    {
        $fp = @fopen(self::failFile($ip), 'c+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $data = json_decode((string) stream_get_contents($fp), true);
            if (!is_array($data) || (time() - (int) ($data['start'] ?? 0)) > $window) {
                $data = ['start' => time(), 'n' => 0];
            }
            $data['n'] = (int) ($data['n'] ?? 0) + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private static function clearFailure(string $ip): void
    {
        $file = self::failFile($ip);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    // ---------------- 分钟级限流（原子 + 自动清理） ----------------

    private static function rateLimitOk(int $keyId, int $limit): bool
    {
        $dir = Config::basePath('storage/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/rate_' . $keyId . '_' . date('YmdHi') . '.txt';

        // 计数文件打不开时放行，避免缓存故障阻断业务
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return true;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }
        $count = (int) stream_get_contents($fp);
        if ($count >= $limit) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) ($count + 1));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // 1/200 概率清理 2 小时前的旧计数文件，避免无限堆积
        if (random_int(1, 200) === 1) {
            self::gcCache($dir);
        }
        return true;
    }

    private static function gcCache(string $dir): void
    {
        $expire = time() - 7200;
        foreach ((array) glob($dir . '/{rate_*,authfail_*}', GLOB_BRACE) as $file) {
            if (is_file($file) && @filemtime($file) < $expire) {
                @unlink($file);
            }
        }
    }
}
