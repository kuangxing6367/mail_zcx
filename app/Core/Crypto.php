<?php

namespace App\Core;

/**
 * 对称加密（AES-256-GCM）与 HMAC 签名
 * - SMTP 密码加密存储，避免明文落库
 * - Webhook 出站签名 / 入站验签
 */
class Crypto
{
    private const PREFIX = 'enc:';

    private static function key(): string
    {
        $secret = (string) Config::get('security.app_secret', '');
        return hash('sha256', $secret, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
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
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
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
