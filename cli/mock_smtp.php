<?php

/**
 * Mock SMTP 服务器 —— 仅用于本地验证 SmtpClient
 *
 * 支持三种模式，用于覆盖 465 / 587 / 25 三种真实场景：
 *   --mode=plain   明文，不要求认证（模拟 25 端口）
 *   --mode=tls     明文握手后 STARTTLS 升级（模拟 587 端口）
 *   --mode=ssl     连接即 TLS（模拟 465 端口）
 *
 * 用法:
 *   php -d extension=openssl cli/mock_smtp.php --port=2525 --mode=plain
 *   php -d extension=openssl cli/mock_smtp.php --port=2587 --mode=tls
 *   php -d extension=openssl cli/mock_smtp.php --port=2465 --mode=ssl
 *
 * 参数:
 *   --port=2525     监听端口
 *   --mode=plain    plain|tls|ssl
 *   --auth=1        是否要求认证（默认 1）
 *   --user=xx       期望用户名（默认 test@example.com）
 *   --pass=xx       期望密码（默认 secret123）
 *   --cert=path     证书路径（tls/ssl 模式，默认自动生成到 storage/certs）
 */

declare(strict_types=1);

$opts = getopt('', ['port::', 'mode::', 'auth::', 'user::', 'pass::', 'cert::', 'host::', 'max::', 'idle::']);
$port = (int) ($opts['port'] ?? 2525);
$mode = (string) ($opts['mode'] ?? 'plain');
$auth = (int) ($opts['auth'] ?? 1);
$user = (string) ($opts['user'] ?? 'test@example.com');
$pass = (string) ($opts['pass'] ?? 'secret123');
$host = (string) ($opts['host'] ?? '127.0.0.1');

if (!extension_loaded('openssl') && in_array($mode, ['tls', 'ssl'], true)) {
    fwrite(STDERR, "tls/ssl 模式需要 openssl 扩展: php -d extension=openssl cli/mock_smtp.php ...\n");
    exit(1);
}

// ---- 证书（tls/ssl 模式） ----
$certFile = $keyFile = null;
if (in_array($mode, ['tls', 'ssl'], true)) {
    $certDir = dirname(__DIR__) . '/storage/certs';
    if (!is_dir($certDir)) {
        mkdir($certDir, 0755, true);
    }
    $certFile = (string) ($opts['cert'] ?? $certDir . '/mock_smtp.crt');
    $keyFile  = preg_replace('/\.crt$/', '.key', $certFile);

    if (!is_file($certFile) || !is_file($keyFile)) {
        [$certPem, $keyPem] = makeSelfSignedCert();
        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $keyPem);
        echo "[mock] 已生成自签证书: {$certFile}\n";
    }
}

$context = stream_context_create([
    'ssl' => [
        'local_cert'        => $certFile,
        'local_pk'          => $keyFile,
        'allow_self_signed' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
    ],
]);

$listen = ($mode === 'ssl' ? 'ssl' : 'tcp') . '://' . $host . ':' . $port;
$errno = 0;
$errstr = '';
$server = @stream_socket_server($listen, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) {
    fwrite(STDERR, "监听失败 {$listen}: {$errstr} ({$errno})\n");
    exit(1);
}

echo "[mock] SMTP 服务器已启动 {$listen} mode={$mode} auth={$auth}\n";
echo "[mock] 期望账号: {$user} / {$pass}\n";

$max     = (int) ($opts['max'] ?? 0);   // 处理多少个连接后退出，0 = 不限制
$idleSec = (int) ($opts['idle'] ?? 0);  // 空闲多少秒后退出，0 = 不限制
$served  = 0;

while (true) {
    if ($max > 0 && $served >= $max) {
        echo "[mock] 已达最大连接数 {$max}，退出\n";
        break;
    }

    $conn = @stream_socket_accept($server, $idleSec > 0 ? $idleSec : -1);
    if (!$conn) {
        if ($idleSec > 0) {
            echo "[mock] 空闲 {$idleSec}s，退出\n";
            break;
        }
        continue;
    }

    handleClient($conn, $mode, (bool) $auth, $user, $pass);
    @fclose($conn);
    $served++;
}

// ---------------------------------------------------------------------

