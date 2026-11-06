<?php

/**
 * 网页安装器 —— 给没有 SSH / CLI 权限的虚拟主机用
 *
 * 使用前必须在 config/config.local.php 里设置：
 *   'security' => ['setup_token' => '一串随机字符']
 *
 * 然后浏览器打开：https://你的域名/zc-install.php
 * 安装成功后本文件自动失效（写入 storage/installed.lock），建议再从面板删除它。
 *
 * 安全边界：
 *   - 必须带 token 才能执行；未配置 token 时整个入口禁用
 *   - 不提供「填数据库账号」的表单，凭据只从 config.local.php 读取，
 *     避免安装器变成「谁打开谁改库」的入口
 *   - 不显示 SQL 报错堆栈中的服务器路径，只显示摘要
 */

declare(strict_types=1);

use App\Core\Config;
use App\Service\InstallerService;

$root = dirname(__DIR__);

require $root . '/bootstrap/requirements.php';

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

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render(string $title, string $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title><style>'
        . ':root{color-scheme:dark}*{box-sizing:border-box}'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#0b0d12;color:#e6e8ef;font:14px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px}'
        . '.card{width:100%;max-width:720px;background:#12151d;border:1px solid #232838;border-radius:14px;padding:28px 30px}'
        . 'h1{font-size:19px;margin:0 0 6px;letter-spacing:.2px}'
        . 'p.sub{margin:0 0 20px;color:#8b93a7;font-size:13px}'
        . 'code,kbd{background:#0b0d12;border:1px solid #232838;border-radius:6px;padding:2px 7px;font-size:12.5px;color:#a8b4ff}'
        . 'pre{background:#0b0d12;border:1px solid #232838;border-radius:10px;padding:14px;overflow:auto;font-size:12.5px}'
        . '.ok{color:#4ade80}.bad{color:#f87171}.warn{color:#fbbf24}'
        . 'ul{margin:8px 0 18px;padding-left:20px}li{margin:4px 0}'
        . 'button{background:#6366f1;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:14px;cursor:pointer}'
        . 'button:hover{background:#5457e5}'
        . '.key{background:#0b0d12;border:1px dashed #6366f1;border-radius:10px;padding:14px;'
        . 'font-family:ui-monospace,Consolas,monospace;word-break:break-all;color:#c7d2fe;font-size:13px}'
        . '.hint{color:#6b7385;font-size:12.5px;margin-top:18px}'
        . '</style></head><body><div class="card">' . $body . '</div></body></html>';
    exit;
}

Config::load();

$token = trim((string) Config::get('security.setup_token', ''));

// ---- 已安装：直接关死 ----
if (InstallerService::isInstalled()) {
    render('安装器已失效', '<h1 class="ok">已安装</h1>'
        . '<p class="sub">storage/installed.lock 已存在，安装入口已关闭。</p>'
        . '<p>如需重装/升级，请从文件管理器删除 <code>storage/installed.lock</code> 后再访问本页。</p>'
        . '<p class="hint">强烈建议安装完成后直接删除 <code>public/zc-install.php</code>。</p>', 403);
}

if ($token === '') {
    render('安装器未启用', '<h1 class="warn">安装器未启用</h1>'
        . '<p class="sub">需要先在配置里设置一次性安装口令。</p>'
        . '<p>在 <code>config/config.local.php</code> 中加入：</p>'
        . '<pre>return [
    \'security\' => [
        \'setup_token\' => \'换成你自己的随机字符串\',
    ],
];</pre>'
        . '<p class="hint">也可以用命令行生成：<code>openssl rand -hex 16</code></p>', 403);
}

$missing    = InstallerService::missingExtensions();
$phpInfo    = zc_php_requirement();
$phpIgnored = zc_php_ignore();
$phpOk      = $phpIgnored || in_array($phpInfo['level'], ['ok', 'unknown'], true);
$given      = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
$doRun   = isset($_POST['do']);
$localCfg = is_file(Config::basePath('config/config.local.php'));

