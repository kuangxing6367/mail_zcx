<?php

namespace App\Core;

/**
 * 对称加密（AES-256-GCM）与 HMAC 签名
 * - SMTP 密码加密存储，避免明文落库
 * - Webhook 出站签名 / 入站验签
 *
 * 密钥来源（按优先级）：
 *   1. security.app_secret —— 部署者自己配的随机串
 *   2. storage/app_secret.local —— 首次使用时自动生成的 32 字节随机密钥（0600）
 *   3. 出厂常量 —— 仅用于解开「用出厂常量加密过的历史数据」，不再用于加密
 *
 * 为什么要第 2 条：出厂常量是公开的，所有没改配置的实例共用同一把密钥，
 * 拿到数据库就能解开 SMTP 授权码。让每台机器自动生成一把本地密钥，
 * 既不要求部署者多做一步，也不再有一把「人人都知道」的钥匙。
 */
class Crypto
{
    private const PREFIX = 'enc:';

    /** 出厂占位值：只当历史密钥用，绝不用于加密 */
    private const DEFAULT_SECRET = 'change-me-to-a-random-64-char-string';

    /** 本地自动生成的密钥文件（相对项目根） */
    private const LOCAL_KEY_FILE = 'storage/app_secret.local';

    private static function key(): string
    {
        return self::encryptSecret();
    }

    /** 加密专用密钥：够强的配置值 → 本机自动密钥 → 出厂常量（最后兜底，storage 不可写时） */
    private static function encryptSecret(): string
    {
        $secret = (string) Config::get('security.app_secret', '');
        // 只认足够长的配置值：示例占位串（'__APP_SECRET__'）或短口令一律忽略，
        // 否则等于用一把弱密钥加密所有 SMTP 授权码。
        if (strlen($secret) >= 32 && $secret !== self::DEFAULT_SECRET) {
            return $secret;
        }

        $local = self::localSecret();
        return $local !== '' ? $local : self::DEFAULT_SECRET;
    }

    /**
     * 解密候选密钥，按优先级：当前加密密钥 → 配置里的原值（哪怕很短，可能是历史密钥）
     * → 本机密钥 → 出厂常量。换过配置或从旧版本升级上来的数据都能解开。
     * @return string[]
     */
    private static function keyMaterial(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $secret = (string) Config::get('security.app_secret', '');
        $list   = [self::encryptSecret()];

        if ($secret !== '') {
            $list[] = $secret;
        }
        $local = self::localSecret();
        if ($local !== '') {
            $list[] = $local;
        }
        $list[] = self::DEFAULT_SECRET;

        $cache = array_values(array_unique($list));
        return $cache;
    }

    /** 读取（必要时生成）本机密钥；不可写时返回空串，由调用方退化为出厂常量 */
    private static function localSecret(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $file = Config::basePath(self::LOCAL_KEY_FILE);
        if (is_file($file)) {
            $raw = trim((string) @file_get_contents($file));
            $cached = strlen($raw) >= 32 ? $raw : '';
            return $cached;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            Logger::warning('无法创建 storage 目录，SMTP 密码将退回出厂密钥加密');
            $cached = '';
            return $cached;
        }

        try {
            $raw = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            Logger::warning('随机密钥生成失败: ' . $e->getMessage());
            $cached = '';
            return $cached;
        }

        // 先写临时文件再 rename，避免并发首次请求写出半截密钥
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $raw, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            Logger::warning('本机密钥写入失败（storage 不可写），SMTP 密码将退回出厂密钥加密');
            $cached = '';
            return $cached;
        }
        @chmod($file, 0600);

        $cached = $raw;
        return $cached;
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', self::key(), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('加密失败');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        // 兼容历史明文数据
        if (!str_starts_with($payload, self::PREFIX)) {
            return $payload;
        }
        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        // 依次尝试候选密钥：换过 app_secret、或从「出厂常量时代」升级上来的老数据都能解开
        foreach (self::keyMaterial() as $secret) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) {
                return $plain;
            }
        }
        Logger::warning('SMTP 密码解密失败：所有候选密钥都不匹配，请重新录入授权码');
        return '';
    }

    /** 生成签名：sha256=<hex> */
    public static function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $payload, string $signature, string $secret): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        return hash_equals(self::sign($payload, $secret), $signature);
    }

    public static function randomKey(string $prefix = 'zc_'): string
    {
        return $prefix . bin2hex(random_bytes(24));
    }

    /** 掩码显示密钥，如 zc_ab12****cdef */
    public static function mask(string $value, int $head = 8, int $tail = 4): string
    {
        $len = strlen($value);
        if ($len <= $head + $tail) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, $head) . str_repeat('*', 6) . substr($value, -$tail);
    }
}
