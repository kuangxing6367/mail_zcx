# 宝塔面板部署

全程图形化操作，不需要手写 nginx 配置。约 10 分钟。

> 本文的「站点根目录」与「运行目录」是两回事，搞混是最常见的 404 原因，见第 3 步。

## 0. 环境准备

宝塔「软件商店」安装并启动：

| 组件 | 版本建议 |
|---|---|
| Nginx | 任意较新版本 |
| PHP | ≥ 8.0（如 PHP 8.2） |
| MySQL | ≥ 5.7 |
| phpMyAdmin | 用于导入 SQL |

在「软件商店 → 已安装 → PHP 8.2 → 设置 → 安装扩展」勾选并重启 PHP：

```
pdo_mysql   openssl   mbstring   curl   json
```

> 缺 `pdo_mysql` 会报 `PDO Driver not found`；缺 `openssl` 无法加密 SMTP 密码。

## 1. 创建站点 + 数据库

「网站 → 添加站点」，一次填完：

| 字段 | 填什么 |
|---|---|
| 域名 | 你的 API 域名，如 `api.zgric.top`（没有域名可先填服务器公网 IP） |
| 根目录 | 保持默认 `/www/wwwroot/你的域名`，**不要改** |
| 数据库 | 勾「创建数据库」→ MySQL，记下**库名 / 用户名 / 密码** |
| PHP 版本 | 选刚装好的 PHP 8.2 |

提交后，宝塔会自动创建目录、数据库和用户。

> 根目录先别动。下一步会把**运行目录**单独指到 `public/`。

## 2. 上传源码

「文件」进入 `/www/wwwroot/你的域名/`，上传源码压缩包并解压。

或者用宝塔「终端」：

```bash
cd /www/wwwroot/你的域名
# 若直连 GitHub 被墙，用公开只读代理
git clone https://gh-proxy.org/https://github.com/kuangxing6367/mail_zcx.git .
```

解压后**确认目录层级**：

```
/www/wwwroot/你的域名/
├── app/  cli/  config/  deploy/  docs/  sql/  public/  routes.php  README.md
```

如果变成了 `/www/wwwroot/你的域名/mail_zcx/app/...`（多套了一层），把里面所有文件上移到站点根目录。这是上传压缩包最常见的坑。

> `docs/`、`deploy/`、`.git/` 不是运行必需的，可以删掉，但**必须保留** `public/`、`app/`、`config/`、`sql/`、`routes.php`、`cli/`。

## 3. 设置运行目录（关键）

「网站 → 站点名 → 设置 → 网站目录」：

| 项 | 值 |
|---|---|
| 网站目录 | `/www/wwwroot/你的域名`（保持不变） |
| **运行目录** | **`/public`** ✅ |

保存后 nginx 实际指向 `/www/wwwroot/你的域名/public`。

> **不要把运行目录留空，也不要把「网站目录」直接改成 `.../public`。**
> 后者会让宝塔再拼一次 `/public`，变成 `.../public/public`，直接 404。

## 4. 配置伪静态

「设置 → 伪静态」粘贴并保存：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

保存会自动重载 nginx。

## 5. 删掉 error_page 404（必做）

「设置 → 配置文件」，找到并注释掉这一行：

```nginx
# error_page 404 /404.html;
```

> 宝塔默认注入它，会把 API 返回的 **JSON 404** 替换成 HTML 页面，导致调用方解析失败。

同一个文件里确认 `root` 指向 `.../public`，且 `index` 含 `index.php`：

```nginx
root /www/wwwroot/你的域名/public;
index index.php index.html;
```

## 6. 导入数据库

二选一：

**A. phpMyAdmin（图形化）**
「数据库 → 你的库 → 管理(phpMyAdmin) → 导入」→ 选择 `sql/schema.sql` → 执行。
导入后应看到 7 张表。

**B. 终端一键脚本（推荐）**
见下面「一键初始化脚本」，会自动建表并输出默认 API Key。

## 7. 写入配置

「文件」进入 `/www/wwwroot/你的域名/config/`：

1. 复制 `config.local.example.php` → 重命名为 `config.local.php`
2. 编辑，填入第 1 步的数据库凭据与随机 `app_secret`：

```php
<?php
return [
    'app' => ['debug' => false, 'timezone' => 'Asia/Shanghai', 'base_path' => ''],
    'db' => [
        'host'     => '127.0.0.1',   // 用 127.0.0.1，别用 localhost
        'port'     => 3306,
        'database' => '宝塔生成的库名',
        'username' => '宝塔生成的用户名',
        'password' => '宝塔生成的密码',
        'charset'  => 'utf8mb4',
        'prefix'   => 'zc_',
    ],
    'security' => [
        'app_secret'      => 'openssl rand -hex 32 生成的结果',
        'require_api_key' => true,
        'allow_ips'       => [],
        'api_entry'       => '',
    ],
    'log' => ['level' => 'info'],
];
```

生成随机密钥：

```bash
openssl rand -hex 32
```

> `config.local.php` 不要提交到公开仓库（已在 `.gitignore`）。
> `app_secret` 一旦更换，已入库的邮箱 SMTP 密码将无法解密。

## 8. 权限

「文件」里把站点目录属主设为 `www`，或终端执行：

```bash
cd /www/wwwroot/你的域名
chown -R www:www .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 storage
```

`storage/logs`、`storage/certs` 必须可写，否则日志写不进去会报错。

