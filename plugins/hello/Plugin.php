<?php

declare(strict_types=1);

namespace Plugins\Hello;

use App\Core\Plugin\Hooks;
use App\Core\Plugin\PluginContext;
use App\Core\Response;

/**
 * 最小插件示例：注册接口 + 监听主程序的四个业务扩展点。
 *
 * 约定：类名必须带命名空间（见 plugin.json 的 class），否则多个插件会撞类名。
 * register() 只在插件启用时被调用，注册的东西仅在当前请求 / 进程生命周期内有效。
 *
 * 示例里的改写全部由请求中的 _demo_* 字段显式触发，默认不影响任何正常发信。
 */
class Plugin
{
    public function register(PluginContext $ctx): void
    {
        // ---------- 1) 注册插件自己的接口 ----------
        $ctx->get('/api/v1/hello/ping', static function (): void {
            Response::ok(['pong' => true, 'time' => date('c')]);
        });

        $ctx->get('/api/v1/hello/info', static function () use ($ctx): void {
            Response::ok([
                'slug'     => $ctx->slug(),
                'name'     => $ctx->name(),
                'version'  => $ctx->version(),
                'data_dir' => $ctx->dataDir(),
                'events'   => $ctx->events(),
            ]);
        });

        // ---------- 2) 解析前：可改 input，可拒绝（此时尚未落库）----------
        $ctx->on(Hooks::BEFORE_PREPARE, static function (array $data): array {
            $input = (array) ($data['input'] ?? []);

            if (!empty($input['_demo_reject'])) {
                Hooks::reject('示例插件：请求带 _demo_reject，已拦截');
            }
            if (isset($input['_demo_prefix']) && is_string($input['_demo_prefix'])) {
                $input['subject'] = $input['_demo_prefix'] . (string) ($input['subject'] ?? '');
                $data['input'] = $input;
            }
            return $data;
        });

        // ---------- 3) 落库前：可改已组装的载荷，但不能拒绝 ----------
        // 这里只读取，演示能拿到什么。真正改写时务必同步维护 message / recipients /
        // to / subject 的一致性，否则 assertPayload() 会拦下畸形载荷。
        $ctx->on(Hooks::AFTER_PREPARE, static function (array $payload): array {
            return $payload;
        });

        // ---------- 4) 投递前：最后一次改写 / 拒绝 ----------
        $ctx->on(Hooks::BEFORE_DELIVER, static function (array $payload): array {
            $input = (array) ($payload['input'] ?? []);
            if (!empty($input['_demo_reject_deliver'])) {
                Hooks::reject('示例插件：投递前拦截');
            }
            return $payload;
        });

        // ---------- 5) 终态通知：只读，写插件自己的数据目录 ----------
        $ctx->on(Hooks::AFTER_SEND, static function (array $data) use ($ctx): array {
            $payload = (array) ($data['payload'] ?? []);
            $result  = (array) ($data['result'] ?? []);
            $line = json_encode([
                'time'    => date('c'),
                'log_id'  => $payload['log_id'] ?? null,
                'status'  => $result['status'] ?? '',
                'to'      => $payload['to'] ?? '',
                'subject' => $payload['subject'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            @file_put_contents($ctx->dataDir() . '/events.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            return $data;
        });
    }
}
