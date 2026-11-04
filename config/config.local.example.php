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
    // 占位示例：填入宝塔/MySQL 实际凭据，不要把真实密码提交到公开仓库
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'your_database',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset'  => 'utf8mb4',
        'prefix'   => 'zc_',
    ],
    'security' => [
        'app_secret'      => $secret,
        'require_api_key' => true,
        'allow_ips'       => [],
        // 'api_entry'    => '',   // 秘密入口前缀，详见 README「秘密入口」
    ],
    'mail' => [
        'selection'     => 'least_sent',
        'timeout'       => 15,   // 单步 SMTP 超时
        'total_timeout' => 30,   // 整条会话总时限，0 = 不限
    ],
    'webhook' => [
        'async'          => true,   // FPM 下响应后投递（仍占 worker）
        'async_fallback' => 'sync', // 非 FPM 环境：sync（显式同步）| skip（丢弃回调）
        'timeout'        => 5,
        'retry'          => 2,
    ],
    'log' => [
        'level' => 'info',
    ],
];
