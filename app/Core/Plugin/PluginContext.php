<?php

namespace App\Core\Plugin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;

/**
 * 插件上下文：插件唯一拿到的东西。
 *
 * 这里提供「能力」：注册路由、读配置、拿 DB、私有数据目录、事件监听。
 * 主程序实际触发的事件名与载荷契约集中在 Hooks 类，不要自己发明事件名。
 */
class PluginContext
{
    private array $listeners = [];

    public function __construct(
        private readonly string $slug,
        private readonly array $manifest,
        private readonly Router $router
    ) {
    }

    // ---- 基本信息 ----

    public function slug(): string
    {
        return $this->slug;
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function name(): string
    {
        return (string) ($this->manifest['name'] ?? $this->slug);
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '0.0.0');
    }

    // ---- 路由 ----

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * 注册插件自己的接口。路径建议带插件名前缀，例如 /api/v1/hello/ping。
     * 第三个参数是所需 scope；留空表示该接口只校验 API Key，不额外限权。
     */
    public function route(string $method, string $path, mixed $handler, ?string $scope = null): self
    {
        $this->router->add($method, $path, $handler, $scope);
        return $this;
    }

    public function get(string $path, mixed $handler, ?string $scope = null): self
    {
        return $this->route('GET', $path, $handler, $scope);
    }

    public function post(string $path, mixed $handler, ?string $scope = null): self
    {
        return $this->route('POST', $path, $handler, $scope);
    }

    public function put(string $path, mixed $handler, ?string $scope = null): self
    {
        return $this->route('PUT', $path, $handler, $scope);
    }

    public function delete(string $path, mixed $handler, ?string $scope = null): self
    {
        return $this->route('DELETE', $path, $handler, $scope);
    }

    public function any(string $path, mixed $handler, ?string $scope = null): self
    {
        return $this->route('ANY', $path, $handler, $scope);
    }

    // ---- 基础服务 ----

    /** 只读配置；插件自己的配置建议放 config.local.php 的 plugins.<slug> 段 */
    public function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }

    public function db(): Database
    {
        return Database::instance();
    }

    /** 插件私有数据目录，首次调用自动创建 */
    public function dataDir(): string
    {
        $dir = ZC_MAIL_ROOT . '/storage/plugins/' . $this->slug;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    // ---- 事件容器 ----
    //
    // 用 Hooks 类里定义的事件名注册。可以写任意名字，但只有主程序真正
    // emit 的那几个会触发；返回值必须是数组，且字段类型要符合该事件的契约。

    public function on(string $event, callable $handler): self
    {
        $this->listeners[$event][] = $handler;
        return $this;
    }

    public function listeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    public function events(): array
    {
        return array_keys($this->listeners);
    }
}
