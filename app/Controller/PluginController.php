<?php

namespace App\Controller;

use App\Core\Plugin\PluginManager;
use App\Core\Request;
use App\Core\Response;

/**
 * 插件管理（启用 / 停用 / 重新加载）
 *
 * 插不上手的事：插件文件本身由 FTP / 面板上传，本接口只读不写插件目录，
 * 避免「通过 API 上传 PHP 文件」变成任意代码执行入口。
 */
class PluginController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        $manager = PluginManager::instance();
        Response::ok([
            'plugins' => $manager->list(),
            'info'    => $manager->info(),
        ]);
    }

    public function enable(Request $req, array $params): void
    {
        $this->handle(function () use ($params) {
            PluginManager::instance()->enable((string) ($params['slug'] ?? ''));
            Response::ok(null, '插件已启用，下一个请求开始生效');
        });
    }

    public function disable(Request $req, array $params): void
    {
        $this->handle(function () use ($params) {
            PluginManager::instance()->disable((string) ($params['slug'] ?? ''));
            Response::ok(null, '插件已停用');
        });
    }

    public function reload(Request $req, array $params): void
    {
        $this->handle(function () use ($params) {
            PluginManager::instance()->reload((string) ($params['slug'] ?? ''));
            Response::ok(null, '已清除错误状态并刷新入口文件缓存');
        });
    }
}
