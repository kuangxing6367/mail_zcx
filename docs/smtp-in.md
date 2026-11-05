# SMTP 入站（用 25 端口收信）

这个页面讲的是：**让别的系统用标准 SMTP 把信投给你**，而不是让它改代码调 HTTP API。

## 先分清两件事

这两个「25 端口」经常被混为一谈，但它们完全不同：

| | 出站 25（发信出去） | 入站 25（用 25 收信） |
|---|---|---|
| 含义 | 你的服务器连别人的 25 端口投递 | 别人的服务器连你的 25 端口投递 |
| 常见问题 | 云厂商默认封禁，防止垃圾邮件 | 需要放行安全组、域名通常要备案 |
| 本项目怎么办 | **不用管**：出站默认走 465/587，不受 25 封禁影响 | 本页讲的内容 |
| nginx 能做吗 | **不能**。TCP 层被封，应用层转发无解 | **能**，用 stream 模块中转 |

如果你遇到的是「发不出去信、报 connection timeout」，那是出站问题：到控制台「邮箱管理」把 `smtp_port` 改成 465（ssl）或 587（tls）即可，跟本页无关。

如果你遇到的是「老系统只会填 SMTP 服务器地址，不会调 HTTP」，那就是本页要解决的。

## 它怎么工作

```
老系统 / 打印机 / NAS
        │  标准 SMTP（25 或 587）
        ▼
    nginx stream            ← 只做 TCP 转发 + 带真实 IP
        │  127.0.0.1:2525
        ▼
   cli/smtpd.php            ← 收信、鉴权、落库、入队
        │
        ├─→ zc_mail_logs     （pending）
        └─→ zc_send_jobs     （pending）
                │
                ▼
        cli/worker.php      ← 与你用 HTTP API 时**完全同一条**投递链路
                │  SMTP 465/587
                ▼
            收件人邮箱
```

也就是说：SMTP 入站只是多了一个「收信口」，收进来之后的选箱、模板、日志、统计、Webhook 回调，跟 HTTP API 一模一样。

## 快速开始

### 1. 开启入站

在 `config/config.local.php` 里加：

```php
return [
    'smtp_in' => [
        'enabled'  => true,
        'hostname' => 'mail.example.com',   // 建议填真实发信域名
        'port'     => 2525,                 // 内部端口，不用改
    ],
];
```

### 2. 确认有可用的发件邮箱

```bash
mysql -u你的用户 -p 库名 -e "select id,email,status from zc_mailboxes;"
```

没有就在控制台「邮箱管理」里加一个。SMTP 入站发信时用的是这里配置的邮箱，**不是你客户端填的那个地址**（原因见下方「为什么必须重写 From」）。

### 3. 启动入站进程

```bash
cd /www/wwwroot/你的站点
/www/server/php/82/bin/php cli/smtpd.php --verbose
```

看到这行就算起来了：

```
SMTP 入站已启动 tcp://127.0.0.1:2525 hostname=mail.example.com auth=required tls=off fork=yes
```

先别关终端，另开一个窗口用 `telnet` 试一下：

```bash
telnet 127.0.0.1 2525
```

应该立刻收到 `220 mail.example.com ESMTP ZC Mail`。收到就说明进程正常，可以按 `Ctrl+]` 再输入 `quit` 退出。

### 4. 确认 worker 在跑

SMTP 收进来的信是**入队**的，必须有人投递：

```bash
/www/server/php/82/bin/php cli/worker.php
```

没跑 worker 的话，邮件会一直停在 `pending`。

### 5. 把 25 端口转进来

生产环境**不要**让 PHP 直接监听 25。原因：

- 1024 以下端口需要 root，让 PHP 以 root 跑是安全事故；
- 端口被占用时没法优雅切换；
- 做不了连接数控制、限流、多进程负载均衡。

正确做法是 nginx `stream` 模块转发，见下一节。

## nginx 中转（25 / 587）

完整示例见仓库 `deploy/nginx-smtp-stream.conf.example`。最小可用配置：

