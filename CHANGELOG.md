# 更新日志

## 未发布

## v1.3.2 — 2026-11-18

修复版本。接口路径、入参名、响应字段、事件名均未改动，升级不用改调用方代码。

### 修复

- **带附件的邮件发不出去（附件被静默丢弃）**：`MimeBuilder` 在 `multipart/mixed`
  里漏了附件部件前的 `--boundary` 分隔符，附件被解析器当成上一个部件的正文吞掉 ——
  Gmail / Outlook / Python 的解析器都看不到附件，正文还掺进一段 base64。
  现在每个部件都由自己的 boundary 起头，单部件时头部仍并入报文头。
- **中文附件名显示成 `?=`**：`filename` 参数里塞 RFC 2047 encoded-word 不是合法用法。
  改为 RFC 2231（`filename*=UTF-8''…`），且只写这一种形式 ——
  同时给 `filename=` 与 `filename*=` 时各客户端取舍不同，会出现文件名被兜底值覆盖。
- **worker 崩溃后日志永远 pending**：`QueueService::reclaim()` 把作业置 `failed` 时只改
  作业表，不动 `mail_logs`。控制台一直显示「排队中」，`stats` 的 pending 永不归零，
  选箱时预占的 `sent_total` / 日限额也不回退。现在回收时同事务收口日志（只覆盖仍为
  `pending` 的行，不碰已写终态的）并回退邮箱计数。
- **同步投递被回调异常连坐**：队列路径把 `finalizeCallback` 的异常隔离了，同步路径没有。
  状态回调抛错会让 `MailController` 返回 400，而邮件其实已发出、日志也是 success。
  `Mailer::finalize()` 现在统一隔离回调异常，两条路径行为一致。
- **大报文可能被截断**：`SmtpClient::write()` 只调用一次 `fwrite`。阻塞 socket 也不保证
  一次写完（内核缓冲区满、TLS 分片都会短写），只写一次就去读响应会让 DATA 段缺一截，
  对端一直等结束符到超时。改为循环写满；`readResponse()` 补 socket 有效性检查，
  避免连接已关闭时 `fgets(null)` 抛 `TypeError` 而不是可读的 SMTP 错误。
- **`security.rate_limit` 是个假开关**：配置里写着「全局每分钟请求上限」，代码里没有任何
  地方读它。现在按来源 IP 计数（与 Key 级限流共用文件计数器），超限返回
  「请求过于频繁，请稍后再试」。

### 安全

- 队列载荷落库前剔除 `api_key`，`callback_secret` 改为加密存储
  （`zc_send_jobs.payload` 会长期保留，明文存调用方签名密钥等于在本地抄一份）。
  `Crypto::decrypt()` 对无 `enc:` 前缀的值原样返回，历史任务照常可读。

### 杂项

- 主仓库 `docs/` 移除 `.vitepress/`、`package.json`、`package-lock.json`、`public/CNAME`：
  文档站迁出后这些只会让人以为还能在本仓库构建第二份站点，与「`docs/` 只保留 md 快照」
  的说法冲突。README 的目录说明同步。
- `app.version` → `1.3.2`；出站 Webhook UA 同步。

### 验证

- PHP 8.4 全量 `-l` 语法检查：48 个文件全过。
- MIME 用例（纯文本 / 纯 HTML / text+html / ASCII 附件 / 中文附件名）逐个用独立解析器
  （Python `email`）复核：附件存在、文件名正确、正文未被污染。
- 端到端：4MB 随机二进制附件 → `MimeBuilder` → `SmtpClient` → 本地 SMTP 接收端，
  收到 5,740,546 字节与发出完全一致，附件解出后 SHA-256 与原文相同。
- 回归自检 21 项（头注入、地址解析、限流计数、Crypto 往返、回收收口、回调隔离）全过。

## v1.3.1 — 2026-11-11

修复版本。按 `contract.md` 的版本承诺，改行为之外的 bug 修复 / 默认值 / 文档 = PATCH。
`/api/v1/**` 路径、入参名、响应字段、事件名均未改动；升级不需要改调用方代码。

### 修复

- **API 创建不了最小权限 Key**：`ApiKeyService::normalizeScopes()` 的 scope 白名单漏了
  `settings` / `plugins`，传这两个值直接抛「未知的 scope」，只能给 `*`。
  现与 `routes.php`、`sql/schema.sql`、`API.md` 的八项 scope 对齐。
- **控制台选不到权重策略**：`SettingsService` 的 `mail.selection` 枚举少 `weight`，
  而选箱逻辑、数据库字段、README 都已支持。补齐。
