<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Service\TemplateService;

/**
 * 邮件模板管理
 */
class TemplateController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        [$page, $size] = $this->pagination($req, 50);
        $svc = new TemplateService();
        Response::ok($svc->list($page, $size, $req->string('keyword')));
    }

    public function show(Request $req, array $params): void
    {
        $svc = new TemplateService();
        $row = $svc->get((int) $params['id']);
        if (!$row) {
            Response::notFound('模板不存在');
            return;
        }
        Response::ok($row);
    }

    public function store(Request $req, array $params): void
    {
        $this->handle(function () use ($req) {
            $svc = new TemplateService();
            $id = $svc->create($req->all());
            Response::ok(['id' => $id], '模板创建成功');
        });
    }

    public function update(Request $req, array $params): void
    {
        $this->handle(function () use ($req, $params) {
            $svc = new TemplateService();
            $svc->update((int) $params['id'], $req->all());
            Response::ok(['id' => (int) $params['id']], '模板更新成功');
        });
    }

    public function destroy(Request $req, array $params): void
    {
        $svc = new TemplateService();
        $affected = $svc->delete((int) $params['id']);
        if ($affected === 0) {
            Response::notFound('模板不存在');
            return;
        }
        Response::ok(['id' => (int) $params['id']], '模板已删除');
    }

    /**
     * 预览渲染结果
     * 可传 id（用库中模板）或直接传 subject/body_html/body_text + variables
     */
    public function preview(Request $req, array $params): void
    {
        $svc = new TemplateService();
        $vars = (array) ($req->input('variables') ?? $req->input('vars') ?? []);

        if (!empty($params['id'])) {
            $tpl = $svc->get((int) $params['id']);
            if (!$tpl) {
                Response::notFound('模板不存在');
                return;
            }
            $missing = [];
            $result = [
                'subject' => $svc->substitute((string) $tpl['subject'], $vars, $missing),
                'html'    => $svc->substitute((string) $tpl['body_html'], $vars, $missing),
                'text'    => $svc->substitute((string) $tpl['body_text'], $vars, $missing),
                'missing' => array_values(array_unique($missing)),
            ];
            Response::ok($result);
            return;
        }

        Response::ok($svc->preview($req->all(), $vars));
    }
}
