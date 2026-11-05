<?php

namespace App\Controller;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Service\WebhookService;

/**
 * Webhook 双向
 *   出站：配置 CRUD + 投递记录
 *   入站：/api/v1/hook/inbound 接收外部推送
 */
class WebhookController extends BaseController
{
    // ---------------- 出站配置 ----------------

    public function index(Request $req, array $params): void
    {
        $svc = new WebhookService();
        Response::ok($svc->list());
    }

    public function show(Request $req, array $params): void
    {
        $svc = new WebhookService();
        $row = $svc->get((int) $params['id']);
        if (!$row) {
            Response::notFound('Webhook 配置不存在');
            return;
        }
        Response::ok($row);
    }

    public function store(Request $req, array $params): void
    {
        $this->handle(function () use ($req) {
            $svc = new WebhookService();
            $id = $svc->create($req->all());
            Response::ok(['id' => $id], 'Webhook 创建成功');
        });
    }

    public function update(Request $req, array $params): void
    {
        $this->handle(function () use ($req, $params) {
            $svc = new WebhookService();
            $svc->update((int) $params['id'], $req->all());
            Response::ok(['id' => (int) $params['id']], 'Webhook 更新成功');
        });
    }

    public function destroy(Request $req, array $params): void
    {
        $svc = new WebhookService();
        $affected = $svc->delete((int) $params['id']);
        if ($affected === 0) {
            Response::notFound('Webhook 配置不存在');
            return;
        }
        Response::ok(['id' => (int) $params['id']], 'Webhook 已删除');
    }

    public function deliveries(Request $req, array $params): void
    {
        [$page, $size] = $this->pagination($req);
        $svc = new WebhookService();
        Response::ok($svc->deliveries([
            'log_id' => $req->int('log_id'),
            'status' => $req->string('status'),
        ], $page, $size));
    }

    /**
     * 手动测试投递：给指定 url 发一条测试事件
     */
    public function test(Request $req, array $params): void
    {
        $this->handle(function () use ($req) {
            $url = $req->string('url');
            if ($url === '') {
                Response::error('缺少参数 url', 1, 400);
                return;
            }
            // 提前校验，给出明确错误（httpPost 内还会再拦一次）
            WebhookService::assertSafeUrl($url);
            $svc = new WebhookService();
            $result = $svc->dispatch('test', [
                'message'    => '这是一条来自 ZC Mail Service 的测试回调',
                'request_id' => 'test',
            ], $url, null, $req->string('secret'));

            Response::ok($result, '测试回调已发出');
        });
    }

    // ---------------- 入站接收 ----------------

    /**
     * POST /api/v1/hook/inbound
     * 外部系统把事件推到这里；若配置了入站密钥则校验签名
     */
    public function receive(Request $req, array $params): void
    {
        $secret = (string) Config::get('security.inbound_secret', '');
        if ($secret === '') {
            Response::error('入站 Webhook 未启用：请先配置 security.inbound_secret', 4030, 403);
            return;
        }
        $this->handle(function () use ($req, $secret) {
            $svc = new WebhookService();
            $result = $svc->receive($req, [
                'source'  => $req->string('source'),
                'secret'  => $secret,
                'forward' => $req->bool('forward'),
            ]);
            Response::ok($result, '事件已接收');
        });
    }

    public function inbound(Request $req, array $params): void
    {
        [$page, $size] = $this->pagination($req);
        $svc = new WebhookService();
        Response::ok($svc->inboundList(['source' => $req->string('source')], $page, $size));
    }
}