- **IPv6 对端解析残留方括号**：`stream_socket_get_name()` 返回 `[::1]:port`，
  截取后是 `[::1]`，导致 `smtp_in.trusted_proxies` 里的 `::1` 永不命中，
  PROXY 头被丢弃、`allow_ips` 判定失效、来源 IP 带括号入库。现剥掉方括号。
- **SMTP 入站入队失败留下永久 pending 日志**：`/send` 在 v1.3.0 已收口，入站路径漏了。
  现把已落库的 `pending` 日志改为 `failed`；`451` 响应不再回显内部异常原文。
- **CLI 与 Web 时区不一致**：`app.timezone` 此前只在 `public/index.php` / `zc-cron.php` 生效，
  worker、install、smtpd 等入口走 PHP 默认时区，而队列 SQL 用 MySQL `NOW()` / `CURDATE()`。
  现在 `bootstrap/requirements.php`（所有入口共用）统一设置时区，消除
  `next_attempt_at` 判定、日限额跨天、耗时统计的错位。
- **部署模板提前 413**：nginx 示例的 `client_max_body_size 10m` 小于应用
  `security.max_body_bytes`（16MB），10MB 附件 base64 后约 13.4MB 会在到达 PHP 前被拒。
  改为 `16m` 并注明联动关系。
- **业务码不统一**：`BaseController` / `WebhookController` 返回的 `1` / `4030`
  不在 `API.md` 的 code 表内，统一为 `400` / `403`。
- **「默认不发 CORS 头」名不副实**：`cors_origins` 为空时仍发
  `Allow-Headers` / `Allow-Methods` / `Max-Age` 三个头，现一并收进条件分支。
- `public/zc-install.php` 补 `zc_php_guard()`，与其余入口一致；安装器与 Web Cron 响应补
  `X-Content-Type-Options: nosniff`。
- `config.local.example.php` 不再把 `__APP_SECRET__` 占位串当成真实密钥，
  未设 `ZC_APP_SECRET` 时留空走自动生成；`bootstrap.sh` 提示同步更新。

### 变更

- `app.version` → `1.3.1`（`/health` 回显、插件版本校验用它）；出站 Webhook UA 由
  `ZC-Mail-Service/1.0` 改为带实际版本。
- 部署示例统一使用占位域名 `mail.example.com`、PHP 8.3 分支，并移除注释里的真实公网 IP；
  nginx、supervisor、open_basedir、日志四处路径现在一致。
