<?php

namespace App\Controller;

use App\Core\Config;
use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Mail\Mailer;
use App\Service\QueueService;
use InvalidArgumentException;
use RuntimeException;

/**
 * 发信接口
 *   POST /api/v1/send
 *   GET  /api/v1/send
 *
 * 执行模式由 mail.queue.enabled 决定：
 *   true  → prepare 落库后立即返回 202（queued），worker 后台投递
 *   false → 同步完成 SMTP 投递并返回结果（旧行为）
 */
class MailController extends BaseController
{
    public function send(Request $req, array $params): void
    {
        $context = [
            'source_ip'  => $req->ip(),
            'user_agent' => $req->userAgent(),
            'request_id' => (string) RequestContext::get('request_id', ''),
        ];

        try {
            if ((bool) Config::get('mail.queue.enabled', false)) {
                // ---- 异步队列 ----
                $mailer  = new Mailer();
                $payload = $mailer->prepare($req->all(), $context);

                try {
                    (new QueueService())->enqueue(
                        (int) $payload['log_id'],
                        $payload,
                        (int) Config::get('mail.queue.max_attempts', 3)
                    );
                } catch (\Throwable $e) {
                    // 入队失败：把已落库的 pending 日志标记为 failed，避免留下永远 pending 的脏数据
                    (new \App\Service\LogService())->update((int) $payload['log_id'], [
                        'status' => 'failed',
                        'error'  => '入队失败: ' . $e->getMessage(),
                    ]);
                    throw new RuntimeException('入队失败: ' . $e->getMessage(), 0, $e);
                }

                Response::json([
                    'log_id'     => $payload['log_id'],
                    'request_id' => $payload['request_id'],
                    'status'     => 'queued',
                    'check_url'  => '/api/v1/logs/' . $payload['log_id'],
                ], 0, '已加入发送队列，请通过 GET /api/v1/logs/' . $payload['log_id'] . ' 查询结果或等待 Webhook 回调', 202);
                return;
            }

            // ---- 同步（队列关闭时的降级路径）----
            $mailer = new Mailer();
            $result = $mailer->send($req->all(), $context);

            if ($result['status'] === 'success') {
                Response::json($result, 0, '发送成功', 200);
            } else {
                Response::json($result, 1001, '发送失败: ' . ($result['error'] ?: '未知错误'), 200);
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            // 参数错误、无可用邮箱、模板不存在等业务异常统一返回 400 + 具体原因
            Response::error($e->getMessage(), 400, 400);
        }
    }
}
