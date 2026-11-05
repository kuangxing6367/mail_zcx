<?php

namespace App\Mail;

use App\Core\Config;
use RuntimeException;

/**
 * 原生 SMTP 客户端（零依赖）
 *
 * 支持三种连接方式：
 *   ssl   —— 465 端口，建立连接时即完成 TLS 握手（隐式 SSL）
 *   tls   —— 587 端口，先明文 EHLO，再 STARTTLS 升级为 TLS（显式 TLS）
 *   none  —— 25 端口，明文（仅内网/中继使用）
 *
 * 支持 AUTH PLAIN / AUTH LOGIN，自动按服务器声明的机制选择并回退。
 */
class SmtpClient
{
    /** @var resource|null */
    private $socket = null;

    private string $host;
    private int $port;
    private string $secure;
    private int $timeout;
    /** 整条 SMTP 会话的总时限（秒），0 表示不限制 */
    private int $budget;
    private float $deadline = 0.0;
    private string $ehloHost;

    /** @var string[] 会话日志（密码已脱敏） */
    private array $sessionLog = [];

    public string $lastResponse = '';
    public string $lastCode = '';
    private array $capabilities = [];

    public function __construct(string $host, int $port, string $secure = 'ssl', int $timeout = 15, int $budget = 0)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->secure   = strtolower($secure) ?: 'ssl';
        $this->timeout  = $timeout > 0 ? $timeout : 15;
        $this->budget   = max(0, $budget);
        $this->ehloHost = $this->buildEhloHost();
    }

    /**
     * 剩余可用秒数。单步超时与会话总时限取较小值，避免多步叠加把请求挂死。
     */
    private function remainingTimeout(): int
    {
        if ($this->deadline <= 0.0) {
            return $this->timeout;
        }
        $left = (int) ceil($this->deadline - microtime(true));
        if ($left <= 0) {
            throw new RuntimeException('SMTP 会话超出总时限（' . $this->budget . 's）');
        }
        return min($this->timeout, $left);
    }

    public function sessionLog(): array
    {
        return $this->sessionLog;
    }

    /**
     * 建立连接（含必要时的 STARTTLS 升级）
     */
    public function connect(): void
    {
        $this->deadline = $this->budget > 0 ? microtime(true) + $this->budget : 0.0;

        $verifyTls = (bool) Config::get('mail.smtp_verify_tls', true);
        $context = stream_context_create([
            'ssl' => [
                // 默认校验证书；自建/自签名 SMTP 可在 config.local.php 关掉 mail.smtp_verify_tls
                'verify_peer'       => $verifyTls,
                'verify_peer_name'  => $verifyTls,
                'allow_self_signed' => !$verifyTls,
                'SNI_enabled'       => true,
                'peer_name'         => $this->host,
            ],
        ]);

        $transport = $this->secure === 'ssl' ? 'ssl' : 'tcp';
        $remote    = sprintf('%s://%s:%d', $transport, $this->host, $this->port);

        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->remainingTimeout(),
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(
                sprintf('连接 SMTP 失败 [%s]: %s (%d)', $remote, $errstr ?: 'unknown error', $errno)
            );
        }

        stream_set_timeout($socket, $this->remainingTimeout());
        $this->socket = $socket;

        $this->readResponse(); // 服务端问候语 220
        if ((int) $this->lastCode !== 220) {
            throw new RuntimeException('SMTP 问候异常: ' . $this->lastResponse);
        }

        // 首次 EHLO，采集能力
        $this->command('EHLO ' . $this->ehloHost, [250]);

        // 显式 TLS（587）
        if ($this->secure === 'tls') {
            $this->command('STARTTLS', [220]);
            $ok = @stream_socket_enable_crypto($this->socket, true, $this->tlsMethods());
            if ($ok !== true) {
                throw new RuntimeException('STARTTLS 握手失败，请确认端口/加密方式是否匹配');
            }
            // TLS 建立后必须重新 EHLO
            $this->command('EHLO ' . $this->ehloHost, [250]);
        }
    }

    /**
     * 登录认证；用户名为空表示免认证中继
     */
    public function authenticate(string $user, string $pass): void
    {
        if ($user === '') {
            return;
        }

        $mechs = $this->capabilities['AUTH'] ?? [];

        // 优先 PLAIN
        if (!$mechs || in_array('PLAIN', $mechs, true)) {
            try {
                $this->command('AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), [235], true);
                return;
            } catch (RuntimeException $e) {
                if (!$mechs) {
                    // 未声明机制时继续尝试 LOGIN
                } else {
                    throw $e;
                }
            }
        }

        if (!$mechs || in_array('LOGIN', $mechs, true)) {
            $this->command('AUTH LOGIN', [334], true);
            $this->command(base64_encode($user), [334], true);
            $this->command(base64_encode($pass), [235], true);
            return;
        }

        throw new RuntimeException('服务器不支持 AUTH PLAIN / LOGIN，声明的机制: ' . implode(',', $mechs));
    }

    /**
     * 投递一封邮件
     *
     * @param string   $from       发件人邮箱
     * @param string[] $recipients 收件人（含抄送/密送）
     * @param string   $message    完整 MIME 报文（CRLF 换行）
     */
    public function send(string $from, array $recipients, string $message): string
    {
        if (!$recipients) {
            throw new RuntimeException('收件人列表为空');
        }

        $this->command('MAIL FROM:<' . $from . '>', [250]);

        foreach ($recipients as $rcpt) {
            $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
        }

        $this->command('DATA', [354]);

        // 行首点转义，避免被当作结束符
        $data = preg_replace('/^\./m', '..', $message);
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }

        $this->write($data . "." . "\r\n");
        $resp = $this->readResponse();

        if ((int) $this->lastCode !== 250) {
            throw new RuntimeException('邮件投递被拒: ' . $this->lastResponse);
        }

        return $resp;
    }

    public function quit(): void
    {
        if ($this->socket) {
            try {
                $this->command('QUIT', []);
            } catch (\Throwable $e) {
                // 忽略退出阶段错误
            }
        }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    private function command(string $cmd, array $expectCodes, bool $mask = false): string
    {
        $this->write($cmd . "\r\n", $mask);
        $resp = $this->readResponse();
        $code = (int) $this->lastCode;

        if ($expectCodes && !in_array($code, $expectCodes, true)) {
            // 敏感命令（AUTH 及账号密码的 base64 参数）不能出现在异常消息里，
            // 否则会经 mail_logs.error / 文件日志 / Webhook 扩散出去
            $display = $mask
                ? (str_contains($cmd, ' ') ? preg_replace('#\s.*$#s', ' ***', $cmd) : '***')
                : $cmd;
            throw new RuntimeException(
                sprintf('SMTP 命令失败 [%s] 期望 %s 实际 %d: %s', $display, implode('/', $expectCodes), $code, $this->lastResponse)
            );
        }

        // 解析能力（仅 EHLO 响应）
        if (str_starts_with($cmd, 'EHLO')) {
            $this->parseCapabilities($resp);
        }

        return $resp;
    }

    private function write(string $data, bool $mask = false): void
    {
        $this->sessionLog[] = $mask ? '>> [已隐藏]' : '>> ' . rtrim($data, "\r\n");

        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP 连接不可用');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw new RuntimeException('SMTP 写入失败，连接可能已断开');
        }
    }

    private function readResponse(): string
    {
        // 每次读取前按「会话剩余时间」重设超时，使总时限覆盖整条链路而非单步。
        $wait = $this->remainingTimeout();
        if (is_resource($this->socket)) {
            @stream_set_timeout($this->socket, $wait);
        }

        $buffer = '';
        while (($line = @fgets($this->socket, 515)) !== false) {
            $buffer .= $line;
            // 多行响应：第 4 个字符为 '-' 表示后续还有行
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($buffer === '') {
            $meta = is_resource($this->socket) ? stream_get_meta_data($this->socket) : [];
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('SMTP 读取超时（本次等待 ' . $wait . 's）');
            }
            throw new RuntimeException('SMTP 连接被对端关闭');
        }

        $this->lastResponse = trim($buffer);
        $this->lastCode     = substr($buffer, 0, 3);
        $this->sessionLog[] = '<< ' . str_replace("\r\n", ' | ', trim($buffer));

        return $buffer;
    }

    private function parseCapabilities(string $response): void
    {
        $this->capabilities = [];
        $lines = preg_split('/\r\n/', trim($response));
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue; // 首行是 250-域名
            }
            $line = trim(preg_replace('/^\d{3}[-\s]/', '', $line));
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            $key   = strtoupper(array_shift($parts));
            $this->capabilities[$key] = array_map('strtoupper', $parts);
        }
    }

    private function buildEhloHost(): string
    {
        $host = gethostname() ?: 'localhost';
        return preg_replace('/[^a-zA-Z0-9\.\-]/', '', $host) ?: 'localhost';
    }

    private function tlsMethods(): int
    {
        $methods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        return $methods;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }
}
