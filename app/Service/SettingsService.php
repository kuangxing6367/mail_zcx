<?php

namespace App\Service;

use App\Core\Config;
use InvalidArgumentException;
use RuntimeException;

/**
 * 控制台「系统设置」—— 把少量安全的配置项开放给网页端修改。
 *
 * 设计约束（为什么不是直接改 config.local.php）：
 *   1. config.local.php 里有 getenv() 取密钥之类的逻辑，整文件 var_export 重写会把它变成
 *      明文常量落盘，属于降级；
 *   2. 字段必须能全量校验，不能让人从网页写任意 PHP；
 *   3. 网页端只写 config/config.overrides.php，删掉它即恢复手写配置。
 *
 * 不在白名单里的一律拒绝写入（db 凭据、app_secret、allow_ips 这类改错会自锁的项都不开放）。
 */
class SettingsService
{
    /**
     * 白名单字段定义
     * type: bool | int | enum | string | secret
     * restart: worker = 改动后需重启常驻 worker 才生效
     */
    public const SCHEMA = [
        // ---------- 发信与队列 ----------
        'mail.queue.enabled' => [
            'group' => '发信与队列', 'label' => '异步队列',
            'type'  => 'bool', 'restart' => 'worker',
            'hint'  => '开：接口立即返回 202，必须有 worker 或 Web Cron 在跑；关：请求内直接投递，返回 200',
        ],
        'mail.timeout' => [
            'group' => '发信与队列', 'label' => '单步 SMTP 超时（秒）',
            'type'  => 'int', 'min' => 3, 'max' => 120, 'restart' => 'worker',
            'hint'  => '一次读写的等待上限，QQ 邮箱这类公网 SMTP 建议 10~20',
        ],
        'mail.total_timeout' => [
            'group' => '发信与队列', 'label' => '整条会话总时限（秒）',
            'type'  => 'int', 'min' => 0, 'max' => 300, 'restart' => 'worker',
            'hint'  => '0 = 不限。虚拟主机常有 30 秒脚本超时，同步模式建议 20 以内',
        ],
        'mail.selection' => [
            'group' => '发信与队列', 'label' => '邮箱选择策略',
            'type'  => 'enum', 'restart' => 'worker',
            'options' => [
                'least_sent'  => '累计发件最少（默认，均衡）',
                'round_robin' => '轮询',
                'random'      => '随机',
            ],
        ],
        'mail.smtp_verify_tls' => [
            'group' => '发信与队列', 'label' => '校验 SMTP 证书',
            'type'  => 'bool', 'restart' => 'worker',
            'hint'  => '只有自签名证书的内网 SMTP 才需要关闭；关闭后无法防中间人',
        ],
        'mail.queue.max_attempts' => [
            'group' => '发信与队列', 'label' => '单封最多投递次数',
            'type'  => 'int', 'min' => 1, 'max' => 10, 'restart' => 'worker',
        ],
        'mail.queue.retry_base' => [
            'group' => '发信与队列', 'label' => '重试退避基数（秒）',
            'type'  => 'int', 'min' => 10, 'max' => 3600, 'restart' => 'worker',
            'hint'  => '第 n 次重试等待 retry_base × 2^(n-1)',
        ],
        'mail.queue.web_cron_batch' => [
            'group' => '发信与队列', 'label' => 'Web Cron 单批封数',
            'type'  => 'int', 'min' => 1, 'max' => 20, 'restart' => 'worker',
            'hint'  => '共享主机 30 秒超时，单封 1~3 秒，5 封足够安全',
        ],

        // ---------- Webhook ----------
        'webhook.async' => [
            'group' => 'Webhook', 'label' => '异步投递回调',
            'type'  => 'bool',
            'hint'  => 'FPM 下响应后继续投递；非 FPM 环境按下面的兜底策略处理',
        ],
        'webhook.async_fallback' => [
            'group' => 'Webhook', 'label' => '非 FPM 环境兜底',
            'type'  => 'enum',
            'options' => [
                'sync' => '同步投递（可能拖慢响应）',
                'skip' => '直接丢弃回调',
            ],
        ],
        'webhook.verify_tls' => [
            'group' => 'Webhook', 'label' => '校验回调方证书',
            'type'  => 'bool',
            'hint'  => '默认开启；对自签名的内部接收端才关闭',
        ],
        'webhook.allow_private_hosts' => [
            'group' => 'Webhook', 'label' => '允许回调到内网地址',
            'type'  => 'bool',
            'hint'  => '开启后 127.0.0.1 / 10.x / 169.254.169.254 都能访问，等同于关掉 SSRF 防护，仅内网自建接收端才开',
        ],

        // ---------- 安全与入口 ----------
        'security.api_entry' => [
            'group' => '安全与入口', 'label' => 'API 秘密入口前缀',
            'type'  => 'string', 'pattern' => '/^[A-Za-z0-9_-]{0,32}$/',
            'hint'  => '留空 = 标准 /api/v1。设为 x9m2kq 后真实入口变为 /x9m2kq/api/v1，控制台里的 API_ENTRY 要同步改',
        ],
        'security.cron_token' => [
            'group' => '安全与入口', 'label' => 'Web Cron 口令',
            'type'  => 'secret',
            'hint'  => '计划任务访问 /zc-cron.php?token=此值；留空保存表示不修改',
        ],
        'security.inbound_secret' => [
            'group' => '安全与入口', 'label' => '入站 Webhook 签名密钥',
            'type'  => 'secret',
            'hint'  => '留空 = /hook/inbound 关闭',
        ],
        'security.auth_fail_limit' => [
            'group' => '安全与入口', 'label' => '鉴权失败锁定阈值（次）',
            'type'  => 'int', 'min' => 3, 'max' => 100,
        ],
        'security.auth_fail_window' => [
            'group' => '安全与入口', 'label' => '锁定统计窗口（秒）',
            'type'  => 'int', 'min' => 60, 'max' => 86400,
        ],
        'security.cors_origins' => [
            'group' => '安全与入口', 'label' => 'CORS 允许来源',
            'type'  => 'string', 'pattern' => '/^[A-Za-z0-9\-\.\*:\/,\s]*$/',
            'hint'  => '逗号分隔；留空 = 不发 CORS 头（同源部署不需要填）',
        ],
        'security.trusted_proxies' => [
            'group' => '安全与入口', 'label' => '可信反代地址',
            'type'  => 'string', 'list' => true, 'pattern' => '/^[A-Za-z0-9\-\.\*:,\s\[\]]*$/',
            'hint'  => '只有 REMOTE_ADDR 命中此处才采信 X-Forwarded-For；套 CDN 时填回源 IP，逗号分隔',
        ],

        // ---------- 排错 ----------
        'app.debug' => [
            'group' => '排错', 'label' => '调试模式',
            'type'  => 'bool',
            'hint'  => '开启后接口错误会带回异常详情，仅排障时短暂开启',
        ],
    ];

