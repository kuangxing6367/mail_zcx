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

## 已知边界

- 不支持 IMAP 收信，只做发信与状态回调；
- 附件走请求体内联 base64，受 `max_attachment_mb` 限制，大文件场景应改用对象存储 + 下载链接；
- 限流计数基于本地文件，多实例部署时需要换成 Redis 等共享存储；
- 未实现多租户隔离，一套部署对应一组业务方。

## 后续方向

- 模板版本管理与灰度；
- 退信（bounce）解析；
- 发送队列与后台重试（当前同步发送，适合低频事务邮件）。