```nginx
stream {
    upstream zc_mail_smtpd {
        server 127.0.0.1:2525;
    }

    server {
        listen 25;
        proxy_pass zc_mail_smtpd;
        proxy_protocol on;          # 传真实客户端 IP
        proxy_connect_timeout 5s;
        proxy_timeout 300s;
    }

    server {
        listen 587;
        proxy_pass zc_mail_smtpd;
        proxy_protocol on;
        proxy_connect_timeout 5s;
        proxy_timeout 300s;
    }
}
```

三个容易踩的点：

1. **`stream { }` 必须写在 `nginx.conf` 的 `http { }` 外面**。宝塔的站点配置文件整个都在 `http` 块里，把 stream 写进去会直接 `nginx -t` 报错。正确做法是在 `nginx.conf` 末尾加：

   ```nginx
   stream {
       include /www/server/nginx/conf/stream/*.conf;
   }
   ```

2. **nginx 需要 stream 模块**。检查：

   ```bash
   nginx -V 2>&1 | grep -o with-stream
   ```

   没有输出就装：`apt install libnginx-mod-stream`（Ubuntu/Debian）或 `yum install nginx-mod-stream`（CentOS）。

3. **开了 `proxy_protocol on` 就必须让后端也开**。否则后端会把 `PROXY TCP4 ...` 这一行当成 SMTP 命令，直接回 `500`。在 `config.local.php` 里确认：

   ```php
   'smtp_in' => ['proxy_protocol' => true],
   ```

改完验证：

```bash
nginx -t && nginx -s reload
ss -lntp | grep ':25 '
```

然后从另一台机器 `telnet 你的域名 25`，应该同样收到 `220`。

### 465 端口（隐式 TLS）

`cli/smtpd.php` 只实现了 STARTTLS（明文端口升级），没有实现「连上就 TLS」。

老设备如果不支持 STARTTLS、只会连 465，可以让 nginx 在 TCP 层做 TLS 终止：

```nginx
server {
    listen 465 ssl;
    ssl_certificate     /path/fullchain.pem;
    ssl_certificate_key /path/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    proxy_pass zc_mail_smtpd;
    proxy_protocol on;
}
```

客户端以为连的是加密 SMTPS，实际到后端是本机回环上的明文，安全性没有损失。

## 客户端怎么配

| 字段 | 填什么 |
|---|---|
| SMTP 服务器 | 你的域名（如 `mail.example.com`） |
| 端口 | 25 或 587（要加密就 465，需按上节配 nginx 终止 TLS） |
| 加密 | 587 用 STARTTLS；25 不加密也可（内网）；465 用 SSL |
| 用户名 | 任意（除非你配了 `auth_username` 固定值） |
| 密码 | **控制台「API 密钥」页里的 API Key** |
| 发件人 | 随便填；会被重写成你配置的邮箱（见下） |
| 收件人 | 正常填，支持多个 |

因为认证用的就是 API Key，所以**同一个凭据既能调 HTTP API 也能发 SMTP**，不用维护两套账号。

## 认证与安全

```php
'smtp_in' => [
    'auth_required'   => true,      // 生产必须 true
    'auth_username'   => '',        // 空=任意用户名；填了则必须完全一致
    'allow_ips'       => [],        // 入站 IP 白名单，支持 192.168.1.*
    'max_connections' => 20,        // 瞬时并发会话上限，超了回 421
    'max_size_mb'     => 10,        // 单封信上限
    'max_recipients'  => 50,        // 单封最大收件人数
],
```

几个必须知道的边界：

- **不校验发件人。** 任何通过认证的客户端都能指定任意 `MAIL FROM`。这是一台「认证中继」，不是开放中继——但如果你把 API Key 泄露了，对方就能用它发信。密钥按密码管理。
- **默认只监听 127.0.0.1。** 不要改成 `0.0.0.0` 直接暴露，除非你清楚在做什么。
- **不做来源域名信誉校验、不做 SPF/DKIM 验证。** 入站是「可信客户端投递」模型，不是公网 MTA。
- **单封上限受数据库字段限制。** `zc_send_jobs.payload` 是 `MEDIUMTEXT`（16MB），而作业载荷是 JSON，换行会被转义，所以 `max_size_mb` 建议不超过 10。

