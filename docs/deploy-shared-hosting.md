# 虚拟主机部署（梦奈主机 / MNBT 这类面板）

这一类环境的特点是：**只有 PHP + MySQL，没有 SSH、没有 supervisord、不能改 nginx 主配置**，
面板上能做的一般只有「文件管理 / 数据库 / PHP 版本切换 / 伪静态 / 计划任务（部分商家有）」。
宝塔专页里的 `deploy/bootstrap.sh`、`php cli/install.php`、`php cli/worker.php` 在这里全都用不了。

本文按「从拿到主机到发出第一封信」的顺序写，每一步都能在面板里点出来。

---

## 0. 先确认三件事，再动手

### ① 主机商是否允许跑邮件服务（最容易踩的坑）

这不是技术问题，是服务条款问题。低价虚拟主机（梦奈 / 奈云 / 宇柒这类香港小主机）的
禁止清单里**经常直接写着「SMTP 邮件服务」**，一旦被检测到发信，通常是直接关停不退款。

买之前先翻一遍商家的 TOS / 产品页注意事项。写的是「禁止 SMTP」就别在这类主机上跑；
要用就换云服务器（有独立 IP、能开 rDNS、能配 25 端口），那才是发信该待的地方。

### ② 出站端口是否放行

PHP 发信是**主动连出去**（`fsockopen` + TLS 到 `smtp.qq.com:465`），不是监听端口。
需要确认：

- `fsockopen` 没被 `disable_functions` 禁用（面板的「PHP 设置 → 禁用函数」里看）
- 出站 `465` / `587` 没被防火墙拦

面板一般不给测端口的工具，最快的验证就是：**装完、配好邮箱后点「测试」**，
连接超时基本就是被封；返回 `535 / 550` 是账号授权码问题，不是网络问题。

### ③ PHP 版本与空间

| 项 | 要求 | 说明 |
|---|---|---|
| PHP | **≥ 8.2 且为安全修复版** | 代码用到 PHP 8.1 的 `readonly`，7.4/8.0 即使面板打了补丁也跑不起来；很多主机默认还是 5.6/7.4，务必先切版本 |
| 扩展 | `pdo_mysql`、`openssl`、`mbstring`、`curl` | 都缺一不可；缺 `openssl` 就连不上 465/587 |
| MySQL | 5.7+，**InnoDB** | 队列表用了行锁 `FOR UPDATE`，MyISAM 不行 |
| 空间 | 源码约 1MB；建议 ≥ 200MB | 100MB 也能跑，但要按第 9 节限制日志体积 |
| 数据库 | 建议 ≥ 50MB | 发送日志带请求体时增长很快 |

::: warning 这里有个真实的产品代价
本项目默认会在 PHP 不满足基线时**直接拒绝服务**（Web `503`），
而虚拟主机恰恰是最难自己处理这件事的环境：版本由空间商给、没有 SSH、
看不到 FPM 用的二进制、也无从确认面板有没有回补补丁。
最早「丢进任意一个有 PHP 的格子就能跑」的定位，因此变成了「先确认运行环境安全」——
被挡住的往往是**最没能力升级**的那批用户。

能走的路只有三条：① 让空间商提供已回补补丁的版本，核对后显式忽略检查；
② 换能选 PHP 8.3+ 的主机；③ 接受无法自行保证运行时安全，改用外部发信服务。
`ignore_php_requirement` 不是「跳过报错继续用」的按钮，它只是把判断责任从应用转回给你。
:::

---

## 1. 这种环境能跑什么、不能跑什么

| 功能 | 可用 | 说明 |
|---|---|---|
| HTTP API 发信 | ✅ | 同步模式，一个请求内完成投递 |
| 多邮箱 / 模板 / 日志 / API Key / Webhook | ✅ | 全部功能都在 |
| Web 控制台 `/zc-console/` | ✅ | 纯静态单文件 |
| 队列 + 计划任务 | ✅ | 用 `zc-cron.php` 走「访问 URL」型计划任务 |
| SMTP 入站（25 端口收信） | ❌ | 需要常驻进程 + nginx stream + 25 端口，虚拟主机做不到 |
| supervisor 守护 | ❌ | 没有 root，也没有常驻进程权限 |
| 命令行走 `cli/*.php` | ❌ | 没有 SSH |

---

## 2. 上传源码

面板「文件管理」进入站点根（一般叫 `wwwroot/你的域名/` 或 `public_html/`），上传解压整包。

然后二选一：

### 方案 A（推荐）：把站点根指到 `public/`

