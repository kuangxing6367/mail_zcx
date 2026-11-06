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
    /** 请求体超过 security.max_body_bytes 时为 true，由入口统一返回 413 */
    public bool $tooLarge = false;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->rawBody = $this->readBody();
        $this->headers = $this->parseHeaders();
        $this->query   = $_GET ?? [];
        $this->body    = $this->parseBody();
        $this->files   = $_FILES ?? [];

        // _method 覆盖：仅允许 POST 模拟 PUT/DELETE/PATCH，禁止用 GET 触发写操作
        $override = $this->body['_method'] ?? $this->query['_method'] ?? null;
        if ($this->method === 'POST' && is_string($override) && $override !== '') {
            $override = strtoupper($override);
            if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
                $this->method = $override;
            }
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

    /** 客户端真实 IP：仅当直连方在 trusted_proxies 内时才采信转发头，否则一律用 REMOTE_ADDR */
    public function ip(): string
    {
        $remote  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = (array) Config::get('security.trusted_proxies', ['127.0.0.1', '::1']);
        if ($remote === '' || !self::ipInList($remote, $trusted)) {
            return $remote;
        }

        $cf = trim((string) ($this->header('cf-connecting-ip') ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        $xff = (string) ($this->header('x-forwarded-for') ?? '');
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            // 从右往左取第一个非可信地址，伪造的左侧值会被忽略
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $ip = $parts[$i];
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }
                if (self::ipInList($ip, $trusted)) {
                    continue;
                }
                return $ip;
            }
        }

        $real = trim((string) ($this->header('x-real-ip') ?? ''));
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
            return $real;
        }

        return $remote;
    }

    /** 支持精确 IP、192.168.* 通配与 CIDR */
    private static function ipInList(string $ip, array $list): bool
    {
        foreach ($list as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === '*') {
                return true;
            }
            if (str_contains($rule, '/')) {
                [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
                $ipLong     = ip2long($ip);
                $subnetLong = ip2long($subnet);
                $bits       = (int) $bits;
                if ($ipLong !== false && $subnetLong !== false && $bits >= 0 && $bits <= 32) {
                    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
                    if (($ipLong & $mask) === ($subnetLong & $mask)) {
                        return true;
                    }
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

    public function userAgent(): string
    {
        return substr((string) ($this->headers['user-agent'] ?? ''), 0, 255);
    }

    /**
     * 读取请求体，带上限。
     *
     * php://input 不受 post_max_size 约束（那条限制只管 $_POST 解析），
     * 一个超大 JSON 足以把 FPM 进程内存打满，所以这里必须显式截断。
     * 上限默认 16MB：要容纳 10MB 附件经 base64 膨胀后的 ~13.4MB。
     */
    private function readBody(): string
    {
        $max = max(65536, (int) Config::get('security.max_body_bytes', 16777216));

        // Content-Length 先挡一道，避免把整个流读进内存再判断
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($declared > $max) {
            $this->tooLarge = true;
            return '';
        }

        $fp = @fopen('php://input', 'rb');
        if (!$fp) {
            return '';
        }
        $raw = (string) stream_get_contents($fp, $max + 1);
        fclose($fp);

        if (strlen($raw) > $max) {
            $this->tooLarge = true;
            return '';
        }
        return $raw;
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
