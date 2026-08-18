# Security

This extension adds an authenticated HTTP entry point to iTop and lets a language model act
through it. That deserves a page of its own, so that the people who have to approve the
install can read what it does before it is on their instance.

## Reporting a vulnerability

> **TO BE FILLED IN BEFORE PUBLICATION.** Replace this block with the reporting channel and
> the response commitment — an address, or GitHub private vulnerability reporting on
> `altioo/mcp-server-extension` (Settings → Security → Private vulnerability reporting), plus
> the times below. Everything else on this page is accurate as it stands; this is the only
> part that depends on something outside the repository.
>
> - Where to send it: *(pending)*
> - Acknowledgement: *(pending — state a number of working days)*
> - Fix or mitigation for a confirmed high-severity issue: *(pending)*

Please do not open a public issue for a suspected vulnerability. Include the iTop version, the
extension version (`MCPHelper::VERSION`, also sent to clients as `serverInfo`), the PHP version,
and the request that reproduces it.

## Supported versions

| Version | Status |
|---|---|
| 1.0.x | Supported |
| < 1.0 | Development snapshots, unsupported |

Fixes are issued on the latest patch of the newest minor. The extension follows iTop's own
branch policy: a version supported here runs on the iTop branches named in the README, and a
branch that Combodo has retired is not tested against.

## What the extension does, in security terms

**It adds one public URL**: `extensions/altioo-mcp/index.php`. The module's `.htaccess`
re-grants web access to exactly that file and leaves iTop's blanket deny over everything else
under `extensions/` in place, so `src/`, `vendor/`, `tests/` and `composer.json` stay
unreachable.

**It opens no outbound connection.** iTop never contacts an AI vendor, a model provider or any
other host on behalf of this extension. Traffic is inbound only: an MCP client connects, and
that client is what talks to a model. See *Data flow* in the README.

**Every request is authenticated by iTop itself** (`LoginWebPage::DoLogin()`), on its own — the
endpoint is stateless and resets the session on every request, so a browser cookie cannot be
replayed against it. Four gates apply, and all of them must pass:

1. the profile gate (`secure_mcp_services` / `mcp_allowed_profiles`),
2. the credential, with an `MCP*` token scope where a token is used,
3. the instance capability grading (`mcp_capabilities` / `mcp_read_only`) and the toolset filter,
4. iTop's own `UserRights` — class, object-level, per-attribute and stimulus rights, checked
   inside every tool.

A token scope can only ever make a credential **narrower** than the user's own profiles. It
never widens anything.

**No tool writes on a first call.** Create, update, delete, apply-stimulus and the three bulk
tools all default to `simulate: true` and return what the call *would* change, having run
iTop's `CheckToWrite()`. Writing requires an explicit `simulate=false`. This is the mitigation
that matters most against prompt injection: a model acting on text that came from outside the
organisation cannot silently commit a change on the strength of that text alone.

**Every call is audited** as an `AltiooEventMCPService` object, with the method, the element invoked,
the outcome, the duration and the calling user.

**Errors do not leak internals.** Only `ToolCallException` and `ResourceReadException` messages
reach the client; anything else is answered generically and correlated to `log/error.log` by a
reference.

## Threat model

| Threat | What answers it |
|---|---|
| Prompt injection reaching a write tool — a ticket description, an email, a web page tells the model to delete something | Dry run by default on every write; `mcp_capabilities` / `mcp_read_only` instance-wide; `MCP-read` / `MCP-write` token scopes; `UserRights` on every object and attribute |
| A leaked token used against another iTop API | `MCP*` scopes are distinct from `REST`/`Export` scopes: a token minted for REST cannot call this endpoint, and the reverse holds too |
| A credential stronger than the assistant needs | Scope the token (`MCP-read`, `MCP-toolset-<name>`) rather than creating a second user account |
| Data exfiltration through a wide read | Reads go through per-attribute read rights; attributes whose type implements `iAttributeNoGroupBy` are masked; `mcp_disabled_tools` removes an element outright |
| A malicious or careless third-party tool pack | Packs run with the caller's rights and no more; `mcp_enabled_toolsets` serves only what you list, so a tool added by an update is off until you say otherwise; `mcp_disabled_tools` accepts a class name |
| Browser-based attack on the endpoint | No `Access-Control-Allow-Origin` is sent unless `mcp_allowed_origins` names an origin; the session is reset per request, so a cookie cannot be used |
| Enumeration of the datamodel by an unauthorised caller | The profile gate runs before anything is advertised; `tools/list` is filtered per caller |

