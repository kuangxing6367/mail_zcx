---
layout: home

hero:
  name: ZC Mail Service
  text: 开源自托管的邮件 API 服务
  tagline: 零依赖 PHP 实现 · 多邮箱 SMTP 池 · 模板渲染 · Webhook 双向回调
  actions:
    - theme: brand
      text: 快速开始
      link: /guide
    - theme: alt
      text: API 手册
      link: /api
    - theme: alt
      text: GitHub
      link: https://github.com/kuangxing6367/mail_zcx

features:
  - title: 零运行时依赖
    details: 手写 SMTP 客户端与 MIME 构建器，不依赖 composer、PHPMailer。PHP ≥ 8.2（需为安全修复版）+ pdo_mysql / openssl / mbstring / curl / json 五个扩展即可。
  - title: 多邮箱智能选箱
    details: 默认选择累计发件数最少的邮箱，天然负载均衡；也可按权重、轮询、随机或显式指定。
  - title: 数据完全自持
    details: SMTP 授权码 AES-256-GCM 加密落库，发信链路不经过任何第三方平台。
  - title: 全程可观测
    details: 每次投递记录 SMTP 响应码、错误、耗时、来源 IP 与原始请求体，并支持 Webhook 回调。
---

## 这是什么

一个用原生 PHP 写的邮件发送基础设施：对外提供 HTTP API，对内管理多个 SMTP 发件邮箱，
附带模板、日志、统计与一个纯静态 Web 控制台。

典型用法：业务系统调用 `POST /api/v1/send`，服务自动挑选一个还有额度的发件邮箱完成投递，
结果落库并通过 Webhook 异步通知业务侧。

## 从哪里开始

| 我想…… | 看这里 |
|---|---|
| 把它跑起来 | [快速开始](/guide) |
| 调用接口 | [API 手册](/api) |
| 了解架构 | [设计说明](/architecture) |
| 写个插件 | [插件开发](/plugins) |
| 参与开发 | [GitHub 仓库](https://github.com/kuangxing6367/mail_zcx) |

## License

WTFPL © zc team
