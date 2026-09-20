# Listing copy

Ready to paste into the places this extension is described to people who have not installed it:
the iTop Hub submission, and the GitHub repository's About box. Neither is the archive, and
both are where the install decision is made, so everything an evaluator needs is here rather
than one click away. Keep it in step with `extension.xml` and the README at every release. The
supported-versions rows are checked automatically; everything else here is prose somebody has
to re-read.

---

## Name

Altioo MCP — Model Context Protocol server for iTop

## Short description (one line)

Let an AI assistant search, read and update your CMDB and tickets — through the user's own iTop
permissions, with every write confirmed first.

## GitHub repository description (the About box)

```
iTop extension that adds a Model Context Protocol (MCP) server to your instance - inside iTop, not beside it: no service to deploy, no REST hop, no copy of the datamodel to keep in sync. Assistants search, read and update CMDB and ticketing objects as the authenticated user. Dry-run writes, every call audited.
```

311 characters, inside GitHub's 350 limit. Plain hyphens rather than en dashes: the field is
plain text, and hyphens survive a copy-paste into the Hub form or `composer.json` unchanged.

**It names no iTop or PHP version, deliberately.** Every other copy of that claim is checked
against [`.github/itop-support.json`](../.github/itop-support.json) by `ModuleMetadataTest` —
the README's Requirements table, the Compatibility block below, the sentence in
`extension.xml`. The About box is the one surface no test can reach, so a version put there is
the one that goes stale in silence. Requirements belong in the README, which is one click away
from the box and is checked.

**The first clause is the whole point of the description.** An MCP server for iTop can also be
a separate process that talks to iTop over REST; this is not that, and somebody deciding
between them should not have to open the README to find out. Keep "inside iTop, not beside it"
and the three consequences after the colon if this is ever rewritten.

Topics, set beside the description, are where discovery actually happens. `composer.json`
already declares the keywords worth reusing: `itop`, `mcp`, `model-context-protocol`, `itsm`,
`cmdb`, `ai`.

## Category

Application management / Integration

## Compatibility

<!-- supported-versions:begin — the two rows below are checked against .github/itop-support.json
     and composer.json by ModuleMetadataTest. Edit those, not this. -->

| | |
|---|---|
| iTop | **3.2** (LTS) |
| PHP | **8.2** to **8.4** |

<!-- supported-versions:end -->