    /** 明确不开放网页修改的项，只在界面提示需要手工编辑 */
    public const READONLY = [
        'db.*'                    => '数据库凭据改错会直接连不上，请用面板文件管理器改 config.local.php',
        'security.app_secret'     => '根密钥用于加密 SMTP 密码，替换后既有密码全部无法解密',
        'security.allow_ips'      => 'IP 白名单写错会把自己锁在外面',
        'security.require_api_key'=> '关闭后所有接口裸奔，只能手工改',
        'smtp_in.*'               => 'SMTP 入站是常驻进程，需改配置并重启才生效',
        'mail.attachment_roots'   => '涉及服务器绝对路径，需人工确认',
    ];

    /** 界面数据 */
    public function all(): array
    {
        $groups = [];
        foreach (self::SCHEMA as $key => $def) {
            $group = $def['group'];
            $groups[$group] ??= [];
            $groups[$group][] = $this->describe($key, $def);
        }

        $out = [];
        foreach ($groups as $name => $fields) {
            $out[] = ['name' => $name, 'fields' => $fields];
        }

        $file = Config::overridePath();
        return [
            'groups'      => $out,
            'readonly'    => self::READONLY,
            'file'        => $file,
            'exists'      => is_file($file),
            'writable'    => $this->writable(),
            'writable_note' => $this->writable() ? '' : $this->writableError(),
        ];
    }

