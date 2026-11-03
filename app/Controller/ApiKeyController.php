<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Service\ApiKeyService;

/**
 * API Key 管理
 */
class ApiKeyController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        [$page, $size] = $this->pagination($req, 50);
        $svc = new ApiKeyService();
        Response::ok($svc->list($page, $size));
    }

    public function store(Request $req, array $params): void
    {
        $this->handle(function () use ($req) {
            $svc = new ApiKeyService();
            $row = $svc->create($req->all());
            Response::ok($row, '密钥创建成功，请立即保存，明文仅此一次返回');
        });
    }

    public function update(Request $req, array $params): void
    {
        $this->handle(function () use ($req, $params) {
            $svc = new ApiKeyService();
            $svc->update((int) $params['id'], $req->all());
            Response::ok(['id' => (int) $params['id']], '密钥更新成功');
        });
    }

    public function destroy(Request $req, array $params): void
    {
        $svc = new ApiKeyService();
        $affected = $svc->delete((int) $params['id']);
        if ($affected === 0) {
            Response::notFound('密钥不存在');
            return;
        }
        Response::ok(['id' => (int) $params['id']], '密钥已删除');
    }

    public function rotate(Request $req, array $params): void
    {
        $this->handle(function () use ($params) {
            $svc = new ApiKeyService();
            Response::ok($svc->rotate((int) $params['id']), '密钥已轮换，请更新调用方配置');
        });
    }
}