- 关闭本仓库 GitHub Pages 托管，文档站迁至 [mail_zcx-docs](https://github.com/kuangxing6367/mail_zcx-docs)
  独立仓库，README 指明其为唯一事实来源；本仓库 `docs/` 保留为同结构快照。
- **许可证由 MIT 改为 WTFPL v2**（`LICENSE`）：零义务、无免责条款，名称与标识不随代码授权。

### 文档

- 新增[协议与约定](docs/contract.md)：许可、版本兼容承诺、HTTP 接口契约、Webhook 出站与入站协议、
  插件编写约束、凭据处理与提交规范。文档站侧栏新增「协议」入口。
- 修正 `config.php` 中 `security.timestamp_window` 的过时注释 —— v1.3.0 起该配置已用于
  入站 Webhook 的防重放校验，不再是保留字段。
- README 的「发信模型」段落改回与部署模板一致的默认同步（200），去掉「默认入队异步 202」的旧描述。
- 文档站清理历史遗留冲突：默认发信模式（同步 200 / 异步 202）、`smtp_in.trusted_proxies`
  与 PROXY 头信任边界、队列回收阈值与 `reserved_by` 终态校验、扩展点与「没有事件总线」、
  唯一事实来源指向、插件文档两处坏锚点、PHP 分支与部署参数口径。
- 文档站首页、README 与站点文案改为克制直陈的表述；本仓库 `docs/` 快照同步。

## v1.3.0 — 2026-11-07

安全修复与默认值收紧。**建议所有部署升级**：其中第一条是可直接提权到 `*` 的鉴权缺陷。

### 安全

- **修复：API Key 脱敏展示串可被当成凭证。**
  `keys` 列表接口返回的是 `d6d53ef6******cff8` 这类展示串，它同时存在 `api_key` 列里；
  鉴权在哈希未命中时会用 `api_key = ?` 回退查库，于是把展示串发过来就会命中，
  被当作「老库明文」写回哈希，从而以该行的全部 scope 通过鉴权。
  现在：含 `*` 的 Key 一律不走兼容分支（SQL 侧也排除），自定义 Key 不允许含 `*`。
- **修复：入站 SMTP 无条件信任 PROXY 头。** 任何直连客户端发一行
  `PROXY TCP4 1.2.3.4 ...` 就能改写源 IP，绕过 `smtp_in.allow_ips`。
  新增 `smtp_in.trusted_proxies`，只有建连地址命中时才采信 PROXY 头。
- **修复：SMTP AUTH 无失败锁定。** 复用 `security.auth_fail_limit` / `auth_fail_window`，
  超阈值直接 `421`，不再允许无限猜 Key。
- **修复：入站 Webhook 无重放保护。** `security.timestamp_window` 从死配置变为生效：
  payload 带 `timestamp` 且超窗时拒绝；未带时间戳的请求放行但记日志（兼容老调用方）。
- **修复：503 守卫泄露版本与策略。** 之前把 `current` / `baseline_as_of` / `reason` / `hint`
  回给匿名访问者，等于报出「你跑 8.2.34、基线截止日、忽略开关叫什么」。
  现在 Web 只回通用提示，细节只进 `error_log`，并抹掉 `X-Powered-By: PHP/x.y.z`。
- **修复：出站回调跟随 3xx 时绕过 SSRF 防护。** 改为手动跳转，每一跳都重新校验目标地址。

### 修复

- **旧库升级路径跑不通**：`sql/schema.sql` 的种子 `INSERT` 已带 `api_key_hash` 列，
  而补列 `ALTER` 在其后执行，老库升级必然报 `Unknown column 'api_key_hash'`。
  现在先补列再导 schema（`CREATE TABLE IF NOT EXISTS` 天然幂等），升级后自动回填摘要并脱敏。
- **队列可能重复发信**：`stuck_timeout`（默认 300s）与 `batch(10) × total_timeout(30s)` 正好打平，
  另一实例会回收「还在正常投递」的任务；且 `markDone` / `markFailed` / `markRetry`
  不校验 `reserved_by`，原 worker 事后仍会覆盖状态。
  现在回收阈值取 `max(batch × (total_timeout+5) + 60, 配置值)`，三个终态写入都要求
  `status='processing' AND reserved_by=当前 worker`。
- **队列静默丢信**：`json_encode` 失败（非 UTF-8 报文）时会把空载荷入库，重试 3 次后失败。
  现在序列化失败直接抛错并回滚；SMTP 透传的非 UTF-8 报文自动转 `message_b64`，一个字节不丢。
- **入队失败未回滚日志**：`/send` 入队异常时把已落库的 `pending` 日志收口为 `failed`。

### 变更（默认值收紧）

- `security.cors_origins` 默认改为空：不发 CORS 头（同源部署不需要）。要跨域请显式填域名，`*` 仅用于公开只读场景。
- 新增 `security.max_body_bytes`（默认 16MB）：`php://input` 不受 `post_max_size` 约束，
  超限直接 `413`，避免一个超大 JSON 打满 FPM 内存。
- API 响应统一 `Cache-Control: no-store` + `X-Content-Type-Options: nosniff`。
- **根密钥不再依赖出厂常量**：`security.app_secret` 留空或仍是出厂值时，
  首次运行自动生成 `storage/app_secret.local`（32 字节，0600，已 gitignore），每台机器一份。
  解密会依次尝试候选密钥，用出厂常量加密过的历史数据仍能解开。
- `mail.charset` 此前是死配置，现在真正传给 `MimeBuilder`。

### 文档

- README 删掉「也支持 query 参数传 Key」——代码一直只认请求头（`X-API-Key` / `X-ZC-Key` / `Bearer`）。
- 安全建议与默认值对齐：补根密钥自动生成、CORS 默认收紧、请求体上限、`keys` scope 与展示串的关系。
- `guide` / `architecture` / `deploy-baota` / `deploy-shared-hosting` 同步密钥派生与 503 行为。

### 真机验证（192.168.0.105）

早期功能 47/47 · 安全 33/33 · 扩展点 67/67 · 插件 41/41 · 设置 40/40 · 设置权限 10/10 ·
PHP 基线 HTTP 3 组（8.2.34→503 无版本泄露、8.3.36→200、8.1.35+放行→200）·
脱敏串鉴权→401 · 16MB+ 请求体→413 · 旧库升级（补列/回填/脱敏/幂等）全通过 ·
队列回收竞态 13/13 · 密钥派生与历史密文兼容 7/7 · PROXY 伪造与入站重放 6/6。

---

## v1.2.0 — 2026-11-06

插件生态、业务扩展点与 PHP 运行基线。相对 v1.1.0 的功能增量。

### 新增

- **插件系统（生态扩展）**：`plugins/<slug>/` 目录 + `plugin.json` + 入口类，
  注册自己的接口、数据表与计划任务入口，不改主仓库代码即可扩展
- `PluginContext` 提供路由、配置、数据库、私有数据目录与事件注册；
  四条投递路径（同步发信 / CLI worker / Web Cron / SMTP 入站）都会加载插件，事件行为一致
- 控制台新增「插件」页：查看清单、启用/停用/重载、显示加载错误
- 新接口 `GET /api/v1/plugins`、`POST /api/v1/plugins/{slug}/enable|disable|reload`，独立 `plugins` scope
- 示例插件 `plugins/hello`，可直接启用验证；插件文档 `docs/plugins.md`
- **业务扩展点**：`mail.before_prepare` / `mail.after_prepare` / `mail.before_deliver` /
  `mail.after_send`，事件契约集中在 `App\Core\Plugin\Hooks`；支持改写载荷与显式拒绝
- `Mailer::assertPayload()` 在投递前校验载荷，插件返回畸形数据只给明确 400，不 500
- **PHP 运行基线**：不满足即拒绝服务（Web 503 / CLI 非零退出 / 安装器禁用），
  `security.ignore_php_requirement` 可显式忽略，供宝塔等已回补补丁的环境使用
- `cli/php-check.php` 自检命令、`deploy/bootstrap.sh` 第一步改为调用它
- 文档新增「PHP 版本基线」章节（guide / 宝塔部署），列明官方与宝塔的修复版本对照

### 变更

- PHP 最低要求从 `>= 8.0` 更正为 **`>= 8.2` 且为安全修复版**（代码实际用到 8.1 的 `readonly`，
  且 2026-10-21 两个未认证 RCE 只修在 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12）
- `app.version` 更新为 `1.2.0`，`/health` 与文档示例同步；
  `plugins/hello` 的 `requires.zcmail` 提到 `>=1.2.0`（示例用到了本版才有的 `Hooks`）
- `plugins/hello` 改为演示全部 4 个扩展点，默认不改写正常邮件
- Apache `.htaccess` 与 nginx 示例的禁止目录加入 `bootstrap/`、`plugins/`
- **基线判据改为部署者持有的策略快照**：`bootstrap/requirements.php` 不再内置任何
  上游公告的版本号，只做通用比较；版本号数据 `security.php_baseline` 放在 `config.php`，
  可在 `config.local.php` 覆盖或清空。上游再发安全版、分支到期由运维改配置即可，
  不必改代码或等本项目发版；分支过了 `security_until` 会拒绝并提示升级分支
- `cli/php-check.php` 打印快照日期，便于核对数据是否过期
- `503` 不再发送 `Retry-After: 3600`：这是需人工处理的持久拒绝，不是临时过载，
  重试不会让 PHP 自己升级

### 安全

- 启用状态存 `storage/plugins.json`，接口**不提供上传 PHP 文件的能力**，插件靠 FTP / 面板安装
- slug 正则 + `realpath` 边界校验；清单损坏 / 版本不满足 / 入口异常只自动停用该插件，主程序不受影响
- 插件注册的路由仍走同一套 API Key 鉴权
- 插件事件总线：非数组返回值、类型不符、普通异常一律隔离；只有 `RejectException` 能中断流程
- 版本号不再被当作补丁状态的唯一判据：宝塔 Linux 已回移打包 8.1.35 / 8.0.31 / 7.4.34 等修复版，
  但 7.4 / 8.0 仍因语法门槛无法运行本项目；Windows 面板 8.1 及更早无补丁
- 一键加固 / WAF 规则不能替代补丁，文档明确标注
- **修复主题 CRLF 邮件头注入**：`Mailer::buildMessage()` 在模板变量替换之后拒绝含 CR/LF 的
  主题（明确 400），`MimeBuilder::encodeHeader()` 对所有拼进头部的值兜底清洗。
  此前同步发信的响应回显会暴露注入，而异步队列的 `202` 响应不含主题，
  旧回归用例靠「响应里有没有 Bcc 字样」判断，是假阳性；本次已把断言收紧为必须 400

### 文档

- 修正 `sql/schema.sql` 实际 **8 张表**与 README / 快速开始 / 宝塔部署里仍写「7 张表」的不一致
- 修正快速开始第 10 步、发信示例与 README 发信响应仍按「默认开启队列」描述的问题
  （v1.1.0 起部署模板默认是同步发信，返回 `200` 而非 `202`）
- 合并两段「未发布」，删掉已被扩展点推翻的「主程序不触发任何业务事件」
- `/health` 示例版本统一为 `1.2.0`（快速开始里还停留在 `1.0.0`）
- 新增「快照会过期」「网页能开、worker 一启动就退出（FPM 与 CLI 不是同一 PHP 二进制）」
  「取舍：这挡住了谁」三节；宝塔与虚拟主机文档同步补排查步骤与产品代价
- 修正 VitePress 锚点死链：数字开头章节会生成 `_` 前缀（插件文档「第 5 节」两处），
  以及宝塔文档指向「快照会过期」的锚点（冒号会被转成连字符）

---

## v1.1.0 — 2026-11-06

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