    /**
     * 保存：全部校验通过才落盘，不做部分写入
     * @param array<string,mixed> $input
     */
    public function save(array $input): array
    {
        if (!$input) {
            throw new InvalidArgumentException('没有要保存的配置项');
        }

        $clean = [];
        foreach ($input as $key => $raw) {
            $key = (string) $key;
            if (!isset(self::SCHEMA[$key])) {
                throw new InvalidArgumentException("不支持在线修改的配置项：{$key}");
            }
            $value = $this->cast($key, self::SCHEMA[$key], $raw);
            if ($value !== self::UNCHANGED) {
                $clean[$key] = $value;
            }
        }

        if (!$clean) {
            throw new InvalidArgumentException('没有检测到改动');
        }

        $merged = $this->merge($this->readOverride(), $clean);
        $ok = $this->write($merged);

        foreach ($clean as $key => $value) {
            Config::set($key, $value);
        }

        $restart = [];
        foreach ($clean as $key => $_) {
            if ((self::SCHEMA[$key]['restart'] ?? '') === 'worker') {
                $restart['worker'] = true;
            }
        }

        return [
            'saved'   => array_keys($clean),
            'restart' => array_keys($restart),
            'values'  => $this->currentValues(),
            'file'    => Config::overridePath(),
        ];
    }

    /** 删除覆盖文件，恢复 config.local.php 的原始值 */
    public function reset(): array
    {
        $file = Config::overridePath();
        if (is_file($file)) {
            if (!@unlink($file)) {
                throw new RuntimeException($this->writableError());
            }
            $this->invalidate($file);
        }
        return ['removed' => true, 'file' => $file];
    }

    // ================= 内部 =================

    private const UNCHANGED = "\0__unchanged__";

    private function describe(string $key, array $def): array
    {
        $value = Config::get($key);
        $secret = ($def['type'] ?? '') === 'secret';

        return [
            'key'     => $key,
            'label'   => $def['label'] ?? $key,
            'type'    => $def['type'] ?? 'string',
            'hint'    => $def['hint'] ?? '',
            'min'     => $def['min'] ?? null,
            'max'     => $def['max'] ?? null,
            'options' => $def['options'] ?? null,
            'restart' => $def['restart'] ?? '',
            // 密钥类只回「有没有设置」，不回明文
            'value'   => $secret ? null : $this->normalizeOut($def['type'] ?? 'string', $value),
            'is_set'  => $secret ? ($value !== null && $value !== '') : null,
        ];
    }

