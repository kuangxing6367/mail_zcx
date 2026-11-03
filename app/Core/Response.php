<?php

namespace App\Core;

/**
 * 统一 JSON 响应
 * 结构: {"code":0,"msg":"ok","data":...,"request_id":"...","time":1234567890}
 */
class Response
{
    public static function json(mixed $data = null, int $code = 0, string $msg = 'ok', int $httpCode = 200, array $extra = []): void
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Powered-By: ZC-Mail-Service');
        }

        $payload = array_merge([
            'code'       => $code,
            'msg'        => $msg,
            'data'       => $data,
            'request_id' => RequestContext::get('request_id', ''),
            'time'       => time(),
        ], $extra);

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function ok(mixed $data = null, string $msg = 'ok'): void
    {
        self::json($data, 0, $msg, 200);
    }

    public static function error(string $msg, int $code = 1, int $httpCode = 400, mixed $data = null): void
    {
        self::json($data, $code, $msg, $httpCode);
    }

    /** 常用错误码快捷方法 */
    public static function unauthorized(string $msg = '未授权：API Key 无效或缺失'): void
    {
        self::error($msg, 401, 401);
    }

    public static function forbidden(string $msg = '无权限'): void
    {
        self::error($msg, 403, 403);
    }

    public static function notFound(string $msg = '资源不存在'): void
    {
        self::error($msg, 404, 404);
    }

    public static function serverError(string $msg = '服务器内部错误'): void
    {
        self::error($msg, 500, 500);
    }
}
