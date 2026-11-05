<?php

namespace App\Service;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use RuntimeException;

/**
 * Webhook 双向能力
 *
 * 出站：发信完成后把结果 POST 给调用方/已配置的回调地址，带 HMAC-SHA256 签名
 * 入站：提供端点接收外部系统推送的事件，落库并可选二次转发
 */
class WebhookService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::instance();
    }

    // ==================================================================
    // 出站配置 CRUD
    // ==================================================================

    public function list(): array
    {
        return $this->db->fetchAll('SELECT * FROM `' . $this->db->table('webhooks') . '` ORDER BY id DESC');
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM `' . $this->db->table('webhooks') . '` WHERE id = ? LIMIT 1', [$id]) ?: null;
    }

    public function create(array $data): int
    {
        $row = $this->normalize($data, true);
        return $this->db->insert('webhooks', $row);
    }

    public function update(int $id, array $data): int
    {
        if (!$this->get($id)) {
            throw new RuntimeException('Webhook 配置不存在: #' . $id);
        }
        $row = $this->normalize($data, false);
        return $row ? $this->db->update('webhooks', $row, 'id = :wid', ['wid' => $id]) : 0;
    }

    public function delete(int $id): int
    {
        return $this->db->delete('webhooks', 'id = :id', ['id' => $id]);
    }

    // ==================================================================
    // 出站投递
    // ==================================================================

    /**
     * 派发事件
     *
     * @param string      $event       事件名，如 success / failed
     * @param array       $payload     回调数据
     * @param string|null $callbackUrl 调用方本次指定的回调地址（可自定义）
     * @param int|null    $logId       关联的发送日志 ID
     * @param string      $callbackSecret 本次回调的签名密钥
     */
    public function dispatch(string $event, array $payload, ?string $callbackUrl = null, ?int $logId = null, string $callbackSecret = ''): array
    {
        $targets = [];

        // 1) 调用方按次指定的回调
        if ($callbackUrl) {
            $targets[] = [
                'id'      => null,
                'url'     => $callbackUrl,
                'secret'  => $callbackSecret,
                'headers' => [],
                'retry'   => (int) Config::get('webhook.retry', 2),
            ];
        }

        // 2) 已配置的 webhook（匹配事件）
        foreach ($this->list() as $cfg) {
            if ((int) $cfg['status'] !== 1) {
                continue;
            }
            $events = array_filter(array_map('trim', explode(',', (string) $cfg['events'])));
            if ($events && !in_array($event, $events, true) && !in_array('*', $events, true) && !in_array('mail.' . $event, $events, true)) {
                continue;
            }
            $targets[] = [
                'id'      => (int) $cfg['id'],
                'url'     => (string) $cfg['url'],
                'secret'  => (string) $cfg['secret'],
                'headers' => $this->decodeHeaders($cfg['headers'] ?? ''),
                'retry'   => (int) $cfg['retry'],
            ];
        }

        if (!$targets) {
            return [];
        }

        $body = [
            'event'      => $event,
            'request_id' => (string) ($payload['request_id'] ?? ''),
            'timestamp'  => time(),
            'data'       => $payload,
        ];

        $results = [];
        foreach ($targets as $target) {
            $results[] = $this->deliver($target, $event, $body, $logId);
        }
        return $results;
    }

    /**
     * 响应后派发。
     *
     * 边界：只有 PHP-FPM 提供 fastcgi_finish_request()。注册 shutdown function
     * 只是「先把响应发给调用方」，FPM worker 仍被占用到回调跑完；
     * 非 FPM 环境（php -S / mod_php / CGI）连响应都发不出去，会退化成完全同步阻塞。
     * 这里按环境显式分支，不再伪装异步。
     */
    public function dispatchAsync(string $event, array $payload, ?string $callbackUrl = null, ?int $logId = null, string $callbackSecret = ''): void
    {
        if (!Config::get('webhook.async', true)) {
            $this->dispatch($event, $payload, $callbackUrl, $logId, $callbackSecret);
            return;
        }

        if (!function_exists('fastcgi_finish_request')) {
            $fallback = (string) Config::get('webhook.async_fallback', 'sync');
            Logger::warning('Webhook 异步不可用（缺少 fastcgi_finish_request），按 ' . $fallback . ' 处理');
            if ($fallback === 'sync') {
                try {
                    $this->dispatch($event, $payload, $callbackUrl, $logId, $callbackSecret);
                } catch (\Throwable $e) {
                    Logger::error('Webhook 同步兜底派发失败: ' . $e->getMessage());
                }
            }
            return;
        }

        register_shutdown_function(function () use ($event, $payload, $callbackUrl, $logId, $callbackSecret) {
            @fastcgi_finish_request();
            try {
                $this->dispatch($event, $payload, $callbackUrl, $logId, $callbackSecret);
            } catch (\Throwable $e) {
                Logger::error('异步 Webhook 派发失败: ' . $e->getMessage());
            }
        });
    }

    /**
     * 单次投递（含重试）
     */
    public function deliver(array $target, string $event, array $body, ?int $logId = null): array
    {
        $url      = (string) $target['url'];
        $secret   = (string) ($target['secret'] ?? '');
        $retry    = max(0, (int) ($target['retry'] ?? 0));
        $timeout  = (int) Config::get('webhook.timeout', 5);
        $json     = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type: application/json; charset=utf-8'];
        $headers[] = 'X-ZC-Event: ' . $event;
        $headers[] = 'X-ZC-Timestamp: ' . ($body['timestamp'] ?? time());
        if ($secret !== '') {
            $headers[] = Config::get('webhook.sign_header', 'X-ZC-Signature') . ': ' . Crypto::sign($json, $secret);
        }
        foreach ((array) ($target['headers'] ?? []) as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        $deliveryId = $this->db->insert('webhook_deliveries', [
            'webhook_id'   => $target['id'] ?? null,
            'log_id'       => $logId,
            'event'        => $event,
            'url'          => $url,
            'request_body' => $json,
            'status'       => 'pending',
            'attempts'     => 0,
        ]);

        $attempts = 0;
        $lastResult = ['code' => 0, 'body' => '', 'error' => ''];

        while ($attempts <= $retry) {
            $attempts++;
            $lastResult = $this->httpPost($url, $json, $headers, $timeout);
            if ($lastResult['code'] >= 200 && $lastResult['code'] < 300) {
                break;
            }
            if ($attempts <= $retry) {
                usleep(200000 * $attempts); // 递增退避
            }
        }

        $ok = $lastResult['code'] >= 200 && $lastResult['code'] < 300;
        $this->db->update('webhook_deliveries', [
            'http_code' => $lastResult['code'],
            'response'  => mb_substr((string) $lastResult['body'], 0, 2000),
            'status'    => $ok ? 'success' : 'failed',
            'attempts'  => $attempts,
        ], 'id = :wid', ['wid' => $deliveryId]);

        Logger::info('Webhook 投递', [
            'url'      => $url,
            'event'    => $event,
            'http'     => $lastResult['code'],
            'attempts' => $attempts,
        ]);

        return [
            'delivery_id' => $deliveryId,
            'url'         => $url,
            'http_code'   => $lastResult['code'],
            'attempts'    => $attempts,
            'status'      => $ok ? 'success' : 'failed',
            'error'       => $lastResult['error'],
        ];
    }

    public function deliveries(array $filter = [], int $page = 1, int $size = 20): array
    {
        $page = max(1, $page);
        $size = min(200, max(1, $size));
        $where = ['1'];
        $params = [];

        if (!empty($filter['log_id'])) {
            $where[] = 'log_id = :log_id';
            $params['log_id'] = (int) $filter['log_id'];
        }
        if (!empty($filter['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filter['status'];
        }
        $whereSql = implode(' AND ', $where);

        $table = $this->db->table('webhook_deliveries');
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}", $params);
        $offset = ($page - 1) * $size;
        $items = $this->db->fetchAll(
            "SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}",
            $params
        );

        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $items];
    }

    // ==================================================================
    // 入站事件
    // ==================================================================

    /**
     * 接收外部推送
     */
    public function receive(Request $req, array $opts = []): array
    {
        $secret = (string) ($opts['secret'] ?? '');
        if ($secret === '') {
            // 未配置 inbound_secret 时接口必须关闭，否则任何人都能写入事件并触发转发
            throw new RuntimeException('入站 Webhook 未启用（security.inbound_secret 为空）');
        }

        $source    = (string) ($opts['source'] ?? $req->header('x-zc-source') ?? 'external');
        $signature = (string) ($req->header(Config::get('webhook.sign_header', 'X-ZC-Signature')) ?? '');

        $raw = $req->rawBody !== '' ? $req->rawBody : json_encode($req->all(), JSON_UNESCAPED_UNICODE);
        $verified = Crypto::verify($raw, $signature, $secret);

        if (!$verified) {
            throw new RuntimeException('入站事件签名校验失败');
        }

        $payload = $req->all();
        $event = (string) ($payload['event'] ?? $payload['type'] ?? $req->header('x-zc-event') ?? 'unknown');

        $id = $this->db->insert('inbound_events', [
            'source'    => mb_substr($source, 0, 64),
            'event'     => mb_substr($event, 0, 64),
            'payload'   => mb_substr($raw, 0, 65535),
            'source_ip' => $req->ip(),
            'signature' => mb_substr($signature, 0, 255),
            'verified'  => $verified ? 1 : 0,
        ]);

        Logger::info('收到入站事件', ['id' => $id, 'source' => $source, 'event' => $event, 'verified' => $verified]);

        // 可选：二次转发到已配置的 webhook
        if (!empty($opts['forward']) && $this->list()) {
            $this->dispatchAsync('inbound.' . $event, $payload, null, null);
        }

        return ['id' => $id, 'event' => $event, 'source' => $source, 'verified' => $verified];
    }

    public function inboundList(array $filter = [], int $page = 1, int $size = 20): array
    {
        $page = max(1, $page);
        $size = min(200, max(1, $size));
        $table = $this->db->table('inbound_events');
        $where = ['1'];
        $params = [];
        if (!empty($filter['source'])) {
            $where[] = 'source = :source';
            $params['source'] = $filter['source'];
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}", $params);
        $offset = ($page - 1) * $size;
        $items = $this->db->fetchAll("SELECT * FROM `{$table}` WHERE {$whereSql} ORDER BY id DESC LIMIT {$size} OFFSET {$offset}", $params);
        return ['total' => $total, 'page' => $page, 'size' => $size, 'items' => $items];
    }

    // ==================================================================

    private function httpPost(string $url, string $body, array $headers, int $timeout): array
    {
        // 所有出站回调（配置的 webhook、按次 callback_url、测试）都必须过这道门
        self::assertSafeUrl($url);

        $verifyTls  = (bool) Config::get('webhook.verify_tls', true);
        $follow     = (bool) Config::get('webhook.follow_redirect', false);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => max(2, min(3, $timeout)),
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => $follow,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT      => (string) Config::get('webhook.user_agent', 'ZC-Mail-Service'),
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return ['code' => $code, 'body' => (string) $resp, 'error' => $err];
        }

        // 无 curl 时用 stream 兜底
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
                'follow_location' => $follow ? 1 : 0,
                'max_redirects' => 3,
            ],
            'ssl' => ['verify_peer' => $verifyTls, 'verify_peer_name' => $verifyTls],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return ['code' => $code, 'body' => (string) $resp, 'error' => $resp === false ? 'request failed' : ''];
    }

    /**
     * 回调地址安全校验：只允许 http/https，且不得指向回环/私网/链路本地/保留地址
     * @throws RuntimeException
     */
    public static function assertSafeUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('回调地址为空');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('回调地址格式不正确');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('回调地址只支持 http/https');
        }
        if ((bool) Config::get('webhook.allow_private_hosts', false)) {
            return;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new RuntimeException('回调地址缺少主机名');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $rec) {
                if (!empty($rec['ip'])) {
                    $ips[] = (string) $rec['ip'];
                }
                if (!empty($rec['ipv6'])) {
                    $ips[] = (string) $rec['ipv6'];
                }
            }
            if (!$ips) {
                $ip = @gethostbyname($host);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }
        if (!$ips) {
            throw new RuntimeException('回调地址域名无法解析');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new RuntimeException('回调地址指向内网/保留地址，已拒绝');
            }
        }
    }

    private static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // NO_PRIV_RANGE 覆盖 10/8、172.16/12、192.168/16；NO_RES_RANGE 覆盖 0/8、127/8、169.254/16、224/4 等
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (['127.', '169.254.', '::1', 'fc', 'fd', 'fe80'] as $prefix) {
            if (stripos($ip, $prefix) === 0) {
                return false;
            }
        }
        return true;
    }

    private function decodeHeaders(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalize(array $data, bool $isCreate): array
    {
        $fields = ['name', 'url', 'secret', 'events', 'headers', 'status', 'retry'];
        $row = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $row[$f] = $data[$f];
            }
        }
        if ($isCreate && empty($row['url'])) {
            throw new RuntimeException('缺少必填字段: url');
        }
        if (isset($row['url'])) {
            self::assertSafeUrl((string) $row['url']);
        }
        if (isset($row['headers']) && is_array($row['headers'])) {
            $row['headers'] = json_encode($row['headers'], JSON_UNESCAPED_UNICODE);
        }
        foreach (['status', 'retry'] as $f) {
            if (isset($row[$f])) {
                $row[$f] = (int) $row[$f];
            }
        }
        return $row;
    }
}
