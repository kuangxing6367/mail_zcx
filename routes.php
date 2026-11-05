<?php

/**
 * 路由表
 *
 * 约定：
 *   - 所有接口都在 /api/v1 下
 *   - 除入站 Webhook 外，均需 API Key 鉴权
 *   - 第三个参数是所需 scope；API Key 的 scopes 为 `*` 或包含该值时才放行
 *   - 写操作只允许 POST/PUT/DELETE，不再用 GET 触发副作用
 */

use App\Core\Router;

return function (Router $r): void {
    $r->group('/api/v1', function (Router $r): void {

        // ---------- 发信 ----------
        $r->post('/send', 'MailController@send', 'send');

        // ---------- 多邮箱管理 ----------
        $r->get('/mailboxes', 'MailboxController@index', 'mailboxes');
        $r->post('/mailboxes', 'MailboxController@store', 'mailboxes');
        $r->get('/mailboxes/{id}', 'MailboxController@show', 'mailboxes');
        $r->put('/mailboxes/{id}', 'MailboxController@update', 'mailboxes');
        $r->post('/mailboxes/{id}', 'MailboxController@update', 'mailboxes');
        $r->delete('/mailboxes/{id}', 'MailboxController@destroy', 'mailboxes');
        $r->post('/mailboxes/{id}/test', 'MailboxController@test', 'mailboxes');
        $r->post('/mailboxes/{id}/reset-quota', 'MailboxController@resetQuota', 'mailboxes');

        // ---------- 邮件模板 ----------
        $r->get('/templates', 'TemplateController@index', 'templates');
        $r->post('/templates', 'TemplateController@store', 'templates');
        $r->get('/templates/{id}', 'TemplateController@show', 'templates');
        $r->put('/templates/{id}', 'TemplateController@update', 'templates');
        $r->post('/templates/{id}', 'TemplateController@update', 'templates');
        $r->delete('/templates/{id}', 'TemplateController@destroy', 'templates');
        $r->post('/templates/{id}/preview', 'TemplateController@preview', 'templates');
        $r->post('/templates/preview', 'TemplateController@preview', 'templates');

        // ---------- Webhook 出站 ----------
        $r->get('/webhooks', 'WebhookController@index', 'webhooks');
        $r->post('/webhooks', 'WebhookController@store', 'webhooks');
        $r->get('/webhooks/deliveries', 'WebhookController@deliveries', 'webhooks');
        $r->post('/webhooks/test', 'WebhookController@test', 'webhooks');
        $r->get('/webhooks/{id}', 'WebhookController@show', 'webhooks');
        $r->put('/webhooks/{id}', 'WebhookController@update', 'webhooks');
        $r->post('/webhooks/{id}', 'WebhookController@update', 'webhooks');
        $r->delete('/webhooks/{id}', 'WebhookController@destroy', 'webhooks');

        // ---------- Webhook 入站 ----------
        // 免 API Key，但必须在 security.inbound_secret 配置后按 HMAC 签名校验；
        // 未配置时接口直接返回 403（默认关闭），且只接受 POST。
        $r->post('/hook/inbound', 'WebhookController@receive', null);
        $r->get('/hook/events', 'WebhookController@inbound', 'webhooks');

        // ---------- 发送日志 ----------
        $r->get('/logs', 'LogController@index', 'logs');
        $r->get('/logs/stats', 'LogController@stats', 'logs');
        $r->get('/logs/{id}', 'LogController@show', 'logs');

        // ---------- API Key ----------
        $r->get('/keys', 'ApiKeyController@index', 'keys');
        $r->post('/keys', 'ApiKeyController@store', 'keys');
        $r->put('/keys/{id}', 'ApiKeyController@update', 'keys');
        $r->post('/keys/{id}', 'ApiKeyController@update', 'keys');
        $r->delete('/keys/{id}', 'ApiKeyController@destroy', 'keys');
        $r->post('/keys/{id}/rotate', 'ApiKeyController@rotate', 'keys');
    });
};
