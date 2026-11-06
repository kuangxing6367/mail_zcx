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

// PHP 运行基线（含 2026-10-21 两个未认证 RCE 的安全门槛）。
// 必须在任何 App\ 类加载之前：低版本环境也要拿到明确错误而非白屏 Parse error。
require ZC_MAIL_ROOT . '/bootstrap/requirements.php';
zc_php_guard();

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
    $real        = realpath($staticFile);
    $docRoot     = realpath(__DIR__) ?: __DIR__;
    // 必须落在 public/ 内：否则 /zc-console/../.. 这类路径会绕过路由，
    // 让内置服务器直接把 config/、storage/ 下的文件当静态资源吐出来
    $inside      = $real !== false && str_starts_with($real, $docRoot . DIRECTORY_SEPARATOR);

    if ($requestPath !== '/' && $inside && is_file($real) && $real !== __FILE__) {
        return false;
    }
    // 目录请求补 index.html：让 php -S 与 nginx 的 try_files 行为一致。
    // 否则 /zc-console/ 会落到 PHP 路由被鉴权拦截，返回 401 JSON。
    if ($inside && is_dir($real) && is_file(rtrim($real, '/') . '/index.html')) {
        return false;
    }
}

// ---- 引导 ----
try {
    Config::load();
    date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));
    Logger::init();
} catch (\Throwable $e) {
    // 初始化异常可能包含数据库账号/路径，只写日志，不回显给客户端
    @error_log('ZC Mail 初始化失败: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 500, 'msg' => '服务初始化失败，请检查配置文件与数据库连接'], JSON_UNESCAPED_UNICODE);
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
// 默认空 = 不发任何 CORS 头（同源部署不需要）。需要跨域时在设置里填具体域名，
// 只有明确写 '*' 才全放开 —— 不要用「配置没填」当默认放开。
$corsOrigins = trim((string) Config::get('security.cors_origins', ''));
if ($corsOrigins === '*') {
    header('Access-Control-Allow-Origin: *');
} elseif ($corsOrigins !== '') {
    $origin  = (string) ($req->header('origin') ?? '');
    $allowed = array_map('trim', explode(',', $corsOrigins));
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-ZC-Key, Authorization, X-ZC-Signature, X-Request-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Max-Age: 86400');
// API 响应里全是发信内容/日志/密钥信息，任何中间缓存都不该留存
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
if ($req->method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- 请求 ID ----
RequestContext::initRequestId($req->header('x-request-id'));

// ---- 请求体上限（php://input 不受 post_max_size 约束，必须自己挡）----
if ($req->tooLarge) {
    Response::error('请求体超过上限 ' . (int) Config::get('security.max_body_bytes', 16777216) . ' 字节', 413, 413);
    exit;
}

// ---- 健康检查（免鉴权） ----
if ($req->path === '/' || $req->path === '/health') {
    $health = [
        'service' => Config::get('app.name'),
        'version' => Config::get('app.version'),
        'time'    => date('c'),
    ];
    if (Config::get('app.health_detail', false)) {
        $health['php'] = PHP_VERSION;
    }
    Response::ok($health);
    exit;
}

// ---- 注册路由 ----
$router = new Router();
$register = require ZC_MAIL_ROOT . '/routes.php';
$register($router);

// ---- 插件 + 鉴权 ----
// 主路由先注册，插件后注册；两边都受同一套 API Key 鉴权约束。
// 单个插件加载失败由 PluginManager 自行隔离，不会中断本次请求。
//
// 这段必须在 try 里：数据库抖动 / 插件清单损坏都会抛 Throwable，
// 落在 try 外面就是 PHP fatal（HTML 报错页），而不是统一 JSON。
$publicPaths = ['/api/v1/hook/inbound'];
try {
    \App\Core\Plugin\PluginManager::instance()->boot($router);

    if (!in_array($req->path, $publicPaths, true)) {
        Auth::authenticate($req);
    }
} catch (AuthException $e) {
    Logger::warning('鉴权失败: ' . $e->getMessage(), ['ip' => $req->ip(), 'path' => $req->path]);
    Response::unauthorized($e->getMessage());
    exit;
} catch (\Throwable $e) {
    Logger::error('鉴权/插件初始化异常: ' . $e->getMessage(), [
        'file'  => $e->getFile() . ':' . $e->getLine(),
        'path'  => $req->path,
    ]);
    Response::serverError('服务暂时不可用，请稍后重试');
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
