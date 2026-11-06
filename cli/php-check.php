<?php

/**
 * PHP 运行基线自检（供 deploy/bootstrap.sh 与运维排查使用）
 *
 *   php cli/php-check.php
 *
 * 不满足基线时打印原因并以非零码退出；设置
 * security.ignore_php_requirement = true 或环境变量 ZC_IGNORE_PHP_REQUIREMENT=1
 * 可跳过（用于面板/发行版已回补安全补丁的场景）。
 */

require dirname(__DIR__) . '/bootstrap/requirements.php';

zc_php_guard();

$info = zc_php_requirement();
echo '==> PHP ' . $info['version'] . '，运行基线检查通过'
    . '（语法门槛 ' . $info['syntax_min'] . '，部署基线 ' . $info['deploy_min'] . '）' . PHP_EOL;

if ($info['level'] === 'unknown') {
    echo '==> 提示：' . $info['reason'] . ' ' . $info['hint'] . PHP_EOL;
}
if (zc_php_ignore()) {
    echo '==> 注意：已通过配置忽略运行基线检查，请自行确认 PHP 已打对应安全补丁。' . PHP_EOL;
}

exit(0);
