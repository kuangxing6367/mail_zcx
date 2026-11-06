<?php

namespace App\Core\Plugin;

use App\Core\Logger;
use Throwable;

/**
 * 主程序暴露给插件的业务扩展点。
 *
 * 事件契约集中在这里，插件按事件名用 $ctx->on() 注册监听器。
 * 四个点覆盖一条邮件的完整生命周期，同步 / 队列 / SMTP 入站三条路径都会走：
 *
 *   prepare 之前 → 落库/入队 → deliver 之前 → SMTP → 终态通知
 *
 * 事件返回值必须是数组；不符合契约的返回值会被丢弃并记日志，
 * 不会让畸形数据混进 MIME 或队列载荷。
 */
final class Hooks
{
    /** 可改 input / context，可用 Hooks::reject() 拒绝（此时还没落库） */
    public const BEFORE_PREPARE = 'mail.before_prepare';

    /** 可改已组装好的 payload（收件人、主题、报文），不可拒绝：此时已落库 */
    public const AFTER_PREPARE = 'mail.after_prepare';

    /** 投递前最后一次改写，可用 Hooks::reject() 拒绝本封投递 */
    public const BEFORE_DELIVER = 'mail.before_deliver';

    /** 终态通知（成功 / 最终失败），只读，抛异常也不影响已完成的投递 */
    public const AFTER_SEND = 'mail.after_send';

    private const PREPARE_SCHEMA = [
        'input'   => 'array',
        'context' => 'array',
    ];

    private const PAYLOAD_SCHEMA = [
        'log_id'       => 'int',
        'request_id'   => 'string',
        'mailbox_id'   => 'int',
        'from_email'   => 'email',
        'from_name'    => 'string',
        'recipients'   => 'array',
        'message'      => 'string',
        'subject'      => 'string',
        'to'           => 'string',
        'cc'           => 'string',
        'bcc'          => 'string',
        'missing_vars' => 'array',
        'input'        => 'array',
        'context'      => 'array',
    ];

    /** @return array{0:array,1:array} [input, context] */
    public static function beforePrepare(array $input, array $context): array
    {
        $data = PluginManager::emit(
            self::BEFORE_PREPARE,
            ['input' => $input, 'context' => $context],
            self::PREPARE_SCHEMA
        );
        return [(array) ($data['input'] ?? $input), (array) ($data['context'] ?? $context)];
    }

    public static function afterPrepare(array $payload): array
    {
        try {
            return PluginManager::emit(self::AFTER_PREPARE, $payload, self::PAYLOAD_SCHEMA);
        } catch (RejectException $e) {
            // 已落库，不能在这里中断留下永远 pending 的日志。
            // 降级为记日志 + 丢弃本次改写，让邮件按原样继续。
            Logger::warning('mail.after_prepare 不允许拒绝，已忽略', ['error' => $e->getMessage()]);
            return $payload;
        }
    }

    public static function beforeDeliver(array $payload): array
    {
        return PluginManager::emit(self::BEFORE_DELIVER, $payload, self::PAYLOAD_SCHEMA);
    }

    /** 只在终态调用一次；同步与队列由各自调用方负责，避免重复触发 */
    public static function afterSend(array $payload, array $result): void
    {
        try {
            PluginManager::emit(
                self::AFTER_SEND,
                ['payload' => $payload, 'result' => $result],
                ['payload' => 'array', 'result' => 'array']
            );
        } catch (Throwable $e) {
            Logger::warning('mail.after_send 事件异常（不影响投递结果）', ['error' => $e->getMessage()]);
        }
    }

    /** 插件用来显式拒绝：Hooks::reject('收件人在黑名单中'); */
    public static function reject(string $reason): void
    {
        throw new RejectException($reason);
    }
}
