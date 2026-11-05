#!/usr/bin/env bash
# ZC Mail Service — 服务器一键初始化（宝塔终端 / SSH 均可）
# 幂等：可重复执行；不会覆盖已存在的 config.local.php，不写死任何凭据。
# 用法： bash deploy/bootstrap.sh [项目根目录]
set -euo pipefail

ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ROOT"
echo "==> 项目目录: $ROOT"

# 1. PHP 版本
php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || {
  echo "✗ 需要 PHP >= 8.0，当前: $(php -v | head -1)"
  exit 1
}
echo "==> PHP: $(php -v | head -1)"

# 2. 必需扩展
MISSING=""
for ext in pdo_mysql openssl mbstring curl; do
  php -m | grep -qi "^${ext}$" || MISSING="$MISSING $ext"
done
if [ -n "$MISSING" ]; then
  echo "✗ 缺少 PHP 扩展:$MISSING"
  echo "  宝塔: 软件商店 → PHP → 设置 → 安装扩展，勾选后重启 PHP"
  exit 1
fi
echo "==> 扩展检查通过 (pdo_mysql openssl mbstring curl)"

# 3. 配置文件
if [ ! -f config/config.local.php ]; then
  cp config/config.local.example.php config/config.local.php
  echo "==> 已生成 config/config.local.php"
  echo "    请先编辑它，填入数据库凭据与 app_secret（openssl rand -hex 32），然后重跑本脚本。"
  exit 0
fi
echo "==> 检测到 config/config.local.php，跳过生成"

# 4. 建表
echo "==> 执行 cli/install.php ..."
php cli/install.php

# 5. 权限（非 root 环境下 chmod 失败不阻断）
mkdir -p storage/logs storage/certs
chmod 755 . 2>/dev/null || true
find . -type d -exec chmod 755 {} \; 2>/dev/null || true
find . -type f -exec chmod 644 {} \; 2>/dev/null || true
chmod -R 775 storage 2>/dev/null || true

echo ""
echo "==> 初始化完成"
echo "    当前发信模式：同步（config.local.php 里 mail.queue.enabled = false）"
echo "    要改成异步队列（接口返回 202、后台重试）："
echo "      · 控制台「系统设置 → 发信与队列」把「异步队列」打开，或"
echo "      · 编辑 config/config.local.php 设 'queue' => ['enabled' => true]"
echo "      两种方式都必须让 worker 跑起来，否则邮件会停在 pending："
echo "        supervisor: deploy/supervisor-mail-worker.conf.example"
echo "        或宝塔计划任务每分钟执行 php cli/worker.php --once"
echo ""
echo "    下一步："
echo "      1. 宝塔站点「运行目录」设为 /public，伪静态填 try_files"
echo "      2. 注释掉 nginx 配置里的 error_page 404 /404.html"
echo "      3. curl https://你的域名/health 验证"
echo "      4. 打开 https://你的域名/zc-console/ 用上面输出的 API Key 登录并立即轮换"
