<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\RequestContext;
use App\Core\Response;
use App\Mail\Mailer;
use InvalidArgumentException;
use RuntimeException;

/**
 * 发信接口
 *   POST /api/v1/send
 *   GET  /api/v1/send
 */
class MailController extends BaseController
{
    public function send(Request $req, array $params): void
    {
        try {
            $mailer = new Mailer();

            $result = $mailer->send($req->all(), [
                'source_ip'  => $req->ip(),
                'user_agent' => $req->userAgent(),
                'request_id' => (string) RequestContext::get('request_id', ''),
            ]);

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
