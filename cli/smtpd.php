<?php

/**
 * SMTP 入站服务 —— 让只会说 SMTP 的系统直接投递
 *
 * 定位：HTTP API 的补充入口，不是替代。收到的邮件走同一条流水线
 *       （选箱 → 落库 pending → 入队 → worker 投递），日志 / 统计 / Webhook 全部一致。
 *
 * 用法：
 *   常驻：  php cli/smtpd.php
 *   调试：  php cli/smtpd.php --verbose
 *   换端口：php cli/smtpd.php --port=2587
 *   单次：  php cli/smtpd.php --once          （只接受一个连接后退出，用于测试）
 *
 * 生产部署：
 *   1) 本进程只监听 127.0.0.1:2525，公网 25/587 由 nginx stream 转发
 *      配置见 deploy/nginx-smtp-stream.conf.example
 *   2) 用 supervisor / systemd 守护，见 deploy/supervisor-mail-smtpd.conf.example
 *   3) 必须同时跑 cli/worker.php，否则邮件停在 pending
 *
 * 支持的协议子集（够所有常见客户端用）：
 *   EHLO / HELO / AUTH PLAIN / AUTH LOGIN / STARTTLS / MAIL FROM / RCPT TO /
 *   DATA / RSET / NOOP / VRFY / QUIT
 *
 * Windows 本地调试：
 *   php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring cli/smtpd.php --verbose
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Mail\Mailer;
use App\Service\QueueService;

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

/**
 * 一个 SMTP 会话。
 *
 * 每个连接一个实例，状态全部实例内私有，不用全局变量，
 * 因此 fork 模式下父子进程互不干扰。
 */
final class SmtpSession
{
    /** @var resource */
    private $conn;
    private array $cfg;
    private string $peerIp;
    /** 直连方地址（accept 得到的），PROXY 头的可信判断只看它 */
    private string $directIp;
    private bool $verbose;

    private bool $secured       = false;
    private bool $authenticated = false;
    private string $helo        = '';
    /** null = 本封还没收到 MAIL FROM（空串是合法的空发件人，不能用来判断状态） */
    private ?string $mailFrom   = null;
    private array  $rcptTo      = [];

    public function __construct($conn, string $peerIp, array $cfg, bool $verbose)
    {
        $this->conn     = $conn;
        $this->peerIp   = $peerIp;
        $this->directIp = $peerIp;
        $this->cfg      = $cfg;
        $this->verbose  = $verbose;
    }

