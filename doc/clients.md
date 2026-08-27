# Connecting a client

Every client below talks to the same endpoint:

```
https://<your-itop>/extensions/altioo-mcp/index.php
```

and authenticates the same way — a Personal or User token with an MCP scope, sent as a bearer
credential. Create one under **My Account → Personal Tokens**, and pick the scope that matches
how much you want that client to be able to do:

| Scope | Give it to |
|---|---|
| `MCP-read` | An assistant that answers questions about tickets and CIs |
| `MCP-write` | One that also opens and updates them, but must never delete |
| `MCP-delete` | One whose job is deletion, and only the tools that declare it |
| `MCP` | Everything its owner can do |

Add a toolset scope — `MCP-toolset-objects`, or any of the five the base extension ships — to
keep a token to part of the surface. [Grading a token](../README.md#grading-a-token) in the
README has the full set and how they combine.

> The token is as strong as the user it belongs to. Grade the token *and* pair the user with a
> functional profile that has no more rights than the job needs — the profile is the real blast
> radius.

## Claude Code

```bash
claude mcp add --transport http itop https://<your-itop>/extensions/altioo-mcp/index.php --header "Authorization: Bearer <your-itop-token>"
```

Check it with `/mcp`, which lists the server and its tools.

## Claude Desktop

`claude_desktop_config.json` — **Settings → Developer → Edit Config**:

```json
{
  "mcpServers": {
    "itop": {
      "type": "http",
      "url": "https://<your-itop>/extensions/altioo-mcp/index.php",
      "headers": { "Authorization": "Bearer <your-itop-token>" }
    }
  }
}
```

Restart the app after editing.

## VS Code (GitHub Copilot)

`.vscode/mcp.json` in the workspace, which keeps the token out of the file:

```json
{
  "inputs": [
    { "type": "promptString", "id": "itop-token", "description": "iTop MCP token", "password": true }
  ],
  "servers": {
    "itop": {
      "type": "http",
      "url": "https://<your-itop>/extensions/altioo-mcp/index.php",
      "headers": { "Authorization": "Bearer ${input:itop-token}" }
    }
  }
}
```

## Cursor

`.cursor/mcp.json`, project-local, or `~/.cursor/mcp.json` for every project:

```json
{
  "mcpServers": {
    "itop": {
      "url": "https://<your-itop>/extensions/altioo-mcp/index.php",
      "headers": { "Authorization": "Bearer <your-itop-token>" }
    }
  }
}
```

## Anything else

The endpoint is streamable HTTP with a bearer credential, so any MCP client that can be given
a URL and a header will work. Two things to know:

- **`Auth-Token: <token>` works too**, and is the header iTop's own `authent-token` module
  reads natively. Prefer it if `Authorization` never reaches PHP in your deployment: under
  FastCGI, Apache drops that header unless `CGIPassAuth On` is in effect.
- **A client with only a "Connect" button** expects OAuth discovery. This extension implements
  no OAuth — put an OAuth-terminating proxy in front of iTop and set
  `mcp_protected_resource_metadata` to the URL of the document it serves, so the `401` points
  the client at it.

## Checking it works

It takes two calls, not one. Every method other than `initialize` is answered `400` — *"A
valid session id is REQUIRED for non-initialize requests"* — before any handler runs, so the
first call has to be the handshake, and the second has to carry back the `Mcp-Session-Id` the
first one returned.

```bash
ENDPOINT=https://<your-itop>/extensions/altioo-mcp/index.php
TOKEN=<your-itop-token>

# 1. initialize — -D - prints the response headers, which is where the session id is
curl -sS -D - -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'

# 2. tools/list — with the mcp-session-id the first call answered with
curl -sS -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -H "Mcp-Session-Id: <the id from step 1>" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

The same handshake, with the id read out of the headers for you, is `tools/ci/http-smoke.sh` in
the source repository — it is not in this archive, since nothing under `tools/` is packaged.

- **`401` on step 1** — the credential did not get through. Check the token's scope, check that
  the user holds `MCP Services User` or `Administrator`, and check that `Authorization` reaches
  PHP.
- **`400` on step 2** — the session id was not sent, or not the one step 1 returned.
- **Fewer tools than you expect** — the token is scoped, `mcp_capabilities` or
  `mcp_read_only` is set, `mcp_enabled_toolsets` is narrowed, or a pack's tools declare no
  annotations and are therefore graded `delete`.
- **Nothing in the audit trail** — `log_mcp_level` defaults to `error`, so successful *tool
  calls* are not recorded. Set it to `info` while you are testing. The `initialize` row is the
  exception: it is written at every log level, so it is there whenever step 1 arrived — unless
  `log_mcp_service` is off, or `log_mcp_method` no longer names the method. Either one silences
  the row while requests keep arriving normally.
