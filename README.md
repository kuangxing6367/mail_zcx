# ZC Mail Service

**一个零依赖、可自托管的开源邮件 API 服务。**

用原生 PHP 实现了一套完整的发信基础设施：多邮箱 SMTP 池、模板渲染、HTTP API、Webhook 双向回调、
发送日志与统计，外加一个纯静态的 Web 控制台。不需要 composer，不需要 PHPMailer，也不需要任何外部 SaaS ——
丢到一台有 `pdo_mysql` + `openssl` 的机器上就能跑，数据和授权码全部留在自己手里。

适用场景：注册验证码、登录告警、订单通知、系统告警、批量触达等需要**由自己的服务端可控地发邮件**的业务。

- **源代码**：https://github.com/kuangxing6367/mail_zcx
- **在线文档**：https://mail.zgric.top/ （VitePress 构建，托管于 GitHub Pages，自定义域名）
- **API 手册**：[`docs/API.md`](docs/API.md)
- **问题反馈**：https://github.com/kuangxing6367/mail_zcx/issues

> 文档站与仓库同源维护：`docs/` 下的 Markdown 是唯一事实来源，改文档即改站点。

---

## 这个项目解决什么

自己发邮件时通常会遇到三类麻烦：

1. **单一邮箱容易被限流封禁** —— 支持配置多个发件邮箱，默认按「累计发件数最少」自动选箱，
   天然做负载均衡，并可为每个邮箱单独设置每日上限。
2. **接入第三方发信平台意味着交出授权码和数据** —— SMTP 凭据加密（AES-256-GCM）存在自己的库里，
   调用链路完全自持。
3. **发信过程不可观测** —— 每次投递都落库：SMTP 响应码、错误原因、耗时、来源 IP、原始请求体，
   并可通过 Webhook 异步回调业务侧。

---

## 特性

| 能力 | 说明 |
|---|---|
| **多邮箱管理** | 每个账号独立配置 SMTP 主机 / 端口 / 加密方式（465=SSL、587=STARTTLS、25=明文），密码 AES-256-GCM 加密落库 |
| **智能选箱** | 默认选择**累计发件数最少**的邮箱；也可按权重 / 轮询 / 随机，或显式指定。选箱在事务内 `FOR UPDATE`，并发不重复派单 |
| **HTTP API** | 全 REST 语义，JSON 与表单混合入参，`_method` 可覆盖；统一响应格式与错误码 |
| **API Key 鉴权** | Header / Query / Bearer 三种携带方式，支持 IP 白名单（通配）、分钟级限流与有效期 |
| **邮件模板** | `{{变量}}` 渲染（含点号路径），HTML + 纯文本双版本，未匹配变量可追踪 |
| **Webhook 出站** | 发信结果异步回调，HMAC-SHA256 签名（`X-ZC-Signature`），失败自动重试，投递全程留痕 |
| **Webhook 入站** | 暴露 `/api/v1/hook/inbound` 接收外部事件，可选签名校验与二次转发 |
| **异步队列** | 入队即返回 `202`，worker 后台投递、指数退避重试、卡死任务自动回收 |
| **发送日志** | 收件人、主题、SMTP 响应码、错误、耗时、来源 IP、原始请求体，按状态/关键词检索 |
| **统计报表** | 按日 / 7 天 / 30 天汇总，含按邮箱、按小时的分布 |
| **Web 控制台** | 纯静态单文件，无框架无 CDN，覆盖仪表盘 / 发信 / 邮箱 / 模板 / Webhook / 日志 / 密钥 |