| | |
|---|---|
| Not supported | iTop 3.1 or earlier — the setup refuses |
| Not claimed | iTop 3.3 — untested rather than known broken. It is not in the CI matrix, so it is not listed as supported |
| Prerequisites | `authent-token` ≥ 2.2.1 and `itop-structure` ≥ 3.2.0 — both ship with iTop |
| Licence | AGPL-3.0-or-later, same as iTop |
| Price | Free |
| Tested on | *(per release — copy the **Tested on** line from that version's [CHANGELOG.md](../CHANGELOG.md) entry)* |

The two version rows come from
[`.github/itop-support.json`](../.github/itop-support.json) and `composer.json`, and a test
fails if this file stops agreeing with them. Do not edit them here to match a release; edit
them there and the Hub copy follows. Which PHP an evaluator can actually use on their own patch
is iTop's answer, not ours — the setup warns about a PHP it has not validated rather than
refusing it, so the combination cannot be got wrong silently but it can be got wrong, and this
listing does not try to reproduce iTop's per-patch table.

## Long description

**What it does.** Installs a Model Context Protocol (MCP) server inside iTop, at a single HTTP
endpoint. Point Claude, or any other MCP client, at it and the assistant can find objects by
name the way the console's global search does, list classes, inspect the schema, search with OQL
or by attribute, read objects, walk relations for impact analysis, and create, update, delete or
apply lifecycle stimuli — including in bulk.

**Everything runs inside iTop.** No separate service to deploy, no copy of the datamodel to
keep in sync, no REST hop. Because the schema is read live from `MetaModel`, your own classes,
attributes and states appear without any extra configuration.

**A client can never see or do more than the user it authenticates as.** Every operation goes
through `UserRights`: class rights, object-level rights, per-attribute read and write rights,
stimulus rights. Sensitive attributes are masked. That includes the datamodel itself — a class
the user may not read is not listed, not described, and not searched, so the schema an assistant
is shown is the schema that user would see in the console.

**Object content is treated as data, never as instruction.** Ticket titles, descriptions and
logs are written by anyone who can open a ticket or send a mail. The server tells every client
at connection time that text found in an object asking it to call a tool or ignore an
instruction is content to report, not a request to act on.

**Nothing writes unless asked, and one scope makes that absolute.** Create, update, delete,
attach, apply-stimulus and the bulk tools are dry runs by default: they run iTop's own
`CheckToWrite()` and report what the call *would* change — including the attributes a state
change would clear as a side effect — and write only when the caller explicitly says so.

That default is a guardrail rather than a boundary: a caller may ask to write straight away. The
boundary is the **`MCP-advisory`** token scope, which forces the rehearsal on every write tool
the credential can reach, whatever the caller passes — so an assistant driven by text that came
from outside your organisation cannot commit a change on the strength of that text at all.

**Every change says where it came from.** iTop attaches each write to a change record, and left
alone that record carries the user's name and nothing else — the same name whether the person
made the change themselves, asked an assistant to make it, or issued a token to an agent that
has been making it nightly for a month. A change made here reads
`Jane Doe (MCP: core_object_update)`, with an optional comment for the reason.
`SELECT CMDBChange WHERE userinfo LIKE '%(MCP:%'` finds every one of them. The console's
Activity panel shows the user rather than that line — since iTop 3.0 it prefers the change's
`user_id` — so the per-call view carrying the tool name is the **MCP Service Call** trail
below.

**Four gates before any of that.** A dedicated `MCP Services User` profile; a credential iTop
accepts, with `MCP` token scopes that are distinct from the REST and Export scopes and can make
one credential weaker than its owner (`MCP-read`, `MCP-write`, `MCP-delete`,
`MCP-toolset-<name>`); an instance-wide read-only switch; and iTop's own `UserRights`, checked
per class, per object, per attribute and per stimulus inside every tool. An operator narrows the surface
further without touching code: `mcp_enabled_toolsets` serves only the groups an instance uses,
and `mcp_disabled_tools` withdraws individual tools, prompts or resources whichever extension
registered them. Every call is recorded as an **MCP Service Call** object, visible in the
console, with its outcome, duration and response size.

**iTop opens no outbound connection.** Traffic is inbound only; no telemetry, no callbacks.

**Built to be extended.** Other extensions declare this one as a dependency and register their
own tools, resources and prompts into it — task-shaped tools for your own workflows belong
there, not in the generic core.

**What it does not do:** no SSE streaming or resumability; no OAuth of its own (terminate it in
a proxy — the `401` will advertise it); no console UI, configuration is module parameters.

## Documentation

- Setup, configuration, operating and troubleshooting: README in the repository *(link)*
- Writing a tool pack on top of it: `doc/extending.md`, with a working pack in
  `doc/example-pack/`
- Client configuration for Claude Code, Claude Desktop, VS Code and Cursor: `doc/clients.md`
- Threat model and vulnerability reporting: `SECURITY.md`
- Answers to a security or procurement questionnaire: `doc/security-summary.md`

## Screenshots to attach

The set exists to answer one question an evaluator has and the prose cannot settle: *does it
really refuse, and does it really show me first?* Four, in this order.

They are in [`assets/img/screenshots/`](../assets/img/screenshots), already captured and
stripped of metadata. Upload those; the notes below are what to reshoot *against* if an
instance or a release makes one of them wrong.

1. **The token screen** — `token-scoped-credential.png`. An **application token**:
   `Remote application`, the `MCP Services User` profile **paired with a functional one**
   (`Support Agent` here), and a scope list that is a *subset* of the toolsets, not all of
   them. The pairing is the point: `MCP Services User` grants no data rights of its own, and a
   screenshot showing it alone shows an assistant that can reach nothing. Equally, do not shoot
   an `Administrator` token — the whole claim is least privilege, and a screenshot of full
   rights argues the opposite.
2. **The MCP Service Call list** — `audit-dry-run-then-write.png`. Filtered to one session: a
   row with `Dry run = Yes` immediately followed by the same tool with `Dry run = No`.
   Rehearsed, then committed, with `Objects changed` beside both. This is the strongest single
   image — the guardrail and the audit trail in one frame — and it is the one a security
   reviewer wants.
3. **One MCP Service Call in detail** — `audit-call-detail.png`, showing the credential link,
   the change link and the object list. It is what makes the row an audit record rather than a
   counter.
4. **An assistant session mid-dry-run** — `assistant-rehearses-a-change.png`, reporting what
   it *would* write and asking before committing. Shoot it on a single connected server with real-looking ticket content — not a
   ticket called "test", and not with a second, failing server in frame.

   Aim for a **question rather than a command**: "can this ticket be a higher priority?" beats
   "set the priority to critical". Asked that way the rehearsal is not a safety gate the
   evaluator has to be sold on, it is the thing that answered the question — several candidate
   outcomes, each with what iTop would derive from it, and nothing written until the person
   picks one. A guardrail shown being *useful* argues better than one shown being safe, and it
   is the same screenshot.

Not the object's Activity panel: the `(MCP: …)` attribution is recorded on the change and is
queryable, but the panel displays the user, so there is nothing there to photograph. See
*Change attribution* above.

## Support

Issues and questions on the repository; security reports privately, per `SECURITY.md`. Free
extension, best-effort support. Paid support, custom tool packs and integration work available
from Altioo.
