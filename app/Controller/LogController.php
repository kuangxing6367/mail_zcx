<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Service\LogService;

/**
 * 发送日志与统计
 */
class LogController extends BaseController
{
    public function index(Request $req, array $params): void
    {
        [$page, $size] = $this->pagination($req);
        $svc = new LogService();

        Response::ok($svc->list([
            'status'     => $req->string('status'),
            'mailbox_id' => $req->int('mailbox_id'),
            'request_id' => $req->string('request_id'),
            'keyword'    => $req->string('keyword'),
            'start'      => $req->string('start'),
            'end'        => $req->string('end'),
        ], $page, $size));
    }

    public function show(Request $req, array $params): void
    {
        $svc = new LogService();
        $row = $svc->get((int) $params['id']);
        if (!$row) {
            Response::notFound('日志不存在');
            return;
        }
        Response::ok($row);
    }

    public function stats(Request $req, array $params): void
    {
        $svc = new LogService();
        Response::ok($svc->stats($req->string('range', 'today')));
    }
}
