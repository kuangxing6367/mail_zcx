# 设计说明

本文记录 ZC Mail Service 的关键设计取舍，供二次开发与代码审查参考。

## 总体结构

```
请求 → public/index.php（引导 / 鉴权 / 分发）
         ├── Core/       框架能力：配置、数据库、请求响应、路由、鉴权、加密、日志
         ├── Mail/       协议能力：SMTP 客户端、MIME 构建、发信编排
         ├── Service/    业务能力：邮箱、模板、日志、Webhook、API Key
         └── Controller/ 接口层：参数校验与响应组装
```

没有容器、没有事件总线、没有 ORM。所有依赖通过构造函数或静态入口显式获取，读代码即可掌握全貌。
这样做的代价是扩展性有限，收益是可审计 —— 对一个处理邮箱凭据的服务来说，后者更重要。

## 为什么不用 PHPMailer

需求集中在三件事：多邮箱切换、模板渲染、投递结果留存。PHPMailer 能覆盖发信，但：

- 引入 composer 后部署链路变长（很多虚拟主机不给 composer）；
- 多邮箱、配额、选箱属于本项目自己的业务逻辑，与 PHPMailer 无交集；
- 协议层只有 300 行（`SmtpClient.php`），可控且容易排查线上问题。

`SmtpClient` 支持 465（隐式 TLS）、587（STARTTLS）、25（明文），以及 AUTH LOGIN / PLAIN。
`MimeBuilder` 处理 multipart/alternative、附件 base64、中文 RFC 2047 编码。

## 选箱与并发

默认策略 `least_sent`：按 `sent_total` 升序取一个 `status = 1` 且未超 `daily_limit` 的邮箱。

关键点在事务：

```sql
START TRANSACTION;
SELECT ... FROM zc_mailboxes
 WHERE status = 1 AND (daily_limit = 0 OR sent_today < daily_limit)
 ORDER BY sent_total ASC LIMIT 1
 FOR UPDATE;
UPDATE zc_mailboxes SET sent_today = sent_today + 1, ... WHERE id = ?;
COMMIT;
```

`FOR UPDATE` 保证并发请求不会同时选中同一行，等价于悲观锁实现的负载均衡。
所有邮箱都超限时直接返回业务错误，不会退化成无限重试。

`daily_limit` 的跨天重置基于 `quota_date`：命中新日期时把 `sent_today` 归零，不需要定时任务。

## 凭据加密

SMTP 密码使用 AES-256-GCM（`openssl_encrypt`），密钥由 `app_secret` 经 HKDF 派生，
随机 IV 与 tag 一并存入数据库。输出格式：

```
base64(iv) . '.' . base64(tag) . '.' . base64(ciphertext)
```

GCM 自带完整性校验，密文被篡改会解密失败而非静默出错。
`app_secret` 是唯一的根密钥，换掉它等于让所有已存密码失效 —— 这是刻意的：不提供静默回退。

## Webhook 签名

出站请求体为 `{"event":"success|failed","data":{...}}`，签名头：

```
X-ZC-Signature: sha256=<hex(hmac_sha256(raw_body, secret))>
```

接收方必须对**原始字节**计算 HMAC（不要先 `json_decode` 再 `json_encode`，字段顺序与空格会变）。
签名密钥来自 Webhook 配置的 `secret`，为空时不签名 —— 生产环境应当强制配置。

投递是异步的：响应返回后通过 `fastcgi_finish_request()` 继续执行，失败按配置重试，
每次尝试都写入 `zc_webhook_deliveries`，含 HTTP 状态码与响应体前若干字节。

## 鉴权与限流

`Auth::authenticate()` 依次检查：

1. 从 `X-API-Key` / `X-ZC-Key` / `?api_key=` / `Authorization: Bearer` 提取 Key；
2. 查库校验 `status`、`expires_at`；
3. 匹配 `allow_ips`（支持 `192.168.1.*` 通配）与全局 `security.allow_ips`；
4. 按 `rate_limit` 做分钟级计数（文件缓存，键为 key_id + 分钟）。

失败统一返回 401 + 明确原因，不区分「Key 不存在」与「Key 禁用」，避免枚举。

## 错误码约定

| code | 场景 |
|---|---|
| `0` | 成功 |
| `400` | 参数缺失或格式错误 |
| `401` | 鉴权失败 |
| `403` | 权限不足 |
| `404` | 资源或接口不存在 |
| `1001` | SMTP 投递失败，详情在 `data.error` |
| `500` | 未捕获异常 |

