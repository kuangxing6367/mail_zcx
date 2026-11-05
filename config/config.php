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

        // ---- 异步发送队列（根治同步阻塞）----
        // enabled=true:  /api/v1/send 立即返回 202，发信由 cli/worker.php 后台执行
        //   ⚠ 必须常驻运行 worker，否则邮件会堆积在 pending 状态
        // enabled=false: 走原同步阻塞逻辑（单请求内完成 SMTP 投递，向后兼容）
        'queue' => [
            'enabled'       => true,          // true=队列异步 / false=同步
            'max_attempts'  => 3,             // 单封最多投递次数（含首次）
            'batch'         => 10,            // worker 单次认领任务数
            'idle_sleep'    => 2,             // 队列空时 worker 休眠秒数
            'stuck_timeout' => 300,           // processing 超过此秒数视为 worker 崩溃，自动回收
            'retry_base'    => 30,            // 重试退避基数（秒）：第 n 次重试等待 retry_base * 2^(n-1)
        ],
    ],

    // ---- SMTP 入站（标准 SMTP 协议入口）----
    // 让只会说 SMTP 的老系统（打印机 / NAS / 老业务代码 / 监控告警）直接投递，
    // 不需要改成 HTTP 调用。进程是 cli/smtpd.php，通常由 nginx stream 把 25/587 转进来。
    //
    // 注意：这里解决的是「入站 25」，与云厂商封禁「出站 25」是两件事，
    //       出站投递默认走 465/587，不受 25 封禁影响。详见文档 docs/smtp-in.md
    'smtp_in' => [
        'enabled'         => false,           // 总开关，false 时进程拒绝启动
        'listen'          => '127.0.0.1',     // 只监听本地，公网由 nginx 转发
        'port'            => 2525,            // 内部端口，nginx 把 25/587 转到这里
        'hostname'        => 'localhost',     // EHLO 里宣告的域名，建议填真实发信域名
        'max_size_mb'     => 10,              // 单封信上限（作业表是 MEDIUMTEXT，16MB 封顶）
        'max_recipients'  => 50,              // 单封最大收件人数
        'idle_timeout'    => 120,             // 空闲断开秒数
        'max_connections' => 20,              // 瞬时并发会话上限（超了直接 421）
        'auth_required'   => true,            // 是否要求 AUTH（生产必须 true）
        // 留空 = 接受任意用户名，密码必须是有效的 API Key；
        // 填了则要求用户名与此完全一致（密码仍是 API Key）
        'auth_username'   => '',
        'allow_ips'       => [],              // 入站 IP 白名单，空 = 不限制；支持 192.168.1.*
        // nginx stream 开了 proxy_protocol on 时置 true，才能拿到真实客户端 IP
        'proxy_protocol'  => true,
        // 重写头部 From 为本服务选中的发件邮箱。QQ/163 等会校验发件人一致性，
        // 不重写基本必被拒（554/550）；原发件人会写进 Reply-To 保留回信线索
        'rewrite_from'    => true,
        'reply_to_original' => true,
        // STARTTLS（可选）。填了证书才在 EHLO 里宣告 STARTTLS。
        // 证书需与 hostname 匹配，且 PHP 进程要有读权限
        'tls_cert'        => '',
        'tls_key'         => '',
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
