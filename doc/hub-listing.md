# Hub listing copy

Ready to paste into the iTop Hub submission. The Hub page, not the archive, is where the
install decision is made, so everything an evaluator needs is here rather than one click away.
Keep it in step with `extension.xml` and the README at every release.

---

## Name

Altioo MCP — Model Context Protocol server for iTop

## Short description (one line)

Let an AI assistant search, read and update your CMDB and tickets — through the user's own iTop
permissions, with every write confirmed first.

## Category

Application management / Integration

## Compatibility

| | |
|---|---|
| iTop | 3.2 (LTS) and 3.3. **Not** 3.1 or earlier — the setup refuses |
| PHP | 8.2 – 8.4 (on iTop 3.2.0–3.2.2, 8.2 or 8.3: those patches have known issues with 8.4) |
| Prerequisites | `authent-token` ≥ 2.2.1 and `itop-structure` ≥ 3.2.0 — both ship with iTop |
| Licence | AGPL-3.0-or-later, same as iTop |
| Price | Free |
| Tested on | *(per release — fill from the changelog entry)* |

## Long description

**What it does.** Installs a Model Context Protocol (MCP) server inside iTop, at a single HTTP
endpoint. Point Claude, or any other MCP client, at it and the assistant can list classes,
inspect the schema, search with OQL or by attribute, read objects, walk relations for impact
analysis, and create, update, delete or apply lifecycle stimuli — including in bulk.

**Everything runs inside iTop.** No separate service to deploy, no copy of the datamodel to
keep in sync, no REST hop. Because the schema is read live from `MetaModel`, your own classes,
attributes and states appear without any extra configuration.

**A client can never see or do more than the user it authenticates as.** Every operation goes
through `UserRights`: class rights, object-level rights, per-attribute read and write rights,
stimulus rights. Sensitive attributes are masked.

**Nothing writes on a first call.** Create, update, delete, apply-stimulus and the bulk tools
are dry runs by default: they run iTop's own `CheckToWrite()` and report what the call *would*
change — including the attributes a state change would clear as a side effect — and write only
when the caller explicitly asks again. An assistant acting on text that came from outside your
organisation cannot commit a change on the strength of that text alone.

**Three gates before any of that.** A dedicated `MCP Services User` profile; a credential iTop
accepts, with `MCP` token scopes that are distinct from the REST and Export scopes and can make
one credential weaker than its owner (`MCP-read`, `MCP-write`, `MCP-delete`,
`MCP-toolset-<name>`); and an instance-wide read-only switch. Every call is recorded as an
**MCP Service Call** object, visible in the console.

**iTop opens no outbound connection.** Traffic is inbound only; no telemetry, no callbacks.

**Built to be extended.** Other extensions declare this one as a dependency and register their
own tools, resources and prompts into it — task-shaped tools for your own workflows belong
there, not in the generic core.

**What it does not do:** no SSE streaming or resumability; no OAuth of its own (terminate it in
a proxy — the `401` will advertise it); no console UI, configuration is module parameters.

## Documentation

- Setup, configuration, security and extension points: README in the repository *(link)*
- Client configuration for Claude Code, Claude Desktop, VS Code and Cursor: `doc/clients.md`
- Threat model and vulnerability reporting: `SECURITY.md`

## Screenshots to attach

1. Personal token screen with an `MCP` scope ticked.
2. The **MCP Service Call** list in the console, showing audited calls.
3. An assistant session answering a question from live iTop data.

## Support

Issues and questions on the repository; security reports privately, per `SECURITY.md`. Free
extension, best-effort support. Paid support, custom tool packs and integration work available
from Altioo.
