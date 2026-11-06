<?php

namespace App\Core\Plugin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Router;

/**
 * 插件上下文：插件唯一拿到的东西。
 *
 * 这里只提供「能力」，不预设任何业务事件名。当前版本主程序不触发事件，
 * 插件主要靠注册自己的路由 / 数据表 / 计划任务入口来扩展。
 * 具体扩展点由后续版本在 Mailer、MailboxService 等位置按需求接入。
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

    // ---- 通用事件容器 ----
    //
    // 插件可以注册任意事件名。主程序何时、在哪些位置触发事件属于「扩展点」，
    // 由后续版本决定；在没有触发点的版本里，注册监听是合法但不会被调用的。

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
