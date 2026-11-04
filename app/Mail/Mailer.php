<?php

namespace App\Mail;

use App\Core\Config;
use App\Core\Logger;
use App\Core\RequestContext;
use App\Service\LogService;
use App\Service\MailboxService;
use App\Service\TemplateService;
use App\Service\WebhookService;
use InvalidArgumentException;

/**
 * 发信编排：选箱 → 渲染 → 组包 → SMTP 投递 → 记日志 → 触发 Webhook
 */
class Mailer
{
    private MailboxService $mailboxes;
    private TemplateService $templates;
    private LogService $logs;
    private WebhookService $webhooks;

    public function __construct()
    {
        $this->mailboxes = new MailboxService();
        $this->templates = new TemplateService();
        $this->logs      = new LogService();
        $this->webhooks  = new WebhookService();
    }

    /**
     * @param array $input   调用参数
     * @param array $context 上下文（source_ip / user_agent / request_id）
     */
    public function send(array $input, array $context = []): array
    {
        // ---------- 1. 收件人 ----------
        $to  = $this->parseAddresses($input['to']  ?? null);
        $cc  = $this->parseAddresses($input['cc']  ?? null);
        $bcc = $this->parseAddresses($input['bcc'] ?? null);

        if (!$to) {
            throw new InvalidArgumentException('缺少收件人参数 to');
        }

        $maxRecipients = (int) Config::get('mail.max_recipients', 100);
        if (count($to) + count($cc) + count($bcc) > $maxRecipients) {
            throw new InvalidArgumentException('收件人数量超过上限 ' . $maxRecipients);
        }

        // ---------- 2. 内容与模板渲染 ----------
        $vars         = (array) ($input['variables'] ?? $input['vars'] ?? []);
        $templateCode = trim((string) ($input['template'] ?? $input['template_code'] ?? ''));
        $subject      = trim((string) ($input['subject'] ?? ''));
        $html         = (string) ($input['html'] ?? $input['body_html'] ?? '');
        $text         = (string) ($input['text'] ?? $input['body_text'] ?? '');
        $missing      = [];

        if ($templateCode !== '') {
            $rendered = $this->templates->render($templateCode, $vars);
            $missing  = $rendered['missing'];
            $subject  = $subject !== '' ? $this->templates->substitute($subject, $vars) : $rendered['subject'];
            $html     = $html !== '' ? $this->templates->substitute($html, $vars) : $rendered['html'];
            $text     = $text !== '' ? $this->templates->substitute($text, $vars) : $rendered['text'];
        } elseif ($vars) {
            $subject = $this->templates->substitute($subject, $vars);
            $html    = $this->templates->substitute($html, $vars);
            $text    = $this->templates->substitute($text, $vars);
        }

        if ($subject === '') {
            throw new InvalidArgumentException('邮件主题不能为空（请传 subject 或使用 template）');
        }
        if ($html === '' && $text === '') {
            throw new InvalidArgumentException('邮件正文不能为空（请传 html/text 或使用 template）');
        }

        // ---------- 3. 智能选箱 ----------
        $mailbox = $this->mailboxes->selectForSend(
            isset($input['mailbox_id']) && $input['mailbox_id'] !== '' ? (int) $input['mailbox_id'] : null,
            !empty($input['mailbox']) ? (string) $input['mailbox'] : (!empty($input['from']) ? (string) $input['from'] : null)
        );

        $fromEmail = (string) $mailbox['email'];
        $fromName  = trim((string) ($input['from_name'] ?? '')) ?: (string) $mailbox['display_name'] ?: (string) Config::get('mail.default_from_name', '');

        // ---------- 4. 组装 MIME ----------
        $mime = new MimeBuilder();
        $mime->setFrom($fromEmail, $fromName)
             ->setSubject($subject)
             ->setHtml($html)
             ->setText($text);

        foreach ($to as $addr) {
            $mime->addTo($addr[0], $addr[1]);
        }
        foreach ($cc as $addr) {
            $mime->addCc($addr[0], $addr[1]);
        }
        foreach ($bcc as $addr) {
            $mime->addBcc($addr[0], $addr[1]);
        }
        if (!empty($input['reply_to'])) {
            $mime->setReplyTo((string) $input['reply_to']);
        }
        if (!empty($input['headers']) && is_array($input['headers'])) {
            foreach ($input['headers'] as $k => $v) {
                $mime->addHeader((string) $k, (string) $v);
            }
        }
        $this->attachFiles($mime, $input['attachments'] ?? []);

        $message = $mime->build();
        $recipients = $mime->allRecipients();

        // ---------- 5. 落库 pending ----------
        $requestId = (string) ($context['request_id'] ?? RequestContext::get('request_id', ''));

        // 落库前剔除敏感参数，避免密钥写进日志
        $payloadForLog = $input;
        unset($payloadForLog['api_key'], $payloadForLog['callback_secret'], $payloadForLog['_method']);

        $logId = $this->logs->create([
            'request_id'    => $requestId,
            'mailbox_id'    => (int) $mailbox['id'],
            'from_email'    => $fromEmail,
            'to_email'      => $this->joinEmails($to),
            'cc_email'      => $this->joinEmails($cc),
            'bcc_email'     => $this->joinEmails($bcc),
            'subject'       => mb_substr($subject, 0, 500),
            'template_code' => $templateCode,
            'status'        => 'pending',
            'source_ip'     => (string) ($context['source_ip'] ?? ''),
            'user_agent'    => mb_substr((string) ($context['user_agent'] ?? ''), 0, 255),
            'payload'       => Config::get('mail.log_payload', true)
                ? mb_substr(json_encode($payloadForLog, JSON_UNESCAPED_UNICODE), 0, 65535)
                : null,
        ]);

        // ---------- 6. SMTP 投递 ----------
        $cred  = $this->mailboxes->credentials($mailbox);
        $start = microtime(true);
        $client = null;
        $status = 'failed';
        $smtpCode = '';
        $smtpMessage = '';
        $error = '';

        try {
            $client = new SmtpClient(
                $cred['host'],
                $cred['port'],
                $cred['secure'],
                (int) Config::get('mail.timeout', 15),
                (int) Config::get('mail.total_timeout', 30)
            );
            $client->connect();
            $client->authenticate($cred['user'], $cred['pass']);
            $client->send($fromEmail, $recipients, $message);
            $smtpCode    = (string) $client->lastCode;
            $smtpMessage = (string) $client->lastResponse;
            $client->quit();
            $status = 'success';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if ($client) {
                $smtpCode    = (string) $client->lastCode;
                $smtpMessage = (string) $client->lastResponse;
                $client->close();
            }
            Logger::warning('发信失败: ' . $error, ['mailbox' => $fromEmail, 'to' => $recipients]);
        }

        $duration = (int) round((microtime(true) - $start) * 1000);

        // ---------- 7. 回写日志与计数 ----------
        $this->logs->update($logId, [
            'status'       => $status,
            'smtp_code'    => mb_substr($smtpCode, 0, 16),
            'smtp_message' => $smtpMessage,
            'error'        => $error !== '' ? mb_substr($error, 0, 2000) : null,
            'duration_ms'  => $duration,
            'sent_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($status === 'success') {
            $this->mailboxes->recordSuccess((int) $mailbox['id']);
        } else {
            $this->mailboxes->recordFailure((int) $mailbox['id'], $error);
        }

        $result = [
            'log_id'       => $logId,
            'request_id'   => $requestId,
            'status'       => $status,
            'mailbox_id'   => (int) $mailbox['id'],
            'from'         => $fromEmail,
            'to'           => $this->joinEmails($to),
            'cc'           => $this->joinEmails($cc),
            'subject'      => $subject,
            'smtp_code'    => $smtpCode,
            'smtp_message' => $smtpMessage,
            'error'        => $error,
            'duration_ms'  => $duration,
            'missing_vars' => $missing,
        ];

        // ---------- 8. 触发 Webhook ----------
        $callbackUrl    = trim((string) ($input['callback_url'] ?? $input['webhook'] ?? ''));
        $callbackSecret = trim((string) ($input['callback_secret'] ?? ''));
        $this->webhooks->dispatchAsync($status, $result, $callbackUrl !== '' ? $callbackUrl : null, $logId, $callbackSecret);

        return $result;
    }

    /**
     * 解析多种收件人写法：
     *   "a@b.com"
     *   "a@b.com,c@d.com"
     *   ["a@b.com","c@d.com"]
     *   [{"email":"a@b.com","name":"张三"}]
     */
    public function parseAddresses(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $out = [];
        $items = is_array($value) ? $value : explode(',', (string) $value);

        foreach ($items as $item) {
            if (is_array($item)) {
                $email = trim((string) ($item['email'] ?? $item['address'] ?? ''));
                $name  = trim((string) ($item['name'] ?? ''));
            } else {
                $raw = trim((string) $item);
                if ($raw === '') {
                    continue;
                }
                // 支持 "张三 <a@b.com>" 形式
                if (preg_match('/^(.*)<([^>]+)>$/', $raw, $m)) {
                    $name  = trim(trim($m[1]), '"\'');
                    $email = trim($m[2]);
                } else {
                    $email = $raw;
                    $name  = '';
                }
            }

            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('收件人邮箱格式不正确: ' . $email);
            }
            $out[] = [$email, $name];
        }

        return $out;
    }

