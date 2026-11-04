<?php
/**
 * ZC Mail Service - 全局配置
 *
 * 部署时只需改这个文件。生产环境请务必修改 app_secret 与 db 密码。
 *
 * 安全实践：本文件可安全开源，真实凭据请写在同目录的 config.local.php，
 *          它会自动覆盖本文件对应项，且不应提交到版本库。
 */

return [
    'app' => [
        'name'     => 'ZC Mail Service',
        'version'  => '1.0.0',
        'debug'    => false,                // 本地调试时在 config.local.php 中改为 true
        'timezone' => 'Asia/Shanghai',
        'base_path'=> '',                   // 若部署在子目录（如 /mail）则填 '/mail'
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'zc_mail',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
        'prefix'   => 'zc_',                // 表前缀
    ],

    'security' => [
        // 用于 SMTP 密码对称加密 & Webhook 签名派生，务必改成随机长字符串
        'app_secret'      => 'change-me-to-a-random-64-char-string',
        // 是否强制 API Key 鉴权（生产必须 true）
        'require_api_key' => true,
        // 全局 IP 白名单，空数组表示不限制；支持 192.168.1.* 通配
        'allow_ips'       => [],
        // 全局每分钟请求上限，0 = 不限
        'rate_limit'      => 0,
        // 时间戳容差（秒），用于可选的时间戳签名校验
        'timestamp_window'=> 300,
        // 秘密入口前缀：非空时 API 挂载点变为 /<前缀>/api/v1，直接访问 /api/v1 返回 404
        // 例：设为 'x9m2kq' 后，真实接口为 https://域名/x9m2kq/api/v1/...；控制台需同步设置 API_ENTRY
        'api_entry'       => '',
    ],

    'mail' => [
        'default_from_name'  => 'ZC Mail',
        'charset'            => 'UTF-8',
        'timeout'            => 15,           // 单步 SMTP 连接/读写超时（秒）
        // 单次发信会话的整体时限（秒），0 = 不限。
        // SMTP 一次投递含 connect/EHLO/STARTTLS/AUTH/MAIL/RCPT/DATA/QUIT 多步，
        // 若每步都可能等 15s，最坏会把请求挂到 90s+；这里给整条会话设总闸。
        'total_timeout'      => 30,
        'selection'          => 'least_sent', // 选箱策略: least_sent | round_robin | random | weight
        'max_recipients'     => 100,          // 单封邮件最大收件人总数
        'max_attachment_mb'  => 10,           // 单封邮件附件总大小上限(MB)
        'smtp_keepalive'     => false,
        'log_payload'        => true,         // 是否把原始请求体落库
    ],

    'webhook' => [
        'timeout'     => 5,                   // 出站回调超时（秒）
        'retry'       => 2,                   // 失败重试次数
        'sign_header' => 'X-ZC-Signature',    // 签名头名称
        'user_agent'  => 'ZC-Mail-Service/1.0',
        'async'       => true,                // true: 响应后投递(fastcgi_finish_request)
        // 非 FPM 环境（php -S / mod_php / CGI）没有 fastcgi_finish_request，
        // shutdown function 会在响应发送前执行，注册它等于同步阻塞。
        // sync = 显式同步回调并记 warning（保证回调不丢）；skip = 直接丢弃回调。
        'async_fallback' => 'sync',           // sync | skip
    ],

    'log' => [
        'file'  => __DIR__ . '/../storage/logs/app.log',
        'level' => 'debug',                   // debug | info | warning | error
    ],
];
