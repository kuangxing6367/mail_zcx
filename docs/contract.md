# 协议与约定

本文定义 **ZC Mail Service 的对外契约** —— 一旦破坏，下游调用方、插件作者、部署者就会坏掉的那部分。

三份文档的分工：

| 文档 | 回答什么 |
|---|---|
| [快速开始](/guide) · [API 手册](/api) · [插件开发](/plugins) | 怎么用 |
| [设计说明](/architecture) | 为什么这么设计 |
| **本文** | **哪些东西不许随便改，改了要怎么走流程** |

本文对三类人有效：调用 HTTP 接口的业务方、写插件的人、往主仓库提代码的人。

---

## 1. 许可：WTFPL

主程序、文档、`deploy/`、`sql/` 全部适用 **WTFPL v2**
（Do What The Fuck You Want To Public License，全文见根目录 `LICENSE`）：

> 0. You just DO WHAT THE FUCK YOU WANT TO.

逐条说清它的实际含义：

| 你想干的事 | 是否允许 |
|---|---|
| fork / 改名 / 换作者 / 删掉版权声明 | ✅ |
| 商用、闭源、SaaS 化、二次售卖 | ✅ |
| 只拆走一部分代码 | ✅ |
| 不署名、不附许可全文 | ✅ |

- **没有任何义务**。不要求保留版权声明，不要求随分发附带许可，不要求公开修改。
  也没有专利授权条款 —— 别指望它替你解决专利问题。
- **没有免责条款**。WTFPL 通篇只有一句话，不含 `AS IS` 与责任限制。
  责任划分回到当地法律默认规则：本项目不承诺适用性与安全性，线上出事自己担。
  这是有意选择 —— 要标准免责条款就该用 MIT，这里不用。
- **名称与标识不在授权范围内**。代码随便拿，别用「ZC Mail Service」的名义给自己的衍生版背书。
- 第三方插件许可各自独立，WTFPL 不会"传染"插件，插件也不会反向影响主程序。
- 提交贡献即视为同意你的改动以上述许可发布，不签 CLA。

---

## 2. 版本与兼容承诺

版本号遵循 `MAJOR.MINOR.PATCH`（见 `CHANGELOG.md`）。

| 改动类型 | 版本位 | 例子 |
|---|---|---|
| 破坏性改动（删接口、改语义、改事件名、删配置项、删 `PluginContext` 方法） | MAJOR | v1 → v2 |
| 新增接口 / 新增可选参数 / 新增配置项 / 新增事件 | MINOR | 1.3 → 1.4 |
| 修 bug / 调默认值 / 改文档 | PATCH | 1.3.0 → 1.3.1 |

**1.x 期间承诺**：

1. `/api/v1/**` 下的路径**只增不删**，已发布接口的入参名与语义不改。
2. 响应体**可以新增字段**，调用方必须忽略未知字段；不能因为多了一个键就崩。
3. 新增**必填**参数 = 破坏性改动。要加必填项，走新路径或新版本。
4. 配置项可以新增，**不能静默删除**。废弃项保留一个 MINOR 周期，启动时写 warning，再删。
5. 插件侧：`PluginContext` 的已发布方法不删不改签名；`Hooks` 的四个事件名与载荷字段类型是公共 API。

> 唯一例外是**安全修复**：若某项行为本身就是漏洞（例如旧版把脱敏串当凭证），
> 修复可以立即收严并写进 CHANGELOG 的「安全」段，不需要等 MAJOR。
> 这是唯一被允许的"说到做不到"。

---

## 3. HTTP 接口契约

### 3.1 统一包封

所有接口（含错误）返回同一结构，**不返回裸数组**：

```json
{ "code": 0, "msg": "ok", "data": {}, "request_id": "a1b2c3d4e5f6", "time": 1791895838 }
```

