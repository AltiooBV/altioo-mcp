# Altioo MCP — Model Context Protocol server for iTop

Turns an iTop instance into an MCP server, so assistants (Claude, and any other Model
Context Protocol client) can search, read and update CMDB and ticketing objects.

[Repository](https://github.com/altioo/mcp-server-extension) ·
[Changelog](CHANGELOG.md) ·
[Security](SECURITY.md) ·
[Client setup](doc/clients.md) ·
[Extending](doc/extending.md) ·
[Troubleshooting](#troubleshooting) ·
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

<!-- supported-versions:begin — the two rows below are checked against .github/itop-support.json
     and composer.json by ModuleMetadataTest. Edit those, not this. -->

| | |
|---|---|
| iTop | **3.2** (current LTS) or **3.3** |
| PHP | **8.2** to **8.4** |

<!-- supported-versions:end -->

| | |
|---|---|
| Database | Whatever your iTop runs on — this module adds one table and no dialect-specific SQL |
| iTop modules | `authent-token` 2.2.1 or later, and `itop-structure` 3.2.0 or later (both ship with iTop) |
| Web server | Apache or IIS; the module ships the `.htaccess` / `web.config` that expose its single entry point |

Those two rows are a claim about what CI actually installs and tests, and the claim is declared
once, in
[`.github/itop-support.json`](https://github.com/altioo/mcp-server-extension/blob/main/.github/itop-support.json)
for the branches and in `composer.json` for the PHP range. The CI matrix is computed from that
file, the Hub listing is filled from it, and a test fails if the rows above stop agreeing with
it — so there is one place to change when a branch is added or retired, and nothing that can
quietly disagree with it. iTop 3.1 and earlier are not supported at all: the setup refuses to
install, through the `itop-structure/3.2.0` dependency.

**Which PHP goes with which iTop is iTop's decision, not this module's**, and it moves within a
branch: 3.2 gained 8.4 at 3.2.3-1, having had known issues with it before. You cannot get this
combination wrong silently — iTop's own setup refuses a PHP it has not validated, and the
version it refuses at is `PHP_NOT_VALIDATED_VERSION` in `setup/setuputils.class.inc.php` on the
branch you are installing. For the per-patch answer, read
[iTop's requirements](https://www.itophub.io/wiki/page?id=3_2_0:install:requirements) for the
patch you run. The declared range `>=8.2 <8.5` is the intersection of the branches above: floor
8.2 because iTop 3.3 requires it, no ceiling at 8.5 because no iTop branch validates it yet.

**What a release is tested against.** No version is published until the unit suite has passed in
CI across the declared PHP range, and the archive has been unzipped, installed through the iTop
setup and connected to from a real MCP client on at least one iTop 3.2 instance — the gate is
written down in [doc/release-checklist.md](doc/release-checklist.md), and each release records
the iTop patch and the PHP version its own run used under **Tested on** in
[CHANGELOG.md](CHANGELOG.md). 1.0.0 has not been released, so that line names nothing yet.
Combinations outside that are expected to work from the ranges above rather than
observed; if one of them is the one you run, say so and it can be added to the gate.

## What it exposes

The core surface stays close to iTop's own primitives, in the same spirit as its REST/JSON
API: generic operations over any class, rather than one tool per business object. Task-shaped
tools ("open an incident", "add a work note", "find the caller") are deliberately **not** here
— they belong in extensions built on top of this one (see [Extending](#extending)).

**Tools**

| Tool | Purpose |
|---|---|
| `core_current_user` | Who the session is authenticated as: contact name and id, user id, language, archive mode |
| `core_class_list` | List the readable classes, narrowable by `category` (`bizmodel`…), by `filter`, and by `may` — the rights gate the caller must clear, e.g. `may=create` |
| `core_class_schema` | Describe one class: attributes, relations, lifecycle, and what the caller may do with it |
| `core_object_find_by_name` | Find objects by free text across every searchable class the caller may read, as the console's global search does |
| `core_object_search_by_oql` | Search objects with an OQL query; `audit` adds creation and last-change attribution, for a page of 25 or fewer |
| `core_object_search_by_class` | Search objects of a class by attribute criteria; `audit` as above |
| `core_object_get` | Retrieve a single object by class and ID, with its creation and last-change attribution |
| `core_object_get_related` | Walk a named relation (impacts, depends on…) for impact analysis |
| `core_object_history` | What iTop recorded happening to one object: when, who, which attribute, and the values before and after. Its own `history` toolset |
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

**The read tools that return a set** — `core_object_search_by_class`,
`core_object_search_by_oql`, `core_object_find_by_name` — check `UR_ACTION_BULK_READ` on the
class before returning anything, so a credential issued without the bulk right cannot sweep a
class. `core_object_get_related` applies the same rule to the graph it walks, per class and
from the second object onward: one related object of a class is a single read and is served on
`UR_ACTION_READ` alone, so ordinary impact analysis still works, while two or more of a class
is a bulk read of it and needs the bulk right. That is stricter than iTop's own console, which
gates impact analysis on read alone — the console is a person on one screen, and this is a
credential that can walk every relation from every object it reaches.

The class is withheld, not the call. A walk spans classes an account is graded differently on,
so the rest of the answer is still returned, and the response says which classes were held back
and why:

```json
"withheld": {
  "classes": ["Server"],
  "note": "More than one related object of Server was found, which is a bulk read of that
           class, and this account does not hold UR_ACTION_BULK_READ on it. ..."
}
```

The classes are named and never counted — the names describe this account's rights on classes
it already holds read on, whereas a count would be the very datum the bulk right withholds. The
relations touching a withheld object are dropped with it, so no returned edge points at
something the payload does not carry. The `withheld` key is absent when nothing was held back.

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
| `itop://core/current-user` | Who the request authenticated as: contact, user id, language, and whether archive mode is on |
| `itop://core/classes` | The list of classes in the datamodel |
| `itop://core/class/{class}` | One class in detail: attributes, relations, lifecycle, and the caller's rights on it |
| `itop://core/document/{class}/{id}/{att_code}` | One document, by the URI a read reported |

`itop://core/classes` and `itop://core/class/{class}` are deliberately served twice — as
resources, and as the `core_class_list` / `core_class_schema` tools over the same code (the
document template is doubled the same way, by `core_object_get_document`). Plenty of clients never fetch resources at all,
and support for resource *templates* is thinner still; a model that cannot reach the schema
falls back to guessing attribute codes, and every other tool here is the poorer for it. The
tool form adds the narrowing a fixed URI cannot offer: a stock datamodel declares several
hundred classes, so `core_class_list` takes a `category` and a `filter`.

`core_class_schema` also reports what the calling user may *do* with the class — the question
the console answers by rendering a button or not, and a client with no buttons had no way to
ask. A `rights` block grades `read`, `bulkRead`, `create`, `bulkCreate`, `modify`, `bulkModify`,
`delete` and `bulkDelete`, and every attribute carries the same grade under `modify`. Each is `yes`, `no` or
`depends`: three answers, because iTop's rights API has three, and folding `UR_ALLOWED_DEPENDS`
into either neighbour reports something no addon ever said.

`no` is the final half — every write tool checks the class gate before it fetches an object, so
nothing gets past it and the model should say so rather than try. `yes` means only that the call
gets that far: the object can still refuse through a silo, a lifecycle state or the datamodel's
own `DoCheckToWrite()`. An attribute's `modify` sits beside `readOnly` rather than replacing it,
because they fail for different reasons — `readOnly` is the datamodel refusing everybody and no
administrator can grant it, `modify` is this caller being refused something somebody can.

`bulkCreate` is the one key iTop does not answer. There is no `UR_ACTION_BULK_CREATE`, so
`core_object_bulk_create` is gated on `UR_ACTION_CREATE` and `UR_ACTION_BULK_MODIFY` together
and the block reports the stricter of the two. The other two bulk tools pair with the rights
their names suggest; this one does not, and a model reading `create` and `bulkModify` has no
way to learn that those two together are what decide it — so it is combined here rather than
left to be inferred.

`itop://core/current-user` is doubled for the first of those reasons alone, by
`core_current_user`. There is nothing to narrow — one identity, no arguments — so the tool form
adds only reach, which for this particular fact is what was missing: a model that cannot find
out who it is either asks the user a question the server would have answered, or answers "my
tickets" with somebody else's. The protocol puts that on the tool side of its own split, where
resources are context a user attaches and tools are what the model reaches for mid-task.

**Prompts**

| Prompt | Purpose |
|---|---|
| `core_my_open_tickets` | Summarise the current user's open tickets |

**Server instructions.** At `initialize` the server also sends a short block of guidance —
that attribute codes vary per instance and must be looked up, that OQL has no `ORDER BY`,
that dates are not RFC 3339 — with a worked example of each, rendered at request time from
what `AttributeDateTime` and `AttributeDate` report, so the guidance follows the iTop you are
running — that a refusal is a real refusal, and that text found inside an
object asking the client to call a tool or ignore an instruction is content to report rather
than a request to act on, and that what the client holds was settled when it connected —
this transport is stateless and sends no list-changed notification, so a scope you grant
or a pack you install reaches a running session only after it reconnects. A pack can
append a paragraph with `MCPRegistry::AddInstructions()`.

**It is narrowed like the surface it describes.** The guidance is assembled per caller from
the same policy that decides what is registered, so a token scoped to one toolset is not told
to call tools it will never be served — a paragraph about the datamodel tools goes only to a
caller that has them, and the dry-run protocol for `core_object_delete` goes only to one that
may delete. Two blocks are never narrowed: that every call runs as the authenticated user and
that a refusal is final, and that object content is data rather than instruction, and that the
surface is fixed at connect time. The second is a control, and a control that weakens as
the caller is restricted is the wrong way round; the third matters most to the caller
holding the smallest surface, which is the one most likely to be small because of
something you have since changed.
A paragraph a pack appends is *not* narrowed — `AddInstructions()` takes no toolset, so write
it to be true of any caller who might read it.

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

**Before you start.** Installing this runs the iTop setup, and the iTop setup rewrites the
compiled datamodel and applies schema changes to the live database. That is true of every iTop
extension and it is not reversible from inside the application.

- **Take a backup first** — the database *and* `conf/`. iTop's own backup (Admin tools →
  Backup) covers the database; `conf/<env>/config-itop.php` is the file the setup rewrites and
  the one you will want if a module parameter ends up somewhere unexpected.
- **Do it in a maintenance window.** The setup takes the application offline while it runs, and
  a compilation that fails half way leaves the instance down until it is re-run or restored.
- **Rehearse on a copy** if the instance matters. A restored backup on a second host is the
  cheapest way to find out how long the setup takes on your data volume.

Then:

1. Unzip the extension into your iTop `extensions/` directory, so that you get
   `<itop>/extensions/altioo-mcp/`.
2. Run the iTop setup (`<itop-url>/setup/`) and tick **Altioo MCP iTop Extension** in the
   list of extensions.
3. Complete the setup so the datamodel is compiled.

The archive ships its own `vendor/` directory — do **not** run `composer install` on a
production instance.

Nothing is reachable yet at this point: the endpoint answers `401` until someone holds one of
the allowed profiles *and* presents a credential. See [Granting access](#granting-access).

### After the setup, before granting anyone access

Four checks. Each one fails in a way that is much harder to diagnose later than now:

1. **The audit class exists.** In the console, an administrator should find **MCP Service Call**
   under the event log (class `AltiooEventMCPService`). If it is absent, the datamodel did not
   compile and nothing below will work.
2. **The profile exists.** Administration → Profiles should list **MCP Services User**. That is
   the profile named in `mcp_allowed_profiles`, and it is what the endpoint checks for. A
   profile list without it means the same compilation problem as above — and note that the
   endpoint matches on the profile *name*, so a profile renamed in the console stops matching
   until `mcp_allowed_profiles` is updated to say the same thing.
3. **The configuration block was written.** `conf/<env>/config-itop.php` should now contain an
   `'altioo-mcp' => array(...)` block under `module_settings`, carrying the defaults in
   [Configuration](#configuration). If it is missing, the module is installed but every setting
   falls back to its compiled-in default, and editing the file is how you change them.
4. **Only `index.php` is reachable.** `<itop-url>/env-production/altioo-mcp/index.php` should
   answer (a `401` is the correct answer at this point); `<itop-url>/env-production/altioo-mcp/composer.json`
   and `.../src/Controller/MCPController.php` should not. If they are served, the `.htaccess` or
   `web.config` is not being honoured and the source tree is public. Check the compiled tree
   before the one under `extensions/`: the environment root grants PHP for its whole subtree, so
   the module's own deny is the only thing standing between a caller and `src/`.

### What the install changes

Everything this module adds, so that the change can be reviewed before it is made and found
again afterwards:

| | |
|---|---|
| **One table** | `AltiooEventMCPService`, the audit trail — one row per audited MCP call. It inherits `Event`, so it lives in iTop's event log alongside the others |
| **One profile** | `MCP Services User`. It grants no data rights of its own; it marks a user as allowed through the endpoint, exactly as `REST Services User` does for REST/JSON |
| **Enum values** | Nine `MCP*` values added to the `scope` field of `PersonalToken` and `UserToken` (`_delta="if_exists"`, so no core class is redefined) |
| **One file** | `index.php`. Clients are given it as `env-production/altioo-mcp/index.php`, in the compiled environment. The module's `.htaccess` / `web.config` are copied beside it and deny everything under it but that one file — and the same pair ships under `extensions/`, where it re-grants that directory's `index.php` through iTop's deny. So the file answers at both paths, on identical terms: same code, same authentication, one of the two published |
| **Module parameters** | The `altioo-mcp` block in `conf/<env>/config-itop.php`, written by the setup with the defaults in [Configuration](#configuration) |
| **Nothing else** | Beyond the enum values above, no core class is touched — and no core menu, no cron task, no scheduled job, no outbound connection |

**Removing it.** Back up first, for the same reason as installing: this is another setup run.
Untick the extension in the setup (or delete `<itop>/extensions/altioo-mcp/`) and run the setup
again. Then, if you want the instance genuinely clean rather than merely inert, two things
survive on purpose and have to be removed by hand:

| What survives | Where | What to do |
|---|---|---|
| The audit history | Class `AltiooEventMCPService`, stored across **`priv_event`** and **`priv_altioo_event_mcp_service`** | iTop leaves both behind for any removed module, so the trail outlives the extension. Do this *before* removing the module, while iTop can still read the class: export `SELECT AltiooEventMCPService` if you want to keep it, then delete the rows from the console. `AltiooEventMCPService` inherits `Event`, so the date, the user and the message live in `priv_event` and only the MCP columns are in the module's own table — an export of that one table is missing half of each row, and dropping it alone leaves the other half behind |
| The module settings | The **`'altioo-mcp' => array(...)`** block under `module_settings` in `conf/<env>/config-itop.php` | Delete the block. It is inert once the module is gone, but it is also the thing that quietly reapplies your old settings if the extension is ever reinstalled |

Tokens keep their `MCP*` scope values as stored strings; those scopes simply stop meaning
anything, and no token gains access to anything else as a result. The `MCP Services User`
profile disappears with the datamodel; users who held it keep their other profiles untouched.

**Upgrading to a later version of this extension.** 1.0.0 is the first release, so nothing
installed today is an upgrade. From the next version on: back up, take a maintenance window,
unzip the new version over the old directory and re-run the setup, and read
[CHANGELOG.md](CHANGELOG.md) first — a major version means an identifier or a default that
clients and tool packs depend on has changed.

**After an iTop core upgrade, recompile.** Upgrading iTop itself does not touch this
extension's files, but it does rewrite the compiled datamodel — and until the setup is re-run,
the compiled copy iTop serves has no MCP module in it. The endpoint returns iTop's own
not-found page, the profile is missing from the console, and a client reports the server as
gone. Nothing is lost and nothing needs reinstalling: run the setup once more ("Update an
existing instance"), leave the extension ticked, and it comes back. This is the most common
"the extension disappeared" report, and it is why the module lives in `extensions/` rather
than in `datamodels/`, which a core upgrade overwrites outright.

**Downgrading is not supported**, and unzipping an older archive over a newer install is not
a downgrade: the setup compiles forward and reverses nothing. The rollback *is* the backup you
took before installing — which is why the backup is listed first rather than as an afterthought.

## Granting access

Access is gated by a profile, by a credential, by the instance's capability grading, and by
iTop's own permissions. Four gates, and every one that applies must pass.

**1. A profile.** By default only users holding **Administrator** or **MCP Services User**
may reach the endpoint. `MCP Services User` is created by this extension; like iTop's own
`REST Services User`, it grants no data rights of its own — it only marks a user as allowed
through. What the client can actually read or write still comes from the user's other
profiles, so grant it alongside a functional profile, never on its own.

Change the allowed list with the `mcp_allowed_profiles` module parameter (below), or set
`secure_mcp_services` to `false` to drop the profile check entirely.

Whichever profiles you allow, **the account also needs one that grants access to the console**.
The MCP services serve what the console serves, so a portal-only user is refused even holding
`MCP Services User` and a correctly scoped token — the endpoint answers that the user has no
console access, and `log/error.log` names the account. Neither `mcp_allowed_profiles` nor
`secure_mcp_services` lifts this: it is iTop's login deciding the user has no interface to be
sent to, before any of this extension's gates are reached.

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
cookie: a request that brings no credential of its own is refused `401` before the session is
even looked at, and one that brings a credential has its session reset before the login runs.
Either way the cookie decides nothing.

**3. The instance's capability grading.** `mcp_capabilities` — with `mcp_read_only` as its
shorthand — is an instance-wide floor on what anyone may do, and `mcp_enabled_toolsets` limits
which toolsets are served at all. This gate applies whatever credential is used, so it holds
even for an Administrator on Basic authentication, where no token scope exists to narrow
anything. See [Start read-only](#start-read-only).

**4. iTop's own permissions.** Every operation goes through `UserRights` — class rights,
object-level rights, per-attribute read and write rights, and stimulus rights. Sensitive
attributes (those whose type implements `iAttributeNoGroupBy`) are masked in output.

On top of those four, `mcp_disabled_tools` lets you turn individual tools, prompts and
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
| `MCP-toolset-<name>` | Restricted to one toolset, e.g. `MCP-toolset-objects`. The base extension ships `datamodel`, `objects`, `relations`, `documents`, `history` and `server` |

They combine, and the grades are a union: `MCP-write` is read *and* write, because granting
write without read describes nothing anyone means by it. `MCP-read` together with
`MCP-toolset-objects` is read access to the object tools and nothing else. A token scoped
this way can only ever be *narrower* than the instance configuration and narrower than the
user's own profiles — it never widens either.

A tool is graded by the annotations it already declares: `readOnlyHint` is read,
`destructiveHint` is delete, anything else is write. **A tool that declares no annotations at
all is graded `delete`**, so it is withheld from any token scoped `MCP-read` or `MCP-write`.
That is deliberate — the
alternative is a pack update quietly handing a read-only credential something that writes —
but it means a pack that skips `getAnnotations()` will appear to be missing tools. See
[doc/extending.md](doc/extending.md).

The same grading applies instance-wide through `mcp_capabilities`, and `mcp_read_only` is
shorthand for `array('read')`.

## Endpoint

```
https://<your-itop>/env-production/altioo-mcp/index.php
```

Point your MCP client at that URL and authenticate with the token above. Copy-paste
configuration for Claude Code, Claude Desktop, VS Code and Cursor, and what to check when it
does not connect, are in [doc/clients.md](doc/clients.md).

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

`Auth-Token: <your-itop-token>` works too, and is the header iTop's own `authent-token` module
reads natively. Prefer it if `Authorization` never reaches PHP in your deployment: under
FastCGI, Apache drops that header unless `CGIPassAuth On` (or an equivalent
`SetEnvIf Authorization` rewrite) is in effect.

Scope that directive to this one file rather than turning it on for the whole server. It
makes PHP see `Authorization` on every request it covers, and the rest of iTop has no use
for that header — a narrower blast radius costs one block:

```apache
<Directory /path/to/itop/env-production/altioo-mcp>
    <Files "index.php">
        CGIPassAuth On
    </Files>
</Directory>
```

The setup compiles the module into `env-<env>/altioo-mcp/`, and that is the copy the URL above
serves. The environment root carries an `.htaccess` written by the compiler whose `FilesMatch`
**does** include PHP, granted for the whole subtree — so the module's own `.htaccess` and
`web.config`, copied in beside it, are the only thing denying everything under it and granting
back `index.php` alone. Confirm they arrived: without them the compiled tree serves its own
`src/` and `vendor/`. The copy that stays under `extensions/altioo-mcp/` is covered separately
by iTop's `extensions/.htaccess`, which denies that subtree except a short list of static file
types, PHP not among them.

If your web server ignores per-directory configuration (`AllowOverride None`, or nginx, which
has no `.htaccess` at all), **neither deny applies to you** and both have to be expressed in the
server configuration instead — the compiled tree is public until you do.

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
    'mcp_max_document_bytes' => 5242880,
    'mcp_pagination_limit' => 200,
    'mcp_protected_resource_metadata' => '',
    'mcp_source_url' => '',
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
| `mcp_enabled_toolsets` | *(empty)* | Toolsets this instance serves — the base extension ships `datamodel`, `objects`, `relations`, `documents`, `history` and `server`, and a pack declares its own. An element that declares no toolset falls back to its namespace, which names who wrote it rather than what it does. Empty means all of them. The positive counterpart to `mcp_disabled_tools`: naming what may stay is what you want for a pack whose next release you have not read, since a tool added by an update is then off until you say otherwise |
| `mcp_capabilities` | *(empty)* | What anyone may do: any of `read`, `write`, `delete`. A tool falls into one by its annotations, so a pack is graded by describing its tools rather than by being listed here. Empty means all three |
| `mcp_read_only` | `false` | Shorthand for `mcp_capabilities => array('read')`. Narrows rather than overrides, so setting both cannot come out wider than either |
| `mcp_max_document_bytes` | `5242880` | Largest document served or accepted, in bytes. 5 MB of file is about 6.7 MB of JSON once base64-encoded, which is most of a context window spent on one document. PHP's `upload_max_filesize` and `post_max_size` still apply on the way in |
| `mcp_pagination_limit` | `200` | Elements per `tools/list` page. This module sets it on every request, so the SDK's own default of 50 never applies. What is past the limit is paged behind a cursor, which a client that ignores `nextCursor` never asks for — those elements then exist, are callable, and are advertised to nobody |
| `mcp_protected_resource_metadata` | *(empty)* | URL of the RFC 9728 document your OAuth proxy serves. Advertised in the `WWW-Authenticate` header of a `401`, which is what a Connect-button client follows |
| `mcp_source_url` | *(empty)* | Where the `core/version` resource tells a caller to obtain the corresponding source. Empty means upstream, which is correct unless you modified this module — see [License](#license) |
| `log_mcp_service` | `true` | Write an `AltiooEventMCPService` audit entry per call |
| `log_mcp_method` | see above | Which MCP methods are audited. `initialize` is the record that a client connected, and is written whatever `log_mcp_level` says, because a successful connection is the one success worth a row |
| `log_mcp_level` | `error` | `error` logs failures only; `info` logs everything; `debug` additionally records the raw request parameters |

### Start read-only

The recommended opening position is an instance that cannot write and
tokens that cannot either:

```php
'mcp_capabilities' => array('read'),
```

with clients issued `MCP-read` tokens. Widen one grade at a time, and only once you have
watched the audit trail for what the assistant actually does. `mcp_capabilities` is the
instance-wide floor and the token scope narrows further within it, so the two together let
one credential be weaker than the instance without a second user account.

### Settings outside this module that matter here

One of them, in the main body of `config-itop.php` rather than in the `altioo-mcp`
block, and worth setting before the first client connects.

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
you need to change configuration; the editor buys nothing an SSH session does not. The
other half of the opening position — an instance that cannot write — is
[`mcp_capabilities`](#start-read-only), which is a module setting and belongs in the
`altioo-mcp` block above.

### Audit trail

Calls are recorded as **MCP Service Call** (`AltiooEventMCPService`) objects, visible in the
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

## Troubleshooting

Most of what follows is diagnosed from one file. When the endpoint answers something that does
not say why, the reason is in **`<itop>/log/error.log`**: the module writes there every refusal
whose reason is deliberately kept out of the response — a host that is not served, a caller
holding none of the allowed profiles, an account with no console — along with every unhandled
failure and every configuration complaint, because those responses are vague on purpose and the
log is not.

**The one refusal that file cannot explain is the credential itself.** `LoginWebPage::DoLogin()`
answers this module with a single code and no reason, so a token iTop found and then refused on
its scope is indistinguishable here from a password that was wrong — both are answered `Invalid
login`, and the module has nothing truer to write. The reason is recorded by `authent-token`
instead, under the **`TokenAuthLog`** channel and at `Error` level, so it is already there
without turning anything up. Grep for `TokenAuthLog` under `<itop>/log/`; case 3 below is the
line you are most likely to find.

The refusals that log nothing anywhere are the ones that already said everything in the
response: the 415 on a body that is not JSON, and the 401 on a request that brought no
credential. There is nothing a log could usefully add about a request that never named anyone.

**Turn the detail up.** `log_mcp_level` decides how much the *audit trail* keeps, not the error
log, and it is worth raising while you are looking:

```php
'altioo-mcp' => array(
    // 'error' (default) records failures. 'info' records successes too, which is how you
    // confirm a call arrived at all. 'debug' additionally stores the raw JSON parameters.
    'log_mcp_level' => 'debug',
),
```

Put it back to `'error'` afterwards. `'debug'` stores whatever the caller sent, which can
include data your users would not expect to find in an audit log.

**Is the call arriving at all?** Look for an `AltiooEventMCPService` row with the method
`initialize`. That row is written whenever a client connects, at every log level. Then look for
one with the method `exceptions`: every refusal that lands before the login — the 403 on the
host, the 415 on the media type, the 401 on a request that brought no credential — is caught at
the entry point and audited under that method, so such a row means the request did arrive and
was turned away, and case 2 below is fixed with a module parameter.

No row at all is not yet proof that nothing arrived. `log_mcp_service => false` writes no rows
whatsoever, and a `log_mcp_method` list that does not name a method suppresses that method's
rows while requests keep arriving normally — check both before concluding the request never
reached the module, which is then a web server rule, a proxy, or a wrong URL.

### The three that account for most of it

**1. `401` on a credential you know is right, and no `initialize` row.**

Under FastCGI, Apache drops the `Authorization` header before PHP sees it, so the module never
receives the bearer token and iTop answers as it would to an anonymous caller. Nothing is
misconfigured in iTop, and nothing in `log/error.log` says so, because as far as PHP is
concerned no credential was sent.

Confirm it by sending the same credential as `Auth-Token:` instead of `Authorization: Bearer` —
that header is not affected. If that works, the problem is the header, not the token.

Fix it with `CGIPassAuth On`, scoped to this one file — see [Endpoint](#endpoint) for the block.

**2. `403`, with a body that says only "This host is not served by the MCP endpoint".**

The refusal is deliberately silent about *which* hostnames are configured; a caller probing for
them has no business being told. The reason is in `log/error.log`, and it names both the
`Origin` and the `Host` that were refused:

```
Refused an MCP request: neither its Origin (-) nor its Host (mcp.internal:8080) is in
'mcp_allowed_hosts'. Set that module parameter to the hostname this instance is served under.
```

This happens when iTop is reached under a name `app_root_url` does not carry — an internal
hostname, a container name, a second vhost. Set the parameter to the name the client actually
uses:

```php
'mcp_allowed_hosts' => array('itop.example.com', 'mcp.internal'),
```

Hostnames, no port. `array('*')` turns the check off, which is the right answer only when a
reverse proxy in front of iTop validates `Host` itself.

**3. `401` on a token that works everywhere else in iTop.**

A token needs a scope this module declares — `MCP`, or one of the `MCP-*` scopes — and a token
created for the REST API has none of them. iTop honours a token scope only when the module has
pushed a context tag of the same name, so a token scoped for something else does not partially
work here; it does not authenticate at all.

The response cannot tell you this. It says `Invalid login`, which is also what a token that does
not exist gets, and what a wrong password gets. `TokenAuthLog` separates them:

```
authent-token: Scope not authorized code: 400
OnConnected: Scope not authorized
```

That pair means the token *was* found and matched, and was refused on its scope alone — so
nothing is wrong with its value, its expiry, or the account behind it, and there is no point
reissuing it. `authent-token` also carries a longer message naming both sides of the comparison
— *"Current context (…) does not match current Token allowed scopes: …"* — which lists the
context tags this endpoint actually pushed; raise the `TokenAuthLog` channel in
`config-itop.php` if it is not already showing.

Open the token in the console and confirm at least one `MCP*` scope is ticked. Scopes cannot be
added to an existing personal token in every iTop version — if the field is read-only, issue a
new one. See [Granting access](#granting-access) for which scope grants what.

### Less common, and what they look like

| Symptom | Cause |
|---|---|
| `401`, "This user has no access to the iTop console" | The account reaches only the end-user portal. `MCP Services User` does not grant a console, and neither `mcp_allowed_profiles` nor `secure_mcp_services` lifts the requirement — grant a profile that does. See [Granting access](#granting-access) |
| `415`, "must carry Content-Type: application/json" | The client sent a POST as `text/plain` or a form encoding. That is refused on purpose — it is what forces a cross-origin caller through a preflight |
| A tool you disabled is callable again after an upgrade | The `mcp_disabled_tools` entry no longer matches anything. The module says so in `log/error.log` at every request, naming the stale entries — a tool pack that renamed an element between its own versions is the usual cause |
| `mcp_enabled_toolsets` set, and almost no tools listed | A misspelt toolset name serves nothing rather than everything. The log names the entries that matched nothing, and lists the toolsets this instance actually has |
| A client lists only some of the tools, and always the same number of them | That client ignores `nextCursor`, so it never asks for the second page. The page size is `mcp_pagination_limit`, which this module sets on every request — it defaults to 200, so the SDK's own 50 is never what you are seeing. Raise it if the instance registers more elements than that, and check nobody lowered it |
| "The MCP request could not be completed. Server log reference: `a1b2c3…`" | An internal failure, answered generically on purpose. Grep `log/error.log` for that reference; the audit row carries it too, in **Log reference** |
| A stored file comes back as a different media type than declared | Deliberate, and settled when the file was attached rather than when it was read. `core_object_attach` checks the declared type against the bytes, the bytes win, and it reports the disagreement in `mimetype_note`. Reading sniffs nothing — it returns what was stored, which is the corrected type |

## Extending

Everything about writing a tool pack — the module dependency, the autoloader requirement, the
four element base classes, overriding a core tool, dictionaries, the contract checker, the
service provider, and who owns an identifier — is in
**[doc/extending.md](doc/extending.md)**, which ships in this archive alongside a working
example pack in [doc/example-pack/](doc/example-pack/).

It is a separate document because it answers a different person's questions. This README is
written for whoever installs and operates the endpoint; that one is written for whoever builds
on it, and mixing the two made both harder to read.

What an operator needs to know about packs is short:

- **A pack is an ordinary iTop extension** that declares `altioo-mcp` as a dependency. It
  installs through the same setup run, and it is listed and unticked in the same place.
- **A pack's tools run with the caller's rights and no more.** They go through `UserRights`
  like the core tools, they are audited like the core tools, and a token scope narrows them
  the same way.
- **You decide which of them are served.** `mcp_enabled_toolsets` serves only the groups you
  list, and `mcp_disabled_tools` withdraws individual tools, prompts or resources whichever
  extension registered them — see [Configuration](#configuration). A tool arriving in a pack
  update is off until you say otherwise.
- **Identifiers are namespaced.** A pack registers under its own namespace and cannot land in
  `core` by accident: two packs claiming one name withdraw it from both rather than one
  silently winning. Taking over an existing name is possible, but only deliberately — a pack
  says so through `overrides()`, which is how a core tool is meant to be replaced — so a name
  you have allow-listed in a client configuration changes hands only when something you
  installed asked for it by name.

Review the pairing of a pack with the profiles you grant, exactly as you would for the core
tools — see [Security](#security).

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

**What the module stores.** One `AltiooEventMCPService` row per audited call: timestamp, user,
method, element invoked, outcome, duration, response size. Request *parameters* are stored only
at `log_mcp_level => 'debug'`, which is a troubleshooting setting, not a standing one. Set your
own retention on that table as you do for iTop's other event classes.

## Security

Full threat model, hardening notes and how to report a vulnerability:
**[SECURITY.md](SECURITY.md)**. If what you need is the one page an approver reads — SBOM and
licences, provenance, vulnerability process, footprint, data processing — that is
**[doc/security-summary.md](doc/security-summary.md)**.

In short: the endpoint is a public HTTP entry point. Beyond the gates above, follow
[iTop's security guidance](https://www.itophub.io/wiki/page?id=3_2_0:install:security) —
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
| **Bugs and questions** | [GitHub issues](https://github.com/altioo/mcp-server-extension/issues). Include the iTop version, the extension version, the PHP version, and the request that reproduces it — see [Troubleshooting](#troubleshooting) for where those come from |
| **Security** | Not via a public issue. [Report a vulnerability](https://github.com/altioo/mcp-server-extension/security/advisories/new) privately, or email <security@altioo.com>. Acknowledged within 5 working days — the full commitment is in [SECURITY.md](SECURITY.md) |
| **Response** | **Best effort, with no service commitment.** Issues are read and triaged as time allows. The security channel above is the one thing on this page that carries a stated response time, and it carries one because a vulnerability report cannot wait on goodwill |
| **Paid support, custom tool packs, integration work** | Available from Altioo — see [Custom work](#custom-work) |

There is deliberately no support SLA attached to the free extension. Stating one and then
missing it on a busy month would be worse than saying plainly that there is none: an operator
deciding whether this belongs on a production CMDB is better served by an honest "best effort"
than by a number nobody is on the hook for.

If you are deploying this somewhere that needs a commitment, that is what the paid arrangement
is for, and the terms are agreed there rather than promised here.

## Versioning and compatibility

The extension follows [semver](https://semver.org/). The running version is
`MCPHelper::VERSION` — the same string the server sends clients in `serverInfo`, and the same
string in `extension.xml` and the module declaration.

**What a major version means for you.** Three things this extension promises to keep stable
across minor releases, any of which changing is a major bump and an entry in
[CHANGELOG.md](CHANGELOG.md):

- **Tool, resource and prompt identifiers.** A client configuration that allow-lists tools by
  name, and any `mcp_disabled_tools` entry, breaks if a name changes.
- **The classes a tool pack builds on**, marked `@api` in the source — so a pack you have
  installed keeps working across a minor upgrade of this extension. The rule is in
  [doc/extending.md](doc/extending.md), which points at the tags in the copy you have rather
  than repeating a list that could fall behind them; an operator needs neither, only that the
  promise exists.
- **The default of a module parameter.** Changing one alters behaviour on every instance that
  never set it, which is breaking even though nothing in the API moved.

When a later version changes one of those, the changelog entry for it is where the removed
identifier or the changed default is named, along with what an administrator has to do about it.

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
  nor can a session cookie, since a request carrying no credential of its own is refused before
  the session is consulted, and one carrying a credential resets the session before logging in.
- **Generic tools, not task-shaped ones.** No "open an incident" tool here — see
  [Extending](#extending) and [Custom work](#custom-work).
- **A tool with no annotations is graded `delete`**, so a pack that skips `getAnnotations()`
  appears to be missing tools for any token scoped `MCP-read` or `MCP-write`. That is the safe direction of failure,
  but it is a failure people meet — `ElementContract` reports it, which is the reason it
  reports warnings at all and not only refusals.
- **No middleware around tool execution.** A pack can replace a named tool through `overrides()`
  or subclass it, but there is no hook that applies to *every* call — no place to put redaction,
  a rate limit, an extra audit field or per-tenant filtering once. Cross-cutting behaviour of
  that kind currently means touching this module.
- **A tool result is read into a context window.** Searches narrow by default and long values
  are clipped, but `core_object_get` returns every readable attribute unless `output_fields`
  says otherwise; a deliberately wide `output_fields => *` over thousands of objects is still
  your cost to pay.
- **The audit trail grows.** One `AltiooEventMCPService` row per audited call, with no built-in purge
  — set retention as you do for iTop's other event classes.
- **No console UI.** Configuration is the module parameters in `config-itop.php`.

## Development

```bash
composer install
composer test:unit
```

The unit suite needs neither iTop nor a database. The integration suite additionally needs a
live iTop with the module installed **and iTop's own test harness present**, and skips itself
otherwise:

```bash
ITOP_ROOT=/path/to/itop/web composer test:integration
```

The harness is `ItopDataTestCase`, which ships in `tests/php-unit-tests/` of iTop's *source*
tree and not in the packaged releases — point `ITOP_ROOT` at a checkout, or use
`tools/ci/install-itop.sh`, which adds the harness of the matching tag to a packaged release
the way CI does. Against a release without it the suite reports as skipped rather than failing,
which is easy to read as green.

Build a release archive with `composer install --no-dev`; `exclude.txt` lists what is kept
out of the package.

[`doc/example-pack/`](doc/example-pack/) ships in that archive on purpose — a pack author on an
instance in a year's time has it to hand. Its module declaration carries a `.tpl` suffix so
that the setup, which `eval`s every `module.*.php` it finds anywhere under `extensions/`, does
not offer the example as something to install.

## License

[AGPL-3.0-or-later](LICENSE), matching iTop itself. The same identifier is in `composer.json`
and at the head of every file under `src/`, which `SourceIntegrityTest` checks on every run.
`model.altioo-mcp.php` is the exception: it is iTop's own template, carrying its warning not to
edit it.

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

### The source offer is served, not just documented

The `core/version` resource reports the extension's name, version, licence and source URL
alongside iTop's. That block is the §13 offer in practice: an MCP session has no page to put a
footer on, so a client that only ever speaks JSON-RPC to one endpoint has nowhere else to find
out what it is talking to or where to ask for its source.

**If you modify this module and third parties reach it over a network, set `mcp_source_url` to
where your source is.** The default points upstream, which stops being true the moment you
change something: §13 entitles those users to the source of the version actually running, and
sending them to a repository that does not contain your changes answers nobody's question.

If your situation needs something other than AGPL for the pack itself, that is a licensing
conversation rather than a technical one — see [Custom work](#custom-work).