> **发信模型**：默认入队异步 —— `POST /api/v1/send` 立即返回 `202`，SMTP 投递、重试与回调
> 由 `cli/worker.php` 后台执行。也可在配置里关闭队列改回同步。
> 状态机、重试语义与超时见 [设计说明 · 发信执行模型](https://mail.zgric.top/architecture#发信执行模型)。

---

## 技术选型

- **零运行时依赖**：手写 SMTP 客户端（覆盖 465/587/25 与 AUTH）与 MIME 构建器，
  不依赖 composer、PHPMailer、Symfony Mailer。
- **朴素架构**：`public/index.php` 单一入口 + PSR-4 自动加载 + 自研路由，分为
  Core（框架）/ Mail（协议）/ Service（业务）/ Controller（接口）四层。
- **可审计**：没有魔法，所有行为都在约 20 个类里看得见。

```
public/
  index.php               # 唯一入口（前端控制器）
  zc-console/index.html   # WebUI 控制台（纯静态单文件，目录名可改为随机串隐藏）
app/
  Core/                   # Config / Database / Request / Response / Router / Auth / Crypto / Logger
  Mail/                   # SmtpClient / MimeBuilder / Mailer
  Service/                # Mailbox / Template / Log / Webhook / ApiKey
  Controller/
config/
  config.php              # 主配置（可开源，不含凭据）
  config.local.php        # 环境覆盖（真实凭据，不入版本库）
sql/schema.sql            # 建表语句（7 张表）
cli/
  install.php             # 一键建库建表
  test_smtp.php           # SMTP 连通性自测
  mock_smtp.php           # 本地 mock SMTP 服务器（联调用）
routes.php                # 路由表
docs/API.md               # API 手册（文档站源文件）
storage/                  # 日志 / 证书 / 限流缓存
```

---

## 快速开始

### 0. 先看：部署完打开哪个地址？

这个项目**没有传统首页**。打开域名根 `/` 看到的是一段 JSON，不会跳转到任何网页。
控制台是独立的静态文件，必须手动加路径。

| 想打开 | 地址 | 实际会看到 |
|---|---|---|
| 域名根（健康检查） | `https://你的域名/` | `{"code":0,"msg":"ok",...}` JSON，**不是网页、不跳转** |
| **Web 控制台** ✅ | `https://你的域名/zc-console/` | 登录界面，粘贴 API Key 进入 |
| API | `https://你的域名/api/v1/mailboxes` | JSON；不带 Key 返回 401 |
| 在线文档 | `https://mail.zgric.top/` | VitePress 文档站 |

**两个目录别搞混**：命令行一律在**项目根** `mail_zcx/` 执行；Web 服务器（nginx / 宝塔「运行目录」）
必须指向 **`public/`**。把项目根直接设成站点根会暴露 `config/config.local.php`（DB 密码、密钥）。

| 部署方式 | 说明 |
|---|---|
| **宝塔面板（推荐）** | [文档站 · 宝塔图形化部署](https://mail.zgric.top/deploy-baota)，全程点击 + `deploy/bootstrap.sh` 一键初始化 |
| 纯 nginx 手工 | 本文下面第 4 步 + `deploy/nginx-mail-zgric.top.conf.example` |
| 本地预览 | 项目根执行 `php -S 127.0.0.1:8000 -t public public/index.php`，打开 `http://127.0.0.1:8000/zc-console/` |

> 完整入口说明、首次部署顺序、常见报错速查见[文档站 · 快速开始](https://mail.zgric.top/guide)。

### 1. 环境要求

- PHP ≥ 8.0，扩展：`pdo_mysql`、`openssl`、`mbstring`、`curl`、`json`
- MySQL ≥ 5.7
- nginx（或 Apache），站点根目录指向 **`public/`**

> Windows 本地开发时若 `php.ini` 未启用扩展，可在命令前加：
> `php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl`

### 2. 配置

复制 `config/config.local.example.php` 为 `config/config.local.php`，填入数据库凭据与随机 `app_secret`：

```php
<?php
return [
    'db' => [
        'host'     => '127.0.0.1',
        'database' => 'your_db',
        'username' => 'your_user',
        'password' => 'your_password',
        'prefix'   => 'zc_',
    ],
    'security' => [
        'app_secret' => '至少64位随机字符串',   // openssl rand -hex 32
    ],
];
```

`config.local.php` 会自动覆盖 `config.php` 的同名项，**不要提交到版本库**（已在 `.gitignore` 中）。

### 3. 建表

```bash
php cli/install.php
```

执行成功会输出 7 张表清单和**默认 API Key**（仅显示一次，请立即保存，随后在控制台轮换）。

### 4. 配置 nginx

站点 `root` 指向项目的 `public` 目录，并加伪静态：

```nginx
root /www/wwwroot/your-domain.com/public;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

> ⚠️ 宝塔默认会在站点配置里加 `error_page 404 /404.html;`，
> 这会**拦截 API 返回的 404 状态码并替换成 HTML**。API 站点请注释掉该行，
> 否则「接口不存在」会返回 HTML 而不是 JSON。

### 5. 启动发信 worker（默认队列必需）

`mail.queue.enabled` 默认 `true`：`/api/v1/send` 把邮件写入 `zc_send_jobs` 后立即返回 `202`，
必须由 worker 后台投递：

```bash
# 常驻（推荐用 supervisor 守护，配置见 deploy/supervisor-mail-worker.conf.example）
php cli/worker.php

# 或单次拉取（cron / 宝塔计划任务每分钟执行）
php cli/worker.php --once
```

不启动 worker，邮件会一直停在 `pending`。想改回同步：在 `config.local.php` 里设
`'mail' => ['queue' => ['enabled' => false]]`。

### 6. 本地起服务（调试用）

```bash
php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl \
    -S 0.0.0.0:8000 -t public public/index.php
```

---

## 快速调用

所有接口在 `/api/v1` 下，携带 API Key（示例域名请替换为你的部署域名）：

```bash
BASE=https://mail.example.com

# 请求头方式（推荐）
curl -H "X-API-Key: zc_xxxxxxxx" $BASE/api/v1/mailboxes

# 也支持 query 参数
curl "$BASE/api/v1/mailboxes?api_key=zc_xxxxxxxx"

# 也支持 Bearer
curl -H "Authorization: Bearer zc_xxxxxxxx" $BASE/api/v1/mailboxes
```

统一响应格式：

```json
{ "code": 0, "msg": "ok", "data": {}, "request_id": "a1b2c3d4", "time": 1791895838 }
```

`code = 0` 表示成功；非 0 为业务错误（`401` 鉴权失败、`400` 参数错误、`1001` 发信失败、`404` 不存在）。

### 发一封邮件

```bash
curl -X POST $BASE/api/v1/send \
  -H "X-API-Key: zc_xxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
        "to": "someone@example.com",
        "subject": "你好",
        "html": "<h1>正文</h1>",
        "callback_url": "https://your.app/hook"
      }'
```

不传 `mailbox_id` 时，系统自动选择**发件量最少**的可用邮箱。

默认队列模式下返回 **HTTP 202**，`data.status` 为 `queued`，只表示已入队：

```json
{ "code": 0, "msg": "已加入发送队列...", "data": { "log_id": 12, "status": "queued", "check_url": "/api/v1/logs/12" } }
```

投递结果通过 `GET /api/v1/logs/12` 查询（`pending` → `success` / `failed`），
或配置 `callback_url` / 全局 Webhook 接收终态。关闭队列则退回同步返回结果。

### 用模板发送

```bash
curl -X POST $BASE/api/v1/send \
  -H "X-API-Key: zc_xxxxxxxx" \
  -d '{"to":"someone@example.com","template":"verify_code","variables":{"code":"839201","expire":"5"}}'
```

### 添加邮箱

```bash
curl -X POST $BASE/api/v1/mailboxes \
  -H "X-API-Key: zc_xxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
        "name": "通知专用",
        "email": "noreply@example.com",
        "display_name": "系统通知",
        "smtp_host": "smtp.example.com",
        "smtp_port": 465,
        "smtp_secure": "ssl",
        "smtp_user": "noreply@example.com",
        "smtp_pass": "授权码"
      }'
```

> `smtp_secure` 可省略，系统按端口自动推断：465→`ssl`、587→`tls`、其他→`none`。

完整接口列表见 [`docs/API.md`](docs/API.md)。

---

## Web 控制台

访问 `https://你的域名/zc-console/`，输入 API Key 即可进入可视化管理界面：

| 页面 | 功能 |
|---|---|
| **仪表盘** | 今日发送量 / 成功率 / 平均耗时 / 可用邮箱数，按小时分布柱状图，按邮箱统计 |
| **发送邮件** | 表单发信，支持模板变量、指定发件箱、结果回调；右侧实时显示 API 返回 |
| **邮箱管理** | 增删改查 + 一键 SMTP 连通性测试（展示完整 SMTP 会话记录） |
| **邮件模板** | 在线编辑 HTML/文本模板 + 变量渲染预览 |
| **Webhook** | 出站配置、投递记录、入站事件三合一，支持手动测试回调 |
| **发送日志** | 按状态/关键词筛选，单条详情含 SMTP 响应码、错误、原始请求体 |
| **API 密钥** | 新建、轮换、限流与 IP 白名单管理 |

实现上是**纯静态单文件**（`public/zc-console/index.html`，无框架、无 CDN 依赖），
所有数据通过 `/api/v1` 实时拉取，API Key 存在浏览器 localStorage。
静态页面本身公开无妨 —— 没有 Key 无法调用任何接口。

---

## 选箱策略

`config.php` → `mail.selection`：

| 值 | 行为 |
|---|---|
| `least_sent`（默认） | 累计发件数最少优先，天然均衡 |
| `round_robin` | 最久未使用优先 |
| `weight` | `sent_total / weight` 最小优先，权重越大越常被选中 |
| `random` | 随机 |

选箱在数据库事务内 `SELECT ... FOR UPDATE`，并发下不会重复派给同一邮箱。
每个邮箱可设 `daily_limit`，超限自动跳过。

---

## Webhook 签名校验

出站回调带 `X-ZC-Signature: sha256=<hmac>`，接收方校验示例：

```php
$raw = file_get_contents('php://input');
$expect = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if (!hash_equals($expect, $_SERVER['HTTP_X_ZC_SIGNATURE'] ?? '')) {
    http_response_code(403);
    exit('invalid signature');
}
$data = json_decode($raw, true);   // ['event'=>'success','data'=>[...]]
```

---

## 安全建议

1. **改掉默认 API Key**：安装后立即在控制台轮换（`POST /api/v1/keys/{id}/rotate`）。
2. **设置强 `app_secret`**：用于加密 SMTP 密码，泄露等于泄露所有邮箱授权码。
3. **限制 IP**：给 Key 配置 `allow_ips`，或设全局 `security.allow_ips`。
4. **生产关调试**：`app.debug = false`（默认已关闭），避免异常堆栈外泄。
5. **上 HTTPS**：邮箱授权码通过 API 明文传输，务必套 TLS。
6. **别把 `config.local.php` 提交到公开仓库**。
7. **反代/CDN 前先验证 API 可达**：若套 Cloudflare，注意 Bot Fight Mode / 托管质询会拦截
   **服务端**调用（curl 会拿到 HTML 质询页而不是 JSON）。请对 `/api/*` 配置 WAF 跳过规则，
   或关闭 API 域的浏览器完整性检查。

---

## 秘密入口（可选，防扫描）

仅靠 API Key 鉴权时，接口路径 `/api/v1` 和后台 `/zc-console` 仍是固定可猜的。
生产环境可加一层**入口混淆**，让扫描器找不到门：

### 1. 隐藏后台控制台

后台是纯静态单文件，调用接口用绝对路径，因此**改目录名即可隐藏，无需改代码**：

```bash
# 部署时把目录改名成随机串（示例）
mv public/zc-console public/a7f3c9e2bd
# 之后控制台地址变为 https://你的域名/a7f3c9e2bd/
```

> 仓库默认名 `zc-console` 仅作示意；生产请换成无规律的随机串并收藏书签。
> nginx 示例里对应的 location 也要同步改成 `location ^~ /a7f3c9e2bd/ { ... }`。

### 2. 给 API 加秘密前缀

在 `config.local.php` 设置：

```php
'security' => [
    'api_entry' => 'x9m2kq',   // 非空即启用；真实接口变为 /x9m2kq/api/v1/...
],
```

启用后：

- 真实接口：`https://域名/x9m2kq/api/v1/mailboxes`
- 直接访问 `https://域名/api/v1/...` → **404**（扫描器扑空）
- 控制台需同步设置：打开 `public/<你的目录>/index.html`，把顶部 `const API_ENTRY = ''`
  改成 `const API_ENTRY = 'x9m2kq';`，否则控制台调不通
- 入站 Webhook 地址同步变为 `https://域名/x9m2kq/api/v1/hook/inbound`

> 这是**纵深防御**的一环，不是替代鉴权——API Key 仍需妥善保管。

---

## 自测

```bash
# 起一个本地 mock SMTP 服务器
php -d extension=openssl cli/mock_smtp.php --port=2525 --mode=plain

# 另开终端，跑发送自测
php -d extension=openssl cli/test_smtp.php --port=2525 --secure=none
```

`--mode` 可选 `plain`(25) / `tls`(587) / `ssl`(465)，覆盖三种真实连接方式。

---

## 部署踩坑记录

1. **打开域名根只看到一段 JSON** —— 正常，根路径被健康检查占用，控制台在 `/zc-console/`，不自动跳转。
2. **`/zc-console/` 返回 401 JSON** —— 请求落到了 PHP 路由，说明伪静态 `try_files` 没生效或运行目录没设 `/public`。
3. **宝塔「网站目录」别直接改成 `.../public`** —— 宝塔会再拼一次运行目录 `/public`，变成 `.../public/public` → 404。
   正确做法：网站目录保持项目根，只把**运行目录**设为 `/public`。
4. **解压后多套一层目录**（`站点根/mail_zcx/app/...`）—— 把内层文件全部上移到站点根。
5. **`||` 不是字符串拼接** —— MySQL 里是逻辑 OR，必须用 `CONCAT()`
6. **宝塔 `error_page 404 /404.html`** 会拦截 API 的 JSON 404 → 注释掉
7. **php-fpm 的 opcache** —— 改 PHP 代码后必须 `/etc/init.d/php-fpm-82 reload`
8. **阿里云未备案域名的 80 端口拦截** —— 返回 `403 Server: Beaver`。
   解决方案：源站同一 server 块内同时监听 80 与 443，443 配自签证书（CDN Full 模式不校验），
   对外统一走 `https://`；或在 CDN 开启 Always Use HTTPS，让边缘直接 301、不回源 80。
9. **宝塔 `extension/*.conf` 是在 server 块内 include 的**，不能放 `server` 指令。

---

## 贡献

欢迎 Issue 与 PR。改动前请先看：

- [`docs/API.md`](docs/API.md) —— 接口契约，破坏性改动需同步更新
- 保持零依赖：新增功能优先用原生 PHP，不引入 composer 包
- 涉及数据库结构变更时，同步更新 `sql/schema.sql` 与 `cli/install.php`

---

## License

[MIT](LICENSE) © zc team