    private function normalizeOut(string $type, mixed $value): mixed
    {
        // 个别项在配置里是数组（如 trusted_proxies），界面上按逗号串展示
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }
        return match ($type) {
            'bool' => $value === true || $value === 1 || $value === '1' || $value === 'true',
            'int'  => is_numeric($value) ? (int) $value : 0,
            default => $value === null ? '' : (string) $value,
        };
    }

    private function currentValues(): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            $value = Config::get($key);
            $out[$key] = ($def['type'] === 'secret')
                ? ($value !== null && $value !== '')
                : $this->normalizeOut($def['type'], $value);
        }
        return $out;
    }

    /** 严格类型转换；返回 UNCHANGED 表示保持原值不动 */
    private function cast(string $key, array $def, mixed $raw): mixed
    {
        $type = $def['type'] ?? 'string';
        $label = $def['label'] ?? $key;

        switch ($type) {
            case 'bool':
                if (is_bool($raw)) {
                    return $raw;
                }
                if (in_array($raw, [1, '1', 'true', 'on', 'yes'], true)) {
                    return true;
                }
                if (in_array($raw, [0, '0', 'false', 'off', 'no', ''], true)) {
                    return false;
                }
                throw new InvalidArgumentException("{$label}：只能是开或关");

            case 'int':
                if ($raw === '' || $raw === null) {
                    throw new InvalidArgumentException("{$label}：不能为空");
                }
                if (!is_numeric($raw) || (string) (int) $raw !== (string) $raw) {
                    throw new InvalidArgumentException("{$label}：必须是整数");
                }
                $n = (int) $raw;
                if (isset($def['min']) && $n < $def['min']) {
                    throw new InvalidArgumentException("{$label}：不能小于 {$def['min']}");
                }
                if (isset($def['max']) && $n > $def['max']) {
                    throw new InvalidArgumentException("{$label}：不能大于 {$def['max']}");
                }
                return $n;

            case 'enum':
                $allowed = array_keys($def['options'] ?? []);
                if (!in_array((string) $raw, $allowed, true)) {
                    throw new InvalidArgumentException("{$label}：取值只能是 " . implode(' / ', $allowed));
                }
                return (string) $raw;

            case 'secret':
                // null = 明确清除；'' = 不修改
                if ($raw === null) {
                    return '';
                }
                $raw = trim((string) $raw);
                if ($raw === '') {
                    return self::UNCHANGED;
                }
                if (!preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $raw)) {
                    throw new InvalidArgumentException("{$label}：至少 16 位，只用字母、数字、下划线、短横线");
                }
                return $raw;

            default: // string
                $raw = trim((string) $raw);
                if (mb_strlen($raw) > 255) {
                    throw new InvalidArgumentException("{$label}：内容过长");
                }
                if (isset($def['pattern']) && !preg_match($def['pattern'], $raw)) {
                    throw new InvalidArgumentException("{$label}：含有不允许的字符");
                }
                // 配置里是数组的项（trusted_proxies），按逗号拆开写回，否则 (array) 强转会得到
                // 一个「整串」元素，IP 匹配直接失效
                if (!empty($def['list'])) {
                    return array_values(array_filter(
                        array_map('trim', explode(',', $raw)),
                        static fn ($v) => $v !== ''
                    ));
                }
                return $raw;
        }
    }

    private function readOverride(): array
    {
        $file = Config::overridePath();
        if (!is_file($file)) {
            return [];
        }
        $data = include $file;
        return is_array($data) ? $data : [];
    }

    /** 点号键拍平成嵌套数组 */
    private function merge(array $base, array $flat): array
    {
        foreach ($flat as $key => $value) {
            $segments = explode('.', $key);
            $last = array_pop($segments);
            $ref = &$base;
            foreach ($segments as $seg) {
                if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                    $ref[$seg] = [];
                }
                $ref = &$ref[$seg];
            }
            $ref[$last] = $value;
            unset($ref);
        }
        return $base;
    }

    private function write(array $data): bool
    {
        $file = Config::overridePath();
        $dir  = dirname($file);

        if (!is_dir($dir)) {
            throw new RuntimeException('配置目录不存在：' . $dir);
        }
        if (!is_writable($dir) || (is_file($file) && !is_writable($file))) {
            throw new RuntimeException($this->writableError());
        }

        $code = "<?php\n"
            . "/**\n"
            . " * 本文件由控制台「系统设置」生成，请勿手工编辑。\n"
            . " * 手工配置请写 config.local.php；删除本文件即恢复为手工配置的值。\n"
            . " * 生成时间：" . date('Y-m-d H:i:s') . "\n"
            . " */\n\n"
            . 'return ' . $this->dump($data) . ";\n";

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
            throw new RuntimeException('写入失败：' . $this->writableError());
        }

        // 落盘前先确认这份代码能正常被 require 回来，语法坏了立刻回滚
        try {
            $check = include $tmp;
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new RuntimeException('生成的配置无法解析，已放弃写入：' . $e->getMessage());
        }
        if (!is_array($check) || $check !== $data) {
            @unlink($tmp);
            throw new RuntimeException('生成的配置校验不通过，已放弃写入');
        }

        if (is_file($file)) {
            @copy($file, $file . '.bak');
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('保存失败，请检查 config 目录权限');
        }

        @chmod($file, 0640);
        $this->invalidate($file);
        $this->invalidate($file . '.bak');
        return true;
    }

    private function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function writable(): bool
    {
        $file = Config::overridePath();
        $dir = dirname($file);
        return is_dir($dir) && is_writable($dir) && (!is_file($file) || is_writable($file));
    }

    private function writableError(): string
    {
        $dir = dirname(Config::overridePath());
        $user = '当前进程用户';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                $user = $info['name'];
            }
        }
        return "config 目录不可写：{$dir}（php 进程用户 {$user}）。"
            . '请在面板文件管理器里把 config 目录权限改成可写，或直接手工编辑 config.local.php。';
    }

    private function dump(mixed $value, int $level = 0): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $pad = str_repeat('    ', $level + 1);
            $out = "[\n";
            foreach ($value as $k => $v) {
                $out .= $pad . var_export($k, true) . ' => ' . $this->dump($v, $level + 1) . ",\n";
            }
            return $out . str_repeat('    ', $level) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return var_export((string) $value, true);
    }
}
