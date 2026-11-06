<?php

/**
 * PHP 运行基线检查 —— 所有入口在加载任何业务类之前引入。
 *
 * 本文件必须保持 PHP 7.0 兼容语法：低于门槛的环境也要能先跑到这里，
 * 拿到一句人话错误，而不是等 autoload 到 8.1 语法的文件时白屏 Parse error。
 *
 * 门槛分两层，不能混为一谈：
 *   1) 语法门槛 8.1 —— 代码使用了 readonly 属性（PHP 8.1）。这是硬限制，
 *      面板给老版本打了安全补丁也不会让语法变出来，忽略开关也跳不过。
 *   2) 安全门槛 —— 由 config.php 的 `security.php_baseline` 快照决定。
 *      **应用代码不内置任何上游公告的版本号**：那份数据是部署策略，
 *      属于运维 / 包管理器，上游再发安全版或分支到期时改配置即可，
 *      不需要改代码，也不需要等本项目发版。快照留空则只做第 1 层，
 *      其余分支标 unknown 放行 —— 不臆断补丁状态。
 *
 * 版本号不是补丁状态的证明：宝塔等面板会把官方源码补丁回移到旧分支；
 * 反过来 WAF 与「一键加固」也不能替代实际补丁。所以第 2 层默认拒绝服务，
 * 但允许在 config 里显式忽略（由运维确认后决定）。
 *
 * level: ok | unknown | unsupported | eol | unpatched
 *   - ok / unknown 放行；其余默认拒绝，可用 security.ignore_php_requirement 忽略
 *
 * 参考（快照数据来源，不是运行时依赖）：
 *   https://www.php.net/archive/2026.php                      （2026-10-21 安全公告）
 *   https://docs.bt.cn/notices/php-nodejs-security-update-2026-10-21
 *   https://docs.bt.cn/notices/php-security-protection-guide/
 */

if (!defined('ZC_MAIL_ROOT')) {
    define('ZC_MAIL_ROOT', dirname(__DIR__));
}

if (!function_exists('zc_php_config')) {
    /**
     * 读取三层配置。此时 Config::load() 还没跑，只能自己 include；
     * 顺序与 Config 一致：config.php → config.local.php → config.overrides.php。
     */
    function zc_php_config()
    {
        // 不做进程内缓存：同一进程里改了 overrides 也要能立刻读到
        // （网页设置保存后的自检、回归测试都依赖这一点）。
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
        return $cfg;
    }
}

if (!function_exists('zc_php_baseline')) {
    /**
     * 把配置里的策略快照规整成内部结构。数据本身完全来自配置，代码里
     * 没有任何「哪版修了哪个 CVE」的硬编码；格式不合法的行直接忽略。
     *
     * 支持简写 '分支' => '最低版本'，也支持带截止日的完整写法。
     */
    function zc_php_baseline($cfg)
    {
        $b = array();
        if (isset($cfg['security']['php_baseline']) && is_array($cfg['security']['php_baseline'])) {
            $b = $cfg['security']['php_baseline'];
        }

        $fixed = array();
        if (isset($b['fixed']) && is_array($b['fixed'])) {
            foreach ($b['fixed'] as $branch => $row) {
                $min   = '';
                $until = '';
                if (is_array($row)) {
                    $min   = isset($row['min']) ? (string) $row['min'] : '';
                    $until = isset($row['security_until']) ? (string) $row['security_until'] : '';
                } else {
                    $min = (string) $row;
                }
                if ($min !== '') {
                    $fixed[(string) $branch] = array(
                        'min'            => $min,
                        'security_until' => $until,
                    );
                }
            }
        }

        return array(
            'as_of'  => isset($b['as_of']) ? (string) $b['as_of'] : '',
            'source' => isset($b['source']) ? (string) $b['source'] : '',
            'fixed'  => $fixed,
            'vendor' => (isset($b['vendor']) && is_array($b['vendor'])) ? $b['vendor'] : array(),
        );
    }
}

