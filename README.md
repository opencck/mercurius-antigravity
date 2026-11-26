**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-antigravity

Models of a Google Antigravity plan for an agent: the official Antigravity CLI (`agy`) as a one-shot client of one
model call, behind the Chat Completions API. A module of [Mercurius](https://github.com/opencck/mercurius), built on
[AMPHP](https://amphp.org). The agent loop, tools and confirmations stay with the application: `agy` is the model
only.

## Read this first: Google's terms forbid it

The Antigravity Terms (section 6) and FAQ call access to the service through third-party software, and wrapping `agy`
inside an application, a breach that can get the Antigravity and Gemini CLI accounts suspended or terminated. This
module is off by default. Turn it on only on your own instance and only if you accept that risk; a separate Google
account limits the loss and does not remove the risk. The allowed ways to use Gemini from an agent are an AI Studio
key and Vertex — in Mercurius those are the `gemini:` and `vertex:` providers, and they need no module.

## What it does

- One model call is one `agy` process with one prompt. The process gets an agent of the call without built-in
  tools; the tools of the application are listed to it by an inert MCP server that runs nothing.
- The first call of a tool of the application ends the call and stops the process: the application runs the tool.
  A step of any other tool, or `agy` falling back to its default agent, stops the call with an error.
- A history is folded into one message with markup that its own content cannot break.
- The quota of the plan is asked without a model call; a used-up window refuses the models of its group before
  anything is started.
- After a call nothing is left: the process, the record `agy` kept of the conversation and the folder of the call
  are removed.

What it does not do: change the `agy` binary; read, copy or write the sign-in file; talk to Google's API itself;
take an API key or cloud credentials; rotate accounts; run tools; imitate a person at a terminal.

## State

Checked against a stand-in `agy`. On the real `agy` 1.5.18 only what shows without a sign-in was checked. See
`BRIEF.md`, "Что не проверено".

## HTTP

Everything but `/health` needs `Authorization: Bearer <ANTIGRAVITY_TOKEN>`.

| Route | What |
|-------|------|
| `GET /health` | 200 without a sign-in when the CLI is found and new enough; else 503 |
| `GET /auth/status` | The CLI, the sign-in, whether a sign-in waits for a code |
| `POST /auth/login` | Starts a sign-in, returns the link and how long `agy` waits |
| `POST /auth/code` | Gives the code to the waiting `agy` |
| `POST /auth/logout` | 501: the CLI has no command to sign out |
| `POST /v1/chat/completions` | One model call, as a stream or as one object |
| `GET /v1/models` | The models of the plan with their efforts and windows |
| `GET /usage` | The quota of the plan |

## Settings

| Variable | Default | What it is |
|----------|---------|------------|
| `ANTIGRAVITY_TOKEN` | — | The token of the module, 16+ characters; required |
| `ANTIGRAVITY_PORT`, `ANTIGRAVITY_HOST` | `8095`, `0.0.0.0` | Where the service listens |
| `ANTIGRAVITY_HOME` | `/data/antigravity` | The home of the CLI: settings, log, sign-in. Keep it on a volume, mode 700 |
| `ANTIGRAVITY_COMMAND` | `agy` | The CLI |
| `ANTIGRAVITY_MIN_VERSION` | `1.5.18` | The oldest CLI the service works with |
| `ANTIGRAVITY_IDLE_SECONDS` | `300` | How long a call may be silent |
| `ANTIGRAVITY_MAX_CONCURRENT` | `2` | How many `agy` processes run at once |
| `ANTIGRAVITY_PROXY` | — | `socks5h://`, `http://`, or `direct`; `VLESS_URI` wins unless it is `direct` |
| `ANTIGRAVITY_EGRESS_HOSTS` | — | Exact host names, by commas, the proxy of the service opens besides its own short list of Google hosts |

The service refuses to start when `GEMINI_API_KEY`, `GOOGLE_API_KEY`, `GOOGLE_APPLICATION_CREDENTIALS`,
`GOOGLE_CLOUD_PROJECT` or `GOOGLE_GENAI_USE_VERTEXAI` is in its environment: `agy` would sign in with it and bill
API usage in place of the plan.

## Build and run

```bash
docker build -t mercurius-antigravity .
docker run --rm -e ANTIGRAVITY_TOKEN=... -v "$PWD/data:/data/antigravity" -p 127.0.0.1:8095:8095 mercurius-antigravity
```

The build downloads the pinned `agy` from Google and checks its sha512. Do not push the image to a public registry:
the binary is not ours to redistribute.

## Checks

```bash
composer check   # PHPUnit, PHPStan (max), Psalm (level 1); a stand-in agy, no sign-in, no network
```

## License

MIT (this code). The Antigravity CLI is Google's and is used under Google's terms.