## 为什么必须重写 From

客户端填的 `From: legacy@corp.local`，实际投递时用的是你配置的 `noreply@example.com`。

原因很直接：QQ 邮箱、163、Gmail 这些服务商会校验「 envelope sender 是否属于这个账号」。你不重写，对方收到的信里 `From` 是一个它不认识的域名，SPF/DMARC 对不上，**基本必被拒**（`550`/`554`）。

默认行为：

- `From` 头重写为选中的发件邮箱（`smtp_in.rewrite_from = true`）
- 原发件人写进 `Reply-To`，保留回信线索（`smtp_in.reply_to_original = true`）
- `Bcc` 头被剥离（SMTP 投递本来就不该带，避免泄露抄送人）
- 正文、附件、其他头**原样保留**，不重新组包

最后这条很重要：入站邮件是直接透传原始 MIME 的，不做「解析成 html/text 再重组」。因为重组会丢掉 multipart 结构、破坏编码，对带附件的报表类邮件是破坏性的。

如果你确实需要保留原 `From`（比如你自己掌控了发信域名的 SPF），在 `config.local.php` 里关掉：

```php
'smtp_in' => ['rewrite_from' => false],
```

但先确认你的 SMTP 服务商允许这么做。

## 与 HTTP API 的对照

| | HTTP API | SMTP 入站 |
|---|---|---|
| 适用方 | 能改代码的系统 | 只能填 SMTP 地址的老系统 |
| 接口 | `POST /api/v1/send` | `MAIL FROM` / `RCPT TO` / `DATA` |
| 认证 | `X-API-Key` 头 | AUTH PLAIN / LOGIN，密码=API Key |
| 返回 | `202 queued` + `log_id` | `250 Ok: queued as <log_id>` |
| 模板 | 支持 `template` + 变量 | 不支持（客户端自己组信） |
| 附件 | base64 内联 | 原生 MIME |
| 日志 / 统计 / Webhook | 有 | 完全一样 |

模板是 HTTP API 独有的：SMTP 协议本身不传「用哪个模板」，客户端发什么就是什么。

## 排错

| 现象 | 原因 | 处理 |
|---|---|---|
| 连上没反应，5 秒后才收到 220 | `proxy_protocol=true` 但你是直连（不是经 nginx） | 直连测试时设 `proxy_protocol=false` |
| 收到 `500 5.5.1 Command unrecognized` | 后端把 `PROXY TCP4 ...` 当命令了 | 两边 `proxy_protocol` 要一致：nginx 开了后端必须开 |
| `530 5.7.0 Authentication required` | 客户端没做 AUTH 就 MAIL FROM | 客户端要开「需要认证」，密码填 API Key |
| `535 5.7.8 Authentication failed` | API Key 不对 / 被禁用 / 已过期 | 控制台「API 密钥」里核对状态 |
| `250 queued` 但收不到信 | worker 没跑 | `php cli/worker.php`，或查 `zc_send_jobs` 的 `status` |
| 客户端报 `connection refused` | smtpd 没启动 / 端口不对 | `ss -lntp \| grep 2525` |
| 日志里来源 IP 全是 127.0.0.1 | nginx 没开 `proxy_protocol on` | 开了之后后端也要开 |
| `552 5.3.4 Message size exceeds fixed limit` | 超过 `max_size_mb` | 调大配置，注意别超 10MB（见上） |

查队列当前状态：

```bash
mysql -u用户 -p 库名 -e "
  select id, log_id, status, attempts, last_error
  from zc_send_jobs order by id desc limit 10;"
```

`status` 为 `pending` 且 `next_attempt_at` 是过去时间 → worker 没在跑。
`status` 为 `processing` 但 `reserved_at` 很久没变 → worker 崩了，等 `stuck_timeout` 秒后会自动回收成 `pending`。
