# Hub listing copy

Ready to paste into the iTop Hub submission. The Hub page, not the archive, is where the
install decision is made, so everything an evaluator needs is here rather than one click away.
Keep it in step with `extension.xml` and the README at every release. The supported-versions
rows are checked automatically; everything else here is prose somebody has to re-read.

---

## Name

Altioo MCP — Model Context Protocol server for iTop

## Short description (one line)

Let an AI assistant search, read and update your CMDB and tickets — through the user's own iTop
permissions, with every write confirmed first.

## Category

Application management / Integration

## Compatibility

<!-- supported-versions:begin — the two rows below are checked against .github/itop-support.json
     and composer.json by ModuleMetadataTest. Edit those, not this. -->

| | |
|---|---|
| iTop | **3.2** (LTS) and **3.3** |
| PHP | **8.2** to **8.4** |

<!-- supported-versions:end -->

| | |
|---|---|
| Not supported | iTop 3.1 or earlier — the setup refuses |
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

**Nothing writes on a first call.** Create, update, delete, attach, apply-stimulus and the bulk
tools are dry runs by default: they run iTop's own `CheckToWrite()` and report what the call *would*
change — including the attributes a state change would clear as a side effect — and write only
when the caller explicitly asks again. An assistant acting on text that came from outside your
organisation cannot silently commit a change on the strength of that text alone.

**Every change says where it came from.** iTop attaches each write to a change record, and left
alone that record carries the user's name and nothing else — the same name whether the person
made the change themselves, asked an assistant to make it, or issued a token to an agent that
has been making it nightly for a month. A change made here reads
`Jane Doe (MCP: core_object_update)`, with an optional comment for the reason, in the object's
own History tab. `SELECT CMDBChange WHERE userinfo LIKE '%(MCP:%'` finds every one of them.

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

1. Personal token screen with an `MCP` scope ticked.
2. The **MCP Service Call** list in the console, showing audited calls.
3. An object's History tab, with a change attributed to `… (MCP: core_object_update)`.
4. An assistant session answering a question from live iTop data.

## Support

Issues and questions on the repository; security reports privately, per `SECURITY.md`. Free
extension, best-effort support. Paid support, custom tool packs and integration work available
from Altioo.