如果面板有「运行目录 / 网站目录」设置（MNBT 继承了宝塔的这项），设成 `public`。
这样 `config/`、`storage/` 都在 Web 根之外，最安全。

### 方案 B：站点根改不了，只能用 `public_html/`

那就用仓库里的 `deploy/apache/root.htaccess`：

1. 把它改名为 `.htaccess` 放到站点根
2. 它会把 `/api/v1/...`、`/zc-console/` 内部转发到 `public/`，
   同时**禁止访问** `app/ config/ cli/ sql/ storage/ docs/ deploy/` 和 `.git`

> 面板文件管理器默认不显示隐藏文件，改名后看不到 `.htaccess` 是正常的，
> 用「显示隐藏文件」开关确认一下。

站点根指向 `public/` 时，改用 `deploy/apache/public.htaccess`（同样改名 `.htaccess`）作为伪静态。

---

## 3. 建库

面板「数据库」→ 新建数据库。记下：**库名、用户名、密码、主机地址**
（共享主机一般是 `localhost`，不要填 `127.0.0.1`，除非面板明确写了）。

字符集选 `utf8mb4`。

---

## 4. 写配置

面板「文件管理」进入 `config/`，新建文件 `config.local.php`，内容照下面改：

```php
<?php
return [
    'db' => [
        'host'     => 'localhost',      // 面板给的数据库地址
        'port'     => 3306,
        'database' => '面板里建的库名',
        'username' => '面板里建的用户',
        'password' => '数据库密码',
        'prefix'   => 'zc_',
    ],
    'security' => [
        // 留空 = 自动在 storage/app_secret.local 生成每台机器一份的随机密钥
        'app_secret'  => '',
        // 网页安装器口令（装完就删 zc-install.php）
        'setup_token' => '换成你自己的随机串',
        // Web Cron 口令（用「访问 URL」计划任务时需要）
        'cron_token'  => '再换一个随机串',
    ],
    // 没有常驻进程，走同步发信（部署模板默认值就是 false，这里显式写一遍更清楚）
    'mail' => [
        'queue' => ['enabled' => false],
    ],
];
```

要点：

- `config.local.php` 会覆盖 `config.php` 的同名项，**只需要写要改的部分**
- 这个文件**绝对不能**放在能被浏览器访问到的位置直接读，方案 A/B 都已经拦住了
- `app_secret` 用来加密邮箱 SMTP 密码，改了以后已存的密码要重新填

---

## 5. 建表：用网页安装器（不用 SSH）

浏览器打开：

```
https://你的域名/zc-install.php
```

页面会先做环境体检（PHP 版本、扩展、`storage` 可写、配置是否就位），然后要求输入
`setup_token`。执行完会显示：

- 执行了多少条语句、建了哪 8 张表
- **默认 API Key（只显示这一次）**，立刻复制保存

装完这个入口会自动失效（写了 `storage/installed.lock`），**再从文件管理器把
`public/zc-install.php` 删掉**。

> 不想用网页安装器：面板一般有 phpMyAdmin，进去选库 → 导入 `sql/schema.sql`，
> 然后在 `zc_api_keys` 表里自己看 `api_key` 列的种子 Key（首次鉴权后会自动变成脱敏串）。

---

## 6. 伪静态

| 环境 | 做法 |
|---|---|
| Apache / LiteSpeed | 用第 2 节的 `.htaccess`，不用额外配 |
| 面板「伪静态」输入框 | 填 `location / { try_files $uri $uri/ /index.php?$query_string; }` |

验证伪静态有没有生效：

```bash
curl https://你的域名/api/v1/mailboxes
# 期望：{"code":401,"msg":"缺少 API Key..."}
# 如果返回 404 或 nginx 的 404 页面 → 伪静态没生效
```

顺带确认根路径：

```bash
curl https://你的域名/
# 期望：{"code":0,"msg":"ok","data":{"service":"ZC Mail Service"...}}
```

---

## 7. 发信模式：同步 or 计划任务

### 方式 A：同步（省事，推荐先用这个）

部署模板里 `mail.queue.enabled` **本来就是 `false`**（第 4 节也显式写了一遍），这就是方式 A，
不用再改。这样：

- `POST /api/v1/send` 在请求内完成 SMTP 投递，直接返回 `200` + `status: success`
- 不需要 worker、不需要计划任务
- 代价：一封慢信会把请求挂住（最多 `mail.total_timeout`，默认 30 秒）；
  虚拟主机常有 30 秒脚本超时，超时上限建议同时调低

```php
'mail' => [
    'timeout'       => 10,
    'total_timeout' => 20,
    'queue'         => ['enabled' => false],
],
```

