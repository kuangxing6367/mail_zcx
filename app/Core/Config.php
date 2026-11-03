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
            $override = require $local;
            if (is_array($override)) {
                $cfg = array_replace_recursive($cfg, $override);
            }
        }

        self::$items = $cfg;
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
