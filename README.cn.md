[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文件由模型翻译，未经人工审校；如有出入，以英文版为准。

# mercurius-antigravity

为智能体提供 Google Antigravity 套餐中的模型：把官方 Antigravity CLI（`agy`）作为单次模型调用的一次性客户端，
对外提供 Chat Completions 接口。它是 [Mercurius](https://github.com/opencck/mercurius) 的一个模块，基于
[AMPHP](https://amphp.org) 构建。智能体循环、工具和确认仍由应用负责：`agy` 只充当模型。

## 请先阅读：Google 的条款禁止这样做

Antigravity 条款（第 6 节）和 FAQ 把通过第三方软件访问该服务、以及把 `agy` 封装进应用视为违约，可能导致
Antigravity 和 Gemini CLI 账号被暂停或终止。本模块默认关闭。只在你自己的实例上、并且只有在你接受这一风险时才
启用它；单独的 Google 账号只能限制损失，不能消除风险。智能体使用 Gemini 的许可途径是 AI Studio 密钥和 Vertex：
在 Mercurius 中即 `gemini:` 和 `vertex:` 提供方，它们不需要任何模块。

## 它做什么

- 一次模型调用就是一个 `agy` 进程和一条提示。进程使用本次调用的代理，不带内置工具；应用的工具由一个什么都不
  执行的惰性 MCP 服务器列给它。
- 第一次调用应用的工具即结束本次调用并停止进程：工具由应用执行。出现任何其他工具的步骤，或 `agy` 回退到默认
  代理，都会以错误中止调用。
- 历史被折叠成一条消息，其标记无法被消息自身的内容破坏。
- 套餐配额的查询不消耗模型调用；某个窗口用尽时，该组模型在启动任何东西之前就被拒绝。
- 调用之后不留任何东西：进程、`agy` 保存的会话记录和本次调用的目录都会被删除。

它不做的事：不修改 `agy` 二进制；不读取、复制或写入登录文件；不自行访问 Google 的 API；不接受 API 密钥或云凭据；
不轮换账号；不执行工具；不模仿终端前的人。

## 状态

已用替身 `agy` 检查。在真实的 `agy` 1.5.18 上只检查了未登录时可见的部分。见 `BRIEF.md` 的「Что не проверено」。

## HTTP

除 `/health` 外都需要 `Authorization: Bearer <ANTIGRAVITY_TOKEN>`。

| 路由 | 说明 |
|------|------|
| `GET /health` | 未登录时，只要找到 CLI 且版本足够新即返回 200；否则 503 |
| `GET /auth/status` | CLI、登录状态、是否有登录在等待代码 |
| `POST /auth/login` | 发起登录，返回链接以及 `agy` 等待的时长 |
| `POST /auth/code` | 把代码交给等待中的 `agy` |
| `POST /auth/logout` | 501：CLI 没有退出登录的命令 |
| `POST /v1/chat/completions` | 一次模型调用，以流或单个对象返回 |
| `GET /v1/models` | 套餐中的模型及其强度级别和窗口 |
| `GET /usage` | 套餐配额 |

## 设置

| 变量 | 默认值 | 含义 |
|------|--------|------|
| `ANTIGRAVITY_TOKEN` | — | 模块令牌，16 个字符以上；必填 |
| `ANTIGRAVITY_PORT`、`ANTIGRAVITY_HOST` | `8095`、`0.0.0.0` | 服务监听的位置 |
| `ANTIGRAVITY_HOME` | `/data/antigravity` | CLI 的主目录：设置、日志、登录。请放在卷上，权限 700 |
| `ANTIGRAVITY_COMMAND` | `agy` | CLI |
| `ANTIGRAVITY_MIN_VERSION` | `1.5.18` | 服务可配合使用的最旧 CLI 版本 |
| `ANTIGRAVITY_IDLE_SECONDS` | `300` | 一次调用允许沉默的时长 |
| `ANTIGRAVITY_MAX_CONCURRENT` | `2` | 同时运行的 `agy` 进程数 |
| `ANTIGRAVITY_PROXY` | — | `socks5h://`、`http://` 或 `direct`；除非为 `direct`，否则 `VLESS_URI` 优先 |
| `ANTIGRAVITY_EGRESS_HOSTS` | — | 以逗号分隔的精确主机名，服务的代理在自身简短的 Google 主机列表之外额外放行 |

当环境中存在 `GEMINI_API_KEY`、`GOOGLE_API_KEY`、`GOOGLE_APPLICATION_CREDENTIALS`、`GOOGLE_CLOUD_PROJECT` 或
`GOOGLE_GENAI_USE_VERTEXAI` 时，服务拒绝启动：`agy` 会用它登录，并按 API 价目计费而不是使用套餐。

## 构建与运行

```bash
docker build -t mercurius-antigravity .
docker run --rm -e ANTIGRAVITY_TOKEN=... -v "$PWD/data:/data/antigravity" -p 127.0.0.1:8095:8095 mercurius-antigravity
```

构建时会从 Google 下载固定版本的 `agy` 并校验其 sha512。不要把镜像推送到公开的镜像仓库：该二进制不是我们可以
再分发的。

## 检查

```bash
composer check   # PHPUnit、PHPStan（max）、Psalm（级别 1）；替身 agy，无需登录和网络
```

## 许可证

MIT（本代码）。Antigravity CLI 属于 Google，按 Google 的条款使用。
