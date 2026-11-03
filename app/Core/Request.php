<?php

namespace App\Core;

/**
 * 请求封装：统一 GET / POST / JSON / 表单 / 请求头
 * 支持 _method 覆盖（表单或 JSON 中传 _method=PUT 即可走 PUT 路由）
 */
class Request
{
    public string $method;
    public string $path;
    public array $query = [];
    public array $body = [];
    public array $headers = [];
    public string $rawBody = '';
    public array $files = [];

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->rawBody = (string) file_get_contents('php://input');
        $this->headers = $this->parseHeaders();
        $this->query   = $_GET ?? [];
        $this->body    = $this->parseBody();
        $this->files   = $_FILES ?? [];

        // _method 覆盖（放在 body 优先，其次 query）
        $override = $this->body['_method'] ?? $this->query['_method'] ?? null;
        if (is_string($override) && $override !== '') {
            $this->method = strtoupper($override);
        }

        $this->path = $this->resolvePath();
    }

    /** 合并 query + body，body 优先 */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function has(string $key): bool
    {
        $all = $this->all();
        return array_key_exists($key, $all);
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        if (is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** 取数组型参数，支持逗号分隔字符串自动拆分 */
    public function array(string $key): array
    {
        $v = $this->input($key);
        if ($v === null || $v === '') {
            return [];
        }
        if (is_array($v)) {
            return array_values(array_filter(array_map('trim', $v), static fn($x) => $x !== ''));
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn($x) => $x !== ''));
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtolower(str_replace('_', '-', $name));
        return $this->headers[$key] ?? $default;
    }

    /** 客户端真实 IP，兼容常见反代头 */
    public function ip(): string
    {
        foreach (['x-forwarded-for', 'x-real-ip', 'cf-connecting-ip'] as $h) {
            $val = $this->header($h);
            if ($val) {
                $first = trim(explode(',', $val)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->headers['user-agent'] ?? ''), 0, 255);
    }

    private function parseBody(): array
    {
        $contentType = strtolower((string) ($this->headers['content-type'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($this->rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }

        // multipart 或 x-www-form-urlencoded
        if (!empty($_POST)) {
            return $_POST;
        }

        // 兜底：无 content-type 但 body 是 JSON
        if ($this->rawBody !== '') {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        // Apache 下 getallheaders 更完整
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                $headers[strtolower($k)] = $v;
            }
        }
        return $headers;
    }

    private function resolvePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $base = (string) Config::get('app.base_path', '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
