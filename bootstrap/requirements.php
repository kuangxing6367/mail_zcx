<?php

/**
 * PHP 运行基线检查 —— 所有入口在加载任何业务类之前引入。
 *
 * 本文件必须保持 PHP 7.0 兼容语法：低于门槛的环境也要能先跑到这里，
 * 拿到一句人话错误，而不是等 autoload 到 8.1 语法的文件时白屏 Parse error。
 *
 * 门槛分两层，不能混为一谈：
 *   1) 语法门槛 8.1 —— 代码使用了 readonly 属性（PHP 8.1）。这是硬限制，
 *      面板给老版本打了安全补丁也不会让语法变出来。
 *   2) 安全门槛 8.2 + 已核实的安全小版本 —— 2026-10-21 PHP 官方修复了
 *      CVE-2026-109618 / CVE-2026-109619（未认证 RCE，CVSS v4.0 9.2）。
 *      官方只给 8.2~8.5 发安装包；8.1 及更早已 EOL。
 *
 * 版本号不是补丁状态的证明：宝塔等面板会把官方源码补丁回移到旧分支
 * （8.1.35 / 8.0.31 / 7.4.34，7.0~7.3 为逐行比对回移版）。
 * 所以第 2 层默认拒绝服务，但允许在 config 里显式忽略（由运维确认后决定）。
 *
 * 参考：
 *   https://www.php.net/archive/2026.php                      （2026-10-21 安全公告）
 *   https://docs.bt.cn/notices/php-nodejs-security-update-2026-10-21
 *   https://docs.bt.cn/notices/php-security-protection-guide/
 */

if (!defined('ZC_MAIL_ROOT')) {
    define('ZC_MAIL_ROOT', dirname(__DIR__));
}

if (!function_exists('zc_php_requirement')) {
    /**
     * 返回当前 PHP 的基线判定。
     * level: ok | unknown | unsupported | eol | unpatched
     *   - ok / unknown 放行（unknown = 未纳入已核实版本表，不臆断，请自行确认）
     *   - 其余默认拒绝服务，可用 security.ignore_php_requirement 忽略
     */
    function zc_php_requirement()
    {
        return zc_php_requirement_for(PHP_VERSION);
    }

    /**
     * 判定任意版本字符串。单独抽出来是为了能真跑各分支的边界用例，
     * 而不是只能验证「当前这台机器」的版本。
     */
    function zc_php_requirement_for($ver)
    {
        // PHP 官方安全修复版（php.net 2026-10-21 公告）
        $official = array(
            '8.2' => '8.2.35',
            '8.3' => '8.3.36',
            '8.4' => '8.4.27',
            '8.5' => '8.5.12',
        );

        // 面板 / 发行版回补的修复版（宝塔官方文档 2026-10-21、10-27）
        // 只用于给运维提示，绝不作为自动放行依据：光看版本号无法证明
        // 运行时真的装了对应修复包（Windows 面板 8.1 及更早就没有补丁）。
        $vendor = array(
            '8.1' => '8.1.35',
            '8.0' => '8.0.31',
            '7.4' => '7.4.34',
            '7.3' => '7.3.34',
            '7.2' => '7.2.35',
            '7.1' => '7.1.34',
            '7.0' => '7.0.34',
        );

        $ver    = (string) $ver;
        $parts  = explode('.', $ver);
        $branch = $parts[0] . '.' . (isset($parts[1]) ? (int) $parts[1] : 0);
        $out    = array(
            'version'    => $ver,
            'branch'     => $branch,
            'syntax_min' => '8.1',
            'deploy_min' => '8.2',
            'official'   => $official,
            'vendor'     => $vendor,
            'level'      => 'ok',
            'reason'     => '',
            'hint'       => '',
        );

        if (version_compare($ver, '8.1.0', '<')) {
            $out['level']  = 'unsupported';
            $out['reason'] = '代码使用 PHP 8.1 的 readonly 属性，' . $ver
                . ' 无法解析；本项目最低可运行版本 8.1、部署基线 8.2。';
            $out['hint']   = '面板给 7.x / 8.0 回补了安全补丁也不改变语法门槛，'
                . '忽略开关不能让本项目在这些版本上运行。请升级 PHP。';
            return $out;
        }

        if (version_compare($ver, '8.2.0', '<')) {
            $out['level']  = 'eol';
            $out['reason'] = 'PHP ' . $branch
                . ' 已结束官方安全支持，CVE-2026-109618 / CVE-2026-109619 不再有官方安装包。';
            $out['hint']   = '宝塔 Linux 面板提供 ' . $vendor['8.1']
                . ' 修复版；Windows 面板 8.1 及更早没有补丁，只能临时缓解。'
                . '确认已安装对应修复包后再忽略。';
            return $out;
        }

        if (isset($official[$branch])) {
            if (version_compare($ver, $official[$branch], '<')) {
                $out['level']  = 'unpatched';
                $out['reason'] = 'PHP ' . $branch . ' 的官方安全修复版是 '
                    . $official[$branch] . '，当前 ' . $ver . ' 低于该版本。';
                $out['hint']   = '升级该分支并重启 PHP-FPM；若面板已回补补丁，确认后再忽略。';
                return $out;
            }
            return $out;
        }

        $out['level']  = 'unknown';
        $out['reason'] = '分支 ' . $branch . ' 未纳入已核实的安全基线表，无法从版本号判断补丁状态。';
        $out['hint']   = '请自行核对该版本的安全更新状态；已确认修复可在配置里忽略基线检查。';
        return $out;
    }
}

