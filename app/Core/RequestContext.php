<?php

namespace App\Core;

/**
 * 请求级上下文（request_id 等），避免全局变量
 */
class RequestContext
{
    private static array $data = [];

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::$data;
    }

    /** 生成并记录本次请求 ID */
    public static function initRequestId(?string $provided = null): string
    {
        // X-Request-Id 会原样进入日志与响应，先做白名单，避免伪造日志分段或超长污染。
        $id = ($provided !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $provided) === 1)
            ? $provided
            : self::generate();
        self::set('request_id', $id);
        return $id;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }
}
