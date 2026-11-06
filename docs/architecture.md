# 设计说明

本文记录 ZC Mail Service 的关键设计取舍，供二次开发与代码审查参考。

## 总体结构

```
请求 → public/index.php（引导 / 鉴权 / 分发）
         ├── Core/       框架能力：配置、数据库、请求响应、路由、鉴权、加密、日志
         ├── Mail/       协议能力：SMTP 客户端、MIME 构建、发信编排
         ├── Service/    业务能力：邮箱、模板、日志、Webhook、API Key、发信队列
         └── Controller/ 接口层：参数校验与响应组装
```

没有容器、没有事件总线、没有 ORM。所有依赖通过构造函数或静态入口显式获取，读代码即可掌握全貌。
这样做的代价是扩展性有限，收益是可审计 —— 对一个处理邮箱凭据的服务来说，后者更重要。

### 配置分三层

```
config.php              内置默认值，随代码走，不含任何凭据
config.local.php        服务器手写，含 DB 密码与 app_secret，不入库
config.overrides.php    控制台「系统设置」生成，不入库，优先级最高
```

后加载的层层 `array_replace_recursive` 覆盖前面的。分三层的理由是：

- 网页端改配置时**不能重写 `config.local.php`** —— 里面常有 `getenv()` 取密钥之类的逻辑，
  整文件 `var_export` 回写会把它变成明文常量，属于安全降级；
- 控制台只写自己的 `config.overrides.php`，字段在白名单内、类型化生成，写前先 `require` 验证再 `rename`；
- 删掉 `config.overrides.php` 就完整回到手写配置，不需要「撤销」概念。

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

> 队列模式下选箱发生在**入队时**（`prepare()`），但选中后会在同一事务内**原子预占**
> `sent_total` 与 `sent_today`，所以并发 / 批量入队不会全部选到同一个邮箱，
> `daily_limit` 也不会被瞬时突破。投递失败时 `recordFailure()` 会把预占退回。
> 语义仍是 **at-least-once**：worker 在「SMTP 已投递、尚未 markDone」之间崩溃，会重投一次。

## 凭据加密

SMTP 密码使用 AES-256-GCM（`openssl_encrypt`），密钥为 `sha256(app_secret)` 的 32 字节原始摘要。
随机 IV、tag 和密文拼成**一个** base64 串，整体带 `enc:` 前缀：

```
enc:<base64(iv[12] | tag[16] | ciphertext)>
```

GCM 自带完整性校验，密文被篡改会解密失败而非静默出错。

根密钥按优先级取：`security.app_secret` → `storage/app_secret.local`（首次运行自动生成，
每台机器一份，0600，已 gitignore）→ 出厂常量（仅用于解开历史数据，不再参与加密）。
所以**留空配置也是安全的**：不会出现「所有实例共用一把公开密钥」。
换掉自定义的 `app_secret` 后旧密码解不开，解密时会依次尝试候选密钥并记录 warning，
需要重新录入授权码 —— 不提供无限期的静默回退。

## Webhook 签名

出站请求体为 `{"event":"success|failed","data":{...}}`，签名头：

```
X-ZC-Signature: sha256=<hex(hmac_sha256(raw_body, secret))>
```

接收方必须对**原始字节**计算 HMAC（不要先 `json_decode` 再 `json_encode`，字段顺序与空格会变）。
签名密钥来自 Webhook 配置的 `secret`，为空时不签名 —— 生产环境应当强制配置。

投递失败按配置重试。**每个 target 一条** `zc_webhook_deliveries` 记录，重试时累加 `attempts`、
更新最后一次的 HTTP 状态码与响应体前若干字节（不是每次尝试新增一行）。
队列模式下 Webhook 由 worker 进程触发，不占用任何 Web 请求的 worker。

## 鉴权与限流

`Auth::authenticate()` 依次检查：

1. 只从请求头提取 Key：`X-API-Key` / `X-ZC-Key` / `Authorization: Bearer`（不再接受 `?api_key=`，避免进访问日志与 Referer）；
2. 按 `sha256(key)` 查 `api_key_hash` 比对 —— 库中不存明文，老库里的明文在首次成功鉴权时自动迁移并替换为脱敏串；
3. 校验 `status`、`expires_at`、Key 级 `allow_ips`（支持 `192.168.1.*` 与 `10.0.0.0/8`）以及全局 `security.allow_ips`；
4. 按 Key 的 `scopes` 做**路由级授权**：`send` / `logs` / `mailboxes` / `templates` / `webhooks` / `keys`，`*` 表示全部。旧 Key 默认 `*`；
5. 按 `rate_limit` 做分钟级计数（文件 + `flock` 原子递增，旧文件自动清理）；
6. 同一 IP 在 `auth_fail_window` 内失败达到 `auth_fail_limit` 次后临时锁定。

所有鉴权失败对外统一返回 `API Key 无效`，具体原因（不存在 / 禁用 / 过期 / IP 不符）只写服务端日志，避免枚举。
客户端 IP 只在直连方命中 `security.trusted_proxies` 时才采信 `X-Forwarded-For`，否则一律用 `REMOTE_ADDR`。

## 错误码约定

| code | 场景 |
|---|---|
| `0` | 成功（含入队成功） |
| `400` | 参数缺失或格式错误 |
| `401` | 鉴权失败 |
| `403` | 权限不足 |
| `404` | 资源或接口不存在 |
| `1001` | 同步模式下 SMTP 投递失败，详情在 `data.error` |
| `500` | 未捕获异常 |

