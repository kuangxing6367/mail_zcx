<?php

/**
 * Web Cron —— 无 CLI 权限环境下的队列驱动入口
 *
 * 适用：共享虚拟主机 / 容器 / 面板只能配「访问 URL」型计划任务，不能跑
 *       php cli/worker.php。把它填进面板计划任务（建议每 1 分钟）即可。
 *
 * 访问方式（二选一，token 必须是 config 里的 security.cron_token）：
 *   https://你的域名/zc-cron.php?token=xxxx
 *   curl -H "X-Cron-Token: xxxx" https://你的域名/zc-cron.php
 *
 * 设计取舍：
 *   - 单次只处理 mail.queue.web_cron_batch 封（默认 5），避免共享主机 30s 超时
 *   - 文件锁保证同一时刻只有一个 cron 在跑，避免多实例重复认领
 *   - 不打印任何内部路径/异常堆栈，失败只回 code + 摘要
 */

declare(strict_types=1);

use App\Core\Config;
use App\Service\WorkerRunner;

$root = dirname(__DIR__);

require $root . '/bootstrap/requirements.php';
zc_php_guard();

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function cron_json(?array $data, int $code = 0, string $msg = 'ok', int $httpCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    Config::load();
    date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));
} catch (\Throwable $e) {
    cron_json(null, 500, '配置加载失败', 500);
}

// ---- token 鉴权 ----
$expected = trim((string) Config::get('security.cron_token', ''));
if ($expected === '') {
    cron_json(null, 403, '未配置 security.cron_token，Web Cron 已禁用', 403);
}

$given = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? ''));
if ($given === '' || !hash_equals($expected, $given)) {
    cron_json(null, 403, 'token 无效', 403);
}

// 只允许 GET/POST，避免被当成普通接口随意打
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    cron_json(null, 405, 'method not allowed', 405);
}

// ---- 防止请求中断 / 超时 ----
@ignore_user_abort(true);
$limitSec = max(10, (int) Config::get('mail.queue.web_cron_time_limit', 25));
@set_time_limit($limitSec + 5);

// ---- 单实例锁 ----
$lockDir = Config::basePath('storage/cache');
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/cron.lock';
$fp = @fopen($lockFile, 'c');
if (!$fp) {
    cron_json(null, 500, '无法创建锁文件，请检查 storage/cache 写权限', 500);
}
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    cron_json(['skipped' => true], 0, '上一轮仍在执行，本次跳过');
}

$started = microtime(true);

try {
    // Web Cron 是第三种投递入口，插件事件必须在这里也生效
    \App\Core\Plugin\PluginManager::instance()->boot();

    $maxJobs = max(1, (int) Config::get('mail.queue.web_cron_batch', 5));
    $runner  = new WorkerRunner();
    $stat    = $runner->runOnce($maxJobs);

    // 顺手清掉过期的限流/鉴权失败缓存文件，避免共享主机上无限堆积
    foreach ((array) glob($lockDir . '/{rate_*,authfail_*}', GLOB_BRACE) as $file) {
        if (is_file($file) && @filemtime($file) < time() - 7200) {
            @unlink($file);
        }
    }

    $stat['backlog']    = $runner->queue()->backlogCount();
    $stat['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);

    flock($fp, LOCK_UN);
    fclose($fp);

    cron_json($stat);
} catch (\Throwable $e) {
    @error_log('[zc-cron] ' . $e->getMessage());
    flock($fp, LOCK_UN);
    fclose($fp);
    cron_json(null, 500, '执行失败，详见 storage/logs/app.log', 500);
}
