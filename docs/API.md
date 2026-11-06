# ZC Mail Service · API 文档

- **Base URL**：`https://mail.example.com/api/v1`（替换为你自己的部署域名）
- **鉴权**：请求头 `X-API-Key: <key>`（也支持 `X-ZC-Key`、`Authorization: Bearer`；出于安全考虑不再接受 `?api_key=`）
- **入参**：`application/json`、`application/x-www-form-urlencoded`、`multipart/form-data` 均可
- **方法覆盖**：表单或 JSON 中传 `_method=PUT` 可让 POST 走 PUT 路由
- **统一响应**：

```json
{ "code": 0, "msg": "ok", "data": {}, "request_id": "a1b2c3d4e5f6", "time": 1791895838 }
```

| code | 含义 |
|---|---|
| `0` | 成功 |
| `400` | 参数错误 |
| `401` | 鉴权失败（缺 Key / Key 无效 / IP 不在白名单 / 超限） |
| `403` | 无权限 |
| `404` | 资源不存在 / 接口不存在 |
| `405` | 请求方法不允许 |
| `413` | 请求体超过 `security.max_body_bytes`（默认 16MB） |
| `1001` | 邮件发送失败（SMTP 层错误，详见 `data.error`） |
| `500` | 服务器内部错误 |

> 除 `/api/v1/hook/inbound` 与 `/health` 外，所有接口都需要 API Key。
>
> Key 只从请求头读取：`X-API-Key` / `X-ZC-Key` / `Authorization: Bearer`。
> URL 传参不会生效（避免进 access log 与 Referer）。
> 含 `*` 的值一律拒绝——那是 `keys` 列表里的脱敏展示串，不能当凭证用。
>
> 所有 API 响应带 `Cache-Control: no-store`，中间缓存不会留存发信内容与日志。

---

## 1. 发信

### `POST /send`

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `to` | string \| array | ✅ | 收件人。支持 `"a@b.com"`、`"a@b.com,c@d.com"`、`["a@b.com"]`、`[{"email":"a@b.com","name":"张三"}]`、`"张三 <a@b.com>"` |
| `cc` / `bcc` | 同上 | | 抄送 / 密送 |
| `subject` | string | | 主题。用 `template` 时可省略（取模板主题）。**不能含 CR/LF 换行**（防止邮件头注入，含换行返回 `400`），模板变量替换后同样校验 |
| `html` / `body_html` | string | | HTML 正文 |
| `text` / `body_text` | string | | 纯文本正文。只传 HTML 时自动生成纯文本版本 |
| `template` / `template_code` | string | | 模板标识，与 `variables` 配合 |
| `variables` / `vars` | object | | 模板变量，如 `{"code":"839201"}` |
| `mailbox_id` | int | | 指定发件邮箱 ID |
| `mailbox` / `from` | string | | 指定发件邮箱地址 |
| `from_name` | string | | 发件人显示名（默认取邮箱配置） |
| `reply_to` | string | | 回复地址 |
| `headers` | object | | 附加邮件头 |
| `attachments` | array | | `[{"name":"a.pdf","content":"<base64>","mime":"application/pdf"}]`，也支持 `{"path":"/绝对/路径"}` |
| `callback_url` / `webhook` | string | | 本次发送结果回调地址（按次自定义） |
| `callback_secret` | string | | 本次回调的签名密钥 |

**两种响应模式**

取决于服务端配置 `mail.queue.enabled`：

| 模式 | HTTP | `data.status` | 含义 |
|---|---|---|---|
| **异步队列**（默认） | **202** | `queued` | 已落库入队，SMTP 投递由后台 worker 执行 |
| 同步（队列关闭） | 200 | `success` / `failed` | 请求内完成投递，直接返回最终结果 |

> 异步模式下 `msg` 会给出 `log_id` 与查询地址。投递失败仍会写入 `mail_logs`，
> 不会因为入队成功而丢失；也可通过 `callback_url` 或全局 Webhook 接收终态。

**异步响应（HTTP 202，默认模式）**

```json
{
  "code": 0,
  "msg": "已加入发送队列，请通过 GET /api/v1/logs/12 查询结果或等待 Webhook 回调",
  "data": {
    "log_id": 12,
    "request_id": "5e7cffd91c75f932",
    "status": "queued",
    "check_url": "/api/v1/logs/12"
  }
}
```

**同步成功响应（HTTP 200，队列关闭时）**

