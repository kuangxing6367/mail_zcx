---
layout: home

hero:
  name: ZC Mail Service
  text: 邮件发送基础设施。原生 PHP。
  tagline: 一套 HTTP API，多个 SMTP 发件箱，数据留在自己的机器上。
  actions:
    - theme: brand
      text: 读部署
      link: /guide
    - theme: alt
      text: 查接口
      link: /api
    - theme: alt
      text: 看代码
      link: https://github.com/kuangxing6367/mail_zcx

features:
  - title: 零运行时依赖
    details: 手写 SMTP 客户端与 MIME 构建器。不装 Composer，不引 PHPMailer。PHP ≥ 8.2（安全修复版）+ pdo_mysql / openssl / mbstring / curl / json。
  - title: 多邮箱选箱
    details: 默认挑累计发件最少的可用邮箱。least_sent / round_robin / random / weight 四种策略，也可以显式指定发件箱。
  - title: 数据自持
    details: SMTP 授权码 AES-256-GCM 加密落库。发信链路不经过第三方平台，日志留在自己的库里。
  - title: 可核对
    details: 每次投递记录 SMTP 响应码、错误、耗时、来源 IP 与原始请求体，支持双向 Webhook。
---

## 前提

这是一个自托管程序。需要一台能跑 PHP 的机器、一个 MySQL 5.7+ 实例、至少一个可用的 SMTP 发件账号。缺任何一项，后面的内容都不适用。

它不负责发信域名信誉、SPF / DKIM 配置、收件方拒收、云厂商封禁出站 25 端口。这些属于部署方。

## 事实清单

| 项 | 值 |
|---|---|
| 实现 | 原生 PHP，零 composer 依赖 |
| 对外 | `/api/v1/**` HTTP JSON 接口；可选 SMTP 入站（25 / 587） |
| 发信模式 | 默认同步，返回 `200`；开启 `mail.queue.enabled` 后返回 `202` + `log_id` |
| 鉴权 | 请求头 `X-API-Key` / `X-ZC-Key` / `Authorization: Bearer`。URL 传参不支持 |
| 当前版本 | `1.3.2`。兼容承诺见[协议与约定](/contract) |
| 许可 | WTFPL v2 |

## 先读哪个

| 目的 | 页面 |
|---|---|
| 部署 | [快速开始](/guide) · [宝塔](/deploy-baota) · [虚拟主机](/deploy-shared-hosting) |
| 接入 | [API 手册](/api) |
| 改造 | [设计说明](/architecture) · [插件](/plugins) · [协议与约定](/contract) |
| SMTP 入站 | [SMTP 入站](/smtp-in) |

## 文档口径

只写代码中已实现的行为。文档与实现冲突时，以实现为准；不要改结论去迁就描述。站点源文件在 [mail_zcx-docs](https://github.com/kuangxing6367/mail_zcx-docs)，主仓库的 `docs/` 是快照，不是事实来源。

## License

WTFPL v2 © zc team