    private function joinEmails(array $addresses): string
    {
        return implode(',', array_map(static fn($a) => $a[0], $addresses));
    }

    /**
     * 处理附件：支持 base64 内容或本地路径
     */
    private function attachFiles(MimeBuilder $mime, mixed $attachments): void
    {
        if (empty($attachments) || !is_array($attachments)) {
            return;
        }

        $maxBytes = (int) Config::get('mail.max_attachment_mb', 10) * 1024 * 1024;
        $total    = 0;

        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $name = (string) ($att['name'] ?? $att['filename'] ?? 'attachment');
            $mimeType = (string) ($att['mime'] ?? $att['type'] ?? 'application/octet-stream');

            if (!empty($att['content'])) {
                $data = base64_decode((string) $att['content'], true);
                if ($data === false) {
                    throw new InvalidArgumentException('附件 base64 内容解析失败: ' . $name);
                }
            } elseif (!empty($att['path'])) {
                $path = (string) $att['path'];
                if (!is_file($path) || !is_readable($path)) {
                    throw new InvalidArgumentException('附件文件不可读: ' . $path);
                }
                $data = (string) file_get_contents($path);
            } else {
                continue;
            }

            $total += strlen($data);
            if ($total > $maxBytes) {
                throw new InvalidArgumentException('附件总大小超过上限 ' . Config::get('mail.max_attachment_mb') . 'MB');
            }

            $mime->addAttachment($name, $data, $mimeType);
        }
    }
}