```json
{
  "code": 0,
  "msg": "发送成功",
  "data": {
    "log_id": 3,
    "request_id": "5e7cffd91c75f932",
    "status": "success",
    "mailbox_id": 1,
    "from": "zgric@foxmail.com",
    "to": "2765126451@qq.com",
    "cc": "",
    "subject": "Webhook 回调测试",
    "smtp_code": "250",
    "smtp_message": "250 OK: queued as.",
    "error": "",
    "duration_ms": 846,
    "missing_vars": []
  }
}
```

**同步失败响应**（HTTP 仍为 200，业务码非 0）

```json
{
  "code": 1001,
  "msg": "发送失败: SMTP 命令失败 [AUTH PLAIN ...] 期望 235 实际 535: 535 Authentication failed",
  "data": { "status": "failed", "error": "...", "log_id": 4 }
}
```

`missing_vars` 会列出模板中未被赋值的变量名，便于排查。

**查询异步发送结果**

```bash
curl -H "X-API-Key: $KEY" $BASE/api/v1/logs/12
```

`data.status` 为 `pending`（排队/投递中）、`success` 或 `failed`；`smtp_code`、`error`、`duration_ms` 在投递完成后写入。

---

## 2. 多邮箱管理

### `GET /mailboxes`

| 参数 | 说明 |
|---|---|
| `page` / `size` | 分页，默认 1 / 20 |
| `keyword` | 按邮箱、备注名、SMTP 主机模糊搜索 |
| `status` | `1` 启用 / `0` 禁用 |

响应中的 `smtp_pass` 一律脱敏为 `************`，另附 `has_password: true`。

### `POST /mailboxes` — 新增

| 参数 | 必填 | 说明 |
|---|---|---|
| `email` | ✅ | 发件邮箱地址 |
| `smtp_host` | ✅ | SMTP 主机或 IP |
| `smtp_port` | | 默认 465 |
| `smtp_secure` | | `ssl` / `tls` / `none`，省略则按端口推断 |
| `smtp_user` | | 留空则用 `email` |
| `smtp_pass` | | 授权码 / 密码，加密存储 |
| `name` | | 备注名 |
| `display_name` | | 发件人显示名 |
| `weight` | | 权重，默认 1 |
| `daily_limit` | | 日限额，0 = 不限 |
| `status` | | 默认 1 |

### `GET /mailboxes/{id}` — 详情

### `PUT /mailboxes/{id}` · `POST /mailboxes/{id}` — 修改
字段同上；`smtp_pass` 传空字符串表示清空密码，不传则保持原值。

### `DELETE /mailboxes/{id}` — 删除

### `POST /mailboxes/{id}/test` — 测试连通性

> 只接受 POST。早期版本允许 `GET` 触发，属于「GET 写操作」，已移除（`GET` 返回 405）。

返回完整 SMTP 会话记录，便于定位问题：

```json
{
  "code": 0,
  "data": {
    "ok": true,
    "message": "SMTP 连接与认证成功",
    "duration_ms": 552,
    "capabilities": ["PIPELINING","SIZE","AUTH","SMTPUTF8","8BITMIME"],
    "session": ["<< 220 ... QQ Mail Server.", ">> EHLO ...", ">> [已隐藏]", "<< 235 Authentication successful"]
  }
}
```

### `POST /mailboxes/{id}/reset-quota` — 重置当日发件计数

---

## 3. 邮件模板

### `GET /templates` — 列表（`page` / `size` / `keyword`）

### `POST /templates` — 新建

| 参数 | 必填 | 说明 |
|---|---|---|
| `code` | ✅ | 模板标识，仅字母数字下划线短横线 |
| `name` | | 模板名称 |
| `subject` | | 主题模板，支持 `{{var}}` |
| `body_html` | | HTML 正文模板 |
| `body_text` | | 纯文本正文模板 |
| `variables` | | 变量说明，如 `{"code":"验证码"}` |
| `status` | | 默认 1 |

变量写法：`{{code}}`、`{{ code }}`、`{{user.name}}`（点号路径）。

### `GET /templates/{id}` — 详情

### `PUT /templates/{id}` · `POST /templates/{id}` — 修改

### `DELETE /templates/{id}` — 删除

### `POST /templates/{id}/preview` — 用库中模板渲染预览

```json
{ "variables": { "code": "123456", "expire": "5" } }
```

### `POST /templates/preview` — 不落库，直接渲染传入内容

```json
{
  "subject": "验证码 {{code}}",
  "body_html": "<p>{{code}}</p>",
  "variables": { "code": "123456" }
}
```

---

## 4. Webhook

### 4.1 出站配置

#### `GET /webhooks` — 列表

#### `POST /webhooks` — 新建