// ---- 未提交：显示环境检查 + 表单 ----
if (!$doRun) {
    $items = '';
    if ($phpOk) {
        $phpState = $phpIgnored
            ? ' <span class="warn">基线检查已被配置忽略（请确认补丁已装）</span>'
            : ($phpInfo['level'] === 'unknown'
                ? ' <span class="warn">未核实分支：' . h($phpInfo['reason']) . '</span>'
                : ' <span class="ok">✓ 运行基线通过</span>');
        $items .= '<li>PHP 版本：' . h(PHP_VERSION) . $phpState . '</li>';
    } else {
        $items .= '<li>PHP 版本：' . h(PHP_VERSION) . ' <span class="bad">✗ ' . h($phpInfo['reason']) . '</span>'
            . '<div style="color:#8b93a7;font-size:12.5px;margin:4px 0 8px">'
            . h($phpInfo['hint']) . ' 确认已打补丁后，可在 <code>config/config.local.php</code> 设 '
            . '<code>\'security\' =&gt; [\'ignore_php_requirement\' =&gt; true]</code>。'
            . '</div></li>';
    }
    $items .= '<li>扩展 pdo_mysql / openssl / mbstring / curl：'
        . ($missing ? '<span class="bad">缺少 ' . h(implode(', ', $missing)) . '</span>' : '<span class="ok">齐全 ✓</span>') . '</li>';
    $items .= '<li>数据库连接配置：' . (((string) Config::get('db.database', '')) !== ''
        ? '<span class="ok">已填写</span>（库名不明文回显）'
        : '<span class="bad">db.database 为空</span>') . '</li>';
    $items .= '<li>凭据文件 config.local.php：' . ($localCfg ? '<span class="ok">已存在 ✓</span>' : '<span class="warn">不存在（正在使用 config.php 里的默认值）</span>') . '</li>';
    $items .= '<li>storage 目录可写：' . (is_writable(Config::basePath('storage')) ? '<span class="ok">可写 ✓</span>' : '<span class="bad">不可写，请给 storage 及子目录 755/写权限</span>') . '</li>';

    render('ZC Mail 安装', '<h1>ZC Mail Service · 网页安装</h1>'
        . '<p class="sub">创建数据表并做增量迁移（幂等，可重复执行）。</p>'
        . '<ul>' . $items . '</ul>'
        . ($phpOk && !$missing
            ? '<form method="post" action="zc-install.php">'
              . '<p>输入安装口令：</p>'
              . '<p><input name="token" type="password" autocomplete="off" '
              . 'style="width:100%;background:#0b0d12;border:1px solid #232838;border-radius:8px;padding:10px;color:#e6e8ef">'
              . '</p><p style="margin-top:16px"><button type="submit" name="do" value="1">开始安装</button></p>'
              . '</form>'
            : '<p class="bad">环境不满足，先把上面标红的项处理好。</p>')
        . '<p class="hint">安装完成后本页会自动失效，请再删除 public/zc-install.php，并继续按文档配置伪静态与计划任务。</p>');
}

// ---- 提交：环境基线复核（防止绕过表单直接 POST）----
if (!$phpOk) {
    render('环境不满足', '<h1 class="bad">PHP 运行基线不满足，已拒绝安装</h1>'
        . '<p class="sub">' . h($phpInfo['reason']) . '</p>'
        . '<p>' . h($phpInfo['hint']) . '</p>'
        . '<p class="hint">若发行版/面板已回补 CVE-2026-109618 / CVE-2026-109619 补丁，'
        . '在 config/config.local.php 设 <code>\'security\' =&gt; [\'ignore_php_requirement\' =&gt; true]</code> 后重试。</p>', 503);
}

// ---- 提交：校验 token ----
if ($given === '' || !hash_equals($token, $given)) {
    render('口令错误', '<h1 class="bad">安装口令错误</h1>'
        . '<p class="sub">请返回上一页重试。</p>', 403);
}

try {
    $report = (new InstallerService())->run();
    InstallerService::markInstalled($report);
} catch (\Throwable $e) {
    render('安装失败', '<h1 class="bad">安装失败</h1>'
        . '<p class="sub">' . h($e->getMessage()) . '</p>'
        . '<p class="hint">常见原因：库名/账号密码不对、账号无建表权限、PDO 扩展未开。'
        . '修好后刷新本页重试。</p>', 500);
}

$tables = '';
foreach ($report['tables'] as $t) {
    $tables .= '<li><code>' . h((string) $t) . '</code></li>';
}

$notes = '';
foreach ($report['notes'] as $n) {
    $notes .= '<li class="ok">' . h((string) $n) . '</li>';
}
$warnings = '';
foreach ($report['warnings'] as $w) {
    $warnings .= '<li class="warn">' . h((string) $w) . '</li>';
}

$keyBlock = $report['seed_key']
    ? '<p>默认 API Key（<b class="warn">只显示这一次</b>，请立即保存，随后到控制台轮换）：</p>'
      . '<div class="key">' . h((string) $report['seed_key']) . '</div>'
    : '<p class="warn">默认 Key 已哈希入库（明文不在了）。如已遗失，请重置 api_keys 表的 api_key_hash 或轮换。</p>';

render('安装完成', '<h1 class="ok">安装完成</h1>'
    . '<p class="sub">数据库 <code>' . h($report['database']) . '</code> · 执行 ' . (int) $report['statements'] . ' 条语句 · '
    . count($report['tables']) . ' 张表</p>'
    . '<ul>' . $tables . '</ul>'
    . ($notes ? '<ul>' . $notes . '</ul>' : '')
    . ($warnings ? '<ul>' . $warnings . '</ul>' : '')
    . $keyBlock
    . '<p style="margin-top:22px">下一步：</p>'
    . '<ul>'
    . '<li>打开 <a style="color:#a8b4ff" href="zc-console/">/zc-console/</a>，粘贴上面的 Key 进入控制台</li>'
    . '<li>加一个发件邮箱（SMTP 主机/授权码），点「测试」确认能连通</li>'
    . '<li>配伪静态（Apache 用 .htaccess，nginx 用 try_files），否则 API 会 404</li>'
    . '<li>队列模式需配计划任务访问 <code>zc-cron.php?token=...</code>，或关掉队列走同步发送</li>'
    . '</ul>'
    . '<p class="hint">现在就可以删除 public/zc-install.php 了。');