所有响应都带 `request_id`（可由调用方通过 `X-Request-Id` 指定），日志与发送记录串联同一个 ID。

## 并发与阻塞模型

> 本节只描述**当前实现的请求时序与阻塞边界**：一个 HTTP 请求会同步等待哪些 I/O、超时怎么算、
> 哪些环节会占用 PHP worker。不涉及功能清单，也不提供压测数据或吞吐量数字。
> 结论来自 PHP 同步 I/O 模型与本地实测，不是性能基准。

### 发信请求是同步的

`POST /api/v1/send` 在**同一个请求内**跑完整条链路：

```
选箱(DB 事务) → MIME 构建 → connect → EHLO/STARTTLS → AUTH
→ MAIL FROM → RCPT → DATA → QUIT → 写日志 → 触发 Webhook → 返回 JSON
```

调用方的 HTTP 连接一直等到 SMTP 服务器应答。SMTP 慢、端口被封、DNS 不通，这个请求就慢。
这是设计取舍：事务邮件要求**立即拿到投递结果**（成功/失败、SMTP 响应码），所以默认不入队。

### 两层超时

| 配置 | 默认 | 含义 |
|---|---|---|
| `mail.timeout` | 15s | **单步**连接 / 读写超时 |
| `mail.total_timeout` | 30s | **整条会话**总时限，`0` = 不限 |

一次会话含 6 次以上网络往返。只有单步超时时，最坏情况 `15s × N` 会把请求挂到分钟级。
`total_timeout` 在每次读取前按剩余时间重设 `stream_set_timeout`，使总闸覆盖整条链路。

> 本地实测：单步 15s、总闸 2s、对端接受连接后不响应 → **2.02s** 抛出
> `SMTP 读取超时（本次等待 2s）`。

### Webhook 的「异步」有边界

`webhook.async = true` 用 `register_shutdown_function` + `fastcgi_finish_request()`：

- 调用方能立刻拿到响应 ✅
- **PHP-FPM worker 仍被占用**到回调跑完 ❌ —— 并发高时 worker 池会被 drain 光
- 多个 target 串行投递，含 `retry` 次重试与 `usleep` 递增退避，单 worker 最坏占用
  `webhook.timeout × (retry + 1)` 秒
- 非 FPM 环境（`php -S` / `mod_php` / CGI）没有 `fastcgi_finish_request`，
  shutdown function 会在响应发送**之前**执行 → 退化成完全同步阻塞

> 本地实测：`php -S` 下 1.5s 的回调把响应耗时拖到 **1.517s**。

实现按环境显式分支，不再伪装异步：

| 环境 | 行为 | 配置 |
|---|---|---|
| PHP-FPM | 响应后执行，仍占 worker | `webhook.async = true` |
| 非 FPM | 显式同步回调并记 warning（保证回调不丢） | `webhook.async_fallback = sync`（默认） |
| 非 FPM | 直接丢弃回调，绝不让请求被拖住 | `webhook.async_fallback = skip` |
| 任意 | 完全同步投递 | `webhook.async = false` |

### 想彻底不堵

需要把发信与回调都改成「**入队 + 后台 worker**」：请求只写库立即返回 `202`，
独立 CLI 进程消费队列、投递、重试。代价是调用方拿不到即时 SMTP 结果，
需改为按 `log_id` 查询或靠 Webhook 接收最终状态。这是架构决策，当前版本未实现。

### 不改架构的缓解手段

- 调低 `mail.total_timeout`（如 10s），并在调用方设客户端超时
- 调小 `webhook.timeout`、`retry` 设为 0
- **生产务必用 PHP-FPM**，不要用 `php -S`
- 调大 `pm.max_children`，并给发信接口单独限流（`security.rate_limit`）

## 已知边界

- 不支持 IMAP 收信，只做发信与状态回调；
- **发信与 Webhook 均为同步阻塞模型**，详见上节「并发与阻塞模型」；
- 附件走请求体内联 base64，受 `max_attachment_mb` 限制，大文件场景应改用对象存储 + 下载链接；
- 限流计数基于本地文件，多实例部署时需要换成 Redis 等共享存储；
- 未实现多租户隔离，一套部署对应一组业务方。

## 后续方向

- 模板版本管理与灰度；
- 退信（bounce）解析；
- 发送队列与后台重试（当前同步发送，适合低频事务邮件）。
