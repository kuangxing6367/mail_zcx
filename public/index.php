<?php

/**
 * ZC Mail Service - 前端控制器（唯一入口）
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Exceptions\AuthException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Core\Router;

define('ZC_MAIL_ROOT', dirname(__DIR__));

// ---- 自动加载（PSR-4: App\ -> app/） ----
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = ZC_MAIL_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---- 内置服务器：静态文件直接放行 ----
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $staticFile  = __DIR__ . $requestPath;
    if ($requestPath !== '/' && is_file($staticFile)) {
        return false;
    }
    // 目录请求补 index.html：让 php -S 与 nginx 的 try_files 行为一致。
    // 否则 /zc-console/ 会落到 PHP 路由被鉴权拦截，返回 401 JSON。
    if (is_dir($staticFile) && is_file(rtrim($staticFile, '/') . '/index.html')) {
        return false;
    }
}

// ---- 引导 ----
try {
    Config::load();
    date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));
    Logger::init();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => '初始化失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$req = new Request();

// ---- 秘密入口前缀（可选，混淆 API 挂载点，防御扫描器） ----
// 配置 security.api_entry 后，API 仅在 /<前缀>/api/v1 下响应，直接访问 /api/v1 返回 404
$apiEntry = trim((string) Config::get('security.api_entry', ''), '/');
if ($apiEntry !== '') {
    if (str_starts_with($req->path, '/' . $apiEntry)) {
        $req->path = substr($req->path, strlen($apiEntry) + 1) ?: '/';
    } elseif (str_starts_with($req->path, '/api')) {
        Response::notFound('not found');
        exit;
    }
}

// ---- CORS ----
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-ZC-Key, Authorization, X-ZC-Signature, X-Request-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');
if ($req->method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- 请求 ID ----
RequestContext::initRequestId($req->header('x-request-id'));

// ---- 健康检查（免鉴权） ----
if ($req->path === '/' || $req->path === '/health') {
    Response::ok([
        'service' => Config::get('app.name'),
        'version' => Config::get('app.version'),
        'php'     => PHP_VERSION,
        'time'    => date('c'),
    ]);
    exit;
}

// ---- 注册路由 ----
$router = new Router();
$register = require ZC_MAIL_ROOT . '/routes.php';
$register($router);

// ---- 鉴权（入站 Webhook 走签名校验，不走 API Key） ----
$publicPaths = ['/api/v1/hook/inbound'];
try {
    if (!in_array($req->path, $publicPaths, true)) {
        Auth::authenticate($req);
    }
} catch (AuthException $e) {
    Logger::warning('鉴权失败: ' . $e->getMessage(), ['ip' => $req->ip(), 'path' => $req->path]);
    Response::unauthorized($e->getMessage());
    exit;
}

// ---- 分发 ----
Logger::debug('请求进入', ['method' => $req->method, 'path' => $req->path, 'ip' => $req->ip()]);

try {
    $router->dispatch($req);
} catch (\Throwable $e) {
    Logger::error('未捕获异常: ' . $e->getMessage(), [
        'file'  => $e->getFile() . ':' . $e->getLine(),
        'trace' => Config::get('app.debug') ? $e->getTraceAsString() : null,
    ]);
    Response::serverError(Config::get('app.debug') ? $e->getMessage() : '服务器内部错误');
}
