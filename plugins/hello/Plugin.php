<?php

declare(strict_types=1);

namespace Plugins\Hello;

use App\Core\Plugin\PluginContext;
use App\Core\Response;

/**
 * 最小插件示例。
 *
 * 约定：类名必须带命名空间（见 plugin.json 的 class），否则多个插件会撞类名。
 * register() 只在插件启用时被调用，注册的东西仅在当前请求生命周期内有效。
 */
class Plugin
{
    public function register(PluginContext $ctx): void
    {
        // 1) 注册插件自己的接口。handler 可以是闭包、[类名, 方法] 或 "Controller@method"
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

        // 2) 事件容器：插件可以按自己的约定注册监听器。
        //    当前版本主程序不触发任何事件 —— 具体扩展点由后续版本按需求接入，
        //    这里只是展示注册方式，不代表下面这个事件现在会被调用。
        $ctx->on('example.ready', static function (array $payload): array {
            $payload['hello'] = true;
            return $payload;
        });
    }
}
