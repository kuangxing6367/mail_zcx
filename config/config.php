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
        // /health 是否返回 PHP 版本等细节；生产建议 false，减少指纹暴露
        'health_detail' => false,
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
        // 全局 IP 白名单，空数组表示不限制；支持 192.168.1.* 通配与 10.0.0.0/8 CIDR
        'allow_ips'       => [],
        // 只有直连方（REMOTE_ADDR）命中此列表时才采信 X-Forwarded-For / CF-Connecting-IP。
        // 反代或 CDN 在本地时保持默认；直连公网部署时不要放开，否则 IP 白名单可被伪造。
        'trusted_proxies' => ['127.0.0.1', '::1'],
        // 全局每分钟请求上限，0 = 不限
        'rate_limit'      => 0,
        // 鉴权失败锁定：窗口内失败达到 limit 次后，该 IP 在窗口内一律返回「API Key 无效」
        'auth_fail_limit' => 10,
        'auth_fail_window'=> 600,
        // 时间戳容差（秒），保留字段，当前鉴权链路未使用
        'timestamp_window'=> 300,
        // 入站 Webhook（/api/v1/hook/inbound）签名密钥；留空 = 该接口禁用（返回 403）
        'inbound_secret'  => '',
        // CORS 允许的来源：'*' 表示任意；生产可填具体域名（逗号分隔）
        'cors_origins'    => '*',
        // 秘密入口前缀：非空时 API 挂载点变为 /<前缀>/api/v1，直接访问 /api/v1 返回 404
        // 例：设为 'x9m2kq' 后，真实接口为 https://域名/x9m2kq/api/v1/...；控制台需同步设置 API_ENTRY
        'api_entry'       => '',
        // Web Cron 令牌：无 CLI 的虚拟主机靠「访问 URL」型计划任务驱动队列（public/zc-cron.php）。
        // 留空 = Web Cron 与网页安装器均禁用。建议 openssl rand -hex 16
        'cron_token'      => '',
        // 网页安装器令牌（public/zc-install.php），安装完成后入口自动失效
        'setup_token'     => '',
    ],

    'mail' => [
        'default_from_name'  => 'ZC Mail',
        'charset'            => 'UTF-8',
        'timeout'            => 15,           // 单步 SMTP 连接/读写超时（秒）
        // 单次发信会话的整体时限（秒），0 = 不限。
        // SMTP 一次投递含 connect/EHLO/STARTTLS/AUTH/MAIL/RCPT/DATA/QUIT 多步，
        // 若每步都可能等 15s，最坏会把请求挂到 90s+；这里给整条会话设总闸。
        'total_timeout'      => 30,
        // SMTP 出站是否校验证书。自建/自签名服务器可设 false，公网邮箱必须保持 true
        'smtp_verify_tls'    => true,
        'selection'          => 'least_sent', // 选箱策略: least_sent | round_robin | random | weight
        'max_recipients'     => 100,          // 单封邮件最大收件人总数
        'max_attachment_mb'  => 10,           // 单封邮件附件总大小上限(MB)
        'smtp_keepalive'     => false,        // 保留字段，当前未使用
        'log_payload'        => true,         // 是否把原始请求体落库
        // 附件路径模式（attachments[].path）：默认关闭。开启后只允许读取下列目录内的文件，
        // 否则会把服务器任意文件（含 config/config.local.php）当附件发出去。
        'allow_path_attachment' => false,
        'attachment_roots'      => [],       // 例：['/www/wwwroot/mail/storage/uploads']

        // ---- 异步发送队列（根治同步阻塞）----
        // enabled=true:  /api/v1/send 立即返回 202，发信由 cli/worker.php 后台执行
        //   ⚠ 必须常驻运行 worker，否则邮件会堆积在 pending 状态
        // enabled=false: 走原同步阻塞逻辑（单请求内完成 SMTP 投递，向后兼容）
        //
        // 注意：这里的 true 只是「代码内建默认」。实际部署用的
        // config/config.local.example.php 里是显式 false（同步），因为虚拟主机占多数；
        // 宝塔/VPS 这条线要异步，就在 config.local.php 里改成 true，或在控制台
        // 「系统设置 → 发信与队列」里切换（写 config.overrides.php，优先级更高）。
        'queue' => [
            'enabled'       => true,          // true=队列异步 / false=同步
            'max_attempts'  => 3,             // 单封最多投递次数（含首次）
            'batch'         => 10,            // worker 单次认领任务数
            'idle_sleep'    => 2,             // 队列空时 worker 休眠秒数
            'stuck_timeout' => 300,           // processing 超过此秒数视为 worker 崩溃，自动回收
            'retry_base'    => 30,            // 重试退避基数（秒）：第 n 次重试等待 retry_base * 2^(n-1)
            // ---- 无 CLI 环境（虚拟主机）----
            // Web Cron（public/zc-cron.php）单次处理的邮件数：共享主机通常 30s 超时，
            // 按单封 1~3s 估算，5 封足够安全；调大可处理更多，但别超过脚本时限。
            'web_cron_batch'      => 5,
            'web_cron_time_limit' => 25,      // Web Cron 目标执行时限（秒）
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
        // 出站回调 TLS 证书校验（生产必须 true）
        'verify_tls'      => true,
        // 是否跟随 3xx 跳转。跟随会成为 SSRF 绕过的跳板，默认关闭
        'follow_redirect' => false,
        // 是否允许回调到回环/私网/链路本地地址。内网自建接收端才需要开启
        'allow_private_hosts' => false,
    ],

    'log' => [
        'file'  => __DIR__ . '/../storage/logs/app.log',
        'level' => 'debug',                   // debug | info | warning | error
        // 是否允许 /logs/{id}?include_payload=1 回显原始请求体；默认 false
        'return_payload' => false,
    ],
];
