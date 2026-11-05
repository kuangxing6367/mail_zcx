<?php

namespace App\Core;

/**
 * 配置加载器（点号路径读取）
 */
class Config
{
    private static ?array $items = null;

    public static function load(?string $path = null): void
    {
        $path ??= dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException("配置文件不存在: {$path}");
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new \RuntimeException('配置文件必须返回数组');
        }

        // 环境覆盖：config/config.local.php 存放真实凭据，不入版本库
        $local = dirname($path) . '/config.local.php';
        if (is_file($local)) {
            $override = self::requireArray($local);
            if ($override !== null) {
                $cfg = array_replace_recursive($cfg, $override);
            }
        }

        // 控制台覆盖：config/config.overrides.php 由网页端「系统设置」生成，
        // 优先级高于 config.local.php —— 网页上刚保存的值必须立刻生效。
        // 单独一个文件的好处：网页永远不碰用户手写的 config.local.php，
        // 里面 getenv() 之类的逻辑不会被重写掉。删掉本文件即恢复手写配置。
        $web = dirname($path) . '/config.overrides.php';
        if (is_file($web)) {
            $override = self::requireArray($web);
            if ($override !== null) {
                $cfg = array_replace_recursive($cfg, $override);
            }
        }

        self::$items = $cfg;
    }

    /** 读取配置文件；语法损坏时返回 null 而不是把整个站点打挂 */
    private static function requireArray(string $file): ?array
    {
        try {
            $data = require $file;
        } catch (\Throwable $e) {
            error_log('[ZC Mail] 配置文件解析失败 ' . $file . ': ' . $e->getMessage());
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /** 网页端设置的落盘位置 */
    public static function overridePath(): string
    {
        return dirname(__DIR__, 2) . '/config/config.overrides.php';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$items === null) {
            self::load();
        }
        $value = self::$items;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::$items === null) {
            self::load();
        }
        $ref = &self::$items;
        $segments = explode('.', $key);
        $last = array_pop($segments);
        foreach ($segments as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        $ref[$last] = $value;
    }

    public static function all(): array
    {
        if (self::$items === null) {
            self::load();
        }
        return self::$items;
    }

    public static function basePath(string $append = ''): string
    {
        $root = dirname(__DIR__, 2);
        return $append === '' ? $root : $root . '/' . ltrim($append, '/\\');
    }
}
