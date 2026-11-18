<?php

namespace App\Mail;

use InvalidArgumentException;

/**
 * MIME 报文构建器
 *
 * - 自动选择结构：纯文本 / HTML / 纯文本+HTML(alternative) / 含附件(mixed)
 * - 中文主题与文件名使用 RFC2047 B 编码，长行自动分片
 * - 正文使用 base64 编码，附件按 76 字符折行
 * - 全文统一 CRLF 换行，符合 RFC 5322
 */
class MimeBuilder
{
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];

    private string $fromEmail = '';
    private string $fromName = '';
    private string $replyTo = '';
    private string $subject = '';

    private string $text = '';
    private string $html = '';

    /** @var array<int,array{name:string,data:string,mime:string}> */
    private array $attachments = [];

    private array $headers = [];
    private string $charset = 'UTF-8';

    public function setCharset(string $charset): self
    {
        $this->charset = strtoupper($charset) ?: 'UTF-8';
        return $this;
    }

    public function setFrom(string $email, string $name = ''): self
    {
        $this->fromEmail = $email;
        $this->fromName  = $name;
        return $this;
    }

    public function setReplyTo(string $email): self
    {
        $this->replyTo = self::stripCrlf($email);
        return $this;
    }

    public function addTo(string $email, string $name = ''): self
    {
        $this->to[] = [$email, $name];
        return $this;
    }

    public function addCc(string $email, string $name = ''): self
    {
        $this->cc[] = [$email, $name];
        return $this;
    }

    public function addBcc(string $email, string $name = ''): self
    {
        $this->bcc[] = [$email, $name];
        return $this;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function setHtml(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function addAttachment(string $name, string $data, string $mime = 'application/octet-stream'): self
    {
        // 文件名与 MIME 都会拼进头部，必须去掉 CRLF 防止邮件头注入
        $this->attachments[] = [
            'name' => self::stripCrlf($name),
            'data' => $data,
            'mime' => self::stripCrlf($mime) ?: 'application/octet-stream',
        ];
        return $this;
    }

    public function addHeader(string $key, string $value): self
    {
        $key = trim(self::stripCrlf($key));
        // 头字段名只允许 RFC 5322 的 token 字符
        if ($key === '' || !preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $key)) {
            return $this;
        }
        $this->headers[$key] = self::stripCrlf($value);
        return $this;
    }

    /** 去掉 CR/LF，阻断邮件头注入 */
    private static function stripCrlf(string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');
    }

    /** 所有收件人（To + Cc + Bcc），用于 SMTP RCPT TO */
    public function allRecipients(): array
    {
        $all = array_merge($this->to, $this->cc, $this->bcc);
        return array_values(array_unique(array_map(static fn($r) => $r[0], $all)));
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * 生成完整 MIME 报文
     */
    public function build(): string
    {
        if ($this->fromEmail === '') {
            throw new InvalidArgumentException('发件人不能为空');
        }
        if (!$this->to) {
            throw new InvalidArgumentException('至少需要一个收件人');
        }

        $uid = bin2hex(random_bytes(12));

        $headers   = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->formatAddress($this->fromEmail, $this->fromName);
        $headers[] = 'To: ' . implode(', ', array_map(fn($r) => $this->formatAddress($r[0], $r[1]), $this->to));

        if ($this->cc) {
            $headers[] = 'Cc: ' . implode(', ', array_map(fn($r) => $this->formatAddress($r[0], $r[1]), $this->cc));
        }
        if ($this->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        $headers[] = 'Subject: ' . $this->encodeHeader($this->subject);
        $headers[] = 'Message-ID: <' . $uid . '@' . $this->messageIdHost() . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: ZC-Mail-Service';

        foreach ($this->headers as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        // ---- 正文结构 ----
        //
        // multipart 里的每个部件都必须由「--boundary」单独起头，否则解析器会把
        // 后续内容当成上一个部件的正文（附件直接被吞掉，客户端看不到）。
        // 这里逐部件补分隔符，不要再把部件裸拼在一起。
        if ($this->hasAttachments()) {
            $mixed = 'mix_' . $uid;
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';

            $parts   = [$this->buildContentPart('alt_' . $uid)];
            foreach ($this->attachments as $att) {
                $parts[] = $this->buildAttachmentPart($att);
            }

            $body = '';
            foreach ($parts as $part) {
                $body .= '--' . $mixed . "\r\n" . $part . "\r\n";
            }
            $body .= '--' . $mixed . "--\r\n";
        } else {
            $part = $this->buildContentPart('alt_' . $uid);
            // 单部件：part 头部就是报文头，必须并进去，不能再放在正文里
            [$partHeaders, $partBody] = explode("\r\n\r\n", $part, 2);
            $headers[] = trim($partHeaders);
            $body = $partBody;
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        return $this->normalizeCrlf($message);
    }

    // ------------------------------------------------------------------

    /** 正文部件：text+html 时为 multipart/alternative */
    private function buildContentPart(string $boundary): string
    {
        $text = $this->text;
        $html = $this->html;

        if ($html === '' && $text === '') {
            $text = '';
        }
        // 只有 HTML 时，自动生成一份极简纯文本，提升送达率
        if ($text === '' && $html !== '') {
            $text = $this->htmlToText($html);
        }

        if ($text !== '' && $html !== '') {
            $out   = 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n";
            $out  .= "--{$boundary}\r\n";
            $out  .= $this->encodeTextPart($text, 'plain') . "\r\n";
            $out  .= "--{$boundary}\r\n";
            $out  .= $this->encodeTextPart($html, 'html') . "\r\n";
            $out  .= "--{$boundary}--";
            return $out;
        }

        $isHtml = $html !== '';
        return $this->encodeTextPart($isHtml ? $html : $text, $isHtml ? 'html' : 'plain');
    }

    private function encodeTextPart(string $content, string $subtype): string
    {
        $headers   = [];
        $headers[] = 'Content-Type: text/' . $subtype . '; charset=' . $this->charset;
        $headers[] = 'Content-Transfer-Encoding: base64';

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->base64Wrap($content);
    }

    private function buildAttachmentPart(array $att): string
    {
        $lines   = [];
        $lines[] = 'Content-Type: ' . $att['mime'] . ';' . $this->filenameParam('name', $att['name']);
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = 'Content-Disposition: attachment;' . $this->filenameParam('filename', $att['name']);

        return implode("\r\n", $lines) . "\r\n\r\n" . $this->base64Wrap($att['data']);
    }

    /**
     * 文件名参数。
     *
     * 纯 ASCII 直接写引号串；含非 ASCII 时只写 RFC 2231 的
     * `filename*=UTF-8''<百分号编码>`，不再附带一个 ASCII 兜底值。
     *
     * 为什么不两个都写：同时出现 `filename=` 与 `filename*=` 时各客户端的取舍不一致
     * （解析器实现不同，有的取前者），结果是文件名被兜底值覆盖。只留 `*=` 形式没有歧义。
     * 也不要用 RFC 2047 的 encoded-word：`filename="=?UTF-8?B?...?="` 不是合法用法，
     * 多数客户端会把 "?=" 原样当文件名显示。
     */
    private function filenameParam(string $param, string $name): string
    {
        $name = self::stripCrlf($name);
        if ($name === '') {
            $name = 'attachment';
        }
        if (!preg_match('/[^\x20-\x7E]/', $name)) {
            return ' ' . $param . '="' . addcslashes($name, '"\\') . '"';
        }

        // 保持 rawurlencode 的 %20：部分客户端会把未转义的空格当成参数分隔符，
        // 宁可全部转义，也不要去猜对方的解析器。
        return ' ' . $param . "*=UTF-8''" . rawurlencode($name);
    }

    /** 地址格式化：有显示名时做 RFC2047 编码 */
    private function formatAddress(string $email, string $name = ''): string
    {
        if ($name === '') {
            return $email;
        }
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    /**
     * RFC2047 B 编码，按字节分片避免拆断多字节字符
     */
    private function encodeHeader(string $value): string
    {
        // 所有拼进头部的值都在这里兜底清洗：Subject / From 显示名 / 自定义头
        // 只要含 CR/LF 就换成空格，杜绝应用层漏校验时的头注入。
        $value = self::stripCrlf($value);
        if ($value === '') {
            return '';
        }
        // 纯 ASCII 直接返回
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        $chunks  = [];
        $offset  = 0;
        $length  = strlen($value);
        while ($offset < $length) {
            $chunk = function_exists('mb_strcut')
                ? mb_strcut($value, $offset, 30, 'UTF-8')
                : substr($value, $offset, 30);
            $offset += strlen($chunk);
            if ($chunk === '') {
                break;
            }
            $chunks[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        // 多个编码片段之间用 CRLF + 空格折叠
        return implode("\r\n ", $chunks);
    }

    private function base64Wrap(string $data): string
    {
        return rtrim(chunk_split(base64_encode($data), 76, "\r\n"));
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
        $text = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);
        return trim((string) $text);
    }

    private function normalizeCrlf(string $data): string
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        return str_replace("\n", "\r\n", $data);
    }

    private function messageIdHost(): string
    {
        $host = parse_url('http://' . ($this->fromEmail ? explode('@', $this->fromEmail)[1] ?? '' : ''), PHP_URL_HOST);
        return $host ?: 'zc-mail.local';
    }
}