| 参数 | 必填 | 说明 |
|---|---|---|
| `url` | ✅ | 回调地址 |
| `name` | | 名称 |
| `secret` | | HMAC 签名密钥（留空则不签名） |
| `events` | | 触发事件，逗号分隔：`success,failed`，`*` 表示全部 |
| `headers` | | 附加请求头 JSON |
| `retry` | | 失败重试次数，默认 2 |
| `status` | | 默认 1 |

#### `GET /webhooks/{id}` / `PUT|POST /webhooks/{id}` / `DELETE /webhooks/{id}`

#### `GET /webhooks/deliveries` — 投递记录（`log_id` / `status` 筛选）

#### `POST /webhooks/test` — 手动测试投递

```json
{ "url": "https://your.app/hook", "secret": "your_secret" }
```

**回调请求格式**

```
POST <url>
X-ZC-Event: success
X-ZC-Timestamp: 1791895838
X-ZC-Signature: sha256=5cdecf1f...
Content-Type: application/json

{"event":"success","request_id":"5e7cffd91c75f932","timestamp":1791895838,"data":{...}}
```

回调在**响应返回给调用方之后**异步执行，不阻塞发信接口。

### 4.2 入站接收

#### `POST /api/v1/hook/inbound`（**免 API Key**）

> 只接受 POST；`GET` 返回 405，避免被浏览器预取或被动触发写入。

| 参数 | 说明 |
|---|---|
| 任意 JSON body | 原样落库，`event` / `type` 字段作为事件名 |
| `source` | 来源标识（或请求头 `X-ZC-Source`） |
| `forward` | `1` 时二次转发给已配置的出站 webhook |

> 如需签名校验，在 `config.php` 设 `security.inbound_secret`，
> 请求需带 `X-ZC-Signature: sha256=<hmac(rawBody, secret)>`，校验失败返回 400。
>
> **防重放**：body 里带 `timestamp`（Unix 秒）时，与服务器时间相差超过
> `security.timestamp_window`（默认 300 秒）会被拒绝。建议发送方始终带上——
> 不带仍能通过（兼容老调用方），但记 warning，且同一个合法请求可以被无限重放。

#### `GET /hook/events` — 入站事件列表

---

## 5. 日志与统计

### `GET /logs`

| 参数 | 说明 |
|---|---|
| `page` / `size` | 分页 |
| `status` | `success` / `failed` / `pending` |
| `mailbox_id` | 按发件邮箱筛选 |
| `request_id` | 按追踪 ID 筛选 |
| `keyword` | 收件人或主题模糊搜索 |
| `start` / `end` | 时间范围，`YYYY-MM-DD HH:MM:SS` |

### `GET /logs/{id}` — 单条详情

默认不返回 `payload`（原始请求体，可能含验证码/收件人明细）。
需要时加 `?include_payload=1`，且服务端 `log.return_payload` 必须为 `true`。

### `GET /logs/stats?range=today|7d|30d|all`

```json
{
  "code": 0,
  "data": {
    "range": "today",
    "total": 3, "success": 3, "failed": 0, "pending": 0,
    "avg_duration_ms": 965,
    "by_mailbox": [{ "mailbox_id": 1, "from_email": "zgric@foxmail.com", "total": 3, "success": "3" }],
    "by_hour": [{ "hour": "2026-10-13 20:00", "total": 3 }]
  }
}
```

---

## 6. API Key 管理

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/keys` | 列表（key/secret 脱敏，摘要不返回） |
| `POST` | `/keys` | 新建，**明文仅此一次返回** |
| `PUT` / `POST` | `/keys/{id}` | 修改 `name` / `status` / `allow_ips` / `rate_limit` / `expires_at` / `scopes` |
| `DELETE` | `/keys/{id}` | 删除 |
| `POST` | `/keys/{id}/rotate` | 轮换密钥 |

新建参数：`name`、`scopes`、`allow_ips`（如 `1.2.3.4,192.168.1.*,10.0.0.0/8`）、
`rate_limit`（每分钟上限，0=不限）、`expires_at`、可选 `api_key`（自定义时**至少 32 字符**）。

`scopes` 为逗号分隔的权限名，缺省 `*`：

| scope | 覆盖接口 |
|---|---|
| `send` | `/send` |
| `logs` | `/logs`、`/logs/stats`、`/logs/{id}` |
| `mailboxes` | `/mailboxes*` |
| `templates` | `/templates*` |
| `webhooks` | `/webhooks*`、`/hook/events` |
| `keys` | `/keys*` |
| `settings` | `/settings*`（网页端改配置） |
| `plugins` | `/plugins*`（插件启用 / 停用） |

权限不足返回 **403** `当前 API Key 缺少权限: <scope>`（早期版本误用 401，已修正）。
鉴权失败（Key 无效/缺失）才是 401。
数据库只保存 `sha256(api_key)`；`api_key` 字段仅存脱敏串，**丢失后无法找回，只能轮换**。

---

## 7. 系统设置

供控制台「系统设置」页使用。只开放白名单字段，写入 `config/config.overrides.php`，
优先级高于 `config.local.php`；需要 `settings` scope。

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/settings` | 读取可改项、当前生效值、只读项清单 |
| `POST` | `/settings` | 保存（只提交要改的项） |
| `POST` | `/settings/reset` | 删除覆盖文件，恢复 `config.local.php` 的值 |

