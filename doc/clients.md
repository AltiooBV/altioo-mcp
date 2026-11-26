# Connecting a client

Every client below talks to the same endpoint:

```
https://<your-itop>/env-production/altioo-mcp/index.php
```

`env-production` is iTop's default environment name; substitute `env-<env>` if your instance
runs another. The endpoint is served from the **compiled** environment, not from `extensions/`,
which is only where the archive is unzipped.

They all authenticate the same way — a Personal or User token with an MCP scope, sent as a bearer
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

> The token's user needs the profiles described in the README's
> [Granting access](../README.md#granting-access), or the token is refused at login.

> The token is as strong as the user it belongs to. Grade the token *and* pair the user with a
> functional profile that has no more rights than the job needs — the profile is the real blast
> radius.

## Claude Code

```bash
claude mcp add --transport http itop https://<your-itop>/env-production/altioo-mcp/index.php --header "Authorization: Bearer <your-itop-token>"
```

Check it with `/mcp`, which lists the server and its tools.

**OAuth instead of a token.** With [altioo-oauth-server](../README.md#endpoint) installed, leave the
`--header` off: `claude mcp add --transport http itop <url>`, then `/mcp` and follow the browser
login ([Claude Code's MCP page](https://code.claude.com/docs/en/mcp)). A header you configure is used as
given and OAuth is not tried for that server, so do not set both. There is no token to create.

**A portal-only user** is served portal mode: the same endpoint and tools, limited to what their
portal shows. The README says who is let in and why ([Portal-only users](../README.md#granting-access));
if the user is allowed into two portals, give each its own entry with `?portal_id=<portal>` on the URL
([ADR 0007](adr/0007-the-connection-names-its-portal.md)). Their token needs an expiry within
`mcp_portal_token_max_days`, which an administrator sets under **User management → Personal tokens**.
`core_current_user` says which portal a connection is served.

**The server's instructions are cut, by the client, not by this extension.** Claude Code keeps the first
4,096 characters of a server's instructions and says so in its docs and its log. The Claude desktop app's
Code tab was seen on 2026-11-23 to keep about 2,050, ending mid-word. Neither figure is a constant: they
are the clients' and can change with their versions. The extension is written for the smaller one: the
security paragraph comes first and whole inside the first 2,000 characters, and the whole block stays under
4,096 for every combination of toolsets in both modes (`ServerInstructionsTest`). A client that cuts earlier
still loses the tail, so `core_instructions` returns the block in full on request.

**Prompts and resources are the user's, not the model's.** The extension's prompts (for example
`core_my_open_tickets`) are listed by Claude Code as slash commands, `/mcp__<server>__<prompt>`, where
`<server>` is the name you gave it (`itop` above). Only the user runs one; a model cannot call a prompt, and
tool search does not find it. Its resources are attached with an `@` mention, `@<server>:<uri>`, for example
`@itop:itop://core/instructions`; typing `@` lists what the server offers, and a mention stays plain text
under `claude -p`. Both are described on [Claude Code's MCP page](https://code.claude.com/docs/en/mcp).
Every resource also has a tool twin, so a model can do what a mention does.

## Claude Desktop

`claude_desktop_config.json` — **Settings → Developer → Edit Config**:

```json
{
  "mcpServers": {
    "itop": {
      "type": "http",
      "url": "https://<your-itop>/env-production/altioo-mcp/index.php",
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
      "url": "https://<your-itop>/env-production/altioo-mcp/index.php",
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
      "url": "https://<your-itop>/env-production/altioo-mcp/index.php",
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
  no OAuth. Either install the optional
  [altioo-oauth-server](../README.md#endpoint) ("Or install altioo-oauth-server"), or put an
  OAuth-terminating proxy in front of iTop and set `mcp_protected_resource_metadata` to the URL
  of the document it serves, so the `401` points the client at it.

## Checking it works

How many calls it takes depends on the revision your client speaks, and this endpoint serves
both from the one URL.

### On 2025-06-18 or 2025-11-25: two calls

This endpoint keeps no session, so `initialize` is answered without an `Mcp-Session-Id` and nothing has to be
carried from the first call to the second: every request stands on its own credential and names the revision it
speaks in `MCP-Protocol-Version`. A client that does the handshake first works as it always did; one that skips it
is served as well.

```bash
ENDPOINT=https://<your-itop>/env-production/altioo-mcp/index.php
TOKEN=<your-itop-token>

# 1. initialize — -D - prints the response headers; there is no Mcp-Session-Id among them
curl -sS -D - -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}'

# 2. tools/list — nothing from step 1 is carried over
curl -sS -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

The same two calls, with the answers checked for you, are `tools/ci/http-smoke.sh` in
the source repository — it is not in this archive, since nothing under `tools/` is packaged.

### On 2026-07-28: one call

That revision dropped the handshake and the session id, so there is nothing to establish and
nothing to carry back. What `initialize` used to negotiate travels in `params._meta` on every
request instead, and the method — plus the subject, for the methods that name one — is mirrored
into headers so an intermediary can route the call without reading the body.

```bash
curl -sS -D - -X POST "$ENDPOINT" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/list" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"_meta":{
        "io.modelcontextprotocol/protocolVersion":"2026-07-28",
        "io.modelcontextprotocol/clientInfo":{"name":"curl","version":"0"},
        "io.modelcontextprotocol/clientCapabilities":{}}}}'
```

No `Mcp-Session-Id` comes back, and that is the answer rather than a fault: nothing was stored,
so there is nothing to return to. A session that sends no `initialize` is never handed the server
instructions — there is no answer to carry them — so the first call worth making is
`core_instructions`, which returns the same block. The `itop://core/instructions` resource
carries it too, at `priority: 1`, for a client that reads resources. A `tools/call` additionally needs `Mcp-Name: <tool>`, and
`resources/read` needs `Mcp-Name: <uri>`; `Mcp-Param-*`, which mirrors the arguments, is
optional and the call is served without it.

- **`401` on step 1** — the credential did not get through. Check the token's scope, check that
  the user holds `MCP Services User` or `Administrator`, and check that `Authorization` reaches
  PHP.
- **`400` on step 2** — a header that does not match the body, or a body that is not one JSON-RPC request (a batch is refused, whatever the revision).
- **`-32020` on the one-call form** — a required mirror header is missing. The message names
  which: `Missing required Mcp-Method header`, or `Mcp-Name` for a method that has a subject.
  A browser-based client gets this when its preflight was not allowed to send them, which is
  what the endpoint's `Access-Control-Allow-Headers` exists to permit.
- **Fewer tools than you expect** — the token is scoped, `mcp_capabilities` or
  `mcp_read_only` is set, `mcp_enabled_toolsets` is narrowed, or a pack's tools declare no
  annotations and are therefore graded `delete`.
- **Nothing in the audit trail** — `log_mcp_level` defaults to `error`, so successful *tool
  calls* are not recorded. Set it to `info` while you are testing. The `initialize` row is the
  exception: it is written at every log level, so it is there whenever step 1 arrived — unless
  `log_mcp_service` is off, or `log_mcp_method` no longer names the method. Either one silences
  the row while requests keep arriving normally.
