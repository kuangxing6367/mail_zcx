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
    ],

    'mail' => [
        'default_from_name'  => 'ZC Mail',
        'charset'            => 'UTF-8',
        'timeout'            => 15,           // SMTP 连接/读写超时（秒）
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
    ],

    'log' => [
        'file'  => __DIR__ . '/../storage/logs/app.log',
        'level' => 'debug',                   // debug | info | warning | error
    ],
];