    public function serve(): void
    {
        $timeout = max(10, (int) ($this->cfg['idle_timeout'] ?? 120));
        @stream_set_timeout($this->conn, $timeout);

        // ---- PROXY protocol v1（nginx stream 转发时携带真实客户端 IP）----
        //
        // 关键点 1：走 nginx 时 PROXY 行是 nginx 立刻发来的，读它没问题；
        //           但客户端直连时会先等服务器 220 问候，直接 fgets 会互相等死。
        //           所以先短探测 0.5s：有数据且以 PROXY 开头才消费。
        // 关键点 2：**只信可信来源**。PROXY 行是纯文本，任何直连客户端都能自己发一行
        //           "PROXY TCP4 1.2.3.4 ..." 把源 IP 改成任意值，从而绕过 allow_ips。
        //           因此只有建连方（accept 得到的地址）命中 trusted_proxies 时才采信。
        $first = '';
        $trustedProxies = (array) ($this->cfg['trusted_proxies'] ?? ['127.0.0.1', '::1']);
        $proxyAllowed   = (bool) ($this->cfg['proxy_protocol'] ?? true)
            && $this->ipAllowed($this->directIp, $trustedProxies);

        if ($proxyAllowed) {
            $read = [$this->conn];
            $w = $e = null;
            $ready = @stream_select($read, $w, $e, 0, 500000);
            if ($ready > 0) {
                $first = (string) @fgets($this->conn, 1024);
                if (str_starts_with($first, 'PROXY ')) {
                    $parts = explode(' ', trim($first));
                    if (($parts[1] ?? '') !== 'UNKNOWN' && !empty($parts[2])) {
                        $this->peerIp = $parts[2];
                    }
                    $this->log('PROXY 客户端 IP: ' . $this->peerIp);
                    $first = '';
                }
            }
        }

        // 入站 IP 白名单（在 PROXY 解析之后判断，拿到的才是真实客户端 IP）
        $allowIps = (array) ($this->cfg['allow_ips'] ?? []);
        if ($allowIps && !$this->ipAllowed($this->peerIp, $allowIps)) {
            Logger::warning('SMTP 入站 IP 不在白名单', ['ip' => $this->peerIp]);
            $this->reply('554', '5.7.1 Access denied');
            return;
        }

        $this->reply('220', $this->hostname() . ' ESMTP ZC Mail');

        while (true) {
            $line = $first !== '' ? $first : @fgets($this->conn, 8192);
            $first = '';
            if ($line === false || $line === '') {
                break;
            }

            // 单行长度保护，防止无换行的垃圾流把内存撑爆
            if (strlen($line) >= 8191 && !str_ends_with($line, "\n")) {
                $this->reply('500', '5.5.2 Line too long');
                break;
            }

            $this->log('C: ' . rtrim($line, "\r\n"));

            $cmd = strtoupper(strtok(trim($line), ' '));
            if ($cmd === false || $cmd === '') {
                $this->reply('500', '5.5.2 Syntax error');
                continue;
            }

            switch ($cmd) {
                case 'EHLO':
                case 'HELO':
                    $this->helo = trim(substr(trim($line), 4));
                    $this->greeting($cmd === 'EHLO');
                    break;

                case 'AUTH':
                    $this->handleAuth($line);
                    break;

                case 'STARTTLS':
                    $this->handleStartTls();
                    break;

                case 'MAIL':
                    $this->handleMailFrom($line);
                    break;

                case 'RCPT':
                    $this->handleRcptTo($line);
                    break;

                case 'DATA':
                    $this->handleData();
                    break;

                case 'RSET':
                    $this->resetEnvelope();
                    $this->reply('250', '2.0.0 Ok');
                    break;

                case 'NOOP':
                    $this->reply('250', '2.0.0 Ok');
                    break;

                case 'VRFY':
                    $this->reply('252', '2.1.5 Cannot VRFY user');
                    break;

                case 'QUIT':
                    $this->reply('221', '2.0.0 Bye');
                    return;

                default:
                    $this->reply('500', '5.5.1 Command unrecognized');
            }
        }
    }

    // ------------------------------------------------------------------
    // 命令处理
    // ------------------------------------------------------------------

    private function greeting(bool $extended): void
    {
        if (!$extended) {
            $this->reply('250', $this->hostname());
            return;
        }

        $caps = [
            $this->hostname(),
            'PIPELINING',
            'SIZE ' . $this->maxBytes(),
            '8BITMIME',
            'ENHANCEDSTATUSCODES',
        ];
        if ((bool) ($this->cfg['auth_required'] ?? true) && !$this->authenticated) {
            $caps[] = 'AUTH PLAIN LOGIN';
        }
        if ($this->tlsEnabled() && !$this->secured) {
            $caps[] = 'STARTTLS';
        }
        $caps[] = 'HELP';

        $last = array_pop($caps);
        foreach ($caps as $cap) {
            $this->reply('250-' . $cap);
        }
        $this->reply('250', (string) $last);
    }