所有响应都带 `request_id`（可由调用方通过 `X-Request-Id` 指定），日志与发送记录串联同一个 ID。

## 发信执行模型

`POST /api/v1/send` 有两种执行模式，由 `mail.queue.enabled` 切换。
**部署模板里默认 `false`（同步）**，因为虚拟主机占多数；`config.php` 的内建默认是 `true`，
只代表「能力上限」。两种模式共用同一条 MIME 构建与日志链路，区别只在谁来发起 SMTP 会话。

### 可选：入队异步（`enabled = true`）

请求只做「无网络 IO 的准备 + 落库」，立即返回 `202`：

```
解析/渲染模板 → 选箱(DB 事务) → 构建 MIME → 写 mail_logs(pending) → 写 send_jobs(pending)
→ 返回 202 { status: "queued", log_id, check_url }
```

SMTP 投递、失败重试、Webhook 回调由独立进程 `cli/worker.php` 完成：

```
claim(send_jobs, FOR UPDATE) → connect/EHLO/STARTTLS/AUTH → MAIL/RCPT/DATA → QUIT
→ 回写 mail_logs(success/failed) → 邮箱计数 → 触发 Webhook → 标记 job done/failed
```

请求耗时与 SMTP 服务器无关，只取决于 DB 写入（毫秒级）。代价是调用方拿不到即时投递结果，
需要 `GET /api/v1/logs/{log_id}` 查询，或配置 `callback_url` / 全局 Webhook 接收终态。

> 这一模式要求 **worker 常驻运行**。只开队列不跑 worker，邮件会一直停在 `pending`。

### 状态机

| 表 | 字段 | 值 |
|---|---|---|
| `zc_mail_logs` | `status` | `pending` → `success` / `failed` |
| `zc_send_jobs` | `status` | `pending` → `processing` → `done` / `failed` |

`send_jobs` 的并发安全靠 InnoDB 行锁：worker 在事务内 `SELECT ... FOR UPDATE` 取一批
`pending` 且到期的任务，再批量置为 `processing`。多个 worker 不会重复认领同一任务。

`processing` 超过 `mail.queue.stuck_timeout`（默认 300s）视为 worker 崩溃，
`reclaim()` 会把它放回 `pending`，避免任务永久卡死。

### 重试与投递语义

- 失败按 `retry_base * 2^(n-1)` 退避重试，默认最多 3 次（含首次）。
- 用尽重试后 `job.status = failed`，同时把 `mail_logs.status` 写为 `failed`，触发 `failed` Webhook。
- 语义是 **at-least-once**：若 worker 在「SMTP 已投递、尚未 markDone」之间崩溃，任务会被回收并重投，
  极端情况下收件人会收到重复邮件。验证码/通知类邮件可接受；要求严格幂等的场景应在业务侧用
  `request_id` 去重。

### 超时

| 配置 | 默认 | 含义 |
|---|---|---|
| `mail.timeout` | 15s | **单步**连接 / 读写超时 |
| `mail.total_timeout` | 30s | **整条会话**总时限，`0` = 不限 |
| `mail.queue.stuck_timeout` | 300s | 认领后超时回收阈值，应大于 `total_timeout × max_attempts` |

`total_timeout` 在每次读取前按剩余时间重设 `stream_set_timeout`，避免 `15s × N` 挂到分钟级。
本地实测：单步 15s、总闸 2s、对端不响应 → 2.02s 抛出。

### 同步模式（`enabled = false`，部署模板默认）

回退到旧行为：单请求内完成整条 SMTP 会话，直接返回投递结果。适合低频、强一致性要求、
且不方便常驻 worker 的环境。此时两层超时与下述 Webhook 边界仍然适用。

### Webhook 的「异步」边界（仅同步模式相关）

`webhook.async = true` 用 `register_shutdown_function` + `fastcgi_finish_request()`：

- 调用方能立刻拿到响应 ✅
- **PHP-FPM worker 仍被占用**到回调跑完 ❌
- 非 FPM 环境（`php -S` / `mod_php` / CGI）没有 `fastcgi_finish_request`，
  会退化成完全同步阻塞

队列模式下不存在这个问题：`cli/worker.php` 启动时把 `webhook.async` 设为 `false`，
回调由 worker 进程串行执行，不占 Web 请求的 worker。

| 环境 | 行为 | 配置 |
|---|---|---|
| 队列 worker | 同步回调，由独立进程执行 | worker 内固定 `async = false` |
| FPM 同步模式 | 响应后执行，仍占 worker | `webhook.async = true` |
| 非 FPM 同步模式 | 显式同步并记 warning，保证不丢 | `webhook.async_fallback = sync`（默认） |
| 任意同步模式 | 完全同步投递 | `webhook.async = false` |

## 已知边界

- 不支持 IMAP 收信，只做发信与状态回调；
- 队列为 **at-least-once**，极端情况下可能重复投递，见「重试与投递语义」；
- 队列把 MIME 原文存进 `zc_send_jobs.payload`（MEDIUMTEXT），大附件会放大存储；大文件建议改用对象存储 + 下载链接；
- 附件走请求体内联 base64，受 `max_attachment_mb` 限制；
- 限流计数基于本地文件，多实例部署时需要换成 Redis 等共享存储；
- 未实现多租户隔离，一套部署对应一组业务方。

## 后续方向

- 模板版本管理与灰度；
- 退信（bounce）解析；
- 队列任务的可视化重放与死信管理。
