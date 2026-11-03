# 快速开始

把 ZC Mail Service 部署到自己的服务器上，大约需要 10 分钟。

## 环境要求

| 项 | 要求 |
|---|---|
| PHP | ≥ 8.0 |
| 扩展 | `pdo_mysql`、`openssl`、`mbstring`、`curl`、`json` |
| 数据库 | MySQL ≥ 5.7 |
| Web 服务器 | nginx 或 Apache，站点根目录指向 `public/` |

不需要 composer，也不需要 Node.js —— 服务本身零依赖。

## 1. 获取代码

```bash
git clone https://github.com/kuangxing6367/mail_zcx.git
```

## 2. 配置

复制示例配置并填入真实凭据：

```bash
cp config/config.local.example.php config/config.local.php
```

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

`app_secret` 用于加密 SMTP 授权码与派生 HMAC 密钥，**一旦更换，已入库的邮箱密码将无法解密**。

## 3. 建表

```bash
php cli/install.php
```

会创建 7 张表，并输出一个默认 API Key（仅显示一次，请立即保存）。

## 4. 配置 nginx

```nginx
server {
    listen 443 ssl http2;
    server_name mail.example.com;

    root /www/wwwroot/mail.example.com/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

> 宝塔面板会默认注入 `error_page 404 /404.html;`，这会把 API 的 JSON 404 替换成 HTML，请注释掉。

## 5. 验证

```bash
curl https://mail.example.com/health
```

```json
{ "code": 0, "msg": "ok", "data": { "service": "ZC Mail Service", "version": "1.0.0" } }
```

无 Key 访问业务接口应返回 401，说明鉴权生效：

```bash
curl -i https://mail.example.com/api/v1/mailboxes
```

## 6. 添加发件邮箱

```bash
curl -X POST https://mail.example.com/api/v1/mailboxes \
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

添加后可在控制台点「测试」，会展示完整 SMTP 会话记录，便于定位认证失败、端口被封等问题。

## 7. 打开发信

```bash
curl -X POST https://mail.example.com/api/v1/send \
  -H "X-API-Key: zc_xxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{"to":"someone@example.com","subject":"你好","html":"<h1>正文</h1>"}'
```

不传 `mailbox_id` 时自动选择累计发件量最少的可用邮箱。

## 控制台

访问 `https://mail.example.com/admin/`，粘贴 API Key 登录。Key 存在浏览器 localStorage，不会上传到别处。

## 本地联调

不需要真实 SMTP 账号也能跑通发送链路：

```bash
# 终端 1：mock SMTP 服务器
php -d extension=openssl cli/mock_smtp.php --port=2525 --mode=plain

# 终端 2：发送自测
php -d extension=openssl cli/test_smtp.php --port=2525 --secure=none
```

`--mode` 支持 `plain`(25) / `tls`(587) / `ssl`(465)。

## 下一步

- [API 手册](/api) —— 全部接口与参数
- [设计说明](/architecture) —— 分层、选箱并发、签名机制