    private function handleAuth(string $line): void
    {
        if (\App\Core\Auth::lockedOut($this->peerIp)) {
            $this->log('AUTH 被拒：该 IP 失败次数已达阈值');
            $this->reply('421', '4.7.0 Too many authentication failures, try again later');
            return;
        }

        if ($this->secured === false && $this->tlsEnabled()) {
            // 有证书但客户端没先 STARTTLS：提示一下，不强制（内网老设备可能不支持）
            $this->log('警告：客户端在明文连接上直接 AUTH');
        }
        if ($this->authenticated) {
            $this->reply('503', '5.5.1 Already authenticated');
            return;
        }

        $rest  = trim(substr(trim($line), 4));
        $parts = preg_split('/\s+/', $rest, 2) ?: [];
        $mech  = strtoupper((string) ($parts[0] ?? ''));
        $arg   = trim((string) ($parts[1] ?? ''));

        [$user, $pass] = ['', ''];

        if ($mech === 'PLAIN') {
            if ($arg === '') {
                $this->reply('334 ');
                $arg = trim((string) @fgets($this->conn, 4096));
            }
            $decoded = base64_decode($arg, true);
            if ($decoded === false) {
                $this->reply('501', '5.5.2 Cannot decode AUTH response');
                return;
            }
            $parts = explode("\0", $decoded);
            // \0user\0pass
            $user = $parts[1] ?? '';
            $pass = $parts[2] ?? '';
        } elseif ($mech === 'LOGIN') {
            $this->reply('334', base64_encode('Username:'));
            $user = (string) base64_decode(trim((string) @fgets($this->conn, 4096)), true);
            $this->reply('334', base64_encode('Password:'));
            $pass = (string) base64_decode(trim((string) @fgets($this->conn, 4096)), true);
        } else {
            $this->reply('504', '5.5.4 Unsupported authentication mechanism');
            return;
        }

        if ($this->checkCredentials($user, $pass)) {
            $this->authenticated = true;
            $this->reply('235', '2.7.0 Authentication successful');
            $this->log("AUTH 成功 user={$user} ip={$this->peerIp}");
            return;
        }

        $this->reply('535', '5.7.8 Authentication failed');
        $this->log("AUTH 失败 user={$user} ip={$this->peerIp}");
    }

    private function handleStartTls(): void
    {
        if (!$this->tlsEnabled()) {
            $this->reply('454', '4.7.0 TLS not available');
            return;
        }
        if ($this->secured) {
            $this->reply('503', '5.5.1 TLS already active');
            return;
        }

        $this->reply('220', '2.0.0 Ready to start TLS');

        stream_context_set_option($this->conn, 'ssl', 'local_cert', (string) $this->cfg['tls_cert']);
        stream_context_set_option($this->conn, 'ssl', 'local_pk', (string) ($this->cfg['tls_key'] ?: $this->cfg['tls_cert']));
        stream_context_set_option($this->conn, 'ssl', 'verify_peer', false);
        stream_context_set_option($this->conn, 'ssl', 'allow_self_signed', true);

        $ok = @stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($ok !== true) {
            Logger::warning('SMTP STARTTLS 握手失败', ['ip' => $this->peerIp]);
            $this->reply('454', '4.7.0 TLS handshake failed');
            return;
        }

        $this->secured = true;
        $this->resetEnvelope();
        $this->authenticated = false;   // 按 RFC 3207，TLS 后必须重新 EHLO/AUTH
        $this->helo = '';
        $this->log('STARTTLS 已启用');
    }

    private function handleMailFrom(string $line): void
    {
        if (!$this->authenticated && (bool) ($this->cfg['auth_required'] ?? true)) {
            $this->reply('530', '5.7.0 Authentication required');
            return;
        }

        if (!preg_match('/^MAIL\s+FROM:\s*<([^>]*)>/i', $line, $m)) {
            $this->reply('501', '5.5.2 Syntax: MAIL FROM:<address>');
            return;
        }

        $from = trim($m[1]);
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $this->reply('501', '5.1.7 Bad sender address syntax');
            return;
        }

