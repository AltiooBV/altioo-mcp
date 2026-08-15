# Changelog

All notable changes to this extension are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning is
[semver](https://semver.org/) over the surface a tool pack can touch: the four abstracts,
`MCPRegistry`, `MCPExtensionCollector`, `iMCPServiceProvider` and the helpers under
`Helper/`. See the README, [Extending](README.md#extending).

## [Unreleased]

### Breaking

- **Tool, prompt and resource identifiers are `snake_case`.** `core_ObjectSearchByOQL` is now
  `core_object_search_by_oql`, and a pack's `acme_TicketAddLogEntry` becomes
  `acme_ticket_add_log_entry` — the name is derived from the class name, so no pack has to do
  anything, but a client configuration that allow-lists tools by name, and any
  `mcp_disabled_tools` entry, has to be updated. This is the shape every other MCP server in
  the ecosystem uses.
- The `iTopVersion` resource class is now `Version`, so its name (`core_version`) agrees with
  its URI (`itop://core/version`).

### Added

- **Bulk tools**: `core_object_bulk_create`, `core_object_bulk_update` and
  `core_object_bulk_delete`, up to 100 objects per call. They check `UR_ACTION_BULK_MODIFY` /
  `UR_ACTION_BULK_DELETE` before anything else, then check every object and every attribute
  individually. Dry runs by default, and each object is reported separately, because a call
  can partly succeed.
- **Graded access**, per instance (`mcp_capabilities`, with `mcp_read_only` as shorthand) and
  per token (`MCP-read`, `MCP-write`, `MCP-delete` scopes). A tool is graded by the
  annotations it already declares. `MCP-write` is the case a read-only switch cannot express:
  may open a ticket and add a note, may not delete anything.
- **Toolsets.** `getToolset()` groups elements by what they are for, defaulting to the
  namespace; `mcp_enabled_toolsets` serves a subset, and `MCP-toolset-<name>` scopes a token
  to one. The core surface declares `datamodel`, `objects` and `relations`.
- **`output_fields`** on the reading tools, spelled as iTop's REST API spells it. Searches
  default to `id, friendlyname`; `core_object_get` defaults to `*`.
- **`order_by` / `order_direction`** on both searches. OQL has no `ORDER BY`, so before this
  there was no way to ask for "the ten most recent".
- **Server instructions** at `initialize`, and `MCPRegistry::AddInstructions()` for packs to
  append to them.
- `WWW-Authenticate: Bearer` on a `401`, carrying RFC 9728 `resource_metadata` when
  `mcp_protected_resource_metadata` names the document an OAuth proxy serves.
- `mcp_pagination_limit`, defaulting to 200, so a client that ignores `nextCursor` cannot
  silently miss the 51st tool.
- `Helper\ToolOutput::Json()`, the recommended return for a tool.

### Fixed

- **Attribute values are rendered with `GetForJSON()`, as the REST API renders them.**
  `DBObject::Get()` returns internal ORM objects — `ormCaseLog`, `ormLinkSet`, `ormDocument` —
  none of which is JSON-serialisable, so every case log, link set and attachment was reaching
  clients as `{}`. A ticket's `public_log` arrived empty, silently, behind a `200`.
- **Paging is stable.** Neither search passed an order, so both fell back to `friendlyname`,
  which is not unique: objects could appear on two consecutive pages while others were never
  returned. Every page is now ordered by the requested attribute and then by `id`.
- **`core_class_schema` no longer enumerates the rows behind an external key.** Describing
  `UserRequest` returned every `Person` in the database under `caller_id`, on every call, and
  those rows had not been through object-level rights. The target class is reported instead.
- Case logs, link sets and long texts are cut to a declared ceiling, and a blob is reported as
  its metadata rather than base64. Naming the attribute in `output_fields` returns it in full.
- Tool descriptions pointed at `itop://iTop/...` URIs that no longer exist and at a tool named
  `itop_object_search` that never did. A test now walks every description and fails on a URI
  nothing serves or a `core_` name that is not registered.
- Results are sent once. An array return was JSON-encoded into the text content *and* copied
  into `structuredContent`, pretty-printed — roughly twice the tokens per read.

### Changed

- A tool's default `getTitle()` is the class name rather than the identifier, now that
  identifiers are `snake_case`.
- Sensitive attributes are masked before conversion rather than after, and one predicate
  decides what "sensitive" means for both the schema and the object tools.
