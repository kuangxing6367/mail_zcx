<?php

namespace App\Core\Plugin;

use RuntimeException;

/**
 * 插件显式拒绝继续处理。
 *
 * 唯一会被事件总线原样向上抛的异常，用于「拦截」语义：
 *   - mail.before_prepare：拒绝后请求返回 400，且不会落任何日志
 *   - mail.before_deliver：拒绝后本封信按失败处理（队列走重试/最终失败）
 *
 * 其它任何 Throwable 一律被事件总线吞掉并记日志（单插件出错不拖垮发信）。
 * 不提供乱码/畸形返回值来「碰运气」拒绝 —— 那只会得到一条警告并被忽略。
 */
class RejectException extends RuntimeException
{
}