if (!function_exists('zc_php_requirement')) {
    /** 返回当前 PHP 的基线判定。 */
    function zc_php_requirement()
    {
        return zc_php_requirement_for(PHP_VERSION);
    }

    /**
     * 判定任意版本字符串。单独抽出来是为了能真跑各分支的边界用例，
     * 而不是只能验证「当前这台机器」的版本。
     *
     * @param string     $ver      要判定的 PHP 版本
     * @param array|null $baseline 可选：直接传入策略快照，便于回归测试；
     *                             不传则从三层配置读取
     */
    function zc_php_requirement_for($ver, $baseline = null)
    {
        if ($baseline === null) {
            $baseline = zc_php_baseline(zc_php_config());
        }

        $ver    = (string) $ver;
        $parts  = explode('.', $ver);
        $branch = $parts[0] . '.' . (isset($parts[1]) ? (int) $parts[1] : 0);
        $asOf   = isset($baseline['as_of']) ? (string) $baseline['as_of'] : '';
        $fixed  = isset($baseline['fixed']) && is_array($baseline['fixed'])
            ? $baseline['fixed'] : array();
        $vendor = isset($baseline['vendor']) && is_array($baseline['vendor'])
            ? $baseline['vendor'] : array();

        $out = array(
            'version'        => $ver,
            'branch'         => $branch,
            'syntax_min'     => '8.1',
            'deploy_min'     => '8.2',
            'baseline_as_of' => $asOf,
            'baseline_fixed' => $fixed,
            'vendor'         => $vendor,
            'level'          => 'ok',
            'reason'         => '',
            'hint'           => '',
        );

        // ---- 第 1 层：语法门槛，任何配置都跳不过 ----
        if (version_compare($ver, '8.1.0', '<')) {
            $out['level']  = 'unsupported';
            $out['reason'] = '代码使用 PHP 8.1 的 readonly 属性，' . $ver
                . ' 无法解析；本项目最低可运行版本 8.1、部署基线 8.2。';
            $out['hint']   = '面板给 7.x / 8.0 回补了安全补丁也不改变语法门槛，'
                . '忽略开关不能让本项目在这些版本上运行。请升级 PHP。';
            return $out;
        }

        // 8.1 官方已 EOL 是固定历史事实，不随上游发版变化，无需放进快照。
        if (version_compare($ver, '8.2.0', '<')) {
            $out['level']  = 'eol';
            $out['reason'] = 'PHP ' . $branch
                . ' 已结束官方安全支持，CVE-2026-109618 / CVE-2026-109619 不再有官方安装包。';
            $out['hint']   = '宝塔 Linux 面板提供 '
                . (isset($vendor['8.1']) ? $vendor['8.1'] : '8.1 分支')
                . ' 修复版；Windows 面板 8.1 及更早没有补丁，只能临时缓解。'
                . '确认已安装对应修复包后再忽略，或升级到受支持分支。';
            return $out;
        }

        // ---- 第 2 层：策略快照，数据全在配置里 ----
        if (isset($fixed[$branch])) {
            $row   = $fixed[$branch];
            $min   = isset($row['min']) ? (string) $row['min'] : '';
            $until = isset($row['security_until']) ? (string) $row['security_until'] : '';

            // 分支过了官方安全支持截止日：快照这一行已经不能代表「安全」。
            // 与其拿过期数据继续放行，不如明确拒绝并让运维更新快照 / 升级分支。
            if ($until !== '' && strcmp(date('Y-m-d'), $until) > 0) {
                $out['level']  = 'eol';
                $out['reason'] = '分支 ' . $branch . ' 的官方安全支持已于 ' . $until
                    . ' 结束，基线快照' . ($asOf !== '' ? '（' . $asOf . '）' : '')
                    . '对该分支已失效。';
                $out['hint']   = '升级到仍受支持的分支；同时更新 config.php 的 '
                    . 'security.php_baseline（上游公告为准）。确认运行时已回补补丁也可显式忽略检查。';
                return $out;
            }

            if ($min !== '' && version_compare($ver, $min, '<')) {
                $out['level']  = 'unpatched';
                $out['reason'] = '基线快照要求 ' . $branch . ' ≥ ' . $min
                    . '，当前 ' . $ver . ' 低于该版本。';
                $out['hint']   = '升级该分支并重启 PHP-FPM；若面板已回补补丁，确认后再忽略。'
                    . '这份快照归你维护，上游发新安全版时改 config.php 即可。';
                return $out;
            }
            return $out;
        }

        $out['level'] = 'unknown';
        if (empty($fixed)) {
            $out['reason'] = '未配置安全基线快照（security.php_baseline.fixed 为空），'
                . '本次只做了语法门槛检查。';
            $out['hint']   = '如需按版本拦截，在 config.php 里填入已核实的修复版本；'
                . '否则请以你的发行版 / 面板安全公告为准。';
        } else {
            $out['reason'] = '分支 ' . $branch . ' 不在基线快照'
                . ($asOf !== '' ? '（' . $asOf . '）' : '') . '中，无法从版本号判断补丁状态。';
            $out['hint']   = '请自行核对该版本的安全更新状态，并更新 security.php_baseline；'
                . '已确认修复可在配置里忽略基线检查。';
        }
        return $out;
    }
}

if (!function_exists('zc_php_ignore')) {
    /** 读取忽略开关：环境变量优先，其次三层配置。 */
    function zc_php_ignore()
    {
        $env = getenv('ZC_IGNORE_PHP_REQUIREMENT');
        if ($env !== false && trim((string) $env) !== '' && trim((string) $env) !== '0') {
            return true;
        }

        $cfg = zc_php_config();
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

        // 这是「环境不合规、需人工处理」的持久拒绝，不是临时过载：
        // 不发 Retry-After —— 一小时后自动重试不会让 PHP 自己升级。
        $detail = '当前 PHP ' . $info['version'] . ' 不满足运行基线。'
            . "\n原因：" . $info['reason']
            . "\n建议：" . $info['hint']
            . "\n若发行版 / 面板已回补 CVE-2026-109618、CVE-2026-109619 补丁，"
            . "可在 config/config.local.php 设置 "
            . "'security' => ['ignore_php_requirement' => true] 后重启。";

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            // CLI 是运维本人在跑，版本与策略细节都给他
            fwrite(STDERR, 'ZC Mail 拒绝启动：' . $detail . "\n");
        } else {
            // 匿名访问者不该拿到「你跑的是 8.2.34 / 基线截止日 / 补丁策略」——
            // 那是给攻击者做指纹和挑版本用的。细节只进服务端日志。
            @error_log('ZC Mail 拒绝启动（PHP 基线）：' . $detail);
            if (!headers_sent()) {
                // PHP 自带的 X-Powered-By: PHP/8.3.6 也是版本指纹，一并抹掉
                @header_remove('X-Powered-By');
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, private');
                header('Pragma: no-cache');
                header('X-Content-Type-Options: nosniff');
            }
            echo json_encode(array(
                'code' => 503,
                'msg'  => '服务未就绪：运行环境不满足安全要求，请联系管理员处理（重试不会自动恢复）',
                'data' => null,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit(1);
    }
}