`GET` 返回结构：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "groups": [
      { "name": "发信与队列", "fields": [
        { "key": "mail.queue.enabled", "label": "异步队列", "type": "bool",
          "value": false, "hint": "...", "restart": "worker", "is_set": null }
      ]}
    ],
    "readonly": { "db.*": "数据库凭据改错会直接连不上…" },
    "file": "/www/wwwroot/mail.zcx/config/config.overrides.php",
    "exists": false,
    "writable": true,
    "writable_note": ""
  }
}
```

`POST` 请求体就是「键 → 值」，键名用点号路径：

```json
{ "mail.queue.enabled": true, "mail.timeout": 15, "security.cron_token": "..." }
```

规则：

- 未在白名单里的键直接 `400`，不会部分写入
- 值按类型校验（布尔 / 整数范围 / 枚举 / 正则），**密钥类字段留空表示不改**，传 `null` 表示清除
- 密钥类（`security.cron_token` 等）读取时只回 `is_set`，不返回明文
- 返回里的 `restart` 含 `worker` 表示这项改动要重启常驻 worker 才生效
- `config` 目录不可写时返回 `400` 并说明进程用户与目录路径

> 不在白名单的项（数据库、`app_secret`、IP 白名单、SMTP 入站）需要手工编辑 `config.local.php`。

---

## 8. 插件管理

全部需要 `plugins` scope。插件文件由 FTP / 面板上传，接口**不提供上传 PHP 的能力**。

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/plugins` | 插件列表 + 启用状态 + 加载错误 + 目录/可写信息 |
| `POST` | `/plugins/{slug}/enable` | 启用，下一个请求生效 |
| `POST` | `/plugins/{slug}/disable` | 停用（不删文件） |
| `POST` | `/plugins/{slug}/reload` | 清除错误状态并失效入口文件 opcache |

`GET /plugins` 返回：

```json
{
  "plugins": [{
    "slug": "hello", "name": "示例插件", "version": "1.0.0",
    "enabled": true, "loaded": true, "error": "",
    "events": [], "path": "/www/wwwroot/mail/plugins/hello"
  }],
  "info": { "dir": ".../plugins", "writable": true, "enabled": true, "loaded": ["hello"] }
}
```

插件加载失败会被自动停用，`error` 里是原因；主程序不受影响。详见[插件开发](/plugins)。

---

## 9. 健康检查

### `GET /health`（免鉴权）

```json
{ "code": 0, "msg": "ok", "data": { "service": "ZC Mail Service", "version": "1.3.0", "time": "2026-10-13T20:49:26+08:00" } }
```

默认不返回 PHP 版本；需要时在 `config.local.php` 设 `app.health_detail = true`。

---

## 10. 入站 Webhook

`POST /api/v1/hook/inbound`（免 API Key，但必须签名）：

- 先在 `config.local.php` 配置 `security.inbound_secret`，**留空时该接口返回 403**；
- 请求头 `X-ZC-Signature: sha256=<hmac_sha256(raw_body, inbound_secret)>`，校验失败返回业务错误；
- 只接受 `POST`（`GET` 返回 405），避免被浏览器预取或 CSRF 触发写入。

---

## 错误排查

| 现象 | 原因与处理 |
|---|---|
| `401 缺少 API Key` | 未带 `X-API-Key` |
| `1001` + `535 Authentication failed` | SMTP 授权码错误（注意 QQ 邮箱需用**授权码**而非登录密码） |
| `1001` + `连接 SMTP 失败` | 主机/端口不通，或安全组未放行出站端口 |
| `1001` + `STARTTLS 握手失败` | 端口与 `smtp_secure` 不匹配（587 配 `tls`、465 配 `ssl`） |
| `没有可用的发件邮箱` | 所有邮箱被禁用或已达 `daily_limit` |
| 404 返回 HTML 而非 JSON | nginx 的 `error_page 404` 拦截，注释掉即可 |
