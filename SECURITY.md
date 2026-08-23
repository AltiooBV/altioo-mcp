# Security

This extension adds an authenticated HTTP entry point to iTop and lets a language model act
through it. That deserves a page of its own, so that the people who have to approve the
install can read what it does before it is on their instance.

## Reporting a vulnerability

Report it privately, through either channel:

- **GitHub** — [Report a vulnerability](https://github.com/altioo/mcp-server-extension/security/advisories/new)
  on `altioo/mcp-server-extension`. Preferred: it keeps the report, the discussion and the
  eventual advisory in one place, and it works without you having to trust an email route.
- **Email** — <security@altioo.com>, if you would rather not use GitHub, or if you cannot
  reach it.

What we commit to:

| | |
|---|---|
| Acknowledgement | Within **5 working days** |
| Assessment, with a severity and a plan | Within **10 working days** of acknowledgement |
| Fix or documented mitigation, confirmed high severity | Within **90 days** of acknowledgement |
| Credit | In the CHANGELOG entry and the advisory, unless you ask us not to |

If 90 days pass without a fix or an agreed extension, publish. We will not ask you to sit on a
report indefinitely, and a deadline that only one side can move is not coordinated disclosure.

Please do not open a public issue for a suspected vulnerability. Include the iTop version, the
extension version (`MCPHelper::VERSION`, also sent to clients as `serverInfo`), the PHP version,
and the request that reproduces it.

**Out of scope**, because they are decisions rather than defects: iTop's own permissions
letting a user reach something you did not expect (that is what `mcp_allowed_profiles`,
`mcp_capabilities` and the token scopes are for); a model being talked into calling a tool it
was allowed to call; and anything reachable only by an administrator, who can already edit the
datamodel. A tool that lets a caller exceed *their own* iTop permissions is very much in
scope — that is the property this extension exists to keep.

## Supported versions

| Version | Status |
|---|---|
| 1.0.x | **Not released yet.** Supported from the day it is |
| < 1.0 | Development snapshots, unsupported |

Fixes are issued on the latest patch of the newest minor. The extension follows iTop's own
branch policy: a version supported here runs on the iTop branches named in the README, and a
branch that Combodo has retired is not tested against.

### Support period

**Five years of security fixes from the release date of a minor version.**

`1.0.x` has not been released, so its window has not started and no end date is given here.
Both dates are written into this section when the tag is pushed —
[doc/release-checklist.md](doc/release-checklist.md) carries the step. A commitment dated from
anything other than the day the archive actually became installable is one that runs short by
however long the release slipped, and the five years below is a floor, not a target: it is not
a number to spend on a delay in the repository.

Five years is the floor the EU [Cyber Resilience Act](https://eur-lex.europa.eu/eli/reg/2024/2847/oj)
sets for a product with digital elements, and it is committed to here whether or not this
extension ends up inside that Act's scope. Whether it does turns on whether it is supplied in
the course of a commercial activity — a question about how Altioo offers it, not about anything
in this repository, and not one an operator planning a deployment should have to wait on.

Ending support for a minor earlier than that would be announced in the CHANGELOG and in the
GitHub releases **six months** ahead. It will not happen quietly.

### Single point of contact

<security@altioo.com>, alongside the GitHub advisory channel above. The same address is in the
README, in this file, and in `composer.json` under `support.email` — and all three ship inside
the release archive, so it travels with the extension instead of living only on a web page,
which is the point of the requirement. (`support.security` is a URL to this file on GitHub,
which is the field's meaning and is not a contact address.)

Where the CRA's 24-hour and 72-hour reporting duties for an *actively exploited* vulnerability
apply, they are met from that address, and they run in parallel with the timetable above rather
than replacing it: a reporter still gets an acknowledgement within 5 working days.

### What ships beside the archive

| File | What it answers |
|---|---|
| `<archive>.zip.sha256` | "is the file I downloaded the file CI built" |
| `sbom.cyclonedx.json` | CycloneDX inventory of every production dependency |
| `licenses.json` | the licence of each of those dependencies |

The SBOM is what makes a same-day vulnerability answer possible. When a CVE lands on something
under `vendor/`, "does this extension ship it, and at which version" has to be answerable in
minutes. Regenerate both with `composer sbom` and `composer licenses`.

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

Follow [iTop's security guidance](https://www.itophub.io/wiki/page?id=3_2_0:install:security)
— the version-pinned page for the current LTS, deliberately rather than the wiki's `latest:`
namespace, which tracks the development branch and has been observed recommending parameters
and files that do not exist on 3.2 ([doc/itop-branch-notes.md](doc/itop-branch-notes.md) §1
records two). If you run 3.3, read
[the 3.3 page](https://www.itophub.io/wiki/page?id=3_3_0:install:security) instead. In
particular, and specifically relevant here:

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
