<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Service\MailboxService;

/**
 * 多邮箱管理
 */
class MailboxController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        [$page, $size] = $this->pagination($req);
        $svc = new MailboxService();

        $status = ($req->has('status') && $req->string('status') !== '') ? $req->int('status') : null;

        Response::ok($svc->list($page, $size, $req->string('keyword'), $status));
    }

    public function show(Request $req, array $params): void
    {
        $svc = new MailboxService();
        $row = $svc->get((int) $params['id']);
        if (!$row) {
            Response::notFound('邮箱不存在');
            return;
        }
        Response::ok($svc->toApi($row));
    }

    public function store(Request $req, array $params): void
    {
        $this->handle(function () use ($req) {
            $svc = new MailboxService();
            $id = $svc->create($req->all());
            Response::ok(['id' => $id], '邮箱添加成功');
        });
    }

    public function update(Request $req, array $params): void
    {
        $this->handle(function () use ($req, $params) {
            $svc = new MailboxService();
            $svc->update((int) $params['id'], $req->all());
            Response::ok(['id' => (int) $params['id']], '邮箱更新成功');
        });
    }

    public function destroy(Request $req, array $params): void
    {
        $svc = new MailboxService();
        $affected = $svc->delete((int) $params['id']);
        if ($affected === 0) {
            Response::notFound('邮箱不存在');
            return;
        }
        Response::ok(['id' => (int) $params['id']], '邮箱已删除');
    }

    /** 测试 SMTP 连通性与认证 */
    public function test(Request $req, array $params): void
    {
        $svc = new MailboxService();
        $row = $svc->get((int) $params['id']);
        if (!$row) {
            Response::notFound('邮箱不存在');
            return;
        }

        $result = $svc->testConnection($row);
        Response::json($result, $result['ok'] ? 0 : 1, $result['message'], 200);
    }

    public function resetQuota(Request $req, array $params): void
    {
        $svc = new MailboxService();
        $svc->resetQuota((int) $params['id']);
        Response::ok(['id' => (int) $params['id']], '配额已重置');
    }
}