## 9. SSL 证书

「网站 → 设置 → SSL → Let's Encrypt」→ 选域名 → 申请 → 开启「强制 HTTPS」。

> 邮箱授权码会通过 API 传输，**必须上 HTTPS**。

## 10. 验证并打开

```bash
curl https://你的域名/health
```

返回 `{"code":0,"msg":"ok",...}` 即服务正常。

| 打开 | 地址 | 看到 |
|---|---|---|
| 域名根 | `https://你的域名/` | **健康检查 JSON**（不是网页，正常） |
| 控制台 ✅ | `https://你的域名/zc-console/` | 登录界面 |
| 业务接口 | `https://你的域名/api/v1/mailboxes` | 不带 Key 返回 401 |

用安装时输出的默认 API Key 登录控制台，然后**立刻在「API 密钥」页轮换它**。

---

## 11. 启动发信 worker（默认队列必需）

`mail.queue.enabled` 默认 `true`：发信接口只把邮件写入队列并返回 `202`，
真正的 SMTP 投递、重试与 Webhook 回调由 `cli/worker.php` 完成。
**不做这一步，邮件会一直停在 `pending`。**

### 方式 A：宝塔计划任务（最简单，1 分钟兜底）

宝塔 → 计划任务 → 添加任务：

| 项 | 填 |
|---|---|
| 任务类型 | Shell 脚本 |
| 任务名称 | zc-mail-worker |
| 执行周期 | 每 1 分钟 |
| 脚本内容 | `cd /www/wwwroot/你的域名 && php cli/worker.php --once` |

保存后点「执行」手动跑一次，日志应出现 `无待处理任务，退出`。

### 方式 B：supervisor 常驻（推荐，秒级处理）

服务器装了 supervisor 时：

```bash
cp /www/wwwroot/你的域名/deploy/supervisor-mail-worker.conf.example \
   /etc/supervisor/conf.d/zc-mail-worker.conf
vi /etc/supervisor/conf.d/zc-mail-worker.conf   # 改 command / directory 为实际路径

supervisorctl reread
supervisorctl update
supervisorctl status zc-mail-worker
```

### 验证

```bash
cd /www/wwwroot/你的域名
php cli/worker.php --once --verbose
```

发一封测试邮件，接口应返回 `202`；再查：

```bash
curl -H "X-API-Key: zc_xxx" https://你的域名/api/v1/logs/<log_id>
```

`status` 从 `pending` 变为 `success` 即 worker 正常。

> 想改回同步（不需要 worker）：在 `config/config.local.php` 加
> `'mail' => ['queue' => ['enabled' => false]]`，然后重载 php-fpm。

---

## 一键初始化脚本

不想在 phpMyAdmin 里点导入，用宝塔「终端」在项目根目录跑一次：

```bash
bash deploy/bootstrap.sh
```

脚本做四件事，幂等、不写死任何凭据：

1. 检查 PHP 版本与 `pdo_mysql` / `openssl` / `mbstring` / `curl` 扩展
2. 若 `config/config.local.php` 不存在 → 从示例复制一份并提示你填好再重跑
3. 若已存在 → 执行 `php cli/install.php` 建表（输出默认 API Key，仅显示一次）
4. 设置权限（`755` / `644` / `storage` 可写）

```bash
# 指定其他站点路径
bash /path/to/deploy/bootstrap.sh /www/wwwroot/你的域名
```

> 脚本**不会**替你填数据库密码，也不会覆盖已有的 `config.local.php`。
> 填完配置再跑一次即可完成建表。

---

## 常见报错速查

| 现象 | 原因 | 处理 |
|---|---|---|
| 打开域名是一片 JSON | 正常，根路径是健康检查 | 控制台在 `/zc-console/` |
| `/zc-console/` 404 | 运行目录没设 `/public`，或源码多套一层 | 回第 2、3 步 |
| `No input file specified.` | 伪静态未生效 / 运行目录错 | 回第 3、4 步 |
| 403 / 目录列表 | 根目录指到了项目根 | 运行目录改 `/public` |
| 接口 404 返回 HTML | 宝塔 `error_page 404` | 回第 5 步 |
| 白屏 / `Connection refused` | DB 连不上 | 核对凭据；用 `127.0.0.1` |
| `PDO Driver not found` | 缺扩展 | 软件商店 → PHP → 安装扩展 → 重启 |
| 502 / 504 | php-fpm 没起来 | 软件商店重启 PHP；看 `/www/wwwlogs/` 日志 |
| 改了 PHP 代码没生效 | opcache | `/etc/init.d/php-fpm-82 reload` |
| 控制台登录失败 | Key 不对 / 未建表 / 跨域 | 先看 `/health`，再看浏览器控制台报错 |
| 发信返回 202 但日志一直 `pending` | worker 没启动 / 已退出 | 回第 11 步；`php cli/worker.php --once --verbose` 看报错 |
| `zc_send_jobs` 不存在 | 用旧版 `schema.sql` 建的表 | 重新执行 `php cli/install.php` 补建新表 |

> 若套了 Cloudflare：Bot Fight Mode / 托管质询会拦截**服务端**调用（curl 拿到 HTML 质询页而非 JSON）。
> 对 `/api/*` 加 WAF Skip 规则，或关闭该域名的浏览器完整性检查。

## 相关

- [快速开始](/guide) —— 入口说明、手工 nginx 部署、本地预览
- [API 手册](/api)
