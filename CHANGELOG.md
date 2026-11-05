# 更新日志

## 未发布

### 新增

- **控制台「系统设置」页**：不用 SSH、不用 FTP 就能改常用配置 —— 发信模式与队列参数、
  SMTP 超时、邮箱选择策略、Webhook 投递策略、API 秘密入口、Web Cron 口令、鉴权锁定阈值、
  可信反代地址、调试开关。保存立即生效，标了「需重启 worker」的项需重启常驻进程
- **配置三层**：`config.php` → `config.local.php` → `config.overrides.php`。
  网页端只写最后一层，不碰手写文件里的 `getenv()` 等逻辑；删掉该文件即恢复手写配置
- 新接口 `GET/POST /api/v1/settings`、`POST /api/v1/settings/reset`，独立 `settings` scope

### 变更

- **部署模板默认改为同步发信**（`config/config.local.example.php` 里 `mail.queue.enabled = false`）。
  虚拟主机/无 SSH 环境是主要使用场景，装完即可发信，不必先配 worker 或计划任务；
  要异步队列的环境显式打开（控制台或配置），并让 worker 跑起来

### 安全

- 网页端写配置只允许白名单字段，值经类型/范围/正则校验；写入前先 `require` 验证生成的代码，
  通过后才 `rename` 落盘并保留 `.bak`；写完 `opcache_invalidate`
- 密钥类字段（`cron_token` / `inbound_secret`）读取时不返明文，只给「是否已设置」
- 数据库凭据、`app_secret`、IP 白名单、附件目录、SMTP 入站不在白名单内，并在页面上明确列出原因

---

## v1.0.0 — 2026-11-06

第一个正式版本。零依赖 PHP 发信服务，`public/` 为唯一入口，MySQL 单库。

### 功能

- **发信**：`POST /api/v1/send`，纯 HTML / 纯文本 / 模板三种写法，支持抄送、密送、附件
- **多邮箱**：每箱独立 SMTP 主机 / 端口 / 加密方式，密码 AES-256-GCM 加密落库；选箱策略 `least_sent` / `round_robin` / `random` / `weight`，选中即事务内原子预占
- **模板**：`{{变量}}` 渲染（支持点号路径），HTML + 纯文本双版本，未匹配变量可追踪
- **日志与统计**：每次发信留痕，按状态 / 关键词 / 时间检索，按日 / 7 天 / 30 天汇总
- **API Key**：只存 `sha256` 摘要，支持 scope、IP 白名单、分钟级限流、有效期、轮换
- **Webhook**：出站结果回调（HMAC-SHA256 签名、失败重试），入站事件接收（签名校验）
- **异步队列**：`202` 入队，worker 后台投递、指数退避重试、卡死任务回收
- **SMTP 入站**：内置标准 SMTP 服务（AUTH PLAIN/LOGIN、STARTTLS、PROXY protocol），老系统直接填服务器地址即可发信
- **Web 控制台**：纯静态单文件，覆盖仪表盘 / 发信 / 邮箱 / 模板 / Webhook / 日志 / 密钥

### 部署

- nginx / 宝塔：站点根指向 `public/`，`php cli/install.php` 建表，`cli/worker.php` 常驻或计划任务
- **虚拟主机（无 SSH）**：网页安装器 `public/zc-install.php`（一次性口令、装完自动失效）+ 同步发信，或 URL 计划任务 `public/zc-cron.php`
- Apache / LiteSpeed 提供 `.htaccess`（站点根指向 `public/` 与只能指向项目根两种情况）
- 支持子目录部署（`app.base_path`）

### 安全

- 附件路径模式默认关闭，开启后必须落在白名单目录内（`realpath` 校验，防符号链接逃逸）
- Webhook 默认拒绝私网 / 环回 / 链路本地地址，不跟随跳转，出站校验证书
- 请求 IP 仅在 `trusted_proxies` 命中时才采信 `X-Forwarded-For` / `CF-Connecting-IP`
- 鉴权失败统一文案，同一 IP 连续失败达阈值临时锁定
- 写操作只允许 POST/PUT/DELETE，不再用 GET 触发副作用
- 日志与详情默认不回显原始请求体

### 修复（相对上一版）

- 日志 / 邮箱 / 模板的关键词检索在原生预处理下报 `HY093 Invalid parameter number`（同名占位符重复），搜索功能实际不可用
- `POST /templates/preview`（免存预览）被 `/templates/{id}` 抢占，返回「模板不存在: #0」
- 权限不足误返回 401，改为 403；路由匹配改为静态段优先
- 内置服务器静态放行未校验路径是否在 `public/` 内，可被 `..` 绕过
- 网页安装器不再回显数据库名