if (!function_exists('zc_php_ignore')) {
    /**
     * 读取忽略开关。此时 Config::load() 还没跑，只能自己读配置文件；
     * 三层配置按与 Config 相同的顺序合并。
     */
    function zc_php_ignore()
    {
        $env = getenv('ZC_IGNORE_PHP_REQUIREMENT');
        if ($env !== false && trim((string) $env) !== '' && trim((string) $env) !== '0') {
            return true;
        }

        $files = array(
            ZC_MAIL_ROOT . '/config/config.php',
            ZC_MAIL_ROOT . '/config/config.local.php',
            ZC_MAIL_ROOT . '/config/config.overrides.php',
        );
        $cfg = array();
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            try {
                $part = include $file;
                if (is_array($part)) {
                    $cfg = array_replace_recursive($cfg, $part);
                }
            } catch (Throwable $e) {
                // 用户手写配置里可能有高版本语法，在低版本 PHP 上解析失败；
                // 跳过这一层，继续用默认值判断，不要在这里二次致命错误。
            }
        }

        $val = isset($cfg['security']['ignore_php_requirement'])
            ? $cfg['security']['ignore_php_requirement']
            : false;
        return $val === true || $val === 1 || $val === '1' || $val === 'true';
    }
}

if (!function_exists('zc_php_guard')) {
    /** 不满足基线就直接拒绝服务；Web 返回 503 JSON，CLI 打印到 STDERR 后退出。 */
    function zc_php_guard()
    {
        if (zc_php_ignore()) {
            return;
        }

        $info = zc_php_requirement();
        if ($info['level'] === 'ok' || $info['level'] === 'unknown') {
            return;
        }

        $message = 'ZC Mail 拒绝启动：当前 PHP ' . $info['version'] . ' 不满足运行基线。'
            . "\n原因：" . $info['reason']
            . "\n建议：" . $info['hint']
            . "\n若发行版 / 面板已回补 CVE-2026-109618、CVE-2026-109619 补丁，"
            . "可在 config/config.local.php 设置 "
            . "'security' => ['ignore_php_requirement' => true] 后重启。";

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, $message . "\n");
        } else {
            @error_log($message);
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                header('Retry-After: 3600');
            }
            echo json_encode(array(
                'code' => 503,
                'msg'  => 'PHP 版本不满足运行基线，服务已拒绝启动',
                'data' => array(
                    'current'    => $info['version'],
                    'syntax_min' => $info['syntax_min'],
                    'deploy_min' => $info['deploy_min'],
                    'level'      => $info['level'],
                    'reason'     => $info['reason'],
                    'hint'       => $info['hint'],
                ),
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit(1);
    }
}
