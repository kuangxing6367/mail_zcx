<?php
/**
 * 环境专属配置 —— 仅存在于服务器，不提交版本库
 * 自动覆盖 config/config.php 中同名项
 */

// 根密钥：只从环境变量读取，不再从 /tmp 等共享目录读文件
// （同机其他用户可预置 /tmp 文件 → 直接控制加密密钥）
// 生成：openssl rand -hex 32
$secret = (string) (getenv('ZC_APP_SECRET') ?: '__APP_SECRET__');

return [
    'app' => [
        'debug'    => false,
        'timezone' => 'Asia/Shanghai',
        'base_path'=> '',
    ],
    // 占位示例：填入宝塔/MySQL 实际凭据，不要把真实密码提交到公开仓库
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'your_database',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset'  => 'utf8mb4',
        'prefix'   => 'zc_',
    ],
    'security' => [
        'app_secret'      => $secret,
        'require_api_key' => true,
        'allow_ips'       => [],
        // 只有 REMOTE_ADDR 命中此列表时才采信 X-Forwarded-For。套 CDN/反代时填其回源地址
        'trusted_proxies' => ['127.0.0.1', '::1'],
        'auth_fail_limit' => 10,     // 窗口内失败次数上限
        'auth_fail_window'=> 600,    // 窗口秒数
        // 'api_entry'    => '',     // 秘密入口前缀，详见 README「秘密入口」
        // 'inbound_secret' => '',   // 入站 Webhook 签名密钥；留空 = /hook/inbound 关闭
        // 'cors_origins'   => 'https://console.example.com',
        // ---- PHP 运行基线（默认强制，不满足直接拒绝服务）----
        // 2026-10-21 PHP 官方修复两个未认证 RCE（CVE-2026-109618 / 109619，CVSS 9.2），
        // 官方只给 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 发安装包，8.1 及更早已 EOL。
        // 但宝塔 Linux 面板把官方源码补丁回移打包了 8.1.35 / 8.0.31 / 7.4.34 等修复版，
        // 版本号本身不等于「有漏洞」。确认你装的确实是修复版后，再打开这个开关：
        // 'ignore_php_requirement' => true,
        // ⚠ 开关只跳过安全门槛；本项目用 PHP 8.1 的 readonly 语法，7.x / 8.0 仍跑不起来。
        // ⚠ Windows 面板的 8.1 及更早没有补丁，只能临时缓解，不要打开。
        // ---- 没有 SSH / CLI 的虚拟主机（梦奈主机这类面板）需要这两项 ----
        // 网页安装器口令（public/zc-install.php）。装完就删掉该文件。
        // 'setup_token'  => 'openssl rand -hex 16 生成',
        // Web Cron 口令（public/zc-cron.php?token=...），给「访问 URL」型计划任务用
        // 'cron_token'   => 'openssl rand -hex 16 生成',
    ],
    'mail' => [
        'selection'     => 'least_sent',
        'timeout'       => 15,   // 单步 SMTP 超时
        'total_timeout' => 30,   // 整条会话总时限，0 = 不限
        'smtp_verify_tls' => true, // 自签名 SMTP 才设 false
        // 附件走服务端路径（默认关闭）。开启后必须限定目录，否则可外发任意文件
        // 'allow_path_attachment' => true,
        // 'attachment_roots'      => ['/www/wwwroot/mail/storage/uploads'],
        // ---- 队列 ----
        // 这里默认「同步发信」：请求内直接完成投递、返回真实结果，不依赖任何后台进程。
        // 虚拟主机 / 无 SSH 的环境只能跑这个模式，也是项目最早期的行为。
        //
        // 想用异步队列（接口立即返回 202，后台投递 + 失败重试）：
        //   ① 把 enabled 改成 true
        //   ② 确保 worker 在跑：supervisor 常驻，或宝塔计划任务每分钟
        //      `php cli/worker.php --once`，或配 URL 型任务访问 /zc-cron.php?token=xxx
        // 也可以在控制台「系统设置 → 发信与队列」里直接切换（不用改这个文件）。
        'queue' => [
            'enabled'        => false,
            'web_cron_batch' => 5,   // Web Cron 单次最多投递几封
        ],
    ],
    // SMTP 入站：让只会说 SMTP 的老系统直接投递，不需要改成 HTTP 调用
    // 开启前必须：① nginx stream 把 25/587 转到 127.0.0.1:2525  ② 常驻跑 cli/worker.php
    // 详见文档 /smtp-in
    'smtp_in' => [
        'enabled'  => false,             // 不用就保持 false
        'hostname' => 'mail.example.com',// EHLO 里宣告的域名
        'port'     => 2525,
        // 走 nginx 转发时置 true 才能拿到真实客户端 IP；
        // 直连（本机 telnet 调试）时置 false，否则会多等 0.5 秒探测
        'proxy_protocol' => true,
        // 'auth_username' => 'apikey',  // 留空 = 任意用户名，密码必须是有效 API Key
        // 'allow_ips'     => ['192.168.1.*'],
        // 'tls_cert'      => '/path/fullchain.pem',  // 填了才宣告 STARTTLS
        // 'tls_key'       => '/path/privkey.pem',
    ],
    'webhook' => [
        'async'          => true,   // FPM 下响应后投递（仍占 worker）
        'async_fallback' => 'sync', // 非 FPM 环境：sync（显式同步）| skip（丢弃回调）
        'timeout'        => 5,
        'retry'          => 2,
        'verify_tls'     => true,   // 出站回调必须校验证书
        'follow_redirect'=> false,  // 跟随跳转会被用作 SSRF 跳板
        // 'allow_private_hosts' => true, // 仅当回调接收端在内网时开启
    ],
    'log' => [
        'level' => 'info',
    ],
];
