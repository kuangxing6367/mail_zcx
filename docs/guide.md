# 快速开始

## 先回答最容易懵的问题：部署完打开哪个地址？

这个项目**没有传统首页**。浏览器打开域名根 `/`，看到的是一段 JSON，不会跳转到任何网页。
控制台是一个独立的静态文件，必须手动加路径才能打开。

| 你想打开 | 浏览器地址 | 实际会看到 |
|---|---|---|
| **域名根（健康检查）** | `https://你的域名/` | JSON：`{"code":0,"msg":"ok","data":{"service":"ZC Mail Service",...}}`。**不是网页，不会跳转** |
| **Web 控制台** ✅ | `https://你的域名/zc-console/` | 登录界面，粘贴 API Key 进入 |
| API 接口 | `https://你的域名/api/v1/mailboxes` | JSON；不带 Key 返回 `401` |
| 健康检查（显式） | `https://你的域名/health` | 与域名根同样的 JSON |
| 在线文档 | `https://mail.zgric.top/` | 本文档站 |

> 原因：`public/index.php` 是 API 的**前端控制器**，根路径 `/` 被健康检查占用了。
> 控制台在 `public/zc-console/index.html`，是另一个文件，两者不互相跳转。
>
> 若启用了 `security.api_entry` 秘密前缀，控制台地址不变，但 API 路径要加前缀，
> 且控制台顶部 `API_ENTRY` 常量必须填相同值，详见 [安全入口](#安全入口)。

## 「该在哪个目录打开」—— 两个目录不要搞混

| 目录 | 是什么 | 谁用 |
|---|---|---|
| `mail_zcx/`（项目根） | 完整源码包，含 `app/`、`config/`、`sql/`、`routes.php` | 你上传文件、执行 `php` 命令的位置 |
| `mail_zcx/public/`（Web 根） | **唯一对外暴露**的目录，只有 `index.php` 和 `zc-console/` | nginx / 宝塔「运行目录」指向这里 |

规则：

- **命令行一律在项目根执行**，例如 `php cli/install.php`。
- **Web 服务器根目录必须是 `public/`**，不是项目根。
- 绝对不要把整个项目根设成站点根目录 —— 那样 `config/config.local.php`（数据库密码、密钥）会被公网直接下载。
- `app/`、`config/`、`sql/`、`routes.php` 都在 `public/` 之上，属于「只给 PHP 读、不给浏览器看」的代码。

上传时目录结构应是这样：

```
/www/wwwroot/你的域名/          ← 站点根目录（项目根，上传位置）
├── app/  config/  cli/  sql/  routes.php
└── public/                     ← ← ← 运行目录指向这一层
    ├── index.php
    └── zc-console/index.html
```

## 选一种部署方式

| 方式 | 适合 | 说明 |
|---|---|---|
| **宝塔面板** | 有面板的服务器、不想手写 nginx | [宝塔图形化部署](/deploy-baota)，全程点击 + 一键脚本 |
| **虚拟主机（无 SSH）** | 梦奈主机 / MNBT 这类只有 PHP + MySQL 的环境 | [虚拟主机部署](/deploy-shared-hosting)：网页安装器 + 同步发信 / URL 计划任务 |
| 纯 nginx 手工 | 无面板、容器、自己管配置 | 本文「手工部署」一节 |
| 本地预览 | 还没买服务器，先看长什么样 | 下面「本地 3 分钟跑起来」 |

## 本地 3 分钟跑起来（不用服务器）

在**项目根目录**执行：

```bash
cd mail_zcx
php -S 127.0.0.1:8000 -t public public/index.php
```

然后浏览器打开：

| 地址 | 看到 |
|---|---|
| `http://127.0.0.1:8000/zc-console/` | 控制台登录页 |
| `http://127.0.0.1:8000/` | 健康检查 JSON |

Windows 若 `php.ini` 没启用扩展，命令前加：

```bash
php -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -d extension=curl \
    -S 127.0.0.1:8000 -t public public/index.php
```

> `php -S` 是 PHP 内置开发服务器，**不能用于生产**（单线程、无 php-fpm 进程管理）。
> 生产必须 nginx + php-fpm。
>
> 本地没建数据库时，控制台登录会失败，但静态页面能正常打开 —— 可以先看界面。

## PHP 版本基线

> 部署前先看这一节。

本项目**默认会拒绝在不符合基线的 PHP 上启动**：Web 请求返回 `503`，
CLI（`cli/worker.php`、`cli/smtpd.php`、`cli/install.php`）打印原因后非零退出，
网页安装器直接禁用安装按钮。这不是提示，是故意的拒绝服务。

`503` 在这里表示「环境不合规、需人工处理」，不是临时过载：响应**不带 `Retry-After`**，
客户端定时重试不会让 PHP 自己升级，必须由运维升级运行时或显式放行。

**Web 端不再回显版本号与策略细节**：匿名访问者只会看到一句通用提示，
`current` / `baseline_as_of` / `reason` / `hint` 全部只写服务端 `error_log`。
想在自己机器上看到完整原因，跑：

```bash
php cli/php-check.php
```

### 为什么

2026-10-21 PHP 官方修复了两个**未认证的远程代码执行**漏洞：

| 编号 | 成因 | 评分 |
|---|---|---|
| CVE-2026-109618 | 内存耗尽（OOM）处理时内部请求数据未完整初始化，清理阶段被错误使用 | CVSS v4.0 9.2 |
| CVE-2026-109619 | 请求内部状态在内存释放后残留，同一 worker 处理后续请求时误用 | CVSS v4.0 9.2 |

影响所有用 PHP 处理 Web 请求的环境（PHP-FPM、`mod_php`、php-cgi、FrankenPHP、LiteSpeed），
其中 `mod_php` 风险最高，官方已有「应用代码运行前」即触发的验证案例。
漏洞在 PHP 内核，**应用层代码无法防御**，只能升级运行时。

下表同时是 `config/config.php` 里 `security.php_baseline` 的**默认策略快照**（记录于 2026-11-06）。
它不是自动更新的 feed：上游再发安全版、分支到期，改配置即可，不用改代码、也不用等本项目发版。

官方安全修复版（[php.net 公告](https://www.php.net/archive/2026.php)）：

| 分支 | 安全修复版本 | 官方安全支持截止 |
|---|---|---|
| 8.2 | **8.2.35** | 2026-12-31 |
| 8.3 | **8.3.36** | 2027-12-31 |
| 8.4 | **8.4.27** | 2028-12-31 |
| 8.5 | **8.5.12** | 2029-12-31 |

8.1 及更早已 EOL，官方不再发安装包。**推荐 8.3+**：8.2 的官方安全支持
**2026-12-31 截止**，之后 `8.2.35` 也不再等于「有安全更新」。
支持状态以 [php.net/supported-versions.php](https://www.php.net/supported-versions.php) 为准。

还有一层纯语法门槛：代码用了 PHP 8.1 的 `readonly` 属性，
所以 7.x / 8.0 无论打没打安全补丁都跑不起来。

### 默认判定

| 当前 PHP | 默认行为 |
|---|---|
| < 8.1 | 拒绝（语法门槛） |
| 8.1.x | 拒绝（官方 EOL，无官方安装包） |
| 8.2~8.5 且低于快照修复版 | 拒绝 |
| 8.2~8.5 且 ≥ 快照修复版 | 通过 |
| 分支已过快照里的 `security_until` | 拒绝（快照对该分支失效，提示升级分支） |
| 其它更新分支 / 快照为空 | 放行，但标为「未核实」，需自行确认补丁状态 |

自检：`php cli/php-check.php`。`deploy/bootstrap.sh` 第一步也会调它。

### 快照会过期：这份数据归你维护

应用代码里**不内置**任何上游公告的版本号，只有通用的版本比较逻辑。
判据来自 `config/config.php` 的 `security.php_baseline`：

```php
'php_baseline' => [
    'as_of'  => '2026-11-06',
    'source' => 'https://www.php.net/archive/2026.php',
    'fixed'  => [
        '8.3' => ['min' => '8.3.36', 'security_until' => '2027-12-31'],
        // 其它分支同理
    ],
],
```

- **上游再发安全版**：改对应分支的 `min`，把 `as_of` 改成更新当天。
  追公告这件事属于你的运维流程（包管理器 / 面板 / 监控），本项目不替你承担。
- **分支过了 `security_until`**：该行不再代表「安全」，服务转为拒绝并提示升级分支，
  避免拿过期快照继续放行。请升级到受支持分支，并同步更新快照。
- **快照里没有的分支**：标 `unknown` 放行并提示「未核实」，不臆断补丁状态。
- **不想让应用管这件事**：把 `fixed` 留空，就只剩语法门槛 8.1，
  运行时是否安全完全由你和你的发行版负责。
- 该项**不在网页「系统设置」白名单里**：基线检查发生在配置加载之前，
  控制台打不开时没法用它自救，只能改文件。

`php cli/php-check.php` 会打印快照日期，方便核对是否过期。

### 网页能开、worker 一启动就退出

多版本 PHP 的机器（宝塔尤其常见）上，Web 与 CLI 常常**不是同一个 PHP 二进制**：
PHP-FPM 跑的是面板里已升级的 `/www/server/php/83/bin/php`，
而命令行的 `php` 指向另一个没升级的 8.3.6 甚至 7.4。结果就是
`/health` 和控制台一切正常，但 `php cli/worker.php`、`php cli/smtpd.php`、
`php cli/install.php`、面板定时访问的 `zc-cron.php` 一启动就打印基线错误并退出，
队列永远没人投递，页面上看不出任何异常。

两边分别验一遍，而且要用**绝对路径**：

```bash
which php && php -v                       # CLI 侧
php cli/php-check.php                     # CLI 侧判定
/www/server/php/83/bin/php -v             # 面板里 FPM 实际用的那个
/www/server/php/83/bin/php cli/php-check.php
```

Web 侧想看实际版本，把 `app.health_detail` 设为 `true` 后请求 `/health`。
supervisor / systemd / crontab 里同样写绝对路径，别用裸 `php` 碰运气；
升级后记得重启 PHP-FPM，否则旧 worker 仍在跑未修复的版本。

### 取舍：这挡住了谁

默认拒绝服务把「随便找个有 PHP 的格子就能跑」换成了「你得先管好 PHP」。
对虚拟主机、梦奈这类只有面板、没有 SSH、也没法自己升级 PHP 的用户，
这恰恰削掉了最没能力处理这件事的一批人 —— 这是明确的产品代价，不是疏漏。

三条现实的路：① 让空间商提供已回补补丁的版本，确认后显式忽略检查；
② 换能选 PHP 8.3+ 的环境；③ 接受无法自行保证运行时安全，改用外部发信服务。
如果你运营共享主机，建议在开通页就写明 PHP 版本，别让用户装完才发现 `503`。

### 什么时候可以忽略

**版本号不是补丁状态的证明。** 发行版 LTS、宝塔这类面板会把官方源码补丁回移到
官方已经不管的旧分支；反过来，WAF 规则和「一键加固」也不能替代实际补丁。
如果你确认运行时确实装了对应修复包，可以在 `config/config.local.php` 里关掉这道检查：

```php
'security' => [
    'ignore_php_requirement' => true,
],
```

也可以用环境变量 `ZC_IGNORE_PHP_REQUIREMENT=1` 临时跳过。注意：

- 该开关在**任何业务类加载之前**读取，因此**不开放网页端修改** —— 版本不合规时控制台本身也打不开；
- 它只跳过安全门槛，**跳不过语法门槛**；7.x / 8.0 打开也只会白屏；
- 宝塔用户请看[宝塔部署 · PHP 版本基线](/deploy-baota#php-版本基线)，里面列了面板实际发布的修复版本号。

---

## 首次部署顺序（照这个顺序做，别跳步）

1. 上传完整源码包到站点根目录（[宝塔](/deploy-baota) / 手工）
2. 创建 MySQL 数据库，导入 `sql/schema.sql`，或执行 `php cli/install.php`
3. 从 `config/config.local.example.php` 复制出 `config/config.local.php`，填 DB 凭据与 `app_secret`
4. **把站点运行目录设为 `public/`**
5. 配伪静态 `try_files`，并**删掉宝塔的 `error_page 404 /404.html`**
6. 申请 SSL 证书、强制 HTTPS
7. `curl https://你的域名/health` 验证
8. 打开 `https://你的域名/zc-console/`，粘贴安装时输出的默认 API Key
9. 立刻在控制台「API 密钥」页轮换默认 Key
10. 到此**即可发信**：部署模板默认是同步发信，不需要 worker。
    只有当你手动打开 `mail.queue.enabled` 时才需要启动 worker 或配 Web Cron
    （否则邮件会一直停在 `pending`），见下文「发信模式：同步（默认）与异步队列」

## 手工部署（nginx）

环境要求：

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.2，且为安全修复版（见上方「PHP 版本基线」） |
| 扩展 | `pdo_mysql`、`openssl`、`mbstring`、`curl`、`json` |
| 数据库 | MySQL ≥ 5.7 |
| Web | nginx + php-fpm（或 Apache） |

不需要 composer，也不需要 Node.js —— 服务本身零运行时依赖。

### 1. 获取代码

```bash
git clone https://github.com/kuangxing6367/mail_zcx.git
cd mail_zcx
```

### 2. 配置

```bash
cp config/config.local.example.php config/config.local.php
```

```php
<?php
return [
    'db' => [
        'host'     => '127.0.0.1',   // 用 127.0.0.1，别用 localhost
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

`app_secret` 用于加密 SMTP 授权码与派生 HMAC 密钥。**一旦更换，已入库的邮箱密码将无法解密。**

### 3. 建表

```bash
php cli/install.php
```

会创建 8 张表，并输出一个默认 API Key（仅显示一次，立即保存）。
也可以不用 CLI，直接在 phpMyAdmin 导入 `sql/schema.sql`。

### 4. nginx 配置

完整可用示例在仓库 [`deploy/nginx-mail-zgric.top.conf.example`](https://github.com/kuangxing6367/mail_zcx/blob/main/deploy/nginx-mail-zgric.top.conf.example)，核心是：

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    root /www/wwwroot/your-domain.com/public;   # ← 必须指向 public
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # error_page 404 /404.html;   ← 宝塔默认注入，务必删掉
}
```

> **必须删掉 `error_page 404 /404.html;`**：它会把 API 返回的 JSON 404 替换成 HTML 页面，
> 调用方解析失败。宝塔里在「设置 → 配置文件」手动注释掉。

### 5. 权限

```bash
chown -R www:www /www/wwwroot/your-domain.com
find /www/wwwroot/your-domain.com -type d -exec chmod 755 {} \;
find /www/wwwroot/your-domain.com -type f -exec chmod 644 {} \;
chmod -R 775 /www/wwwroot/your-domain.com/storage
```

### 6. 验证

```bash
curl https://your-domain.com/health
```

```json
{ "code": 0, "msg": "ok", "data": { "service": "ZC Mail Service", "version": "1.3.0" } }
```

无 Key 访问业务接口应返回 401，说明鉴权生效：

```bash
curl -i https://your-domain.com/api/v1/mailboxes
```

## 常见报错速查

| 现象 | 原因 | 处理 |
|---|---|---|
| 打开域名是一片 JSON | 正常，根路径就是健康检查 | 控制台在 `/zc-console/` |
| `/zc-console/` 404 | 运行目录没设成 `public/`，或源码多套了一层目录 | 检查根目录与运行目录 |
| `/zc-console/` 返回 401 JSON | 请求落到了 PHP 路由（伪静态未生效） | 检查 `try_files`；本地用 `php -S` 时升级到最新版本代码 |
| `No input file specified.` | 伪静态没生效 / 运行目录错 | 检查 nginx 配置与 SCRIPT_FILENAME |
| 接口 404 返回 HTML | 宝塔 `error_page 404` 拦截 | 注释掉该行 |
| 打开是 403 / 目录列表 | 根目录指到了项目根而非 `public/` | 改运行目录 |
| 白屏 / `Connection refused` | 数据库连不上 | 核对 DB 凭据；用 `127.0.0.1` 而非 `localhost` |
| 502 / 504 | php-fpm 未启动或超时 | 软件商店重启 PHP，看 `/www/wwwlogs/` 错误日志 |
| `PDO Driver not found` | 缺 `pdo_mysql` 扩展 | PHP 设置 → 安装扩展，重启 PHP |
| 改了 PHP 代码不生效 | opcache 缓存 | `/etc/init.d/php-fpm-82 reload` |

## 控制台

访问 `https://你的域名/zc-console/`，粘贴 API Key 登录。Key 存在浏览器 localStorage，不会上传到别处。静态页面本身公开无妨 —— 没有 Key 调不动任何接口。

若想进一步隐藏入口，把 `public/zc-console/` 目录改名成随机串即可，无需改代码（控制台调接口用绝对路径）。

## 首次发信

```bash
curl -X POST https://your-domain.com/api/v1/mailboxes \
  -H "X-API-Key: zc_xxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
        "name": "通知专用",
        "email": "noreply@example.com",
        "smtp_host": "smtp.example.com",
        "smtp_port": 465,
        "smtp_secure": "ssl",
        "smtp_user": "noreply@example.com",
        "smtp_pass": "授权码"
      }'
```

`smtp_secure` 可省略，按端口自动推断：465 → `ssl`，587 → `tls`，其余 → `none`。
添加后在控制台点「测试」，会展示完整 SMTP 会话记录，便于定位认证失败、端口被封。

```bash
curl -X POST https://your-domain.com/api/v1/send \
  -H "X-API-Key: zc_xxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{"to":"someone@example.com","subject":"你好","html":"<h1>正文</h1>"}'
```

不传 `mailbox_id` 时自动选择累计发件量最少的可用邮箱。

> 默认**同步模式**下返回 `200` + `status: "success"`，表示 SMTP 投递已经完成。
> 只有打开异步队列后才会返回 `202` + `status: "queued"`，那时只表示已入队，不代表已投递。

## 发信模式：同步（默认）与异步队列

`config/config.local.example.php` 里 **`mail.queue.enabled` 默认是 `false`**，也就是同步发信：
`/api/v1/send` 在请求内完成 SMTP 投递，直接返回真实结果。不需要 worker、不需要计划任务，
虚拟主机和「先跑起来再说」的场景都用这个。

异步队列是可选项：把它打开后 `/api/v1/send` 只做解析与落库，立即返回 `202` +
`{"status":"queued","log_id":...}`，SMTP 投递交给后台 worker，失败按指数退避重试。

**开了队列就必须有人投递，否则邮件一直停在 `pending`。** 二选一：

### 方式 A：supervisor 常驻（推荐）

```bash
cp deploy/supervisor-mail-worker.conf.example /etc/supervisor/conf.d/zc-mail-worker.conf
vi /etc/supervisor/conf.d/zc-mail-worker.conf   # 改 command / directory 为你的路径

supervisorctl reread
supervisorctl update
supervisorctl status zc-mail-worker
```

### 方式 B：宝塔计划任务（每分钟拉一次，兜底）

宝塔 → 计划任务 → 添加：

- 类型：Shell 脚本
- 执行周期：每 1 分钟
- 脚本内容：

```bash
cd /www/wwwroot/你的站点 && php cli/worker.php --once
```

`--once` 处理完当前积压就退出，适合没有 supervisor 的环境。缺点是每分钟才拉一次，
单封邮件最多延迟约 60 秒。

### 验证 worker 正常

```bash
# 手动跑一轮，看是否有积压
php cli/worker.php --once --verbose

# 发一封测试邮件（应返回 202 + status=queued）
curl -X POST https://你的域名/api/v1/send \
  -H "X-API-Key: zc_xxx" -H "Content-Type: application/json" \
  -d '{"to":"you@example.com","subject":"队列测试","text":"hello"}'

# 查结果：pending → success / failed
curl -H "X-API-Key: zc_xxx" https://你的域名/api/v1/logs/<log_id>
```

失败按 `retry_base * 2^(n-1)` 退避重试，默认最多 3 次（含首次）。

### 想改回同步

| 方式 | 做法 | 生效时机 |
|---|---|---|
| 控制台（最省事） | 系统设置 → 发信与队列 → 关掉「异步队列」 | 立即 |
| 覆盖配置 | `config.local.php` 里加 `return ['mail' => ['queue' => ['enabled' => false]]];` | 下一次请求 |
| 删覆盖文件 | 删除 `config/config.overrides.php` | 下一次请求 |

改回同步后 `/api/v1/send` 直接返回投递结果，不需要 worker；代价是请求会被 SMTP 会话阻塞。

### 队列配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `mail.queue.enabled` | `false` | 队列开关（部署模板默认同步） |
| `mail.queue.max_attempts` | `3` | 最多投递次数（含首次） |
| `mail.queue.batch` | `10` | worker 单次认领数 |
| `mail.queue.idle_sleep` | `2` | 队列空时休眠秒数 |
| `mail.queue.stuck_timeout` | `300` | 认领超时回收（worker 崩溃兜底） |
| `mail.queue.retry_base` | `30` | 重试退避基数（秒） |

---

## 在网页上改配置（不用登服务器）

控制台左侧「系统设置」可以改一批常用项，改完点保存立即生效，不需要 SSH、不需要 FTP。

<div style="border:1px solid var(--vp-c-divider);border-radius:10px;padding:14px 16px;margin:16px 0">
<strong>能改的</strong>：发信模式与队列参数、SMTP 超时、邮箱选择策略、Webhook 投递策略、
API 秘密入口、Web Cron 口令、鉴权锁定阈值、可信反代地址、调试开关。<br>
<strong>不能改的</strong>：数据库凭据、<code>app_secret</code>、IP 白名单、附件目录、SMTP 入站参数 ——
这些改错会让服务直接不可用，页面里也列了清单，需要手工编辑 <code>config.local.php</code>。
</div>

### 它是怎么存的

改动写进 `config/config.overrides.php`，加载顺序是：

```
config.php  →  config.local.php  →  config.overrides.php
（内置默认）    （你的手写配置）      （控制台生成，优先级最高）
```

这样做的好处：

- **不会动你的 `config.local.php`** —— 里面用 `getenv()` 取密钥之类的逻辑不会被重写掉
- 网页端只能写白名单字段，值经过类型/范围/正则校验，写完先校验文件能否被 `require` 再落盘
- 每次覆盖前留一份 `config.overrides.php.bak`
- 想全部恢复成手写配置：点「恢复手写配置」，或直接删掉这个文件

### 前提与注意

- **`config/` 目录要对 PHP 进程可写**。宝塔默认站点属主是 `www`，一般没问题；
  如果页面提示「config 目录不可写」，在文件管理里把 `config` 权限改成可写，或继续手工编辑
- 标了 **「需重启 worker」** 的项（队列、超时、重试）改完对**已经启动的常驻 worker 不生效** ——
  worker 是常驻进程，配置在启动时读进内存。改完记得 `supervisorctl restart zc-mail-worker`，
  或等计划任务下一轮 `--once`
- 写入后会对该文件做 `opcache_invalidate`，PHP-FPM 下不用重启就能读到新值

## 本地联调（不需要真实 SMTP 账号）

```bash
# 终端 1：mock SMTP 服务器
php -d extension=openssl cli/mock_smtp.php --port=2525 --mode=plain

# 终端 2：发送自测
php -d extension=openssl cli/test_smtp.php --port=2525 --secure=none
```

`--mode` 支持 `plain`(25) / `tls`(587) / `ssl`(465)。

## 安全入口

- 隐藏控制台：`public/zc-console/` 改名成随机串，零代码改动。
- 混淆 API：在 `config.local.php` 设 `security.api_entry = 'x9m2kq'`，真实接口变为
  `/x9m2kq/api/v1/...`，直接访问 `/api/v1` 返回 404；同时把 `public/zc-console/index.html`
  顶部的 `API_ENTRY` 常量改成相同值。
- 详见 [README 安全入口章节](https://github.com/kuangxing6367/mail_zcx#秘密入口可选防扫描)。

## 下一步

- [宝塔图形化部署](/deploy-baota) —— 全程点击，含一键初始化脚本
- [API 手册](/api) —— 全部接口与参数
- [设计说明](/architecture) —— 分层、选箱并发、签名机制
