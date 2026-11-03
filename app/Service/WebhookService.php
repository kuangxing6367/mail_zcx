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
     * 异步派发：在响应发送给调用方之后执行，不阻塞接口
     */
    public function dispatchAsync(string $event, array $payload, ?string $callbackUrl = null, ?int $logId = null, string $callbackSecret = ''): void
    {
        if (!Config::get('webhook.async', true)) {
            $this->dispatch($event, $payload, $callbackUrl, $logId, $callbackSecret);
            return;
        }

        register_shutdown_function(function () use ($event, $payload, $callbackUrl, $logId, $callbackSecret) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
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
        $source    = (string) ($opts['source'] ?? $req->header('x-zc-source') ?? 'external');
        $secret    = (string) ($opts['secret'] ?? '');
        $signature = (string) ($req->header(Config::get('webhook.sign_header', 'X-ZC-Signature')) ?? '');

        $raw = $req->rawBody !== '' ? $req->rawBody : json_encode($req->all(), JSON_UNESCAPED_UNICODE);
        $verified = $secret !== '' ? Crypto::verify($raw, $signature, $secret) : false;

        if ($secret !== '' && !$verified) {
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
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => max(2, min(3, $timeout)),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
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
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        return ['code' => $code, 'body' => (string) $resp, 'error' => $resp === false ? 'request failed' : ''];
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
        if (isset($row['url']) && !filter_var($row['url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('回调地址格式不正确');
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
