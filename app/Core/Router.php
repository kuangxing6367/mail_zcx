<?php

namespace App\Core;

use App\Core\Exceptions\AuthException;

/**
 * 极简路由：支持 {param} 占位符、路由分组、多种 handler 写法
 */
class Router
{
    private array $routes = [];
    private string $prefix = '';

    public function group(string $prefix, callable $fn): void
    {
        $prev = $this->prefix;
        $this->prefix = $prev . '/' . trim($prefix, '/');
        $fn($this);
        $this->prefix = $prev;
    }

    public function add(string $method, string $pattern, mixed $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->prefix . '/' . ltrim($pattern, '/'),
            'handler' => $handler,
        ];
    }

    public function get(string $p, mixed $h): void
    {
        $this->add('GET', $p, $h);
    }

    public function post(string $p, mixed $h): void
    {
        $this->add('POST', $p, $h);
    }

    public function put(string $p, mixed $h): void
    {
        $this->add('PUT', $p, $h);
    }

    public function delete(string $p, mixed $h): void
    {
        $this->add('DELETE', $p, $h);
    }

    /** 任意方法都匹配 */
    public function any(string $p, mixed $h): void
    {
        $this->add('ANY', $p, $h);
    }

    public function dispatch(Request $req): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $regex = $this->toRegex($route['pattern']);
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($route['method'] !== 'ANY' && $route['method'] !== $req->method) {
                $allowedMethods[$route['method']] = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->invoke($route['handler'], $params, $req);
            return;
        }

        if ($allowedMethods) {
            header('Allow: ' . implode(', ', array_keys($allowedMethods)));
            Response::error('请求方法不允许: ' . $req->method, 405, 405);
            return;
        }

        Response::error('接口不存在: ' . $req->path, 404, 404);
    }

    private function toRegex(string $pattern): string
    {
        $regex = preg_replace('#\{([a-zA-Z_]\w*)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '/?$#';
    }

    private function invoke(mixed $handler, array $params, Request $req): void
    {
        try {
            if (is_callable($handler)) {
                $handler($req, $params);
                return;
            }
            if (is_array($handler)) {
                [$class, $method] = $handler;
                (new $class())->$method($req, $params);
                return;
            }
            if (is_string($handler) && str_contains($handler, '@')) {
                [$class, $method] = explode('@', $handler, 2);
                $fqcn = str_contains($class, '\\') ? $class : 'App\\Controller\\' . $class;
                (new $fqcn())->$method($req, $params);
                return;
            }
            throw new \RuntimeException('无效的路由处理器');
        } catch (AuthException $e) {
            Response::unauthorized($e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('路由处理异常: ' . $e->getMessage(), [
                'path'  => $req->path,
                'trace' => Config::get('app.debug') ? $e->getTraceAsString() : null,
            ]);
            if (Config::get('app.debug')) {
                Response::serverError($e->getMessage());
            } else {
                Response::serverError();
            }
        }
    }
}
