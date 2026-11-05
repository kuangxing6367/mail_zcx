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
10. **启动发信 worker**（默认开启队列，不启动邮件会停在 `pending`，见下文「异步发信与 worker」）

## 手工部署（nginx）

环境要求：

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.0 |
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

会创建 7 张表，并输出一个默认 API Key（仅显示一次，立即保存）。
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
{ "code": 0, "msg": "ok", "data": { "service": "ZC Mail Service", "version": "1.0.0" } }
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

> 默认队列模式下，上面的请求返回 **HTTP 202 + `status: "queued"`**，只表示已入队，不代表已投递。
> 投递结果见下节。

## 异步发信与 worker（默认开启）

`config/config.php` 的 `mail.queue.enabled` 默认 `true`：`/api/v1/send` 只做解析与落库，
立即返回 `202` + `{"status":"queued","log_id":...}`，SMTP 投递交给后台 worker。

**只开队列不跑 worker，邮件会一直停在 `pending`。** 部署后必须启动 worker，二选一：

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

在 `config.local.php` 覆盖：

```php
return ['mail' => ['queue' => ['enabled' => false]]];
```

改回同步后 `/api/v1/send` 直接返回投递结果，不需要 worker；代价是请求会被 SMTP 会话阻塞。

### 队列配置

| 配置 | 默认 | 说明 |
|---|---|---|
| `mail.queue.enabled` | `true` | 队列开关 |
| `mail.queue.max_attempts` | `3` | 最多投递次数（含首次） |
| `mail.queue.batch` | `10` | worker 单次认领数 |
| `mail.queue.idle_sleep` | `2` | 队列空时休眠秒数 |
| `mail.queue.stuck_timeout` | `300` | 认领超时回收（worker 崩溃兜底） |
| `mail.queue.retry_base` | `30` | 重试退避基数（秒） |

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