**What is out of scope.** This extension cannot control what the MCP *client* does with data it
has legitimately read, which model that client sends it to, or what that vendor retains. That is
a property of the client and its provider, and it is the thing to review alongside the profiles
you grant.

## Hardening the deployment

Follow [iTop's security guidance](https://www.itophub.io/wiki/page?id=latest:install:security).
In particular, and specifically relevant here:

- Serve iTop over **HTTPS with HSTS**. A bearer token on a plaintext connection is a shared secret.
- Set `session.cookie_secure` and `session.cookie_httponly` in PHP.
- Set **`zend.exception_ignore_args=On`** in `php.ini`. With it off, a stack trace records the
  arguments of every frame — and the frame that authenticates a request was handed the raw
  token. That is how a credential ends up in a log file nobody thinks of as sensitive.
- Disable the console configuration editor wherever this endpoint is enabled:
  `'itop-config' => array('config_editor' => 'disabled')`. It executes the PHP saved into it by
  design, and an Administrator-scoped token reaches it — which is the difference between a
  prompt injection that closes a ticket and one that runs code.
- Keep `mcp_allowed_origins` empty unless a browser-based client you control needs it. Never `*`.
- Leave `mcp_allowed_hosts` derived unless iTop answers under a name `app_root_url` does not
  carry. `array('*')` disables the `Host` check and belongs only behind a proxy that performs
  it itself.
- Start with `mcp_capabilities => array('read')` and issue `MCP-read` tokens. Widen one grade
  at a time, against what the audit trail shows the assistant actually doing.
- Scope `CGIPassAuth On` to `extensions/altioo-mcp/index.php`, never to the whole server.
- Behind an OIDC proxy: map identity to an iTop credential the proxy holds. Never forward the
  upstream access token as the iTop credential — the MCP specification forbids that
  passthrough with a MUST NOT, and it turns a token stolen from any other service into a
  working iTop credential.
- Grant `MCP Services User` alongside a functional profile chosen for this purpose — the paired
  profile is the real blast radius, not the MCP profile itself.
- Leave `log_mcp_level` at `error` in normal operation: `debug` stores raw request parameters,
  which may contain data your users would not expect to find in an audit log.
- Review `AltiooEventMCPService` retention against your own data-retention policy.

## Dependencies

Runtime dependencies are vendored in the archive and listed in `composer.json` /
`composer.lock`. The notable one is **`mcp/sdk`**, pinned `^0.7.1` — a pre-1.0 package, so its
API can change between minors. The extension pins the minor it was tested against and treats an
SDK upgrade as a release of its own, with the test suite as the gate; nothing on an installed
instance changes until you install a new version of this extension. `composer audit` runs in CI
against the lock file.

The other one worth knowing about is **`nyholm/psr7`**, the PSR-17 implementation every request
and response is built through. No line of this module names it: it is located at runtime by
`php-http/discovery`, which makes it look unused to a reader and to anything that prunes
dependencies. `Psr17AvailabilityTest` fails if it goes missing. Nyholm rather than Guzzle
deliberately — iTop ships `guzzlehttp/psr7` itself, and this module's autoloader is registered
before iTop's, so vendoring a second copy of that namespace would shadow iTop's own for the
whole request.
