<?php

namespace App\Core;

/**
 * 轻量文件日志
 */
class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?string $file = null;
    private static string $minLevel = 'debug';

    public static function init(): void
    {
        self::$file = (string) Config::get('log.file', Config::basePath('storage/logs/app.log'));
        self::$minLevel = (string) Config::get('log.level', 'debug');

        $dir = dirname(self::$file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::log('debug', $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::log('info', $msg, $ctx);
    }

    public static function warning(string $msg, array $ctx = []): void
    {
        self::log('warning', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::log('error', $msg, $ctx);
    }

    public static function log(string $level, string $msg, array $ctx = []): void
    {
        $level = strtolower($level);
        $min = self::LEVELS[self::$minLevel] ?? 0;
        if ((self::LEVELS[$level] ?? 0) < $min) {
            return;
        }
        if (self::$file === null) {
            self::init();
        }

        $rid = RequestContext::get('request_id', '-');
        $line = sprintf(
            "[%s] [%s] [%s] %s%s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $rid,
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            PHP_EOL
        );

        @file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX);
    }
}