        $this->mailFrom = $from;
        $this->rcptTo   = [];
        $this->reply('250', '2.1.0 Ok');
    }

    private function handleRcptTo(string $line): void
    {
        if ($this->mailFrom === null) {
            $this->reply('503', '5.5.1 Need MAIL before RCPT');
            return;
        }
        if (!preg_match('/^RCPT\s+TO:\s*<([^>]*)>/i', $line, $m)) {
            $this->reply('501', '5.5.2 Syntax: RCPT TO:<address>');
            return;
        }

        $to = trim($m[1]);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->reply('501', '5.1.3 Bad recipient address syntax');
            return;
        }
        if (count($this->rcptTo) >= (int) ($this->cfg['max_recipients'] ?? 50)) {
            $this->reply('452', '4.5.3 Too many recipients');
            return;
        }

        $this->rcptTo[] = $to;
        $this->reply('250', '2.1.5 Ok');
    }

    private function handleData(): void
    {
        if (!$this->rcptTo) {
            $this->reply('503', '5.5.1 Need RCPT before DATA');
            return;
        }

        $this->reply('354', 'End data with <CR><LF>.<CR><LF>');

        $maxBytes = $this->maxBytes();
        $raw      = '';
        $tooBig   = false;

        while (true) {
            // 64KB 行缓冲：base64 附件段偶有超长行，8192 会截断并破坏 MIME 结构
            $chunk = @fgets($this->conn, 65536);
            if ($chunk === false) {
                $this->log('DATA 中途连接断开');
                return;
            }
            if ($chunk === ".\r\n" || $chunk === ".\n") {
                break;
            }
            // dot-stuffing：行首 ".." 还原为 "."
            if (str_starts_with($chunk, '..')) {
                $chunk = substr($chunk, 1);
            }
            if (!$tooBig) {
                $raw .= $chunk;
                if (strlen($raw) > $maxBytes) {
                    $tooBig = true;
                    $raw    = '';
                }
            }
        }

        if ($tooBig) {
            $this->reply('552', '5.3.4 Message size exceeds fixed limit');
            $this->resetEnvelope();
            return;
        }

        $this->deliver($raw);
    }

    // ------------------------------------------------------------------
    // 投递
    // ------------------------------------------------------------------

    private function deliver(string $raw): void
    {
        $context = [
            'source_ip'  => $this->peerIp,
            'user_agent' => 'SMTP/' . ($this->helo !== '' ? $this->helo : 'unknown'),
            'request_id' => 'smtp_' . bin2hex(random_bytes(8)),
        ];

        $envelope = [
            'from'    => (string) $this->mailFrom,
            'to'      => $this->rcptTo,
            'subject' => '',
        ];

        try {
            $mailer  = new Mailer();
            $payload = $mailer->prepareRaw($envelope, $raw, $context);

            if ((bool) Config::get('mail.queue.enabled', false)) {
                (new QueueService())->enqueue(
                    (int) $payload['log_id'],
                    $payload,
                    (int) Config::get('mail.queue.max_attempts', 3)
                );
                $this->reply('250', '2.0.0 Ok: queued as ' . $payload['log_id']);
                $this->log("已入队 log#{$payload['log_id']} to=" . implode(',', $this->rcptTo));
            } else {
                // 队列关闭：SMTP 语义要求同步给出最终结果，这里同步投递
                $result = $mailer->deliver($payload);
                $mailer->finalize($payload, $result);
                if ($result['status'] === 'success') {
                    $this->reply('250', '2.0.0 Ok: delivered as ' . $payload['log_id']);
                } else {
                    $this->reply('554', '5.0.0 Delivery failed: ' . mb_substr((string) $result['error'], 0, 200));
                }
            }
        } catch (\Throwable $e) {
            Logger::error('SMTP 入站处理失败: ' . $e->getMessage(), ['ip' => $this->peerIp]);
            $this->reply('451', '4.3.0 Temporary failure: ' . mb_substr($e->getMessage(), 0, 150));
        }

        $this->resetEnvelope();
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function checkCredentials(string $user, string $pass): bool
    {
        $expectedUser = trim((string) ($this->cfg['auth_username'] ?? ''));
        if ($expectedUser !== '' && !hash_equals($expectedUser, $user)) {
            return false;
        }

        // 允许任意用户名，密码必须是对应状态正常的 API Key（库中只存摘要，复用 Auth 的哈希比对）
        try {
            $row = \App\Core\Auth::verifyKeyOnly($pass, (string) $this->peerIp);
        } catch (\Throwable $e) {
            Logger::error('SMTP AUTH 校验失败: ' . $e->getMessage());
            return false;
        }

        if (!$row) {
            return false;
        }

        // Key 级 scope 不含 send 时，不允许通过 SMTP 发信
        $scopes = array_filter(array_map('trim', preg_split('/[,|\s]+/', (string) ($row['scopes'] ?? '*')) ?: []));
        if ($scopes && !in_array('*', $scopes, true) && !in_array('send', $scopes, true)) {
            Logger::warning('SMTP AUTH 被拒：Key 缺少 send 权限', ['key_id' => $row['id']]);
            return false;
        }

        return true;
    }

    private function resetEnvelope(): void
    {
        $this->mailFrom = null;
        $this->rcptTo   = [];
    }

    /** 支持精确 IP、192.168.1.* 通配与 10.0.0.0/8 CIDR */
    private function ipAllowed(string $ip, array $rules): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($rules as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if ($rule === '*') {
                return true;
            }
            if (str_contains($rule, '/')) {
                [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
                $ipLong     = ip2long($ip);
                $subnetLong = ip2long($subnet);
                $bits       = (int) $bits;
                if ($ipLong !== false && $subnetLong !== false && $bits >= 0 && $bits <= 32) {
                    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
                    if (($ipLong & $mask) === ($subnetLong & $mask)) {
                        return true;
                    }
                }
                continue;
            }
            if (str_contains($rule, '*')) {
                if (preg_match('#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#', $ip)) {
                    return true;
                }
            } elseif ($rule === $ip) {
                return true;
            }
        }
        return false;
    }

    /**
     * 发送一行响应。
     * 多行响应的中间行用 "250-XXXX" 直接作为 $code 传入，此时不能再补空格。
     */
    private function reply(string $code, string $text = ''): void
    {
        $line = $text === '' ? $code : $code . ' ' . $text;
        @fwrite($this->conn, $line . "\r\n");
        $this->log('S: ' . $line);
    }

    private function hostname(): string
    {
        $host = trim((string) ($this->cfg['hostname'] ?? ''));
        return $host !== '' ? $host : 'localhost';
    }

    private function maxBytes(): int
    {
        return max(1, (int) ($this->cfg['max_size_mb'] ?? 10)) * 1024 * 1024;
    }

    private function tlsEnabled(): bool
    {
        $cert = trim((string) ($this->cfg['tls_cert'] ?? ''));
        return $cert !== '' && is_file($cert);
    }

    private function log(string $msg): void
    {
        if ($this->verbose) {
            fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $this->peerIp . ' ' . $msg . PHP_EOL);
        }
    }
}

