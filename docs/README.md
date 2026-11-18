# ZC Mail Service · 文档站源文件

`mail.zgric.top` 的源文件仓库。VitePress 构建，GitHub Pages 托管。不含服务代码。

| 项 | 值 |
|---|---|
| 站点 | https://mail.zgric.top/ |
| 主仓库 | https://github.com/kuangxing6367/mail_zcx （PHP 服务本体） |
| 本仓库 | 文档正文 + 站点构建配置 |

拆开的原因：文档是静态站，主仓库是 PHP 服务。文档改动不应触发服务仓库的 CI。主仓库 `docs/` 是同结构快照，不是源文件；改文档改这里，否则下次同步会被覆盖。

## 本地构建

```bash
npm ci
npm run docs:dev      # http://localhost:5173
npm run docs:build    # 产物 .vitepress/dist
npm run docs:preview
```

## 目录

```
*.md                     文档正文（唯一事实来源）
.vitepress/config.mts     导航、侧栏、base、rewrites
.vitepress/theme/         主题覆写与样式
public/CNAME              mail.zgric.top
.github/workflows         Pages 构建与部署
```

## 规则

- 内容准确优先于措辞。未验证的行为不写成事实，标注「未验证」。
- 文档与代码冲突时以代码为准，然后修文档。
- 接口、事件、配置默认值变化 → 同步 `API.md` / `plugins.md` / `contract.md`，以及主仓库的 `CHANGELOG.md`。
- 版本号、`/health` 示例、Webhook UA 必须与 `config/config.php` 的 `app.version` 一致。
- 提交格式 `docs: 摘要`，一个提交一件事。
- 换域名要同时改 `public/CNAME` 与文档里的绝对链接。

## 许可

[WTFPL](LICENSE) v2 © zc team
