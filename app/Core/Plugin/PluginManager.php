<?php

namespace App\Core\Plugin;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Router;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 插件加载器。
 *
 * 约定：plugins/<slug>/plugin.json + 入口 PHP 文件，类通过 register(PluginContext) 注册能力。
 * 状态存 storage/plugins.json（不写数据库、不改表结构，虚拟主机同样可用）。
 * 单个插件加载失败只禁用它自己，不影响主程序继续处理请求。
 */
class PluginManager
{
    private static ?self $instance = null;

    private string $dir;
    private string $stateFile;
    private string $lockFile;

    /** @var array<string, PluginContext> 本次请求成功加载的插件 */
    private array $contexts = [];

    private bool $booted = false;

    private function __construct()
    {
        $root = defined('ZC_MAIL_ROOT') ? ZC_MAIL_ROOT : dirname(__DIR__, 3);
        $custom = trim((string) Config::get('plugins.dir', ''));
        $this->dir       = $custom !== '' ? $custom : $root . '/plugins';
        $this->stateFile = $root . '/storage/plugins.json';
        $this->lockFile  = $root . '/storage/plugins.lock';
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    // ------------------------------------------------------------------ 加载

    /**
     * 在路由注册之后、鉴权之前调用。
     * CLI 入口（worker / smtpd / Web Cron）没有 HTTP 路由，传 null 即可：
     * 会给一个不参与分发的空 Router，让插件的 register() 照常执行，
     * 从而保证队列投递、SMTP 入站这些路径也会触发业务事件。
     */
    public function boot(?Router $router = null): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $router ??= new Router();

        if (!Config::get('plugins.enabled', true)) {
            return;
        }
        if (!is_dir($this->dir)) {
            return;
        }

        $state = $this->state();
        foreach ($this->scan() as $slug => $item) {
            if (empty($state['enabled'][$slug])) {
                continue;
            }
            $this->load($slug, $item, $router);
        }
    }

    private function load(string $slug, array $item, Router $router): void
    {
        try {
            $manifest = $item['manifest'];
            $this->assertCompatible($manifest);

            $entry = (string) ($manifest['entry'] ?? 'Plugin.php');
            $file  = realpath($item['path'] . '/' . $entry);
            $base  = realpath($item['path']);
            if ($file === false || $base === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('入口文件不存在或越出插件目录');
            }
            if (!is_file($file)) {
                throw new RuntimeException('入口文件不是普通文件: ' . $entry);
            }

            $class = (string) ($manifest['class'] ?? '');
            if ($class === '' || !str_contains($class, '\\')) {
                throw new RuntimeException('plugin.json 的 class 必须是带命名空间的完整类名，避免多插件类名冲突');
            }

            require_once $file;
            if (!class_exists($class)) {
                throw new RuntimeException('入口类不存在: ' . $class);
            }

            $plugin = new $class();
            if (!method_exists($plugin, 'register')) {
                throw new RuntimeException('入口类缺少 register(PluginContext $ctx) 方法');
            }

            $ctx = new PluginContext($slug, $manifest, $router);
            $plugin->register($ctx);
            $this->contexts[$slug] = $ctx;
            $this->clearError($slug);
        } catch (Throwable $e) {
            // 一个插件写错不能让整个发信接口白屏：记错、自动停用、继续下一个
            Logger::warning('插件加载失败，已自动停用', [
                'plugin' => $slug,
                'error'  => $e->getMessage(),
            ]);
            $this->markError($slug, $e->getMessage());
        }
    }

    /**
     * 触发一个事件，把上一个监听器的返回值传给下一个（filter 链）。
     *
     * 隔离规则：
     *   - RejectException 原样上抛（显式拦截，由 Hooks 决定在哪一层生效）
     *   - 其它 Throwable 只记日志，跳过这个监听器
     *   - 返回值不是数组、或不符合 $schema 契约 → 整份丢弃，不让畸形数据混进主流程
     *
     * @param array<string,string> $schema 字段名 => array|string|int|email
     */
    public static function emit(string $event, array $payload = [], array $schema = []): array
    {
        foreach (self::instance()->contexts as $slug => $ctx) {
            foreach ($ctx->listeners($event) as $handler) {
                try {
                    $result = $handler($payload, $ctx);
                } catch (RejectException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    Logger::warning('插件事件处理异常', [
                        'plugin' => $slug,
                        'event'  => $event,
                        'error'  => $e->getMessage(),
                    ]);
                    continue;
                }

                if (!is_array($result)) {
                    Logger::warning('插件事件返回值不是数组，已丢弃', [
                        'plugin' => $slug, 'event' => $event, 'type' => get_debug_type($result),
                    ]);
                    continue;
                }
                if (!self::matchesSchema($result, $schema)) {
                    Logger::warning('插件事件返回值不符合契约，已丢弃', [
                        'plugin' => $slug, 'event' => $event,
                    ]);
                    continue;
                }
                $payload = $result;
            }
        }
        return $payload;
    }

