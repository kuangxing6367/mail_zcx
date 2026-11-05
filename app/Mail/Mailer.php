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
 *
 * 两种执行模式（由 mail.queue.enabled 决定）：
 *   同步：send() = prepare() + deliver() + finalize()，单请求内完成，向后兼容
 *   异步：prepare() 落库 pending 并返回作业载荷，cli/worker.php 调 deliver() + finalize()
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
     * 同步发送（队列关闭时的降级路径，保持旧行为）
     */
    public function send(array $input, array $context = []): array
    {
        $payload = $this->prepare($input, $context);
        $result  = $this->deliver($payload);
        $this->finalize($payload, $result);

        return [
            'log_id'       => $payload['log_id'],
            'request_id'   => $payload['request_id'],
            'status'       => $result['status'],
            'mailbox_id'   => $payload['mailbox_id'],
            'from'         => $payload['from_email'],
            'to'           => $payload['to'],
            'cc'           => $payload['cc'],
            'subject'      => $payload['subject'],
            'smtp_code'    => $result['smtp_code'],
            'smtp_message' => $result['smtp_message'],
            'error'        => $result['error'],
            'duration_ms'  => $result['duration_ms'],
            'missing_vars' => $payload['missing_vars'],
        ];
    }

    /**
     * 阶段一：解析 / 渲染 / 选箱 / 组 MIME / 落库 pending，返回作业载荷。
     * 队列模式下在请求内同步完成，耗时极短（无网络 IO）。
     */
    public function prepare(array $input, array $context = []): array
    {
        [$to, $cc, $bcc, $subject, $html, $text, $missing, $mailbox, $mime, $fromName] =
            $this->buildMessage($input);

        $fromEmail = (string) $mailbox['email'];
        $message   = $mime->build();
        $recipients = $mime->allRecipients();

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
            'template_code' => trim((string) ($input['template'] ?? $input['template_code'] ?? '')),
            'status'        => 'pending',
            'source_ip'     => (string) ($context['source_ip'] ?? ''),
            'user_agent'    => mb_substr((string) ($context['user_agent'] ?? ''), 0, 255),
            'payload'       => Config::get('mail.log_payload', true)
                ? mb_substr(json_encode($payloadForLog, JSON_UNESCAPED_UNICODE), 0, 65535)
                : null,
        ]);

        return [
            'log_id'       => $logId,
            'request_id'   => $requestId,
            'mailbox_id'   => (int) $mailbox['id'],
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'recipients'   => $recipients,
            'message'      => $message,
            'subject'      => $subject,
            'to'           => $this->joinEmails($to),
            'cc'           => $this->joinEmails($cc),
            'bcc'          => $this->joinEmails($bcc),
            'missing_vars' => $missing,
            'input'        => $input,
            'context'      => $context,
        ];
    }

    /**
     * 阶段一（SMTP 入站专用）：**原样透传**客户端发来的 MIME，不重新组包。
     *
     * 为什么不复用 buildMessage()：SMTP 客户端送来的信已经是完整 MIME
     * （multipart／附件／编码／自定义头都在里面），解析成 html/text 再重组会丢掉结构，
     * 对老系统投递过来的邮件（尤其是回执、带附件的报表）是破坏性的。
     * 这里只做必要的头部清理与 From 重写，其余原样入队。
     *
     * @param array  $envelope ['from' => envelope sender, 'to' => [rcpt...], 'subject' => '']
     * @param string $rawMessage DATA 段原始内容（已去 dot-stuffing）
     */
    public function prepareRaw(array $envelope, string $rawMessage, array $context = []): array
    {
        $to = $this->parseAddresses($envelope['to'] ?? []);
        if (!$to) {
            throw new InvalidArgumentException('缺少收件人参数 to');
        }

        $maxRecipients = (int) Config::get('mail.max_recipients', 100);
        if (count($to) > $maxRecipients) {
            throw new InvalidArgumentException('收件人数量超过上限 ' . $maxRecipients);
        }

        // ---------- 选箱：优先匹配 envelope sender 是否为本站已配置邮箱 ----------
        $fromHint = strtolower(trim((string) ($envelope['from'] ?? '')));
        $mailbox  = null;
        if ($fromHint !== '') {
            try {
                $mailbox = $this->mailboxes->selectForSend(null, $fromHint);
            } catch (\Throwable $e) {
                // 客户端用的发件人不在本服务配置里是常见情况，回退到智能选箱即可
                $mailbox = null;
            }
        }
        $mailbox ??= $this->mailboxes->selectForSend();

        $fromEmail = (string) $mailbox['email'];
        $fromName  = trim((string) ($envelope['from_name'] ?? ''))
            ?: ((string) $mailbox['display_name'] ?: (string) Config::get('mail.default_from_name', ''));

        // ---------- 头部清理与 From 重写 ----------
        [$rawMessage, $originalFrom] = $this->rewriteRawHeaders($rawMessage, $fromEmail, $fromName);

        // ---------- 主题（只用于日志展示）----------
        $subject = trim((string) ($envelope['subject'] ?? ''));
        if ($subject === '' && preg_match('/^Subject:[ \t]*(.*(?:\r?\n[ \t].*)*)/mi', $rawMessage, $m)) {
            $subject = trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]));
        }
        $subject = trim((string) @mb_decode_mimeheader($subject));

        $requestId = (string) ($context['request_id'] ?? RequestContext::get('request_id', ''));

        // 日志只存信封摘要，正文原文随作业 payload 走，避免重复落库
        $payloadForLog = [
            'source'        => 'smtp',
            'envelope_from' => $fromHint,
            'original_from' => $originalFrom,
            'size'          => strlen($rawMessage),
        ];

        $logId = $this->logs->create([
            'request_id'    => $requestId,
            'mailbox_id'    => (int) $mailbox['id'],
            'from_email'    => $fromEmail,
            'to_email'      => $this->joinEmails($to),
            'cc_email'      => '',
            'bcc_email'     => '',
            'subject'       => mb_substr($subject, 0, 500),
            'template_code' => '',
            'status'        => 'pending',
            'source_ip'     => (string) ($context['source_ip'] ?? ''),
            'user_agent'    => mb_substr((string) ($context['user_agent'] ?? 'SMTP'), 0, 255),
            'payload'       => Config::get('mail.log_payload', true)
                ? mb_substr(json_encode($payloadForLog, JSON_UNESCAPED_UNICODE), 0, 65535)
                : null,
        ]);

        return [
            'log_id'       => $logId,
            'request_id'   => $requestId,
            'mailbox_id'   => (int) $mailbox['id'],
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'recipients'   => array_map(static fn($a) => $a[0], $to),
            'message'      => $rawMessage,
            'subject'      => $subject,
            'to'           => $this->joinEmails($to),
            'cc'           => '',
            'bcc'          => '',
            'missing_vars' => [],
            'input'        => ['source' => 'smtp', 'callback_url' => (string) ($envelope['callback_url'] ?? '')],
            'context'      => $context,
        ];
    }

    /**
     * 清理原始 MIME 头：
     *   1. 去掉 Bcc（避免泄露抄送人，SMTP 投递时本来就不该带）
     *   2. 按需把 From 重写为真实发信邮箱（否则 QQ/163 因发件人不一致直接 550）
     *   3. 保留原发件人为 Reply-To，不丢回信线索
     *
     * @return array{0:string,1:string} [处理后的原文, 原始 From]
     */
    private function rewriteRawHeaders(string $raw, string $fromEmail, string $fromName): array
    {
        $originalFrom = '';
        if (preg_match('/^From:[ \t]*(.*(?:\r?\n[ \t].*)*)/mi', $raw, $m)) {
            $originalFrom = trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1]));
        }

        // 剥离 Bcc 头（含折行续行）
        $raw = (string) preg_replace('/^Bcc:[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*\r?\n/mi', '', $raw);

        if (!(bool) Config::get('smtp_in.rewrite_from', true)) {
            return [$raw, $originalFrom];
        }

        $nameEncoded = $fromName !== '' ? mb_encode_mimeheader($fromName, 'UTF-8', 'B') : '';
        $newFrom     = $nameEncoded !== '' ? $nameEncoded . ' <' . $fromEmail . '>' : '<' . $fromEmail . '>';

        $replaced = (string) preg_replace(
            '/^From:[^\r\n]*(?:\r?\n[ \t][^\r\n]*)*\r?\n/mi',
            'From: ' . $newFrom . "\r\n",
            $raw,
            1,
            $count
        );
        if ($count > 0) {
            $raw = $replaced;
        } else {
            // 没有 From 头：补在头部
            $raw = 'From: ' . $newFrom . "\r\n" . $raw;
        }

        // 原发件人留一条回信线索（仅在原地址不是自己、且原文没有 Reply-To 时）
        $keepReplyTo = (bool) Config::get('smtp_in.reply_to_original', true);
        if ($keepReplyTo && $originalFrom !== '' && stripos($originalFrom, $fromEmail) === false
            && !preg_match('/^Reply-To:/mi', $raw)) {
            $raw = 'Reply-To: ' . $originalFrom . "\r\n" . $raw;
        }

        return [$raw, $originalFrom];
    }

    /**
     * 阶段二：执行 SMTP 投递（worker 或同步模式调用）
     * 网络异常内部捕获并转为 failed 结果，不向外抛；邮箱缺失等前置错误会抛。
     */
    public function deliver(array $payload): array
    {
        $mailboxId = (int) $payload['mailbox_id'];
        $mailbox   = $this->mailboxes->get($mailboxId);
        if (!$mailbox) {
            throw new \RuntimeException('发信邮箱不存在或已删除: #' . $mailboxId);
        }
        $cred = $this->mailboxes->credentials($mailbox);

        $start       = microtime(true);
        $client      = null;
        $status      = 'failed';
        $smtpCode    = '';
        $smtpMessage = '';
        $error       = '';

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
            $client->send((string) $payload['from_email'], (array) $payload['recipients'], (string) $payload['message']);
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
            Logger::warning('发信失败: ' . $error, [
                'mailbox' => (string) ($payload['from_email'] ?? ''),
                'to'      => $payload['recipients'] ?? [],
            ]);
        }

        return [
            'status'       => $status,
            'smtp_code'    => $smtpCode,
            'smtp_message' => $smtpMessage,
            'error'        => $error,
            'duration_ms'  => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * 阶段三：回写日志、邮箱计数、触发 Webhook（同步 / 队列共用）
     */
    public function finalize(array $payload, array $result): void
    {
        $logId     = (int) $payload['log_id'];
        $mailboxId = (int) $payload['mailbox_id'];
        $status    = (string) ($result['status'] ?? 'failed');

        $this->logs->update($logId, [
            'status'       => $status,
            'smtp_code'    => mb_substr((string) ($result['smtp_code'] ?? ''), 0, 16),
            'smtp_message' => $result['smtp_message'] ?? '',
            'error'        => ($result['error'] ?? '') !== '' ? mb_substr((string) $result['error'], 0, 2000) : null,
            'duration_ms'  => (int) ($result['duration_ms'] ?? 0),
            'sent_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($status === 'success') {
            $this->mailboxes->recordSuccess($mailboxId);
        } else {
            $this->mailboxes->recordFailure($mailboxId, (string) ($result['error'] ?? ''));
        }

        // 状态回调
        $input          = (array) ($payload['input'] ?? []);
        $context        = (array) ($payload['context'] ?? []);
        $callbackUrl    = trim((string) ($input['callback_url'] ?? $input['webhook'] ?? ''));
        $callbackSecret = trim((string) ($input['callback_secret'] ?? ''));

        $webhookPayload = [
            'log_id'       => $logId,
            'request_id'   => (string) ($payload['request_id'] ?? ($context['request_id'] ?? '')),
            'status'       => $status,
            'mailbox_id'   => $mailboxId,
            'from'         => (string) ($payload['from_email'] ?? ''),
            'to'           => (string) ($payload['to'] ?? ''),
            'cc'           => (string) ($payload['cc'] ?? ''),
            'subject'      => (string) ($payload['subject'] ?? ''),
            'smtp_code'    => $result['smtp_code'] ?? '',
            'smtp_message' => $result['smtp_message'] ?? '',
            'error'        => $result['error'] ?? '',
            'duration_ms'  => (int) ($result['duration_ms'] ?? 0),
            'missing_vars' => $payload['missing_vars'] ?? [],
        ];

        $this->webhooks->dispatchAsync(
            $status,
            $webhookPayload,
            $callbackUrl !== '' ? $callbackUrl : null,
            $logId,
            $callbackSecret
        );
    }

    /**
     * 解析 / 渲染 / 选箱 / 组 MIME
     *
     * @return array{0:array,1:array,2:array,3:string,4:string,5:string,6:array,7:array,8:MimeBuilder,9:string}
     *         0..2 to/cc/bcc  3..5 subject/html/text  6 missing  7 mailbox  8 mime  9 fromName
     */
    private function buildMessage(array $input): array
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
        $fromName  = trim((string) ($input['from_name'] ?? ''))
            ?: ((string) $mailbox['display_name'] ?: (string) Config::get('mail.default_from_name', ''));

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

        return [$to, $cc, $bcc, $subject, $html, $text, $missing, $mailbox, $mime, $fromName];
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