> 这几项都能在控制台「系统设置 → 发信与队列」里直接改，改完立即生效，不用重新传文件。

### 方式 B：队列 + Web Cron（主机有「访问 URL」计划任务时）

保持 `queue.enabled => true`，然后让面板定时访问：

```
https://你的域名/zc-cron.php?token=你的cron_token
```

- 频率：**每 1 分钟**
- 返回：`{"code":0,"data":{"claimed":1,"done":1,"failed":0,"backlog":0,"elapsed_ms":205}}`
- 单次最多处理 `mail.queue.web_cron_batch` 封（默认 5），防止 30 秒超时
- 有文件锁，两个 cron 撞上时后来的直接返回「上一轮仍在执行，本次跳过」
- 不支持「访问 URL」型计划任务的商家，就只能用方式 A

`token` 会出现在访问日志里，介意的话把 `cron_token` 设长一点，或者改用
`curl -H "X-Cron-Token: xxx"`（多数面板计划任务只支持填 URL，那就只能放 query）。

---

## 8. 发第一封信

打开 `https://你的域名/zc-console/`，粘贴第 5 步拿到的 Key，然后：

1. 「邮箱」→ 添加：填发件邮箱 + SMTP 主机 + 端口 + **授权码**（不是登录密码）
   - QQ 邮箱：`smtp.qq.com` / `465` / `ssl` / 授权码
   - 163：`smtp.163.com` / `465` / `ssl` / 授权码
2. 点「测试」→ 期望 `250`；`535` 是授权码错，超时是端口被封
3. 「发信」页发一封给自己

或者直接命令行验证：

```bash
curl -X POST https://你的域名/api/v1/send \
  -H "X-API-Key: 你的Key" \
  -H "Content-Type: application/json" \
  -d '{"to":"你自己@qq.com","subject":"测试","html":"<b>ok</b>"}'
```

同步模式下返回 `status: "success"`、`smtp_code: "250"` 就算通了。

---

## 9. 空间 / 数据库偏小怎么办

虚拟主机常见 100MB 空间 + 50MB 数据库，而发送日志写满得很快。

在 `config.local.php` 里：

```php
'log'  => ['level' => 'warning'],   // 不写 debug 日志
'mail' => ['log_payload' => false], // 不把请求体整段落库（默认已经是 true，改成 false）
```

再用 phpMyAdmin 定期清：

```sql
DELETE FROM zc_mail_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
DELETE FROM zc_send_jobs WHERE status IN ('done','failed') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

> 表前缀按你配置的 `prefix` 改。

---

## 10. 常见问题

| 现象 | 原因 |
|---|---|
| 打开域名是一段 JSON | 正常。项目没有首页，控制台在 `/zc-console/` |
| `/api/v1/...` 返回 404 或 HTML | 伪静态没生效；或方案 B 的 `.htaccess` 没上传/没被识别 |
| 返回 503「服务未就绪：运行环境不满足安全要求」 | 版本低于基线或不是安全修复版，具体原因在服务端 `error_log` 与 `php cli/php-check.php` 里（Web 端不回显版本细节）。这是需人工处理的持久拒绝、不带 `Retry-After`，定时重试不会自行恢复 |
| 全部接口 500，页面空白 | PHP 版本 < 8.2，或 `pdo_mysql` 没开；先看 `storage/logs/app.log` |
| 安装器提示「无法切换到数据库」 | 库名/用户/密码不对，或该用户没被授权到这个库（面板里重新绑定） |
| 安装器提示 storage 不可写 | 把 `storage` 及子目录权限改成 755（部分主机要 777） |
| 发信卡住然后超时 | 出站 465/587 被封，或 `fsockopen` 被禁用 |
| 返回 535 | 授权码错 / 邮箱没开 SMTP 服务 |
| 邮件发出但进垃圾箱 | 虚拟主机共享 IP、无 rDNS、无 SPF/DKIM，这是这类环境的先天缺陷，换云服务器 + 域名邮箱才能根治 |
| 控制台能开但接口 401 | Key 复制时带了空格，或 Key 已被轮换 |
| 队列一直是 pending | `queue.enabled` 开着但没有 worker 也没有 cron。改成同步，或配 `zc-cron.php` |

---

## 11. 想再省事一点

如果只是想「有个能调的发信接口」，不追求队列和收信：

- 同步模式 + 一个发件邮箱 + 一个 API Key，就是最早版本的全部能力，这套在虚拟主机上完全够用
- 需要 25 端口收信、需要高并发、需要稳定送达率 → 换云服务器，按[宝塔部署](/deploy-baota)那篇走
