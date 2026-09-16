# Changelog

All notable changes to this extension are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning is
[semver](https://semver.org/).

**What this project treats as breaking**, and therefore as a major version:

- a change to a class marked `@api` in `src/` — the surface a tool pack builds on, defined by
  the tags themselves rather than by a list written here, and described in
  [doc/extending.md](doc/extending.md);
- a change to a tool, resource or prompt **identifier**, because client configurations and
  `mcp_disabled_tools` entries name them;
- a change to the **default** of a module parameter, which alters behaviour on every instance
  that never set it.

Removal of anything on that surface comes at least one minor release after a `@deprecated`
naming its replacement. Migration steps an administrator has to take are called out in the
entry itself, not left to be inferred from it.

## [Unreleased]

### Fixed

- **Every request to `extensions/altioo-mcp/index.php` was a fatal error.** The entry point
  required `__DIR__.'/vendor/autoload.php'` before booting iTop, and `module.altioo-mcp.php`
  names `vendor/autoload.php` as its first datamodel file, which iTop resolves against the
  *compiled* tree during startup. In the compiled copy both requires land on the same absolute
  path and `require_once` dedupes them; under `extensions/` they do not, nothing dedupes, and the
  second declaration of `ComposerAutoloaderInitAltiooMcpExtension` ended the request. The
  autoloader suffix is pinned in `composer.json`, so the two copies collided by construction
  rather than by coincidence of names, and the URL answered `500` rather than the `401` an
  unauthenticated caller should get. The early require is removed — nothing above the boot needs
  a class from this module, and the controller resolves through the same datamodel entry. A unit
  test now holds both halves of that contract (the entry point requires no loader, the manifest
  still names one), and the HTTP smoke step asks this URL for a refusal too, since from the wire
  the bug was only ever a status code.

  One consequence is worth naming, because a comment in the entry point asserted the opposite:
  the module's loader now registers during startup, *after* iTop's, so it is prepended last and
  this module's copies of the overlapping packages (`psr/*`, `webmozart/assert`) are the ones
  that answer. That is not new behaviour — it is how every other entry point in the application
  already resolved them, because that datamodel entry loads on every request to the environment.
  This endpoint simply stopped being the exception.

- **The endpoint answered `202` with an empty body, so no client could connect.** The stateless
  session store accepted every write and answered every read with "no such session". The SDK
  queues a response into the session, saves it through the store, and then reads it back through
  a *second* `Session` object whose data cache is empty and which therefore goes to the store —
  so the reply the server had already generated was gone by the time the transport looked for
  it, and the transport answered `202` with no body and no `Mcp-Session-Id`. Clients waited for
  a result that never came, until they timed out. `initialize` was affected like everything
  else, so nothing could connect at all. The store now serves back what the current request
  wrote and still keeps nothing across requests: the array is an instance property, one store is
  built per request, and nothing survives into the next one under a persistent worker either.

- **Only the bare `MCP` scope could authenticate; the other eight were refused.** `scope` is an
  `AttributeEnumSet`, and the enumeration behind it was read with `GetAllowedValues()`, which
  returns `null` for a set — one line later that null became an empty list, so no context tag was
  ever pushed for `MCP-read`, `MCP-write`, `MCP-delete` or any `MCP-toolset-*` value. iTop honours
  a token scope only when a tag of the same name is on the stack, so every token scoped to
  anything narrower than `MCP` was refused: `authent-token` recorded `Scope not authorized` under
  `TokenAuthLog`, and the caller saw `Invalid login` — the same answer a wrong password gets. A
  tool pack adding its own `MCP-*` scope to the token classes was never honoured either, for the
  same reason. The enumeration is now read with `GetPossibleValues()`, and an integration test
  asserts that every scope the datamodel declares reaches the context tag stack.

- **`itop://core/current-user` failed outright for the identities most likely to read it.**
  The resource answered `-32603 "Error while reading resource"` and nothing else.
  `UserRights::GetContactFriendlyname()` resolves through `User::GetContactObject()`, which reads
  the contact under the caller's own silo: the `Person` lookup answers `null`, and the `Contact`
  fallback then runs with `$bMustBeFound` left at its default, so a contact outside that silo
  raises `CoreException` rather than being merely hidden. The SDK's `ReadResourceHandler` turned
  the stray throwable into a generic internal error, and the user id, language and archive mode
  that had all resolved correctly were lost with it.

  Reading it under the silo was the wrong question. The contact is the *caller's own*, named by
  its own user record, and an identity is not something a caller has to hold a right on to be
  told — the same reason a user may change their own password without holding any right over
  anyone else's. iTop settles the principle one method away: `FindUser()` loads the user's own
  account with `AllowAllData()`, because an account outside its holder's silo still has to be
  able to log in. The contact is now fetched the same way, by the id on the caller's own user
  record and with `$bMustBeFound` false, so a `contactid` left dangling by a deleted contact
  answers `null` instead of throwing. The id can only ever come from `UserRights::GetContactId()`,
  never from the caller, and a unit test holds that invariant: it is the whole reason the
  `AllowAllData` fetch is not a reader for every contact in the database.

- **`core_object_find_by_name` reported "no match" when it had in fact searched nothing.** Every
  candidate class the caller lacks `UR_ACTION_BULK_READ` on is dropped before the scan; dropped
  silently, a caller with no bulk read anywhere got `total: 0` with no error, which a model reads
  as "there is no such object" and passes to the user as fact. The envelope now carries
  `classes_withheld`, and naming a class that is readable one object at a time but not searchable
  is refused with `Bulk read access denied to class '<class>'` — the same wording
  `core_object_search_by_class` already uses for the same condition. This reports the caller's own
  rights on a class, never whether an object exists: naming a class the caller may not read stays
  indistinguishable from naming one that does not exist, and a unit test holds that apart.

Everything else written so far ships in 1.0.0. Nothing above has shipped — 1.0.0 is untagged, so
this entry folds into it at release rather than describing a change anyone has seen.

## [1.0.0] — unreleased

The first public release. It has not been tagged, so this heading carries no date; the date is
written in on the day of the tag — [doc/release-checklist.md](doc/release-checklist.md) carries
the step, [release.yml](.github/workflows/release.yml) refuses a tag whose version has no dated
heading, and that same date starts the support window in
[SECURITY.md](SECURITY.md#support-period).

Nothing precedes this version, so the entries below describe what the extension *is* rather
than what changed in it. Two properties are worth having before the list: **no tool writes on a
first call**, and **a tool is graded read / write / delete by the annotations it declares**.

**Tested on.** Not recorded yet, because there has been no release run. Step 7 of
[the release checklist](doc/release-checklist.md) writes the iTop patch and the PHP version the
published archive was installed and exercised on into this line; the README and
[doc/hub-listing.md](doc/hub-listing.md) both read it from here.

### Added

- **`core_current_user`, the identity as a tool.** `itop://core/current-user` already answered
  who the session is authenticated as, and a client that never fetches resources never asked:
  a model looks for a tool, finds none, and concludes the server cannot say — then either puts
  the question to the user or answers "my tickets" with somebody else's. The protocol puts a
  fact the model needs mid-task on the tool side of its own split, and the resource stays for
  clients that do browse. Both read through `Helper\CurrentUserReader`, so the two surfaces
  cannot drift and the `AllowAllData` contact lookup is written once, still taking nothing from
  the caller. This is the same reasoning that already doubles the class list and the class
  schema; the identity had been left out of it.

- **No tool writes unless it is called with `simulate=false`.** `core_object_create`,
  `core_object_update`, `core_object_apply_stimulus`, `core_object_delete`,
  `core_object_attach` and the three bulk tools all default to a dry run and answer with a
  validation report of what would change. An MCP client is driven by a model acting on
  instructions that may have come from outside the organisation, and creating a ticket from an
  injected prompt is not made recoverable by being non-destructive.
- **Tool, prompt and resource identifiers are `snake_case`** — `core_object_search_by_oql`, and
  a pack's `acme_ticket_add_log_entry`. The name is derived from the class name, so a pack
  author writes none of them by hand. This is the shape every other MCP server in the ecosystem
  uses, and it is the string a client configuration and an `mcp_disabled_tools` entry name.
- **Bulk tools**: `core_object_bulk_create`, `core_object_bulk_update` and
  `core_object_bulk_delete`, up to 100 objects per call. They check `UR_ACTION_BULK_MODIFY` /
  `UR_ACTION_BULK_DELETE` before anything else, then check every object and every attribute
  individually — before writing anything, so a batch cannot fail on the eleventh object having
  already written ten. Dry runs by default, and each object is reported separately, because a
  call can partly succeed.
- **Graded access**, per instance (`mcp_capabilities`, with `mcp_read_only` as shorthand) and
  per token (`MCP-read`, `MCP-write`, `MCP-delete` scopes). A tool is graded by the annotations
  it already declares. `MCP-write` is the case a read-only switch cannot express: may open a
  ticket and add a note, may not delete anything. The two sides combine by narrowing, and a
  list that narrows to nothing grants nothing — a read-only instance plus a token scoped
  `MCP-delete` serves neither, rather than serving both. This gate is the instance-wide
  configuration; `UserRights` is a separate layer underneath it.
- **Toolsets.** `getToolset()` groups elements by what they are for; `mcp_enabled_toolsets`
  serves a subset, and `MCP-toolset-<name>` scopes a token to one. The core surface declares
  `datamodel`, `objects`, `relations`, `documents` and `server`. Every value the setting can
  take is a functional group with a token scope and dictionary entries behind it, declared by
  the elements themselves rather than falling out of a namespace.
- **`CheckToWrite()` on every write**, so a missing mandatory attribute is reported as what it
  is rather than surfacing as an ORM exception written for a developer. The dry run reports
  what it found and what would change, per object for the bulk tools.
- **Every write tool answers with one shape, whatever `simulate` was.** Whatever a tool
  declares in its output schema, it reports on every call; it is the *values* that vary, not
  the keys — `id` is `null` rather than absent until there is one, `changes` is `{}` rather
  than absent, `valid` is reported on both paths. The five single-object write tools share
  `class`, `id`, `simulated` and `valid` and each adds its own (`changes` for create and
  update; `stimulus`, `state`, `would_move_to` and `changes` for apply stimulus; `deletionPlan`
  for delete; `attached_to`, `document` and `mimetype_note` for attach), while a bulk tool
  answers about a batch and reports counts plus a list of entries carrying `row`, `id`,
  `status` and `message`. Pinned by `OutputSchemaContractTest`, which fails on any declared
  property that is not required and on any description saying a field is "present only" under
  some condition.
- **`output_fields`** on the reading tools, spelled as iTop's REST API spells it. Searches
  default to `id, friendlyname`; `core_object_get` defaults to `*`.
- **`order_by` / `order_direction`** on both searches. OQL has no `ORDER BY`, so without them
  there is no way to ask for "the ten most recent".
- **Paging is stable, and says when there is more.** Every page is ordered by the requested
  attribute and then by `id`, so an object cannot appear on two consecutive pages while another
  is never returned. `has_more` and `next_offset` come back on both searches, because a page can
  be shorter than `limit` when object-level rights removed rows from it, and a caller that reads
  a short page as the end of the set stops early and silently.
- **Attribute values are rendered with `GetForJSON()`, as the REST API renders them**, so case
  logs, link sets and attachments arrive as themselves rather than as `{}`. Case logs, link
  sets and long texts are cut to a declared ceiling and a blob is reported as its metadata;
  naming the attribute in `output_fields` returns it in full.
- **`core_object_find_by_name`**, the console's global search as a tool. Every other reading
  tool needs the class before it can do anything, and a question rarely arrives with one. It is
  iTop's own search — the same needle splitting and quoted phrase, the same
  `full_text_needle_min` floor, `MetaModel::GetClasses('searchable')` or `EnumChildClasses()`
  when a class is named, `AddCondition_FullText()` over every searchable scalar attribute, and
  the same leaf rule that stops one object being reported once per class in its ancestry. The
  rights are `core_object_get`'s, applied per object. `full_text_chunk_duration` bounds the scan
  and `truncated` says when it stopped early.
- **Documents can be read and stored**, without putting bytes back into results that fan out.
  A blob attribute is reported as filename, type and size, and carries a `uri`; reading that
  URI — as the `itop://core/document/{class}/{id}/{att_code}` resource template, or through
  `core_object_get_document` for the many clients that read no resources — returns the one
  document, an image as an image so a model can look at it. `core_object_attach` goes the other
  way, storing content the caller already holds as an attachment or into a named blob
  attribute; it fetches nothing, because "download this URL and attach it" is an outbound
  request from inside iTop's network to an address the model read somewhere. The rule behind
  the metadata-only read is that a file never arrives unasked: `AttributeBlob`'s JSON form is
  the whole file base64-encoded, base64 costs a third again, and `core_object_get` defaults to
  every attribute — one call on a ticket with a 4 MB PDF would be a 5.4 MB response, and a
  fifty-row search fifty of them. `mcp_max_document_bytes` (5 MB) bounds both directions, and
  the two tools and the template form the `documents` toolset, with an `MCP-toolset-documents`
  token scope.
- **Changes are attributed in the object's own history.** A write recorded under the calling
  user's name and nothing else says less through this endpoint than it looks: the same name
  appears whether the person made the change, asked an assistant to make it, or issued a token
  to an agent that has been making it nightly. A change reads `Jane Doe (MCP:
  core_object_update)` — the tool is taken from the request rather than asked for, so a pack's
  tools are attributed exactly like the core ones without their author doing anything. Every
  writing tool also takes an optional `comment`, iTop's REST/JSON `comment` by another route,
  which adds the why. The origin stays `custom-extension`: an `mcp` value on that enum would
  read better in a filter and would cost an `ALTER TABLE` on `priv_change` at every setup.
  Packs spell the parameter with `ChangeTracking::CommentSchemaProperty()` and record it with
  `ChangeTracking::Explain()` — see [doc/extending.md](doc/extending.md).
- **The audit trail records that a client connected, and which one.** `initialize` is audited
  whatever `log_mcp_level` says — the level exists to keep successful calls out of the trail,
  and a successful connection is the one success an operator needs. Without it a token quietly
  in use by something nobody remembers issuing leaves no trace until it does something, and an
  integration that stopped connecting looks like one that connected and had nothing to do. The
  row names the client from `clientInfo`; nothing verifies it, so it identifies a well-behaved
  integration rather than authenticating anyone.
- **`mcp_disabled_tools` and `mcp_enabled_toolsets` entries that match nothing are logged**, at
  every request, naming the stale entries and listing the toolsets the instance actually has. A
  kill-switch entry pointing at a name nobody answers to hides nothing while looking exactly
  like one that works.
- **Every module parameter the code reads is declared in `<module_parameters>` and documented in
  the README**, and a test derives the list from the `GetModuleSetting()` calls rather than from
  a hand-written copy — so an operator told to set something finds it, and the compiled-in
  fallbacks are the values the datamodel ships.
- **Server instructions** at `initialize`, and `MCPRegistry::AddInstructions()` for packs to
  append to them.
- `WWW-Authenticate: Bearer` on a `401`, carrying RFC 9728 `resource_metadata` when
  `mcp_protected_resource_metadata` names the document an OAuth proxy serves.
- `mcp_pagination_limit`, defaulting to 200, so a client that ignores `nextCursor` cannot
  silently miss the 51st tool.
- **The `core/version` resource reports the extension's own name, version, licence and source
  URL**, beside iTop's. This is the AGPL §13 source offer made where a caller can actually reach
  it: an MCP session has no page to carry a footer, so a client that speaks JSON-RPC to one
  endpoint would otherwise have no way to learn what it is talking to or where to ask for the
  corresponding source.
- **`mcp_source_url`**, for an operator running a modified copy. The offer defaults to upstream,
  which is wrong the moment the module is changed and reachable by third parties: §13 entitles
  those users to the source of what is running, not to ours. A constant nobody could override
  would be a compliance problem with no fix.
- **Titles are translated.** `getTitle()` on all four kinds resolves
  `MCP:<kind>:<qualified name>:title` through iTop's dictionary and falls back to a
  `defaultTitle()` hook, so a pack that ships no dictionary still reads correctly. Core titles
  ship in English and French, as do the profile, the token scopes, the class labels, the enum
  values and the fieldsets. Descriptions are deliberately not translated: they are read by the
  model choosing the tool, and one that changed with the caller's language would change what
  the model does.
- **An example tool pack, in `doc/example-pack/`.** Two tools, a prompt, the module
  declaration, the composer settings, the datamodel delta and a contract test — a complete pack
  to copy into `extensions/` and rename, rather than a documentation section to assemble a pack
  from. Its module file carries a `.tpl` suffix because the setup `eval`s every `module.*.php`
  under `extensions/`, `doc/` included, and an example that appears in the module list is not
  an example. CI runs the commands its README tells a reader to run.
- **`Testing\ElementContract`**, the registration contract as a list of findings rather than an
  exception, so a pack can assert its own elements in one line per element. It ships in the
  production autoload deliberately: `autoload-dev` is absent from a production dump and iTop's
  discovery ignores `tests/`, so the version under `tests/` is the one version a downstream
  pack cannot reach. It references no dev dependency, and nothing that serves a request refers
  to it. Alongside the refusals it reports what registers and then disappoints — chief among
  them a tool with no annotations, which works for an administrator and is invisible to every
  scoped token.
- **`MCPRegistry::Check()`**, running the registration checks against one element without
  registering it. The register methods call it, so there is one copy of the rules.
- **`MCPHelper::RequireVersion()` and `MCPHelper::AtLeast()`**, for a pack that needs a base
  newer than the one installed. The module dependency remains the real gate; this catches the
  base being downgraded under a pack already installed, where the alternative is a fatal error
  inside a request that takes the endpoint down for every other pack too.
- **`MCPHelper::SDK_CONSTRAINT`**, publishing the `mcp/sdk` line this module vendors so a pack
  can compile against what will actually be loaded.
- **`Helper\ToolOutput::Json()`**, the recommended return for a tool, and
  **`ToolOutput::Decode()`**, opening a result whichever of the three shapes it arrived in, for
  a pack that subclasses a core tool to add to what it returns.
- **`JsonPayload`, `ResourceOutput` and `WritePlan::Identity()` / `CheckDeletionRights()` /
  `SerializeDeletionPlan()` / `ChangesSchemaProperty()`**, all on the versioned helper surface.
  Tools and resources encode through the one `JsonPayload::Encode()`, so the same class label
  comes back spelled the same way from `core_class_list` and from `itop://core/classes`, and
  `itop://core/classes` returns the same `{category, filter, total, classes}` envelope
  `core_class_list` does. `ResourceOutput::Json()` is the resource-side twin of
  `ToolOutput::Json()` for a pack writing its own resources.
- **`MCPHelper::RejectedValue()`**, beside `OpaqueFailure()`. Same provenance rule and the same
  log-and-reference shape; what differs is the advice. `OpaqueFailure()` answers a server-side
  failure and tells the caller not to retry, which is wrong for a value the caller chose — this
  one names the attribute and leaves another attempt open. A pack validating its own inputs
  wants this one.
- **`MCPHelper::MCP_METHOD_TOOLS_CALL`, `MCP_METHOD_RESOURCES_READ`, `MCP_METHOD_PROMPTS_GET`
  and `MCP_METHOD_INITIALIZE`**, so the method names that decide audit extraction, the default
  audited list and change attribution are one constant rather than a bare literal in three
  files where a typo fails silently in both directions.
- **`@api` on the 26 classes a tool pack may depend on**, beside the `@since` they carry. The
  surface semver applies to is defined by the tags, and `grep -rl '@api' src/` answers it in
  whatever copy you have rather than sending you to a list in a document that could fall
  behind. `AbstractObjectSearch` and `AbstractBulkTool` are on it, under `Abstract\`: they are
  the parts of this module most worth extending.
- **The documentation ships inside the archive** — [doc/extending.md](doc/extending.md) for
  whoever builds a tool pack, [doc/security-summary.md](doc/security-summary.md) for whoever
  has to answer a security or procurement questionnaire, [doc/clients.md](doc/clients.md) for
  connecting one, [CONTRIBUTING.md](CONTRIBUTING.md) with the project's AI-assistance policy,
  and [SECURITY.md](SECURITY.md) with a disclosure channel, a response commitment and a
  declared support period of five years of security fixes from a minor's release date, with six
  months' notice to end one early.

### Security

- **A deletion is refused when its cascade reaches objects the caller may not read, delete or
  modify.** iTop computes a deletion plan with rights off — deliberately, so the plan is
  complete whoever asked for it — and checks the delete right on the object the user clicked and
  on nothing the cascade drags along. That is defensible in the console, where a person saw the
  impact analysis and confirmed it. It is not defensible here: the caller is a language model
  acting on an instruction, cascade is the path by which "delete this ticket" reaches classes an
  operator withheld on purpose, and the audit row afterwards says only that the ticket was
  deleted. `core_object_delete` and `core_object_bulk_delete` check the whole plan, on the dry
  run as well as on the real call. **This endpoint is therefore stricter than the iTop console:
  a deletion the console performs can be refused here.** Grant the rights on the classes the
  cascade reaches, which is the same permission said out loud.
- **The deletion plan is complete, and a caller who may not see all of it is refused rather
  than shown a filtered one.** A plan shown to a person before they approve it has to name
  everything the cascade touches, so the only safe way for it to stay complete is for the
  incomplete case to be refused. A class the caller cannot read is never named in the refusal,
  so the error cannot be used to map the datamodel.
- **A request that presents no credential is refused `401` before the session is touched.**
  `LoginWebPage::ResetSession()` is unauthenticated, so an endpoint that reached it first could
  be called by any website as a top-level GET in a logged-in user's browser and end their
  console session, with nothing in the audit trail that looks like an attack. A cookie is not a
  credential here: what counts is an `Authorization` or `Auth-Token` header, or an identity the
  web server itself decided (`REMOTE_USER`, Basic already parsed by the SAPI), so Basic and
  reverse-proxy deployments are unaffected.
- **iTop's own exception messages never reach the caller.** A failure from `DBInsert()`,
  `DBUpdate()`, `DBDelete()`, `CheckToWrite()` or a query routinely carries SQL, table and class
  names. Those go to `log/error.log` under a reference the caller is given instead. Messages
  this module composed — validation failures, `CheckToWrite()` issues, "Unknown attribute" — are
  returned as they are, because they are what lets a model correct its own call.
- **A refused attribute value comes back naming the attribute, and nothing else.** Every write
  tool passes caller-supplied values through `RestUtils::MakeValue()` and then `DBObject::Set()`;
  `MakeValue()` rethrows what it caught as `<attcode>: <message>`, an external key supplied as a
  string is run as OQL by `FindObjectFromKey()`, and `CoreException` folds its context array into
  the message it exposes — so a database error on that path would otherwise arrive carrying the
  SQL that was issued, the table names in it and the MySQL error beside them. All seven sites go
  through `MCPHelper::RejectedValue()`, and `OrmFailureRedactionTest` scans for the shape of the
  leak rather than for a list of known sites.
- **An uploaded file's media type is verified against the bytes.** The stored type is what iTop
  later serves the file as, so markup stored as `image/png` on a caller's say-so would be markup
  a browser renders under the instance's own origin. The bytes decide, the response says so in
  `mimetype_note`, and inert text formats libmagic has no signature for (CSV, Markdown, YAML)
  are honoured as declared.
- **`core_class_schema` reports the target class of an external key rather than the rows behind
  it.** Describing `UserRequest` otherwise means returning every `Person` in the database under
  `caller_id`, on every call, and those rows have not been through object-level rights.
- **Sensitive attributes are masked before conversion**, and one predicate decides what
  "sensitive" means for both the schema tools and the object tools.
- **The `MCP Services User` profile grants no data rights of its own.** Like iTop's
  `REST Services User`, it marks a user as allowed through the endpoint; what a client can read
  or write still comes from the user's other profiles and is enforced by `UserRights` on every
  call.

### Packaging

- **The module ships its own `.htaccess` and `web.config`.** iTop's `extensions/` rules deny
  PHP, so without them the documented endpoint answers `403` on a stock Apache or IIS install.
  The compiled copy under `env-<env>/altioo-mcp/` sits where PHP *is* granted for the whole
  subtree, so both files are asserted to be in the archive: a guard missing from the zip would
  fail open, on `src/` and `vendor/`, on an instance that installed without a warning.
- **Release archives are built by CI from a tag**, never by hand, with a published SHA-256, a
  CycloneDX SBOM and a licence inventory attached to the release and carried inside the archive,
  and a build provenance attestation signed against the workflow, the commit and the repository
  (`gh attestation verify`). `vendor/` ships, so what is in it is the audit surface rather than
  an implementation detail — and an instance found in a year's time can answer what it is
  running without reaching the internet.
- **AGPL-3.0-or-later is declared the same way everywhere it appears.** Every PHP file in `src/`
  and `tests/`, plus `index.php`, `register.php` and the module declaration, opens with the same
  two lines, and the `@license` value carries the SPDX identifier verbatim so it matches
  `composer.json` as a string rather than merely in spirit. The exception is
  `model.altioo-mcp.php`, which the compiler overwrites on every setup run.
- **`@since` on the surface the versioning promise covers.** The abstracts, `MCPRegistry`,
  `MCPExtensionCollector`, `iMCPServiceProvider`, the helpers and the checker under `Testing/`
  tag their public methods; every class in `src/` tags itself. At 1.0.0 every one of them reads
  `1.0.0` — the tag starts telling a pack author something from the first release that adds to
  the surface.
- **The datamodel declares schema `3.2`**, matching the branch the extension targets. Nothing in
  the compiler reads the attribute; it says which reference the file was written against.
- **The `extension.xml` description** says which of its claims are access controls (iTop's
  permissions, the profile, the token scopes) and which is a guardrail (the dry run).
- **The supported iTop branches and PHP range are stated once.**
  [`.github/itop-support.json`](.github/itop-support.json) and `composer.json` own the answer;
  the copies in the README, the Hub listing and `extension.xml`'s `<description>` are delimited
  and checked against them by `ModuleMetadataTest`.