function handleClient($conn, string $mode, bool $needAuth, string $user, string $pass): void
{
    stream_set_timeout($conn, 30);
    $peer = stream_socket_get_name($conn, true);

    $send = function (string $line) use ($conn): void {
        fwrite($conn, $line . "\r\n");
    };
    $readLine = function () use ($conn): ?string {
        $line = fgets($conn, 2048);
        return $line === false ? null : rtrim($line, "\r\n");
    };

    $send('220 mock.local ESMTP ZC Mock Server ready');

    $authenticated = !$needAuth;
    $inData = false;
    $dataLines = 0;

    while (true) {
        $line = $readLine();
        if ($line === null) {
            return;
        }

        if ($inData) {
            if ($line === '.') {
                $inData = false;
                $send('250 2.0.0 OK: queued as ' . strtoupper(bin2hex(random_bytes(6))));
                echo "[mock] {$peer} 收到邮件正文 {$dataLines} 行\n";
                continue;
            }
            $dataLines++;
            continue;
        }

        $upper = strtoupper($line);

        if (str_starts_with($upper, 'EHLO')) {
            $send('250-mock.local greets you');
            if ($mode === 'tls') {
                $send('250-STARTTLS');
            }
            $send('250-AUTH PLAIN LOGIN');
            $send('250-SIZE 10485760');
            $send('250-8BITMIME');
            $send('250 OK');
            continue;
        }

        if (str_starts_with($upper, 'HELO')) {
            $send('250 mock.local');
            continue;
        }

        if ($upper === 'STARTTLS') {
            $send('220 2.0.0 Ready to start TLS');
            $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($ok !== true) {
                echo "[mock] {$peer} STARTTLS 失败\n";
                return;
            }
            echo "[mock] {$peer} STARTTLS 升级成功\n";
            continue;
        }

        if (str_starts_with($upper, 'AUTH PLAIN')) {
            $parts = preg_split('/\s+/', $line);
            $payload = $parts[2] ?? '';
            $decoded = base64_decode($payload, true);
            $bits = $decoded === false ? [] : explode("\0", $decoded);
            $u = $bits[1] ?? '';
            $p = $bits[2] ?? '';
            if ($u === $user && $p === $pass) {
                $authenticated = true;
                $send('235 2.7.0 Authentication successful');
            } else {
                $send('535 5.7.8 Authentication credentials invalid');
            }
            continue;
        }

        if ($upper === 'AUTH LOGIN') {
            $send('334 ' . base64_encode('Username:'));
            $u = base64_decode((string) $readLine(), true);
            $send('334 ' . base64_encode('Password:'));
            $p = base64_decode((string) $readLine(), true);
            if ($u === $user && $p === $pass) {
                $authenticated = true;
                $send('235 2.7.0 Authentication successful');
            } else {
                $send('535 5.7.8 Authentication credentials invalid');
            }
            continue;
        }

        if (str_starts_with($upper, 'MAIL FROM')) {
            if (!$authenticated) {
                $send('530 5.7.0 Authentication required');
                continue;
            }
            $send('250 2.1.0 Sender OK');
            continue;
        }

        if (str_starts_with($upper, 'RCPT TO')) {
            if (!$authenticated) {
                $send('530 5.7.0 Authentication required');
                continue;
            }
            $send('250 2.1.5 Recipient OK');
            continue;
        }

        if ($upper === 'DATA') {
            if (!$authenticated) {
                $send('530 5.7.0 Authentication required');
                continue;
            }
            $inData = true;
            $dataLines = 0;
            $send('354 End data with <CR><LF>.<CR><LF>');
            continue;
        }

        if ($upper === 'RSET') {
            $send('250 2.0.0 OK');
            continue;
        }

        if ($upper === 'QUIT') {
            $send('221 2.0.0 Bye');
            return;
        }

        if ($upper === 'NOOP') {
            $send('250 2.0.0 OK');
            continue;
        }

        $send('502 5.5.2 Command not implemented: ' . $line);
    }
}

/**
 * 生成自签证书，返回 [certPem, keyPem]
 */
function makeSelfSignedCert(): array
{
    $dn = [
        'countryName'           => 'CN',
        'stateOrProvinceName'   => 'Shanghai',
        'localityName'          => 'Shanghai',
        'organizationName'      => 'ZC Mock',
        'commonName'            => 'localhost',
        'emailAddress'          => 'mock@zc.local',
    ];

    $config = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'digest_alg'       => 'sha256',
    ];

    $key  = openssl_pkey_new($config);
    if ($key === false) {
        fwrite(STDERR, "生成私钥失败: " . openssl_error_string() . "\n");
        exit(1);
    }

    $csr  = openssl_csr_new($dn, $key, $config);
    $cert = openssl_csr_sign($csr, null, $key, 365, $config);

    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);

    return [$certPem, $keyPem];
}