    private static function matchesSchema(array $data, array $schema): bool
    {
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $data)) {
                continue; // 缺字段由主程序的载荷校验兜底，不在这里猜
            }
            $v  = $data[$key];
            $ok = match ($type) {
                'array'  => is_array($v),
                'string' => is_string($v),
                'int'    => is_int($v) || (is_string($v) && ctype_digit($v)),
                'email'  => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
                default  => true,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function loaded(): array
    {
        return array_keys($this->contexts);
    }

    // ------------------------------------------------------------------ 管理

    public function list(): array
    {
        $state = $this->state();
        $items = [];
        foreach ($this->scan() as $slug => $item) {
            $m = $item['manifest'];
            $items[] = [
                'slug'        => $slug,
                'name'        => (string) ($m['name'] ?? $slug),
                'version'     => (string) ($m['version'] ?? '0.0.0'),
                'author'      => (string) ($m['author'] ?? ''),
                'description' => (string) ($m['description'] ?? ''),
                'homepage'    => (string) ($m['homepage'] ?? ''),
                'requires'    => (array) ($m['requires'] ?? []),
                'class'       => (string) ($m['class'] ?? ''),
                'enabled'     => !empty($state['enabled'][$slug]),
                'loaded'      => isset($this->contexts[$slug]),
                'error'       => (string) ($state['errors'][$slug] ?? ''),
                'events'      => isset($this->contexts[$slug]) ? $this->contexts[$slug]->events() : [],
                'path'        => $item['path'],
                'manifest_ok' => $item['ok'],
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $items;
    }

    public function info(): array
    {
        return [
            'dir'        => $this->dir,
            'dir_exists' => is_dir($this->dir),
            'state_file' => $this->stateFile,
            'writable'   => $this->assertWritable(false),
            'enabled'    => (bool) Config::get('plugins.enabled', true),
            'count'      => count($this->list()),
            'loaded'     => $this->loaded(),
            'note'       => '业务扩展点见 App\\Core\\Plugin\\Hooks：mail.before_prepare / after_prepare / before_deliver / after_send。',
        ];
    }

    public function enable(string $slug): array
    {
        $slug = $this->assertSlug($slug);
        if (!isset($this->scan()[$slug])) {
            throw new InvalidArgumentException('插件不存在: ' . $slug);
        }
        return $this->mutate(static function (array $state) use ($slug): array {
            $state['enabled'][$slug] = true;
            unset($state['errors'][$slug]);
            return $state;
        });
    }

    public function disable(string $slug): array
    {
        $slug = $this->assertSlug($slug);
        return $this->mutate(static function (array $state) use ($slug): array {
            unset($state['enabled'][$slug]);
            return $state;
        });
    }

    /** 清错误 + 失效入口文件 opcache；插件代码改动后调用 */
    public function reload(string $slug): array
    {
        $slug = $this->assertSlug($slug);
        $item = $this->scan()[$slug] ?? null;
        if ($item !== null) {
            $entry = (string) ($item['manifest']['entry'] ?? 'Plugin.php');
            $file  = realpath($item['path'] . '/' . $entry);
            if ($file !== false && function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
        }
        return $this->mutate(static function (array $state) use ($slug): array {
            unset($state['errors'][$slug]);
            return $state;
        });
    }

    // ------------------------------------------------------------------ 状态文件

    private function state(): array
    {
        $default = ['enabled' => [], 'errors' => []];
        if (!is_file($this->stateFile)) {
            return $default;
        }
        $raw = @file_get_contents($this->stateFile);
        if ($raw === false) {
            return $default;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $default;
        }
        return [
            'enabled' => (array) ($data['enabled'] ?? []),
            'errors'  => (array) ($data['errors'] ?? []),
        ];
    }

    private function mutate(callable $fn): array
    {
        $this->assertWritable(true);
        return $this->withLock(function () use ($fn): array {
            $state = $fn($this->state());
            $state['updated_at'] = date('c');
            $tmp = $this->stateFile . '.tmp';
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
                throw new RuntimeException('插件状态写入失败：无法写临时文件');
            }
            if (!@rename($tmp, $this->stateFile)) {
                @unlink($tmp);
                throw new RuntimeException('插件状态写入失败：无法替换状态文件');
            }
            @chmod($this->stateFile, 0640);
            return $state;
        });
    }

    private function markError(string $slug, string $message): void
    {
        unset($this->contexts[$slug]);
        try {
            $this->mutate(static function (array $state) use ($slug, $message): array {
                unset($state['enabled'][$slug]);
                $state['errors'][$slug] = $message;
                return $state;
            });
        } catch (Throwable $e) {
            Logger::warning('插件错误状态写入失败', ['plugin' => $slug, 'error' => $e->getMessage()]);
        }
    }

    private function clearError(string $slug): void
    {
        try {
            if (isset($this->state()['errors'][$slug])) {
                $this->mutate(static function (array $state) use ($slug): array {
                    unset($state['errors'][$slug]);
                    return $state;
                });
            }
        } catch (Throwable $e) {
            Logger::warning('清除插件错误状态失败', ['plugin' => $slug, 'error' => $e->getMessage()]);
        }
    }

    private function withLock(callable $fn): mixed
    {
        $handle = @fopen($this->lockFile, 'c');
        if ($handle === false) {
            // 没有锁文件可用时退化为直接执行，写入本身仍是 rename 原子操作
            return $fn();
        }
        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function assertWritable(bool $throw): bool
    {
        $dir = dirname($this->stateFile);
        $ok  = is_dir($dir) && is_writable($dir)
            && (!is_file($this->stateFile) || is_writable($this->stateFile));
        if (!$ok && $throw) {
            throw new RuntimeException(
                'storage/ 目录不可写，无法保存插件启用状态。请给站点运行用户授权：' . $dir
            );
        }
        return $ok;
    }

    // ------------------------------------------------------------------ 扫描与校验

    private function scan(): array
    {
        $out = [];
        $entries = @scandir($this->dir);
        if ($entries === false) {
            return $out;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $entry)) {
                continue;
            }
            $path = $this->dir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $real = realpath($path);
            $base = realpath($this->dir);
            if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $jsonFile = $real . '/plugin.json';
            if (!is_file($jsonFile)) {
                continue;
            }
            $raw = @file_get_contents($jsonFile);
            $manifest = $raw === false ? null : json_decode($raw, true);
            if (!is_array($manifest)) {
                $manifest = [];
            }
            if (isset($manifest['slug']) && $manifest['slug'] !== $entry) {
                continue;
            }
            $manifest['slug'] = $entry;
            $out[$entry] = [
                'path'     => $real,
                'manifest' => $manifest,
                'ok'       => $raw !== false && is_array(json_decode((string) $raw, true)),
            ];
        }
        return $out;
    }

    private function assertCompatible(array $manifest): void
    {
        $requires = (array) ($manifest['requires'] ?? []);
        if (isset($requires['php'])) {
            $this->assertVersion('PHP', PHP_VERSION, (string) $requires['php']);
        }
        if (isset($requires['zcmail'])) {
            $this->assertVersion('ZC Mail', (string) Config::get('app.version', '0.0.0'), (string) $requires['zcmail']);
        }
    }

    /**
     * 支持 >= > <= < = != 以及 composer 风格的 ^ ~。
     * version_compare() 的第二个参数必须是纯版本号，比较符要走第三个参数，
     * 直接把 ">=1.1.0" 整个传进去会导致约束静默失效。
     */
    private function assertVersion(string $label, string $current, string $expr): void
    {
        $expr = trim($expr);
        if (!preg_match('/^(>=|<=|!=|<>|>|<|=|\^|~)?\s*([0-9][0-9A-Za-z.\-+]*)$/', $expr, $m)) {
            throw new RuntimeException($label . ' 版本约束写法不合法: ' . $expr);
        }
        $op   = $m[1] ?: '>=';
        $want = $m[2];

        if ($op === '^' || $op === '~') {
            $parts = explode('.', $want);
            $major = (int) ($parts[0] ?? 0);
            $minor = (int) ($parts[1] ?? 0);
            $upper = $op === '^' ? ($major + 1) . '.0.0' : $major . '.' . ($minor + 1) . '.0';
            $ok    = version_compare($current, $want, '>=') && version_compare($current, $upper, '<');
        } else {
            $ok = version_compare($current, $want, $op);
        }

        if (!$ok) {
            throw new RuntimeException('需要 ' . $label . ' ' . $expr . '，当前 ' . $current);
        }
    }

    private function assertSlug(string $slug): string
    {
        $slug = trim($slug);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $slug)) {
            throw new InvalidArgumentException('插件标识不合法（只允许小写字母、数字、- 和 _）');
        }
        return $slug;
    }
}