// ======================================================================
// 启动
// ======================================================================

$opts    = getopt('', ['once', 'verbose', 'port:', 'host:']);
$once    = isset($opts['once']);
$verbose = isset($opts['verbose']);

$required = ['pdo_mysql', 'openssl', 'mbstring'];
$missing  = array_values(array_filter($required, static fn($e) => !extension_loaded($e)));
if ($missing) {
    fwrite(STDERR, '[错误] 缺少 PHP 扩展: ' . implode(', ', $missing) . PHP_EOL
        . '  Windows: php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring cli/smtpd.php' . PHP_EOL);
    exit(1);
}

Config::load();

// SMTP 入站走 prepareRaw()，同样要触发 mail.after_prepare 等扩展点。
// 父进程加载一次即可，fork 出的子进程继承同一份监听器。
try {
    \App\Core\Plugin\PluginManager::instance()->boot();
} catch (\Throwable $e) {
    fwrite(STDERR, '[警告] 插件加载失败，已跳过: ' . $e->getMessage() . PHP_EOL);
}

$cfg = (array) Config::get('smtp_in', []);
if (!(bool) ($cfg['enabled'] ?? false)) {
    fwrite(STDERR, '[错误] smtp_in.enabled 为 false。' . PHP_EOL
        . '  请在 config.local.php 中开启：return [\'smtp_in\' => [\'enabled\' => true]];' . PHP_EOL);
    exit(1);
}