- `code = 0` 才是成功；HTTP 200 不代表业务成功（同步发信失败也是 200 + `code=1001`）。
- `request_id` 用于跨系统对账，业务方**必须落进自己的日志**。
- 错误码表见 [API 手册](/api#统一响应)，新增码只增不复用。

### 3.2 鉴权

- Key **只从请求头读**：`X-API-Key` / `X-ZC-Key` / `Authorization: Bearer`。
  URL 传参永久不支持 —— 会进 access log、Referer、浏览器历史。
- 缺 Key / Key 无效 / IP 不在白名单 / 超限 → `401`。
- Key 有效但 scope 不够 → `403`，且 `msg` 必须写明缺哪个 scope。**两者不可混用。**
- 含 `*` 的值一律拒绝：那是列表里的脱敏展示串，不是凭证。
- 数据库只存 `sha256(key)`，明文仅创建时返回一次。**丢了只能轮换，没有找回。**

### 3.3 幂等与写操作

- `GET` 永不产生副作用。需要副作用的操作只能 `POST` / `PUT` / `DELETE`。
- 表单 / JSON 里 `_method=PUT` 可覆盖方法，仅为兼容不支持 PUT 的客户端。
- 发信接口**不保证幂等**：重试会真的发两封。需要去重请用 `request_id` 或 `callback_secret` 自行控制。
- 分页统一 `page` / `size`，`size` 上限 **200**；超限不报错，按 200 处理。

### 3.4 响应头

- 所有 API 响应带 `Cache-Control: no-store` 与 `X-Content-Type-Options: nosniff`。
- 默认**不发 CORS 头**。需要跨域由部署者显式配置 `security.cors_origins`。
- 错误响应不泄露版本、路径、堆栈。细节只进 `error_log`。

---

## 4. Webhook 出站协议

发信进入终态后，服务端把结果 POST 给回调地址。**接收方视角这是一份必须稳定的协议。**

### 4.1 事件

| 事件名 | 触发 |
|---|---|
| `success` | 投递成功 |
| `failed` | 最终失败（重试耗尽） |

配置 `events` 字段支持 `success,failed` 列表、`*` 全匹配，以及历史写法 `mail.success`。
派发时 `event` 字段值**永远是 `success` / `failed`**，不带 `mail.` 前缀。

### 4.2 请求

```http
POST <你的地址>
Content-Type: application/json; charset=utf-8
X-ZC-Event: success
X-ZC-Timestamp: 1791895838
X-ZC-Signature: sha256=5cdecf1f...

{"event":"success","request_id":"5e7cffd91c75f932","timestamp":1791895838,"data":{...}}
```

`data` 字段包含：`log_id`、`request_id`、`status`、`mailbox_id`、`from`、`to`、`cc`、
`subject`、`smtp_code`、`smtp_message`、`error`、`duration_ms`、`missing_vars`。

### 4.3 签名（接收方必须实现）

```
X-ZC-Signature: sha256= + hex(HMAC-SHA256(原始请求体字节, secret))
```

硬性要求：

1. **对 raw body 验签**，不能先 `json_decode` 再 `json_encode` —— 键序与转义一变，签名就废。
2. 用**常数时间比较**（PHP `hash_equals` / Node `crypto.timingSafeEqual`）。
3. 校验 `|now - timestamp| <= 300s`，否则拒绝。签名只证明内容没被改，不证明是新请求。
4. 用 `request_id` 做去重，重试会让**同一封信的回调到达多次**，这是协议保证的行为。
5. secret 留空 = 不签名。接收方**不要接受无签名的回调**，除非它本来就只在内网。

### 4.4 重试与超时

| 项 | 值 |
|---|---|
| 最大尝试次数 | `1 + retry`（`webhook.retry` 默认 2） |
| 间隔 | `200ms × 尝试次数` 递增退避，同一次派发内同步重试 |
| 单次超时 | `webhook.timeout` 默认 5 秒 |
| 成功判据 | HTTP `2xx`；**3xx 不跟随**（防 SSRF 跳板），视为失败 |
| 目标限制 | 默认拒绝回环 / 私网 / 链路本地地址，内网自建接收端需显式开启 |
| 留痕 | 每次投递写 `webhook_deliveries`：URL、请求体、HTTP 码、响应摘要、尝试次数 |

接收方**必须能在 5 秒内返回 2xx**。要做的重活放队列，先回 200。

### 4.5 异步的真实边界

`webhook.async = true` 时回调在响应发出后执行，但只有 PHP-FPM 能真正异步。
`php -S` / `mod_php` / CGI 没有 `fastcgi_finish_request`，会退化为同步阻塞
（`async_fallback = sync`）或直接丢弃（`skip`）。**这个差异不隐藏，配置里写明。**

---

## 5. Webhook 入站协议

`POST /api/v1/hook/inbound` —— 唯一免 API Key 的写接口，因此也是最需要约束的一个。

| 规则 | 说明 |
|---|---|
| 方法 | 只接受 `POST`，`GET` 返回 `405`（防浏览器预取、防 CSRF 触发写入） |
| 开关 | `security.inbound_secret` 为空时接口返回 `403`，**默认关闭** |
| 签名 | `X-ZC-Signature: sha256=<hmac(rawBody, inbound_secret)>`，与出站同一套算法 |
| 时间戳 | body 带 `timestamp` 时校验容差（`security.timestamp_window`，默认 300 秒） |
| 来源 | `X-ZC-Source` 头或 body 里的 `source` 字段，截断 64 字符 |
| 落库 | `inbound_events`：原文（截断 65535 字节）、来源 IP、签名、验签结果 |
| 二次转发 | `forward=1` 时以 `inbound.<event>` 为事件名转发给已配置的出站 Webhook |

**不带 `timestamp` 的请求仍然放行**（兼容老调用方），但记 warning，且同一个合法请求可以被无限重放。
新接入方**必须带 `timestamp`**，这是协议要求，不是建议。

---

## 6. 插件编写约定

插件是生态扩展口，不是主程序的补丁。**这一节是插件作者的硬约束。**

### 6.1 身份

| 项 | 约束 |
|---|---|
| 目录名 = slug | `^[a-z0-9][a-z0-9_-]{0,40}$`，不符合的目录**不会被扫描** |
| `plugin.json` 的 `slug` | 填了就必须与目录名一致，否则整个插件跳过 |
| `class` | **必填，且必须带命名空间**。两个插件都叫 `Plugin` 会直接 fatal |
| `entry` | 必须在插件目录内（`realpath` 校验），越界即拒绝加载 |
| `requires.php` / `requires.zcmail` | 只约束插件自身；主程序的运行基线是另一套机制 |

### 6.2 能力边界

`register(PluginContext $ctx)` 是唯一入口。插件可以：

- 注册接口，但**必须**挂在 `/api/v1/<slug>/...` 前缀下，且**依然走 API Key 鉴权**。
  框架不提供注册免鉴权路由的能力 —— 这条不给开口子。
- 通过第三个参数声明 scope；不传表示"只校验 Key，不额外限权"。**敏感操作必须自己声明 scope。**
- 建自己的表，表名**必须**用 `zc_pl_<slug>_` 前缀。
- 写自己的数据，**只能**写 `$ctx->dataDir()`（`storage/plugins/<slug>/`）。
- 读配置（只读）。插件自己的配置放 `config.local.php` 的 `plugins.<slug>` 段。
- 监听第 6.3 节的事件。

插件不可以：

- 改主程序的配置、表结构、`storage/plugins.json`。
- 假设主程序内部类的稳定性。可依赖的是 `App\Core\Plugin\*`，以及 `Response`、`Database`、
  `Request`、`Config` 这些公开入口；其余类的私有实现随时会变。
- 在 `mail.*` 事件里做长耗时操作。四个事件都在发信链路同步执行，慢一秒就是用户多等一秒。
  要跑重活，自己落表，用 cron 或 worker 消费。
- 依赖"我只在 Web 请求里被加载"。`public/index.php`、`cli/worker.php`、`public/zc-cron.php`、
  `cli/smtpd.php` **四个入口都会加载插件**。

### 6.3 扩展点契约

事件名与载荷契约集中在 `app/Core/Plugin/Hooks.php`，**不要在插件里自己发明事件名**，注册了也不会触发。

| 事件 | 时机 | 可拒绝 | 载荷 |
|---|---|---|---|
| `mail.before_prepare` | 解析参数前，未落库 | ✅ `Hooks::reject()` → 400，不留日志 | `input`、`context` |
| `mail.after_prepare` | MIME 组好、日志已落库 | ❌ 拒绝会被降级为忽略 | 完整 payload |
| `mail.before_deliver` | 连 SMTP 之前 | ✅ 拒绝后本封按失败处理 | 完整 payload |
| `mail.after_send` | 终态，每封仅一次 | ❌ 异常一律吞掉 | `payload`、`result` |

签名固定为 `function (array $data): array`。返回值必须是数组，且字段类型要符合该事件的契约。

框架的容错规则（插件作者必须知道，但不要依赖它来写错代码）：

| 插件行为 | 框架反应 |
|---|---|
| 返回非数组 / `null` | 丢弃本次返回值，记 warning，沿用上一版数据 |
| 字段类型不对（`recipients` 变字符串等） | 整份丢弃，记 warning |
| 抛普通异常 | 记 warning，跳过这个监听器 |
| 抛 `RejectException` | 按上表的"可拒绝"语义处理 |

改写载荷时**自己维护一致性**：`message`、`recipients`、`to`、`subject` 是同一封信的不同表示，
只改一个会造成日志与实际投递不符。投递前 `Mailer::assertPayload()` 会兜底拦截，返回 400 而不是 500。

### 6.4 失败隔离与权限真相

清单损坏、版本不满足、入口越界、类不存在、缺 `register()`、`register()` 抛异常 —— 都只影响这个插件，
并**自动停用**，原因写进 `storage/plugins.json`。

但要说清楚：**插件一旦加载成功，执行的就是普通 PHP 代码，权限等同主程序。**
隔离只保证"坏插件不拖垮主程序"，不保证"插件不能读主程序的凭据"。只装可信来源。

### 6.5 分发

没有中心市场。独立 GitHub 仓库，自带 `plugin.json` + 入口文件 + `README.md` + 自己的 `LICENSE`。
使用者把内容拷进 `plugins/<slug>/` 即可。插件许可与本项目 WTFPL **互不影响**。

---

## 7. 数据与凭据约定

### 7.1 配置三层

```
config.php              默认值，随代码走，不含凭据        ← 可开源
config.local.php        部署者手写，含 DB 密码与密钥       ← 永不入库
config.overrides.php    控制台「系统设置」生成             ← 永不入库，优先级最高
```

- 后加载的层 `array_replace_recursive` 覆盖前面的。
- 控制台**只写自己的 overrides 文件**，不回写 `config.local.php`
  （里面有 `getenv()` 之类逻辑，整文件 `var_export` 会把它降级成明文常量）。
- 删掉 `config.overrides.php` 即完整回滚，不需要"撤销"概念。

### 7.2 凭据

- SMTP 密码：**AES-256-GCM 加密落库**，密钥来自 `security.app_secret`；
  留空时首次运行自动生成 `storage/app_secret.local`（32 字节，0600，已 gitignore）。
- API Key：**只存 `sha256`**，`api_key` 列存的是脱敏展示串。
- 任何出口（API 响应、日志、控制台）**不得**返回明文密码、Key、app_secret。
- 日志默认不返回原始请求体；需要时 `log.return_payload = true` 显式打开。

### 7.3 数据删除

删除邮箱 / 模板 / Key 即**真删**，不做软删归档。需要留痕就查 `mail_logs`。

---

## 8. 代码与提交约定

### 8.1 代码

- **零运行时依赖**：不引入 composer、PHPMailer、任何 ORM。新增依赖必须论证"为什么不能自己写 300 行"。
- **PHP 基线由部署者维护**：配置里的策略快照决定是否启动，不是自动 feed。
  上游发安全版、分支到期，部署者自行核对更新。未达基线的环境 Web 返回 503、CLI 非零退出。
- **分层不可越**：`Core`（框架）/ `Mail`（协议）/ `Service`（业务）/ `Controller`（接口）。
  Controller 只做参数校验与响应组装，**不写 SQL**；Service 不碰 HTTP。
- 保持可审计：没有容器、没有事件总线、没有魔法。宁可多写两行显式调用。

### 8.2 提交

格式 `type(scope): 摘要`，摘要用中文，不加句号。

| type | 用途 |
|---|---|
| `feat` | 新功能 |
| `fix` | 修 bug |
| `security` | 安全修复（**单独提交**，不与功能混） |
| `perf` | 性能 |
| `refactor` | 不改行为的重构 |
| `docs` | 文档 |
| `chore` | 版本、构建、依赖 |

规则：

- 一个提交只做一件事。混提交在 review 时会被打回。
- 安全修复**必须单独成一个提交**，并在 CHANGELOG 顶部标注「建议所有部署升级」。
- `main` 任意时刻可发布。功能开发走分支，合并前自测。
- 提交前跑 `php -l` 对改动文件做语法检查。

### 8.3 文档同步

- **`docs/` 是唯一事实来源**，README 与站点同源。改行为必须同时改文档，否则视为未完成。
- 改了对外的接口、事件、配置默认值 → 同时更新 `CHANGELOG.md` 与本文相关章节。
- 文档里不写未经验证的断言。**没有实测的东西不要写成事实**，宁可写"未验证"。
- 发版：CHANGELOG 置顶新增版本段 → 改 `app` 里版本号 → 打 git tag。

---

## 9. 安全披露

- 发现漏洞**不要开公开 issue**。走 GitHub 仓库的 **Security → Report a vulnerability** 私下报告。
- 报告请附：影响版本、复现步骤、影响面（能否提权 / 能否读到凭据）、修复建议（可选）。
- 维护方先在私下确认与修复，修好后统一在 CHANGELOG 的「安全」段公开；
  公开时给报告者署名（除非要求匿名）。
- 在补丁发布前，请勿公开细节或 PoC。

---

## 10. 修改本文

本文本身也是契约的一部分：

- 任何**收紧或放宽**对外行为的改动，先改本文，再改代码。
- 破坏性变更必须先在本文与 CHANGELOG 里写清"旧行为 → 新行为 → 迁移方式"。
- 只改措辞、补例子，直接提 PR。

---

_最后核对：v1.3.0（2026-11-07）。本文与代码不一致时以代码为准，并按上一节提 PR 修正。_
