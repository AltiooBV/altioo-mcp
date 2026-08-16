# Altioo MCP — Model Context Protocol server for iTop

Turns an iTop instance into an MCP server, so assistants (Claude, and any other Model
Context Protocol client) can search, read and update CMDB and ticketing objects.

[Repository](https://github.com/altioo/mcp-server-extension) ·
[Changelog](CHANGELOG.md) ·
[Security](SECURITY.md) ·
[Client setup](doc/clients.md) ·
[Support](#support)

This is the **base extension**. It is free, it works on its own, and it is designed to be
depended upon: other extensions declare it as a dependency and register their own tools,
resources and prompts into it. Nothing here is specific to a business domain — the core
surface is a thin, safe mapping of iTop's own object model.

Everything runs **inside iTop**, against `MetaModel` and `UserRights` directly. There is no
separate service to deploy, no copy of the datamodel to keep in sync, and no REST hop: a
client can never see or change more than the authenticated user could through the console,
down to individual attributes and lifecycle stimuli.

## Requirements

| | |
|---|---|
| iTop | **3.2 (current LTS)** or **3.3** |
| PHP | **8.2 – 8.4** |
| Database | Whatever your iTop runs on — this module adds one table and no dialect-specific SQL |
| iTop modules | `authent-token` 2.2.1 or later, and `itop-structure` 3.2.0 or later (both ship with iTop) |
| Web server | Apache or IIS; the module ships the `.htaccess` / `web.config` that expose its single entry point |

Which PHP goes with which iTop is decided by iTop, not by this module:

| iTop | PHP | This extension |
|---|---|---|
| 3.0, 3.1 and earlier | — | **Not supported.** The setup refuses to install (the `itop-structure/3.2.0` dependency) |
| 3.2.0 – 3.2.2 | 8.2 – 8.3 (8.4 has known issues in iTop) | Supported |
| 3.2.3-1 and later 3.2.x | 8.2 – 8.4 | Supported |
| 3.3.x | 8.2 – 8.4 | Supported |

The declared range `>=8.2 <8.5` is the intersection: floor 8.2 because iTop 3.3 requires it,
ceiling below 8.5 because no iTop branch validates 8.5 yet.

**What a release is tested against.** No version is published until the unit suite has passed in
CI on PHP 8.2, 8.3 and 8.4, and the archive has been unzipped, installed through the iTop setup
and connected to from a real MCP client on at least one iTop 3.2 instance — the gate is written
down in [doc/release-checklist.md](doc/release-checklist.md), and the run for the current version
is recorded in [CHANGELOG.md](CHANGELOG.md). Combinations outside that are expected to work from
the ranges above rather than observed; if one of them is the one you run, say so and it can be
added to the gate.

## What it exposes

The core surface stays close to iTop's own primitives, in the same spirit as its REST/JSON
API: generic operations over any class, rather than one tool per business object. Task-shaped
tools ("open an incident", "add a work note", "find the caller") are deliberately **not** here
— they belong in extensions built on top of this one (see [Extending](#extending)).

**Tools**

| Tool | Purpose |
|---|---|
| `core_class_list` | List the readable classes, narrowable by `category` (`bizmodel`…) and by `filter` |
| `core_class_schema` | Describe one class: attributes, relations, lifecycle |
| `core_object_find_by_name` | Find objects by free text across every readable class, as the console's global search does |
| `core_object_search_by_oql` | Search objects with an OQL query |
| `core_object_search_by_class` | Search objects of a class by attribute criteria |
| `core_object_get` | Retrieve a single object by class and ID |
| `core_object_get_related` | Walk a named relation (impacts, depends on…) for impact analysis |
| `core_object_get_document` | Read one document held by an object: an attachment, a picture, any blob attribute |
| `core_object_attach` | Attach a file to an object, or set one of its document attributes. Dry run by default |
| `core_object_create` | Create an object. Dry run by default (`simulate: true`) |
| `core_object_update` | Update an object's attributes. Dry run by default |
| `core_object_apply_stimulus` | Apply a lifecycle stimulus (state transition). Dry run by default |
| `core_object_delete` | Delete an object, reporting its deletion plan. Dry run by default (`simulate: true`) |
| `core_object_bulk_create` | Create up to 100 objects of one class. Dry run by default |
| `core_object_bulk_update` | Set the same attributes on up to 100 objects. Dry run by default |
| `core_object_bulk_delete` | Delete up to 100 objects, reporting the combined deletion plan. Dry run by default |

Names are qualified by the namespace that owns them, the way resource URIs already
are — `core` belongs to this module, an extension uses its own. Two packs from two
vendors therefore cannot claim one identifier by both calling a class `TicketAddLogEntry`.
Within the namespace, the name is the class name in `snake_case`, which is how every
other MCP server in the ecosystem names its tools.

**Reading without filling a context window.** A tool result is read into one, so the
reading tools narrow by default. `output_fields` takes a comma-separated list of attribute
codes, or `*`; searches default to `id, friendlyname` — the same default iTop's REST API
applies — and `core_object_get` defaults to `*`. Long texts, case logs and link sets are
cut to a ceiling that the value itself declares, unless you name that attribute in
`output_fields`, which is the way to read one in full. Paging is stable: every page is
ordered by `order_by` and then by `id`, so nothing is returned twice or skipped between
pages.

**Files are read one at a time, and never by accident.** A read reports a document attribute
as what it is — filename, media type, size — and a `uri`:

```json
"contents": {
  "filename": "screenshot.png", "mimetype": "image/png", "size": 184320,
  "uri": "itop://core/document/Attachment/71/contents"
}
```

Reading that URI, as a resource or through `core_object_get_document`, returns the file
itself: an image as an image, so a model can actually look at the screenshot someone pasted
into a ticket, anything else as an embedded file. The reason the bytes are not simply in the
read is arithmetic — `AttributeBlob`'s JSON form is the whole file base64-encoded, base64
costs a third again, and `core_object_get` defaults to every attribute, so one call on a
ticket carrying a 4 MB PDF is a 5.4 MB response, larger than the context window it is being
read into and paid for before the caller can decide it did not want it. A search returning
fifty rows multiplies that by fifty, and none of it can be taken back once it is in the
conversation. So a file never arrives *unasked*; asking for one by name is a call of its own.
`mcp_max_document_bytes` (5 MB by default) bounds it in both directions, and the whole
document surface — two tools and the resource template — is the `documents` toolset, so an
instance that would rather serve no files at all turns off one thing.

Attachments are ordinary `Attachment` objects, so `core_object_search_by_class` on
`item_class` and `item_id` is how you find what is attached to a ticket; each result reports
its `contents` with the `uri` that reads it. Going the other way, `core_object_attach` stores
a file you already hold, base64-encoded — as an attachment, or into a named blob attribute.
It deliberately does not fetch: "download this URL and attach it" would have iTop make an
HTTP call to an address chosen by whatever the model was reading, from inside iTop's own
network.

**Nothing writes on a first call.** Every writing tool takes `simulate`, defaulting to
`true`: it runs iTop's own `CheckToWrite()` — mandatory attributes, `DoCheckToWrite()` on the
class and on every extension hooked into it — and reports which attributes the call would
change, without writing. Call again with `simulate=false` to go through with it. That is worth
more than a formality check: for an update, the moment between the check and the write is the
only one where the pending values are still pending, so the report can tell you that setting
`status` to `closed` also cleared three other attributes, *before* it does.

**Every write says where it came from, in the object's own history.** iTop attaches each
change to a `CMDBChange`, and what the console shows on an object's History tab is the line
that record carries. Left alone it is the user's name, which through this endpoint says less
than it looks: the same name appears whether the person made the change themselves, asked an
assistant to make it, or issued a token to an agent that has been making it nightly for a
month. A change made here reads `Jane Doe (MCP: core_object_update)`, and the tool is filled
in from the request — so a tool from a pack that has never heard of any of this is attributed
exactly like a core one.

Every writing tool also takes an optional `comment`, iTop's REST/JSON `comment` by another
route, which adds the *why*: `Jane Doe (MCP: core_object_update) - caller confirmed the laptop
came back`. It is optional rather than mandatory because a required field is answered by
whoever is asked, and what a model writes when it has nothing to say is a sentence restating
the call. The change origin stays `custom-extension`, the value iTop reserves for extensions;
`SELECT CMDBChange WHERE userinfo LIKE '%(MCP:%'` is what finds them all. This is the object's
own history, and it is not the same thing as the endpoint audit trail below — that one records
the calls, including the ones that read and the ones that failed.

**The bulk tools** check `UR_ACTION_BULK_MODIFY` / `UR_ACTION_BULK_DELETE` first — a profile
can be allowed to edit one object and not a thousand — and then take every object one at a
time: the rights question is asked with that object in hand, and the write goes through
`CheckToWrite()`, which is where a datamodel expresses "only the owner may close this". A call
can partly succeed, and the response reports each object separately.

Note what the rights check does and does not give you. iTop's shipped rights add-on documents
that it ignores the instance set for attributes, so on a stock install "may write this
attribute" is decided per class and profile, not per object; the instance set is passed because
the API is tri-state and an add-on that *does* grade per object signals it with
`UR_ALLOWED_DEPENDS`. The per-object rule that always holds is `DoCheckToWrite()`.

**Resources**

| URI | Content |
|---|---|
| `itop://core/version` | iTop version and edition |
| `itop://core/current-user` | The authenticated user and their profiles |
| `itop://core/classes` | The list of classes in the datamodel |
| `itop://core/class/{class}` | One class in detail: attributes, relations, lifecycle |
| `itop://core/document/{class}/{id}/{att_code}` | One document, by the URI a read reported |

The last two are deliberately served twice — as resources, and as the `core_class_list` /
`core_class_schema` tools over the same code. Plenty of clients never fetch resources at all,
and support for resource *templates* is thinner still; a model that cannot reach the schema
falls back to guessing attribute codes, and every other tool here is the poorer for it. The
tool form adds the narrowing a fixed URI cannot offer: a stock datamodel declares several
hundred classes, so `core_class_list` takes a `category` and a `filter`.

**Prompts**

| Prompt | Purpose |
|---|---|
| `core_my_open_tickets` | Summarise the current user's open tickets |

**Server instructions.** At `initialize` the server also sends a short block of guidance —
that attribute codes vary per instance and must be looked up, that OQL has no `ORDER BY`,
that dates are not RFC 3339, that a refusal is a real refusal. A pack can append a paragraph
with `MCPRegistry::AddInstructions()`.

Because the schema is read live from `MetaModel`, whatever your datamodel customisations add
— your classes, your attributes, your states — shows up without any extra configuration.

### Transport and protocol support

Streamable HTTP, over a single endpoint, stateless: each request is authenticated on its own
and no server-side session is carried between requests. SSE streaming and resumability are not
supported. Authentication is delegated to iTop itself — see [Granting access](#granting-access).

### Sizing the worker pool

Every call occupies one PHP worker for its whole duration, and MCP calls are not the short
requests a console page is. A search may read a thousand objects, a bulk tool may read and
write a hundred, and a model exploring a datamodel it has not seen before will make a
succession of them without pausing to think. Several assistants connected at once therefore
hold several workers at once, and they are the same workers the console is served from — an
endpoint sized as an afterthought takes iTop down with it, not just itself.

Nothing here needs a separate pool, but two numbers are worth setting deliberately:

| | |
|---|---|
| `pm.max_children` (PHP-FPM) | Leave headroom above what the console alone needs. The endpoint's ceiling is roughly the number of clients you expect to be connected, not the number of people using iTop |
| `request_terminate_timeout` (PHP-FPM), `max_execution_time` (PHP) | A tool call that will not finish should be cut off rather than held. Set these below whatever timeout sits in front of them, so the worker is released before the proxy gives up on it |

The cheapest way to keep the numbers down is to keep the responses small: `output_fields`
rather than `*`, and a `limit` that matches the question. The defaults already do this — the
search tools return `id` and `friendlyname` unless asked otherwise — and the [audit
trail](#audit-trail) records duration and response size per call, which is where to look
first when the pool is under pressure.

## Installation

1. Unzip the extension into your iTop `extensions/` directory, so that you get
   `<itop>/extensions/altioo-mcp/`.
2. Run the iTop setup (`<itop-url>/setup/`) and tick **Altioo MCP iTop Extension** in the
   list of extensions.
3. Complete the setup so the datamodel is compiled.

The archive ships its own `vendor/` directory — do **not** run `composer install` on a
production instance.

Nothing is reachable yet at this point: the endpoint answers `401` until someone holds one of
the allowed profiles *and* presents a credential. See [Granting access](#granting-access).

### What the install changes

Everything this module adds, so that the change can be reviewed before it is made and found
again afterwards:

| | |
|---|---|
| **One table** | `EventMCPService`, the audit trail — one row per audited MCP call. It inherits `Event`, so it lives in iTop's event log alongside the others |
| **One profile** | `MCP Services User`. It grants no data rights of its own; it marks a user as allowed through the endpoint, exactly as `REST Services User` does for REST/JSON |
| **Enum values** | Seven `MCP*` values added to the `scope` field of `PersonalToken` and `UserToken` (`_delta="if_exists"`, so no core class is redefined) |
| **One URL** | `extensions/altioo-mcp/index.php`. The module's `.htaccess` / `web.config` re-grant web access to that one file and leave iTop's deny over the rest of `extensions/` alone |
| **Module parameters** | The `altioo-mcp` block in `conf/<env>/config-itop.php`, written by the setup with the defaults in [Configuration](#configuration) |
| **Nothing else** | No core class is modified, no core menu, no cron task, no scheduled job, no outbound connection |

**Removing it.** Untick the extension in the setup (or delete
`<itop>/extensions/altioo-mcp/`) and run the setup again. iTop leaves the `EventMCPService`
table in place — as it does for any removed module — so the audit history survives the removal
and can be dropped by hand once you no longer need it. Tokens keep their `MCP*` scope values as
stored strings; those scopes simply stop meaning anything, and no token gains access to
anything else as a result. The `MCP Services User` profile disappears with the datamodel;
users who held it keep their other profiles untouched.

**Upgrading.** Unzip the new version over the old directory and re-run the setup. Read
[CHANGELOG.md](CHANGELOG.md) first: a major version means an identifier or a default that
clients and tool packs depend on has changed.

## Granting access

Access is gated by a profile, by a credential, and by iTop's own permissions. Every gate that
applies must pass.

**1. A profile.** By default only users holding **Administrator** or **MCP Services User**
may reach the endpoint. `MCP Services User` is created by this extension; like iTop's own
`REST Services User`, it grants no data rights of its own — it only marks a user as allowed
through. What the client can actually read or write still comes from the user's other
profiles, so grant it alongside a functional profile, never on its own.

Change the allowed list with the `mcp_allowed_profiles` module parameter (below), or set
`secure_mcp_services` to `false` to drop the profile check entirely.

**2. A credential iTop accepts.** Authentication is delegated to iTop's login stack
(`LoginWebPage::DoLogin()`), so any login mode iTop can perform **non-interactively** works
here, subject to your `allowed_login_types`:

- **A Personal or User token** (recommended). Create one under My Account → Personal Tokens,
  tick an **MCP** scope, and give it to the client. This extension adds those scopes to iTop's
  token classes, so a token minted for REST/JSON or for Export cannot be replayed against the
  MCP endpoint, and vice versa — and so that one credential can be weaker than its owner
  (see [Grading a token](#grading-a-token)).
- **Basic authentication**, exactly as for iTop's REST/JSON API. Scopes are a property of token
  objects, so none applies here — the profile gate and iTop's permissions are what protect the
  endpoint.
- **`external` mode**, where a reverse proxy authenticates the caller and passes `REMOTE_USER`.
  This is where OAuth/OIDC belongs: terminate it in the web server (`mod_auth_openidc`,
  `oauth2-proxy`) and let iTop consume the result. The extension carries no OAuth code of its
  own, and needs none.

Browser-redirect modes (CAS, `combodo-hybridauth`) cannot serve this endpoint — a headless MCP
client has no way to follow a redirect to an identity provider — and neither can a session
cookie, since the endpoint resets the session on every request.

**3. iTop's own permissions.** Every operation goes through `UserRights` — class rights,
object-level rights, per-attribute read and write rights, and stimulus rights. Sensitive
attributes (those whose type implements `iAttributeNoGroupBy`) are masked in output.

On top of those three, `mcp_disabled_tools` lets you turn individual tools, prompts and
resources off outright, by name or by class, whichever extension registered them.

### Grading a token

Everything above answers "who is calling". iTop hangs permissions off the user, so the usual
way to give an assistant less than its owner has is a second user account with its own
profiles, kept in step by hand. The scopes on a token are the shorter route: one person, two
credentials of different strength.

| Scope | Effect |
|---|---|
| `MCP` | Everything the user can do |
| `MCP-read` | Only tools that declare `readOnlyHint` |
| `MCP-write` | Read, create and modify — **not** delete |
| `MCP-delete` | Tools that declare `destructiveHint` |
| `MCP-toolset-<name>` | Restricted to one toolset, e.g. `MCP-toolset-objects`. The base extension ships `datamodel`, `objects`, `relations` and `documents` |

They combine, and the grades are a union: `MCP-write` is read *and* write, because granting
write without read describes nothing anyone means by it. `MCP-read` together with
`MCP-toolset-objects` is read access to the object tools and nothing else. A token scoped
this way can only ever be *narrower* than the instance configuration and narrower than the
user's own profiles — it never widens either.

A tool is graded by the annotations it already declares: `readOnlyHint` is read,
`destructiveHint` is delete, anything else is write. **A tool that declares no annotations at
all is graded `delete`**, so it is withheld from every scoped token. That is deliberate — the
alternative is a pack update quietly handing a read-only credential something that writes —
but it means a pack that skips `getAnnotations()` will appear to be missing tools. See
[Extending](#extending).

The same grading applies instance-wide through `mcp_capabilities`, and `mcp_read_only` is
shorthand for `array('read')`.

## Endpoint

```
https://<your-itop>/extensions/altioo-mcp/index.php
```

Point your MCP client at that URL and authenticate with the token above. Copy-paste
configuration for Claude Code, Claude Desktop, VS Code and Cursor, and what to check when it
does not connect, are in [doc/clients.md](doc/clients.md).

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

`Auth-Token: <your-itop-token>` works too, and is the header iTop's own `authent-token` module
reads natively. Prefer it if `Authorization` never reaches PHP in your deployment: under
FastCGI, Apache drops that header unless `CGIPassAuth On` (or an equivalent
`SetEnvIf Authorization` rewrite) is in effect.

Scope that directive to this one file rather than turning it on for the whole server. It
makes PHP see `Authorization` on every request it covers, and the rest of iTop has no use
for that header — a narrower blast radius costs one block:

```apache
<Directory /path/to/itop/extensions/altioo-mcp>
    <Files "index.php">
        CGIPassAuth On
    </Files>
</Directory>
```

iTop ships an `extensions/.htaccess` (and an `extensions/web.config` for IIS) that denies
every request under `extensions/` except a short list of static file types, PHP not among
them — which is the right default for a directory full of module sources, and would otherwise
answer this endpoint `403`. The module carries its own `.htaccess` and `web.config` granting
access to `index.php` and to nothing else beside it, so the URL above works on a stock Apache
or IIS install. If your web server ignores per-directory configuration (`AllowOverride None`,
or nginx, which has no `.htaccess` at all), the rule does not apply to you in either
direction: nothing denies the endpoint and nothing has to grant it.

MCP clients that offer only a "Connect" button, with no field for a credential, expect the
server to advertise OAuth discovery (RFC 9728). This extension implements no OAuth, by design
— put an OAuth-terminating proxy in front of it. What it does do is advertise the proxy: an
unauthenticated call is answered `401` with a `WWW-Authenticate: Bearer` challenge, carrying
`resource_metadata="…"` when you set `mcp_protected_resource_metadata` to the URL of the
document your proxy serves. That is the pointer such a client follows, and the proxy cannot
add it to a `401` it never sees.

> **If you put an OIDC proxy in front of iTop, it must not forward the upstream token as the
> iTop credential.** The proxy authenticates the caller and then presents iTop with an
> identity it controls — `REMOTE_USER` in `external` mode, or an iTop token the proxy holds
> for that user. Passing the access token it received straight through is the
> *token-passthrough* anti-pattern, which the MCP specification forbids with a MUST NOT: the
> token was issued for the proxy as its audience, iTop cannot validate that it was meant for
> this resource, and a token stolen from any other service the caller uses becomes a working
> iTop credential. Terminate the token at the proxy; map identity, not credentials.

## Configuration

All settings live under the `altioo-mcp` module in `conf/<env>/config-itop.php`:

```php
'altioo-mcp' => array(
    'secure_mcp_services' => true,
    'mcp_allowed_profiles' => array('Administrator', 'MCP Services User'),
    'mcp_allowed_hosts' => array(),
    'mcp_allowed_origins' => array(),
    'mcp_disabled_tools' => array(),
    'mcp_enabled_toolsets' => array(),
    'mcp_capabilities' => array(),
    'mcp_read_only' => false,
    'mcp_pagination_limit' => 200,
    'mcp_protected_resource_metadata' => '',
    'log_mcp_service' => true,
    'log_mcp_method' => array('initialize', 'tools/call', 'resources/read', 'prompts/get', 'exceptions'),
    'log_mcp_level' => 'error',
),
```

| Setting | Default | Effect |
|---|---|---|
| `secure_mcp_services` | `true` | When true, callers must hold one of `mcp_allowed_profiles`. Setting it to `false` opens the endpoint to every authenticated user |
| `mcp_allowed_profiles` | `Administrator`, `MCP Services User` | Profiles allowed through the endpoint |
| `mcp_allowed_hosts` | *(derived)* | Hostnames this endpoint answers to, checked against `Origin` — or against `Host` when there is no `Origin` — before anything else happens, and again inside the MCP SDK. Leave it empty and it is derived from `app_root_url` plus the localhost variants and the hosts of `mcp_allowed_origins`, which is right for a normal install. Set it when iTop is reached under a name `app_root_url` does not carry. `array('*')` turns the check off, which is what a reverse proxy that validates `Host` itself wants — and is what an `app_root_url` written with iTop's `$SERVER_NAME$` placeholder gets, since there is then no name to check against |
| `mcp_allowed_origins` | *(empty)* | Browser origins allowed to read MCP responses. Empty sends no `Access-Control-Allow-Origin` header at all, which is what a token-authenticated endpoint called from a backend wants. Add entries only for browser-based clients you control, and never use `*`. A listed origin gets the header on every response and on the `OPTIONS` preflight, which is answered before authentication because a preflight carries no credential |
| `mcp_disabled_tools` | *(empty)* | Kill switch. List qualified tool or prompt names, resource URIs, or **class names** — e.g. `array('core_object_delete', 'itop://core/current-user', 'Acme\\Tools\\TicketAddLogEntry')`. Anything listed is neither advertised nor callable, whichever extension registered it. The class form is what resolves a name clash between two packs, where the name no longer tells them apart |
| `mcp_enabled_toolsets` | *(empty)* | Toolsets this instance serves — the base extension ships `datamodel`, `objects`, `relations` and `documents`, and a pack declares its own. Empty means all of them. The positive counterpart to `mcp_disabled_tools`: naming what may stay is what you want for a pack whose next release you have not read, since a tool added by an update is then off until you say otherwise |
| `mcp_capabilities` | *(empty)* | What anyone may do: any of `read`, `write`, `delete`. A tool falls into one by its annotations, so a pack is graded by describing its tools rather than by being listed here. Empty means all three |
| `mcp_read_only` | `false` | Shorthand for `mcp_capabilities => array('read')`. Narrows rather than overrides, so setting both cannot come out wider than either |
| `mcp_max_document_bytes` | `5242880` | Largest document served or accepted, in bytes. 5 MB of file is about 6.7 MB of JSON once base64-encoded, which is most of a context window spent on one document. PHP's `upload_max_filesize` and `post_max_size` still apply on the way in |
| `mcp_pagination_limit` | `200` | Elements per `tools/list` page. The SDK defaults to 50 and pages the rest behind a cursor, which a client that ignores `nextCursor` never asks for — the 51st tool then exists, is callable, and is advertised to nobody |
| `mcp_protected_resource_metadata` | *(empty)* | URL of the RFC 9728 document your OAuth proxy serves. Advertised in the `WWW-Authenticate` header of a `401`, which is what a Connect-button client follows |
| `log_mcp_service` | `true` | Write an `EventMCPService` audit entry per call |
| `log_mcp_method` | see above | Which MCP methods are audited. `initialize` is the record that a client connected, and is written whatever `log_mcp_level` says, because a successful connection is the one success worth a row |
| `log_mcp_level` | `error` | `error` logs failures only; `info` logs everything; `debug` additionally records the raw request parameters |

### Settings outside this module that matter here

Two of them, both in the main body of `config-itop.php` rather than in the `altioo-mcp`
block, and both worth setting before the first client connects.

**Disable the configuration editor.** The console's built-in editor executes the PHP you
save into it — that is what it is for, and it is documented behaviour. It is also a code
execution path that an Administrator-scoped credential reaches, which changes what a
successful prompt injection is worth: the difference between a wrongly closed ticket and
arbitrary PHP on the server.

```php
'itop-config' => array(
    'config_editor' => 'disabled',
),
```

Treat this as mandatory wherever the MCP endpoint is enabled. Edit the file directly when
you need to change configuration; the editor buys nothing an SSH session does not.

**Start read-only.** The recommended opening position is an instance that cannot write and
tokens that cannot either:

```php
'mcp_capabilities' => array('read'),
```

with clients issued `MCP-read` tokens. Widen one grade at a time, and only once you have
watched the audit trail for what the assistant actually does. `mcp_capabilities` is the
instance-wide floor and the token scope narrows further within it, so the two together let
one credential be weaker than the instance without a second user account.

### Audit trail

Calls are recorded as **MCP Service Call** (`EventMCPService`) objects, visible in the
console. Each entry holds the MCP method, the tool or resource invoked, the outcome, and the
calling user.

It also holds three numbers that turn the log into something you can act on. **Duration**
and **response size** are what separate a slow instance from a client filling its context
window: a tool answering in 40 ms with 800 KB and one answering in 12 s with 2 KB are
different problems, and the row now says which you have. **Log reference** carries the
identifier that an internal error also gives the caller, so the audit row and the entry in
`log/error.log` can be matched without reading either message.

> `log_mcp_level => 'debug'` stores the **raw JSON request parameters**, which may contain
> data your users would not expect to find in an audit log. Use it for troubleshooting, not
> as a standing setting.

The two records answer two different questions and neither replaces the other. This one is
per *call*: it holds the reads, the failures and the calls that changed nothing, and it is
where you look when you are asking what this endpoint has been doing. The change log
described under [What it exposes](#what-it-exposes) is per *object*: it is what an auditor
opening a CI six months from now reads, and it is where "who changed this, through what, and
why" has to be, because that is the tab they open. `log_mcp_service => false` turns this one
off; attribution in the object's history is not configurable, and costs nothing — it is a
string on a record iTop was going to write anyway.

## Extending

An extension adds its own tools, resources, resource templates and prompts by implementing a
**service provider** and registering objects into the registry. It never has to touch this
module's code.

> **A working pack ships in this archive: [`doc/example-pack/`](doc/example-pack/).** Two tools,
> a prompt, the module declaration, the composer settings, the datamodel delta and a contract
> test — everything below, applied once, in a directory you can copy into `extensions/`. If you
> are starting a pack, start by copying that and renaming `acme`.

### 1. Depend on the base module

In your own `module.<your-extension>.php`:

```php
'dependencies' => array(
    'altioo-mcp/1.0.0',
),
```

This is the version check that matters: the setup refuses the install and tells the
administrator why, which is a better place to find out than a log entry on the first request.
For the case that gets past it — a base extension downgraded under a pack already installed —
call `MCPHelper::RequireVersion('1.0.0', 'your-pack')` at the top of your provider. It throws,
and a provider that throws is logged and skipped on its own, so the failure mode is "this pack
is missing and the log says why" rather than a fatal error taking the endpoint down for every
other pack. `MCPHelper::AtLeast()` is the same question without the exception, for a pack that
would rather hide one element through `isAvailable()` and serve the rest.

**Your autoloader has to produce a classmap.** Not the composer default, and the failure is
silent. iTop's `InterfaceDiscovery` — the mechanism that finds a provider you did not declare —
enumerates candidate classes by reading `env-<env>/<module>/vendor/composer/autoload_classmap.php`.
A PSR-4-only dump leaves that file essentially empty, so nothing is discovered, and any class
the pack fails to autoload fails as "not found". Set both, and list the autoloader first in the
module's `datamodel` array so it is in place before `register.php` names a class:

```json
"config": { "optimize-autoloader": true, "classmap-authoritative": true }
```

**Do not ship `mcp/sdk` — put it in `require-dev`.** It is a genuine *runtime* dependency of
your code: your tools type-hint `Mcp\Schema\ToolAnnotations` and throw `Mcp\Exception\ToolCallException`,
and both have to resolve when a client calls them. `require-dev` is not a mislabelling of that,
it is where the dependency is satisfied from — this module vendors the SDK and loads it from
its own `datamodel` array, and iTop includes a module's datamodel files after those of the
modules it depends on, so `Mcp\*` is already registered by the time anything of yours is
autoloaded. Two copies in one process resolve to whichever autoloader answered first.

Installing it and deleting `vendor/mcp` before packaging does **not** work:
`classmap-authoritative` has already written those classes into `autoload_classmap.php`, so the
entries outlive the files and the first call fatals on a missing include. Pin the same
constraint this module vendors, published as `MCPHelper::SDK_CONSTRAINT`, so that what you
compile against is what will be loaded. The types that are part of the contract described here
are `Mcp\Schema\ToolAnnotations`, `Mcp\Schema\Annotations`, `Mcp\Exception\ToolCallException`
and `Mcp\Exception\ResourceReadException`.

**Versioning.** The extension follows semver, and the surface it applies to is what you touch
from a pack: the abstracts under `Abstract/` — the four element bases plus `AbstractObjectSearch`
and `AbstractBulkTool` — `MCPRegistry`, `MCPExtensionCollector`, `iMCPServiceProvider`, the
helpers under `Helper/` and the checker under `Testing/`. A breaking change there is a major
bump; a new optional hook with a default implementation is a minor one. The running version is
`MCPHelper::VERSION` — the same string the server sends to clients in `serverInfo`.

### 2. Write a tool

Extend `AbstractMCPTool`. `getNamespace()` is yours to pick — a vendor or module name,
`[a-zA-Z0-9-]`, and not `core`, which belongs to this module. `getName()` defaults to the
class short name in `snake_case`, the shape every MCP server in the ecosystem uses and the
one a model has seen thousands of examples of. What the client sees and calls is the two
joined by `_`, so `acme` + `TicketAddLogEntry` is advertised as
`acme_ticket_add_log_entry`. That composition is `final`: an extension cannot put itself
back into the shared space by accident.

The parameter names of `execute()` **must match the properties of the input schema** you
declare: the server binds arguments by name, so a property with no matching parameter is
dropped and a mandatory parameter absent from `required` makes every call fail. Registration
checks this for you (see [What registration checks](#what-registration-checks)). The core
tools declare `execute()` static; an instance method works equally well.

```php
use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

class TicketAddLogEntry extends AbstractMCPTool
{
    public function getNamespace(): string
    {
        return 'acme';
    }

    public function getTitle(): ?string
    {
        return 'Add a log entry to a ticket';
    }

    public function getDescription(): ?string
    {
        return 'Append an entry to the public or private log of a ticket.';
    }

    public function getInputSchema(): ?array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id'      => ['type' => 'integer', 'description' => 'Ticket ID.'],
                'message' => ['type' => 'string',  'description' => 'Text to append.'],
            ],
            'required' => ['id', 'message'],
        ];
    }

    public function getAnnotations(): ?ToolAnnotations
    {
        return new ToolAnnotations($this->getTitle(), false, false, false, false);
    }

    public static function execute(int $id, string $message): mixed
    {
        // Always go through UserRights / MetaModel — never raw SQL.
        // Throw ToolCallException for anything the caller should be told.

        return ToolOutput::Json(['id' => $id, 'added' => true]);
    }
}
```

**Annotate your tools.** `getAnnotations()` is optional to PHP and not optional in practice:
`readOnlyHint` and `destructiveHint` are what grade a tool as read, write or delete, and a
tool that declares nothing is graded `delete` — withheld from every token scoped with
`MCP-read` or `MCP-write`, and from any instance running with `mcp_capabilities`. The
symptom is a tool that works for an administrator and is invisible to everyone else.

**A tool that writes should take a `comment`.** Attribution itself is not yours to remember —
every change made during a request already carries the tool that made it, yours included, set
before any tool runs. What a pack has to offer is the *why*, and there is one spelling of it
so that a model does not meet three:

```php
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;

// In getInputSchema(), beside 'simulate':
'comment' => ChangeTracking::CommentSchemaProperty('the entry is being added'),

// In execute(), immediately before the DBUpdate()/DBInsert()/DBDelete():
ChangeTracking::Explain($comment);
```

Call it before the write and not after — iTop builds the change record from what was last
said, at the moment the write happens. Call it once per batch rather than per object: one
call is one decision, and the objects it touches share one record. On a dry run it costs
nothing, since nothing is written for it to describe.

**Return `ToolOutput::Json()` rather than an array.** Both work, but an array is JSON-encoded
into the text content *and* copied into `structuredContent`, pretty-printed — the whole result
twice, in a response a model pays for by the token. Returning a `TextContent` takes both
branches away. If you do want structured content, declare `getOutputSchema()` so that the
duplicate is at least validated against something.

**Declare a `getToolset()`** if your pack has more than one kind of tool in it. It defaults to
your namespace, which lets an operator turn the pack on or off as a whole; naming groups lets
them turn on the half they use, through `mcp_enabled_toolsets`.

**A toolset token scope needs a line of datamodel as well.** `getToolset()` alone gets you the
configuration setting and nothing else — it does *not* give you an `MCP-toolset-<name>` token
scope. A scope is a value of the `scope` enum on `PersonalToken` and `UserToken`: one that is
not declared there cannot be selected when a token is created, and because iTop honours a scope
only when a context tag of the same name was pushed before login, a token carrying an
undeclared scope **cannot log in at all**. So a pack that wants its toolset grantable per
credential ships the delta itself:

```xml
<class id="PersonalToken" _delta="if_exists">
  <fields>
    <field id="scope" _delta="if_exists">
      <values>
        <value id="MCP-toolset-acme-servicedesk" _delta="define">
          <code>MCP-toolset-acme-servicedesk</code>
        </value>
      </values>
    </field>
  </fields>
</class>
```

Both token classes, since either kind can carry it. This module reads the declared enumeration
rather than a list of its own — `TokenScopes::DeclaredContextTags()` — precisely so that it
needs to know nothing about your name. [`doc/example-pack/datamodel.acme-servicedesk.xml`](doc/example-pack/datamodel.acme-servicedesk.xml)
is the whole file, dictionary entries included.

Two optional hooks, available on **all four kinds** — tools, resources, resource templates
and prompts — and applied to all four when the server is built:

- `isAvailable()` — return `false` to hide the element entirely, e.g. when a module it depends
  on is not installed on this instance.
- `requiredProfiles()` — return a list of profiles the caller must hold for the element to be
  advertised and served. An empty list (the default) means it is offered to everyone who got
  through the endpoint gates. This is a *visibility* filter on top of `UserRights`, not a
  replacement for it.

`requiredProfiles()` is an **AND**: the caller must hold *every* profile listed. That is the
opposite of the endpoint gate `mcp_allowed_profiles`, which is an OR, and the difference is in
the words — *allowed* means any of these lets you in, *required* means all of these are needed.
For any-of semantics on a single element, override `isAvailable()` and test the profiles
yourself.

Writing values back to iTop, from a tool of your own:

```php
use Altioo\iTop\Extension\MCP\Helper\RestValue;

$realValue = RestUtils::MakeValue($sClass, $sAttCode, RestValue::FromDecodedJson($value));
```

The MCP SDK decodes inbound JSON with `json_decode($input, true)`, so a nested JSON object
reaches you as a PHP array — while `RestUtils` branches on `stdClass` to tell search criteria
from an id, and a caselog append from a plain string. Without that call the wrong branch is
taken silently. Scalars pass through untouched and JSON lists stay arrays, which is what link
sets and tag sets need.

**Two base classes worth extending instead of `AbstractMCPTool`**, both under `Abstract/` and
both covered by the versioning policy above:

| Extend | When | What you stop having to write |
|---|---|---|
| `AbstractObjectSearch` | Your tool returns a set of objects | Paging, ordering, field selection, and `has_more` / `next_offset` |
| `AbstractBulkTool` | Your tool acts on a list of ids | The bulk right check, the per-object right check, id validation, the dry run |

Neither declares a namespace — that is yours, and the registry refuses `core` from anything
outside this module. The reason to prefer them over copying is not brevity in either case.
`AbstractObjectSearch` knows that object-level rights remove rows from a page *after* the
database counted them, so a short page is not the end of the set and a caller that reads it as
one stops early and silently. `AbstractBulkTool` knows that `UR_ACTION_BULK_MODIFY` is a
separate grant from `UR_ACTION_MODIFY`, and that the class-level answer is never the end of it.
A bulk tool that checks the class once and then loops is an escalation with a progress counter,
and it passes every test written by a developer whose own account holds both rights.

### Augmenting a core tool

`overrides()` replaces an identifier wholesale. The commoner want is smaller — the core tool is
right except for one thing — and the way to get it is to **subclass the core tool and declare
the override**, which works because the registry validates *your* schema against *your*
`execute()`, not against the parent's:

```php
class ObjectGet extends \Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet
{
    public function getNamespace(): string { return 'acme'; }

    /** Take over the identifier clients already use. */
    public function overrides(): ?string { return 'core_object_get'; }

    public function getInputSchema(): ?array
    {
        $aSchema = parent::getInputSchema();
        $aSchema['properties']['include_history'] = [
            'type' => 'boolean', 'default' => false,
            'description' => 'Also return the last ten changes to this object.',
        ];

        return $aSchema;
    }

    public static function execute(
        string $class, int $id, string $output_fields = '', bool $include_history = false
    ): mixed {
        $oResult = parent::execute($class, $id, $output_fields);

        return $include_history ? self::withHistory($oResult, $class, $id) : $oResult;
    }
}
```

Three rules make this hold. New parameters go **last and with defaults**, so the arguments the
model already sends still bind by name. The declared override is what stops the registry
treating your class and the core one as an accidental clash and withdrawing the name from both.
And `parent::execute()` returns a `TextContent`, not an array — post-processing means decoding
it, which `ToolOutput::Decode()` does.

What this does not give you is a change applied across *every* tool — redaction, an extra audit
field, a rate limit. There is no middleware pipeline; see [Limitations](#limitations).

### Translating what a person reads

`getTitle()` on all four kinds resolves through iTop's dictionary, falling back to what
`defaultTitle()` returns. So override `defaultTitle()` with the English literal, and add
dictionary entries if you want it translated:

```php
protected function defaultTitle(): string { return 'Add a Log Entry to a Ticket'; }
```

```xml
<entry id="MCP:tool:acme_ticket_add_log_entry:title"><![CDATA[Add a Log Entry to a Ticket]]></entry>
```

The key is `MCP:<kind>:<qualified name>:title`, with `<kind>` one of `tool`, `resource`,
`resource_template` or `prompt`; `titleDictionaryKey()` returns it if you would rather not
spell it. A key nobody wrote falls back silently, so a pack that ships no dictionary reads
exactly as it did before.

**Descriptions are deliberately not translated.** A title is read by a person; a description is
read by the model deciding whether to call the tool. English is what those models have seen
thousands of examples of, and a description that changed with the caller's language would change
what the model does — a French-speaking user and an English-speaking one would get different
tool selection from the same instance. Write descriptions in English and leave them there.

### Testing your pack

`ElementContract` runs the same checks the registry runs at boot and returns them instead of
throwing, so a pack's whole contract suite is one assertion per element:

```php
use Altioo\iTop\Extension\MCP\Testing\ElementContract;

self::assertSame([], ElementContract::Violations(new TicketAddLogEntry()));
```

It needs neither iTop nor a database, and it references no dev dependency of this module —
which is why it ships in the production autoload rather than under `tests/`, where
`autoload-dev` and iTop's own path exclusions would both put it out of your reach. Nothing that
serves a request refers to it, and the autoloader is a classmap, so an instance that never calls
it never reads the file.

Alongside the refusals it reports the things that register cleanly and then disappoint — chief
among them a tool with no `getAnnotations()`, which works for an administrator and is invisible
to every scoped token. `MCPRegistry::Check()` is the underlying single-shot version, if you want
the exception rather than the list.

### 3. Write a resource or a prompt

Resources extend `AbstractMCPResource` and expose `read()`; resource templates extend
`AbstractMCPResourceTemplate` and take the URI variables as arguments; prompts extend
`AbstractMCPPrompt` and expose `get()`.

The URI is assembled from `getResourceNamespace()` and `getResourcePath()` as
`itop://<namespace>/<path>`. **Use your own namespace** — `core` belongs to this module.
That is the same namespace tools and prompts declare through `getNamespace()`; resources
had it first, and it is why they never had the collision problem.

```php
class MyThing extends AbstractMCPResource
{
    protected function getResourceNamespace(): string { return 'acme'; }
    protected function getResourcePath(): string      { return 'things'; }
    public function read(): mixed { return json_encode([...]); }
}
```

### 4. Register everything from a service provider

```php
use Altioo\iTop\Extension\MCP\Contract\iMCPServiceProvider;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;

class AcmeMCPExtensions implements iMCPServiceProvider
{
    public static function RegisterServiceProvider(): void
    {
        MCPRegistry::RegisterTool(new TicketAddLogEntry());
        MCPRegistry::RegisterResource(new MyThing());
        // RegisterResourceTemplate() and RegisterPrompt() likewise
    }
}
```

Providers are collected once at the start of every MCP request. The documented way to declare
yours is to call `MCPExtensionCollector::RegisterServiceProvider(AcmeMCPExtensions::class)`
from a file listed in the `datamodel` array of your module declaration — exactly what this
module's own `register.php` does:

```php
'datamodel' => array(
    'vendor/autoload.php',
    'register.php', // calls MCPExtensionCollector::RegisterServiceProvider(...)
),
```

Providers are also discovered automatically through iTop's `InterfaceDiscovery` (3.0+, cached),
so a class implementing `iMCPServiceProvider` is usually found without being declared. That
mechanism ignores anything under `/vendor/`, `/lib/`, `/test/`, `/tests/` and `/node_modules/`,
so put your provider in your module's `src/`. Declaring it explicitly always works and does not
depend on where the file lives; a provider that is both declared and discovered still runs
exactly once.

A provider that throws is logged and skipped — one broken pack does not take the endpoint down
for the others.

### What registration checks

`MCPRegistry` validates each object as it is registered, and throws `MCPRegistrationException`
naming your class and the defect. This is the boot-time half of the contract the abstracts
cannot express: an abstract method forces a method to *exist*, not to agree with the schema
declared next to it. Every one of these otherwise stays invisible until a client calls the
element, and then surfaces as an empty listing or an unrelated error from inside the SDK.

| Kind | Checked |
|---|---|
| all | `getToolset()` matches `[a-zA-Z0-9-]{1,64}`; `getNamespace()` matches `[a-zA-Z0-9-]{1,64}` and is not the reserved `core` unless you *are* core; the composed identifier matches `[a-zA-Z0-9_-]{1,128}`; `overrides()` returns null or another element's identifier, never its own; `requiredProfiles()` returns a list of non-empty strings; the handler (`execute()` / `read()` / `get()`) exists and is public |
| tools | non-empty `getDescription()`; input schema is a JSON Schema of type `object`; every `required` entry is declared under `properties`; every property maps to a parameter of `execute()`; every parameter of `execute()` without a default is listed under `required` |
| resources | the URI carries no `{variable}` |
| resource templates | the URI template carries at least one `{variable}`, and each one matches a parameter of `read()` |

`MCPRegistry::Check()` runs exactly these against one element without registering it, and
[`ElementContract`](#testing-your-pack) wraps it for a test suite — so a pack can meet all of
this in CI rather than on an instance.

### Who owns an identifier

Namespacing makes a collision between two unrelated packs unlikely, not impossible — two
vendors can still pick the same namespace. When one identifier is claimed by two different
classes, the registry does not pick a winner:

- **Same class registered twice** — nothing happens. That is what a provider both declared in
  `register.php` and found by discovery looks like.
- **One of them declares `overrides()`** — it takes the identifier, whichever order the two
  registered in, and the replacement is written to the log. This is how you deliberately
  replace a core tool: return `'core_object_delete'` from `overrides()` and keep the name the
  clients already use. It is the only way to claim an identifier that is not yours.
- **Neither declares anything** — an accident, and it is treated as one. The identifier is
  **withdrawn**: it is served to nobody, and every class that claimed it is logged. The
  alternative, awarding it to whoever loaded last, would have a client call the tool whose
  description it was shown and run a different vendor's code. The rest of both packs is
  unaffected; only the disputed name goes.

The operator resolves a withdrawal with `mcp_disabled_tools`, which accepts a class name for
exactly this reason — the identifier no longer tells the two apart, and the class does.

### What the framework guarantees you

- Authentication, the profile gate, token scope checking and CORS are handled before your code
  runs. Inside `execute()` / `read()` / `get()` you have an authenticated iTop session.
- Exceptions are caught at the entry point. `ToolCallException` and `ResourceReadException`
  messages reach the client; anything else is answered generically and correlated to
  `log/error.log` by a reference, so internal detail (SQL, class names) does not leak.
- Your call is audited as an `EventMCPService` under the same rules as the core tools.
- Your element is registered only if it honours the contract above, and only if the operator
  has not disabled it through `mcp_disabled_tools`.

What it does **not** do for you: enforce your business rules. Check `UserRights` for anything
you read or write, and mask what should not be shown.

## Custom work

The core is free and AGPL, and it stays generic on purpose. Domain-specific tool packs — ITSM
workflows, your own datamodel extensions, integrations with the rest of your estate — sit on
top of it. Build your own with the extension points above, or have
[Altioo](https://github.com/altioo) build and maintain one for you.

## Data flow and privacy

"MCP" tends to read as "my ticket data now goes to an AI vendor". It is worth being exact
about what this module does and does not do, because that is the question a DPO will ask.

**iTop opens no outbound connection.** This extension makes no HTTP call to any model provider,
to Altioo, or to anywhere else. It sends no telemetry, no usage statistics and no error
reports. Traffic is inbound only: an MCP client connects to your endpoint, authenticates as an
iTop user, and receives exactly what that user is allowed to read.

**The client is the data controller's real question.** Whatever the assistant reads, its own
provider then processes under that provider's terms — that is a property of the client you
point at the endpoint, not of this module. The two levers on your side are the profiles you
pair with `MCP Services User` (what may be read at all) and the token scopes (what that one
credential may do).

**Personal data.** iTop objects routinely contain personal data — callers, agents, contact
details, free text in logs. Nothing here filters that beyond iTop's own per-attribute read
rights and the masking of attributes whose type implements `iAttributeNoGroupBy`. If your
lawful basis for processing does not cover sending a ticket's contents to a third-party model,
that decision belongs at the profile you grant, before the first connection.

**What the module stores.** One `EventMCPService` row per audited call: timestamp, user,
method, element invoked, outcome, duration, response size. Request *parameters* are stored only
at `log_mcp_level => 'debug'`, which is a troubleshooting setting, not a standing one. Set your
own retention on that table as you do for iTop's other event classes.

## Security

Full threat model, hardening notes and how to report a vulnerability:
**[SECURITY.md](SECURITY.md)**.

In short: the endpoint is a public HTTP entry point. Beyond the gates above, follow
[iTop's security guidance](https://www.itophub.io/wiki/page?id=latest:install:security) —
in particular serve iTop over HTTPS with HSTS, and set `session.cookie_secure`,
`session.cookie_httponly` and `zend.exception_ignore_args` in PHP.

Grant `MCP Services User` deliberately. An MCP client is driven by a language model acting
on instructions that may come from outside your organisation, so treat the profiles you pair
it with as the real blast radius — start read-only and widen only as needed. The same applies
to the tool packs you install on top: a tool can only do what the calling user could, which is
exactly why the pairing of profile and tool set is the thing to review.

## Support

The extension is free and maintained in the open. What that means concretely:

| | |
|---|---|
| **Bugs and questions** | Open an issue on the repository (link at the top of this page). Include the iTop version, the extension version, the PHP version, and the request that reproduces it |
| **Security** | Not via a public issue — see [SECURITY.md](SECURITY.md) |
| **Response** | Best effort. There is no service commitment attached to the free extension, and this page will not pretend otherwise |
| **Paid support, custom tool packs, integration work** | Available from Altioo — see [Custom work](#custom-work) |

> **To be filled in before publication:** the support and security channels above, and any
> response commitment attached to a paid arrangement. The rest of this page stands as written.

## Versioning and compatibility

The extension follows [semver](https://semver.org/), and the surface it applies to is what a
tool pack touches: the abstracts under `Abstract/`, `MCPRegistry`, `MCPExtensionCollector`,
`iMCPServiceProvider`, the helpers under `Helper/` and the checker under `Testing/`. A breaking
change there is a major bump;
a new optional hook with a default implementation is a minor one. The running version is
`MCPHelper::VERSION` — the same string the server sends clients in `serverInfo`, and the same
string in `extension.xml` and the module declaration.

**Tool and resource identifiers count as public surface too.** A client configuration that
allow-lists tools by name, and any `mcp_disabled_tools` entry, breaks if a name changes — so a
name change is a major bump, and it is in the changelog.

**On `mcp/sdk`.** The MCP SDK this module vendors is pinned `^0.7.1`: a pre-1.0 package, whose
API can change between minor versions. The module pins the minor it was tested against, ships
it inside the archive, and treats an SDK upgrade as a release of its own with the test suite as
the gate. Nothing on an installed instance moves until you install a new version of this
extension. A tool pack must **not** bundle its own copy — two copies of the SDK in one PHP
process collide.

**iTop branches.** A release targets the iTop branches listed under
[Requirements](#requirements). When Combodo retires a branch, support for it is dropped in the
next minor rather than silently.

## Limitations

Known and deliberate, so that none of them is a discovery made after installing:

- **Streamable HTTP only.** No SSE streaming, no resumability. Each request is authenticated on
  its own and no server-side session is carried between them.
- **No OAuth.** By design — terminate it in a proxy in front of iTop. A client that offers only
  a "Connect" button needs `mcp_protected_resource_metadata` set so the `401` can point at the
  proxy's discovery document.
- **No browser-redirect login.** CAS and `combodo-hybridauth` cannot serve a headless client;
  nor can a session cookie, since the endpoint resets the session per request.
- **Generic tools, not task-shaped ones.** No "open an incident" tool here — see
  [Extending](#extending) and [Custom work](#custom-work).
- **A tool with no annotations is graded `delete`**, so a pack that skips `getAnnotations()`
  appears to be missing tools for every scoped token. That is the safe direction of failure,
  but it is a failure people meet — `ElementContract` reports it, which is the reason it
  reports warnings at all and not only refusals.
- **No middleware around tool execution.** A pack can replace a named tool through `overrides()`
  or subclass it, but there is no hook that applies to *every* call — no place to put redaction,
  a rate limit, an extra audit field or per-tenant filtering once. Cross-cutting behaviour of
  that kind currently means touching this module.
- **A tool result is read into a context window.** Reads narrow by default and long values are
  clipped; a deliberately wide `output_fields => *` over thousands of objects is still your
  cost to pay.
- **The audit trail grows.** One `EventMCPService` row per audited call, with no built-in purge
  — set retention as you do for iTop's other event classes.
- **No console UI.** Configuration is the module parameters in `config-itop.php`.

## Development

```bash
composer install
composer test:unit
```

The unit suite needs neither iTop nor a database. The integration suite additionally needs a
live iTop with the module installed, and skips itself otherwise:

```bash
ITOP_ROOT=/path/to/itop/web composer test:integration
```

Build a release archive with `composer install --no-dev`; `exclude.txt` lists what is kept
out of the package.

[`doc/example-pack/`](doc/example-pack/) ships in that archive on purpose — a pack author on an
instance in a year's time has it to hand. Its module declaration carries a `.tpl` suffix so
that the setup, which `eval`s every `module.*.php` it finds anywhere under `extensions/`, does
not offer the example as something to install.

## License

[AGPL-3.0-or-later](LICENSE), matching iTop itself. The same identifier is in `composer.json`
and in every source file header.

**Why AGPL and not something permissive.** An iTop extension is not a separate program that
talks to iTop: it is loaded into the same PHP process, subclasses core classes, calls
`MetaModel` and `UserRights`, and its datamodel is compiled together with core's. The
distributed combination is a derivative work of iTop, which is AGPL — so the combination is
AGPL whatever an extension's own files claim. Choosing AGPL here states that plainly instead of
promising something the obligation would not deliver.

### What this means for a tool pack you write

This is the question a legal review asks, so here is the answer in the open. It is a plain
reading of the licence, not legal advice; your counsel decides for your organisation.

- **Writing your own tool pack against these extension points makes it a derivative work.** It
  subclasses `AbstractMCPTool` and is loaded into the same process, exactly as this module is
  with respect to iTop core. The AGPL applies to it on the same reasoning.
- **The obligation is triggered by distribution, and by §13, by letting people interact with it
  over a network.** Running your own pack on your own iTop, for your own staff, is use, not
  distribution. AGPL §13 covers *remote network interaction* — the people who interact with
  the instance are the ones who may ask for source.
- **In practice**, for an internal pack on an internal iTop: your users are your own
  organisation, and they are entitled to the source of what they interact with — which they
  already have. Nothing obliges you to publish it to the world.
- **If you offer iTop with your pack as a service to third parties**, those users may request
  the corresponding source of the combined work, this module included, under §13.
- **If you distribute your pack** (to a customer, on the Hub), it goes out under AGPL with
  source.

If your situation needs something other than AGPL for the pack itself, that is a licensing
conversation rather than a technical one — see [Custom work](#custom-work).