$host = (string) ($opts['host'] ?? $cfg['listen'] ?? '127.0.0.1');
$port = (int) ($opts['port'] ?? $cfg['port'] ?? 2525);
$maxConn = max(1, (int) ($cfg['max_connections'] ?? 20));

$errno = 0;
$errstr = '';
$server = @stream_socket_server(
    "tcp://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if (!$server) {
    fwrite(STDERR, "[错误] 无法监听 {$host}:{$port} — {$errstr} ({$errno})" . PHP_EOL
        . '  常见原因：端口被占用；或 <1024 端口需要 root（生产应由 nginx 转发，不要直接监听 25）' . PHP_EOL);
    exit(1);
}

$smtpOut = "SMTP 入站已启动 tcp://{$host}:{$port} hostname=" . ($cfg['hostname'] ?? 'localhost')
    . ' auth=' . (($cfg['auth_required'] ?? true) ? 'required' : 'open')
    . ' tls=' . (trim((string) ($cfg['tls_cert'] ?? '')) !== '' ? 'on' : 'off')
    . ' fork=' . (function_exists('pcntl_fork') ? 'yes' : 'no(串行)') . PHP_EOL;
fwrite(STDOUT, $smtpOut);

$useFork = function_exists('pcntl_fork') && !$once;
$active  = 0;

if ($useFork) {
    // 子进程退出即回收，异步信号保证 accept 阻塞时也能及时减计数
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, static function () use (&$active): void {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            $active = max(0, $active - 1);
        }
    });
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }

    $peerName = (string) @stream_socket_get_name($conn, true);
    $peerIp   = str_contains($peerName, ':') ? substr($peerName, 0, (int) strrpos($peerName, ':')) : $peerName;

    // 并发上限：满了直接 421，让客户端稍后重试（比直接丢弃礼貌，客户端会退避）
    if ($active >= $maxConn) {
        @fwrite($conn, "421 4.7.0 Too many connections, try again later\r\n");
        @fclose($conn);
        Logger::warning('SMTP 入站并发超限，拒绝连接', ['ip' => $peerIp, 'active' => $active]);
        continue;
    }

    if ($useFork) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            // fork 失败：降级为串行处理本连接
            $active++;
            (new SmtpSession($conn, $peerIp, $cfg, $verbose))->serve();
            @fclose($conn);
            $active--;
            continue;
        }
        if ($pid === 0) {
            // 子进程：关闭监听 socket，只守自己这条连接
            @fclose($server);
            try {
                (new SmtpSession($conn, $peerIp, $cfg, $verbose))->serve();
            } catch (\Throwable $e) {
                Logger::error('SMTP 会话异常: ' . $e->getMessage(), ['ip' => $peerIp]);
            }
            @fclose($conn);
            exit(0);
        }

        // 父进程：关掉连接句柄，回收已退出的子进程（SIGCHLD 也会做，这里兜底）
        @fclose($conn);
        $active++;
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            $active = max(0, $active - 1);
        }
    } else {
        $active++;
        (new SmtpSession($conn, $peerIp, $cfg, $verbose))->serve();
        @fclose($conn);
        $active--;

        if ($once) {
            fwrite(STDOUT, "单次模式结束" . PHP_EOL);
            break;
        }
    }
}

@fclose($server);
