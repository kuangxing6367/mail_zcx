<?php

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use InvalidArgumentException;
use RuntimeException;

abstract class BaseController
{
    /** 解析分页参数 */
    protected function pagination(Request $req, int $defaultSize = 20): array
    {
        $page = max(1, $req->int('page', 1));
        $size = $req->int('size', 0) ?: $req->int('per_page', $defaultSize);
        $size = min(200, max(1, $size));
        return [$page, $size];
    }

    protected function ok(mixed $data = null, string $msg = 'ok'): void
    {
        Response::ok($data, $msg);
    }

    /** 统一把业务异常转成 400 */
    protected function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (InvalidArgumentException | RuntimeException $e) {
            Response::error($e->getMessage(), 1, 400);
        }
    }
}
