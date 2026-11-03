<?php

/**
 * 路由表
 *
 * 约定：
 *   - 所有接口都在 /api/v1 下
 *   - 除入站 Webhook 外，均需 API Key 鉴权
 *   - 更新类接口同时支持 PUT / POST / _method 覆盖
 */

use App\Core\Router;

return function (Router $r): void {
    $r->group('/api/v1', function (Router $r): void {

        // ---------- 发信 ----------
        $r->post('/send', 'MailController@send');
        $r->get('/send', 'MailController@send');

        // ---------- 多邮箱管理 ----------
        $r->get('/mailboxes', 'MailboxController@index');
        $r->post('/mailboxes', 'MailboxController@store');
        $r->get('/mailboxes/{id}', 'MailboxController@show');
        $r->put('/mailboxes/{id}', 'MailboxController@update');
        $r->post('/mailboxes/{id}', 'MailboxController@update');
        $r->delete('/mailboxes/{id}', 'MailboxController@destroy');
        $r->post('/mailboxes/{id}/test', 'MailboxController@test');
        $r->get('/mailboxes/{id}/test', 'MailboxController@test');
        $r->post('/mailboxes/{id}/reset-quota', 'MailboxController@resetQuota');

        // ---------- 邮件模板 ----------
        $r->get('/templates', 'TemplateController@index');
        $r->post('/templates', 'TemplateController@store');
        $r->get('/templates/{id}', 'TemplateController@show');
        $r->put('/templates/{id}', 'TemplateController@update');
        $r->post('/templates/{id}', 'TemplateController@update');
        $r->delete('/templates/{id}', 'TemplateController@destroy');
        $r->post('/templates/{id}/preview', 'TemplateController@preview');
        $r->post('/templates/preview', 'TemplateController@preview');

        // ---------- Webhook 出站 ----------
        $r->get('/webhooks', 'WebhookController@index');
        $r->post('/webhooks', 'WebhookController@store');
        $r->get('/webhooks/deliveries', 'WebhookController@deliveries');
        $r->post('/webhooks/test', 'WebhookController@test');
        $r->get('/webhooks/{id}', 'WebhookController@show');
        $r->put('/webhooks/{id}', 'WebhookController@update');
        $r->post('/webhooks/{id}', 'WebhookController@update');
        $r->delete('/webhooks/{id}', 'WebhookController@destroy');

        // ---------- Webhook 入站 ----------
        $r->post('/hook/inbound', 'WebhookController@receive');
        $r->get('/hook/inbound', 'WebhookController@receive');
        $r->get('/hook/events', 'WebhookController@inbound');

        // ---------- 发送日志 ----------
        $r->get('/logs', 'LogController@index');
        $r->get('/logs/stats', 'LogController@stats');
        $r->get('/logs/{id}', 'LogController@show');

        // ---------- API Key ----------
        $r->get('/keys', 'ApiKeyController@index');
        $r->post('/keys', 'ApiKeyController@store');
        $r->put('/keys/{id}', 'ApiKeyController@update');
        $r->post('/keys/{id}', 'ApiKeyController@update');
        $r->delete('/keys/{id}', 'ApiKeyController@destroy');
        $r->post('/keys/{id}/rotate', 'ApiKeyController@rotate');
    });
};
