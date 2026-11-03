<?php
/**
 * 环境专属配置 —— 仅存在于服务器，不提交版本库
 * 自动覆盖 config/config.php 中同名项
 */

$secret = trim(@file_get_contents('/tmp/zc_app_secret_local') ?: '');
if ($secret === '') {
    // 兜底：从环境变量或固定值（首次部署后请改为随机值）
    $secret = getenv('ZC_APP_SECRET') ?: '__APP_SECRET__';
}

return [
    'app' => [
        'debug'    => false,
        'timezone' => 'Asia/Shanghai',
        'base_path'=> '',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'mail_zgric_top',
        'username' => 'mail_zgric_top',
        'password' => '4FP1ban6EjGjZFM3',
        'charset'  => 'utf8mb4',
        'prefix'   => 'zc_',
    ],
    'security' => [
        'app_secret'      => $secret,
        'require_api_key' => true,
        'allow_ips'       => [],
    ],
    'mail' => [
        'selection' => 'least_sent',
        'timeout'   => 15,
    ],
    'log' => [
        'level' => 'info',
    ],
];
