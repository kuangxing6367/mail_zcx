<?php

/**
 * SMTP 发送自测脚本（可打真实邮箱，也可打 mock 服务器）
 *
 * 用法:
 *   php -d extension=openssl -d extension=mbstring cli/test_smtp.php \
 *       --host=127.0.0.1 --port=2525 --secure=none \
 *       --user=test@example.com --pass=secret123 \
 *       --from=test@example.com --to=someone@example.com
 *
 * secure: ssl(465) | tls(587) | none(25)
 */

declare(strict_types=1);

use App\Mail\MimeBuilder;
use App\Mail\SmtpClient;

$root = dirname(__DIR__);
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

$o = getopt('', ['host::', 'port::', 'secure::', 'user::', 'pass::', 'from::', 'to::', 'verbose::']);

$host   = (string) ($o['host']   ?? '127.0.0.1');
$port   = (int)    ($o['port']   ?? 2525);
$secure = (string) ($o['secure'] ?? 'none');
$user   = (string) ($o['user']   ?? 'test@example.com');
$pass   = (string) ($o['pass']   ?? 'secret123');
$from   = (string) ($o['from']   ?? $user);
$to     = (string) ($o['to']     ?? 'receiver@example.com');

echo "== SMTP 自测 ==\n";
echo "目标: {$secure}://{$host}:{$port}  账号: {$user}\n\n";

// ---- 1. 构建 MIME ----
$mime = new MimeBuilder();
$mime->setFrom($from, '测试发件人')
     ->setSubject('测试邮件 · 中文主题与附件 ' . date('His'))
     ->setHtml('<div style="font-family:sans-serif"><h1>你好，世界</h1><p>这是一封来自 <b>ZC Mail Service</b> 的测试邮件。</p><p>时间: ' . date('Y-m-d H:i:s') . '</p></div>')
     ->setText("你好，世界\n这是一封来自 ZC Mail Service 的测试邮件。\n时间: " . date('Y-m-d H:i:s'))
     ->addTo($to, '收件人')
     ->addCc('cc@example.com', '抄送人')
     ->addAttachment('说明.txt', "这是附件内容\n第二行\n", 'text/plain');

$message    = $mime->build();
$recipients = $mime->allRecipients();

echo '-- MIME 报文: ' . strlen($message) . " 字节, 收件人 " . count($recipients) . " 个\n";
if (isset($o['verbose'])) {
    echo substr($message, 0, 800) . "\n...\n\n";
}

// ---- 2. 连接并发送 ----
$start  = microtime(true);
$client = new SmtpClient($host, $port, $secure, 10);

try {
    $client->connect();
    echo "-- 连接成功, 能力: " . implode(', ', array_keys($client->capabilities())) . "\n";

    $client->authenticate($user, $pass);
    echo "-- 认证完成\n";

    $client->send($from, $recipients, $message);
    $duration = (int) round((microtime(true) - $start) * 1000);
    echo "-- 投递成功: {$client->lastCode} {$client->lastResponse} ({$duration}ms)\n";

    $client->quit();
    echo "\n✔ 测试通过 ({$secure})\n";
    exit(0);
} catch (Throwable $e) {
    echo "\n✘ 测试失败: " . $e->getMessage() . "\n";
    echo "-- 会话记录 --\n" . implode("\n", $client->sessionLog()) . "\n";
    $client->close();
    exit(1);
}
