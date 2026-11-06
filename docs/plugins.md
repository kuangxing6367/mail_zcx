# 插件

插件是**生态扩展**：第三方可以往这个项目上挂自己的东西，不必改主仓库代码，也不必等主仓库发版。
一个插件就是一个目录，装上就能用，不需要 Composer、不需要改数据库结构。

> **关于扩展点**：主程序会触发 4 个业务事件（发信前拦截、载荷改写、投递前拦截、终态通知），
> 事件名与载荷契约集中在 `app/Core/Plugin/Hooks.php`，见[第 5 节(#_5-业务扩展点)。
> 除此之外，插件还能注册自己的接口、数据表与计划任务入口 —— 三条路是并列的，不冲突。

---

## 1. 目录结构

```
plugins/
├── .htaccess                 # Apache 下禁止 HTTP 直接访问
└── hello/                    # 一个插件 = 一个目录，目录名即 slug
    ├── plugin.json           # 清单，必需
    └── Plugin.php            # 入口，文件名可改，见 entry 字段
```

`plugins/` 在项目根目录，不在 `public/` 里。站点根正确指向 `public/` 时，插件文件天然无法通过 URL 访问；
只能把站点根指向项目根的虚拟主机，请用 [`deploy/apache/root.htaccess`](https://github.com/kuangxing6367/mail_zcx/blob/main/deploy/apache/root.htaccess) 拦住 `plugins/`。

---

## 2. plugin.json

| 字段 | 必需 | 说明 |
|---|---|---|
| `slug` | 否 | 填了就必须和目录名一致；只许小写字母、数字、`-`、`_` |
| `name` | 否 | 控制台显示名 |
| `version` | 否 | 语义化版本，默认 `0.0.0` |
| `author` / `description` / `homepage` | 否 | 展示用 |
| `entry` | 否 | 入口文件，默认 `Plugin.php`；必须落在插件目录内 |
| `class` | **是** | 入口类的**完整类名**，必须带命名空间 |
| `requires.php` | 否 | PHP 版本约束，如 `>=8.2`。注意这只是插件自己的约束，主程序另有[运行基线](/guide#php-版本基线) |
| `requires.zcmail` | 否 | ZC Mail 版本约束，如 `>=1.2.0` |

版本约束支持 `>=` `>` `<=` `<` `=` `!=`，以及 composer 风格的 `^1.2`（`<2.0`）、`~1.2`（`<1.3`）；
不写运算符默认 `>=`。不满足时插件不会被加载，控制台显示具体原因。

`class` 必须带命名空间，这是 PHP 的硬限制：两个插件都叫 `class Plugin` 会类名冲突，直接 fatal。
示例里用 `Plugins\Hello\Plugin`（JSON 里写成 `"Plugins\\Hello\\Plugin"`）。

```json
{
  "slug": "hello",
  "name": "示例插件",
  "version": "1.0.0",
  "author": "zc team",
  "description": "演示插件机制",
  "entry": "Plugin.php",
  "class": "Plugins\\Hello\\Plugin",
  "requires": { "php": ">=8.2", "zcmail": ">=1.2.0" }
}
```

---

## 3. 最小插件

```php
<?php
namespace Plugins\Hello;

use App\Core\Plugin\PluginContext;
use App\Core\Response;

class Plugin
{
    public function register(PluginContext $ctx): void
    {
        // handler 支持闭包、[类名, 方法名]、"Controller@method" 三种写法
        $ctx->get('/api/v1/hello/ping', static function (): void {
            Response::ok(['pong' => true]);
        });
    }
}
```

上传到 `plugins/hello/`，控制台「插件」页点「启用」，下一个请求就能访问 `GET /api/v1/hello/ping`。

**插件注册的所有路由仍要走 API Key 鉴权**，和主程序接口同一套规则；`scope` 由插件自己指定，
不传就只校验 Key。插件没有能力注册免鉴权路由。

---

## 4. 插件能拿到什么

| 方法 | 用途 |
|---|---|
| `$ctx->get/post/put/delete/any($path, $handler, $scope)` | 注册接口 |
| `$ctx->route($method, $path, $handler, $scope)` | 同上，原始形式 |
| `$ctx->router()` | 直接拿 `Router` 实例 |
| `$ctx->config($key, $default)` | 读配置（只读）。插件自己的配置建议放在 `plugins.<slug>` 段 |
| `$ctx->db()` | 拿 `Database` 单例，建表、读写自己的表 |
| `$ctx->dataDir()` | 插件私有目录 `storage/plugins/<slug>/`，首次调用自动创建 |
| `$ctx->slug()` / `name()` / `version()` / `manifest()` | 读清单信息 |
| `$ctx->on($event, $handler)` | 注册业务事件监听（见[第 5 节(#_5-业务扩展点)） |

插件入口文件里可以 `require` 同目录的其他 PHP 文件；项目不强制 autoload 规范，
但保持一个命名空间前缀能让多人协作时少踩类名冲突。

**数据表**：建议用 `zc_pl_<slug>_*` 之类的前缀，避免跟主程序或其他插件撞表。
插件自己建的插件自己管，主程序的 `cli/install.php` 与网页安装器不会动它。

**计划任务**：有 CLI 的环境直接跑 `php plugins/<slug>/cron.php`；
没有 CLI 的虚拟主机，可以注册一个接口让面板的「访问 URL」型计划任务去打。

---

## 5. 业务扩展点

主程序在一条邮件的生命周期里触发 4 个事件。事件名和载荷契约集中在
`app/Core/Plugin/Hooks.php` —— 不要自己发明事件名，注册了也不会触发。

| 事件 | 触发时机 | 能改什么 | 能否拒绝 |
|---|---|---|---|
| `mail.before_prepare` | 解析参数之前，还没落库 | `input`、`context` | 可以，`Hooks::reject()` → 400 且不留日志 |
| `mail.after_prepare` | MIME 组好、日志落库、返回载荷之前 | 收件人 / 主题 / 报文等载荷字段 | 不可以：已落库，拒绝会被降级为忽略 |
| `mail.before_deliver` | 真正连 SMTP 之前 | 同上 | 可以，拒绝后本封按失败处理 |
| `mail.after_send` | 终态（成功或最终失败），每封只触发一次 | 只读，适合记自己的表 / 发通知 | 不可以，异常一律吞掉 |

事件是 filter 链：上一个监听器的返回值传给下一个，最后交回主程序。
签名 `function (array $data, PluginContext $ctx): array`。

```php
use App\Core\Plugin\Hooks;

$ctx->on(Hooks::BEFORE_PREPARE, function (array $data): array {
    $input = $data['input'];
    if (($input['to'] ?? '') === 'blocked@example.com') {
        Hooks::reject('收件人在黑名单中');
    }
    $input['subject'] = '[通知] ' . ($input['subject'] ?? '');
    $data['input'] = $input;
    return $data;
});

$ctx->on(Hooks::AFTER_SEND, function (array $data) use ($ctx): array {
    // $data['payload'] 是完整载荷，$data['result'] 是投递结果
    file_put_contents($ctx->dataDir() . '/sent.log',
        json_encode($data) . PHP_EOL, FILE_APPEND | LOCK_EX);
    return $data;
});
```

### 畸形返回值怎么处理

插件写错不该把发信链路拖垮：

| 插件行为 | 结果 |
|---|---|
| 返回非数组 / `null` | 丢弃这份返回值，记 warning，沿用上一版数据 |
| 返回数组但字段类型不对（`recipients` 变字符串、`from_email` 不是邮箱） | 同上，整份丢弃 |
| 抛普通异常 | 记 warning，跳过这个监听器 |
| 抛 `RejectException`（`Hooks::reject()`） | 原样上抛，由事件所在层决定是否中断 |
| 少返字段 / 塞 NUL 字节 / 收件人超上限 | 事件契约不查缺失字段，但 `Mailer::assertPayload()` 会在投递前拦下，返回明确的 400，不会 500 |

改写载荷时要自己维护一致性：`message`、`recipients`、`to`、`subject` 是同一封信的不同表示，
只改其中一个可能造成日志与实际投递不符。

### 哪些进程会触发

`public/index.php`、`cli/worker.php`、`public/zc-cron.php`、`cli/smtpd.php` **都会加载插件**，
所以同步发信、队列 worker、Web Cron、SMTP 入站四条路径的事件行为一致。

常驻的 worker / smtpd 只在启动时加载一次，启用新插件或修改插件代码后要重启这些进程；
Web 请求与 Web Cron 每个请求都会重新加载。

仓库里的 `plugins/hello/` 注册了全部 4 个事件，默认不改写任何邮件。

---

## 6. 管理接口

全部需要 `plugins` scope（新装的默认 Key 是 `*`，已包含）。

| 接口 | 说明 |
|---|---|
| `GET /api/v1/plugins` | 列出插件、启用状态、加载错误、插件目录与可写状态 |
| `POST /api/v1/plugins/{slug}/enable` | 启用 |
| `POST /api/v1/plugins/{slug}/disable` | 停用（不删文件） |
| `POST /api/v1/plugins/{slug}/reload` | 清除错误状态 + 失效入口文件 opcache |

启用状态存在 `storage/plugins.json`，不改数据库、不改表结构 —— 虚拟主机同样可用。
`storage/` 需要站点运行用户可写（这个项目本来就用它写日志和鉴权计数）。
不可写时控制台会直接提示目录路径，不会白屏。

**接口不提供上传插件文件的能力。** 装插件靠 FTP / 面板文件管理器 / git 拉取。
这是有意为之：能上传 PHP 文件的接口等于一个任意代码执行入口。

---

## 7. 故障隔离

- 插件目录扫描只认符合 slug 正则的子目录，入口文件用 `realpath` 校验在目录内
- 清单损坏、版本不满足、入口越界、类不存在、缺 `register()`、`register()` 抛异常 —— 全部只影响这个插件
- 出错时**自动停用**，错误文案写进 `storage/plugins.json`，控制台上直接显示；主程序继续处理请求
- 修好后点「重载」清错误，再点「启用」

也就是说，装了个写错的插件不会让发信接口挂掉。但插件一旦成功加载，它执行的是普通 PHP 代码
—— **权限等同于主程序**。只装你信任来源的插件，这条边界不会因为隔离机制而消失。

---

## 8. 总开关

`config/config.php`：

```php
'plugins' => [
    'enabled' => true,   // false = 完全不扫描插件目录
    'dir'     => '',     // 留空 = 项目根/plugins；可填绝对路径
],
```

默认 `true`，但启用清单初始为空，所以**默认零插件加载**，对现有部署没有影响。

---

## 9. 发布自己的插件

没有中心插件市场，也没有强制的仓库结构。常见做法是独立 GitHub 仓库：

```
your-plugin/
├── plugin.json
├── Plugin.php
├── README.md
└── LICENSE
```

使用者把仓库内容放进 `plugins/<任意slug>/` 即可。仓库里的 `slug` 字段要么留空，要么由使用者改成目录名。
插件自己的许可证与本项目 WTFPL 互不影响 —— 它是独立作品，只是通过 `PluginContext` 接入。
