<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Service\SettingsService;

/**
 * 系统设置（网页端改配置）
 */
class SettingsController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        Response::ok((new SettingsService())->all());
    }

    public function update(Request $req, array $params): void
    {
        $this->handle(function () use ($req) {
            $svc = new SettingsService();
            // 允许直接提交 { "mail.queue.enabled": false } 或 { values: {...} }
            $body = $req->all();
            $input = isset($body['values']) && is_array($body['values']) ? $body['values'] : $body;
            $res = $svc->save($input);
            Response::ok($res, '配置已保存，立即生效');
        });
    }

    public function reset(Request $req, array $params): void
    {
        $this->handle(function () {
            Response::ok((new SettingsService())->reset(), '已恢复为 config.local.php 中的配置');
        });
    }
}
