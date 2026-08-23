# Changelog

All notable changes to this extension are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning is
[semver](https://semver.org/) over the surface a tool pack can touch: the abstracts under
`Abstract/`, `MCPRegistry`, `MCPExtensionCollector`, `iMCPServiceProvider`, the helpers under
`Helper/` and the checker under `Testing/`. See the README, [Extending](README.md#extending).

## [Unreleased]

Findings from the pre-release review, applied before publication. Nothing here has shipped, so
the breaking entry below breaks nothing that exists — it is recorded because the class it
renames would otherwise be a data migration on any later date.

These are not changes *after* 1.0.0. The version was never bumped, so they ship as part of it:
at tag time this section folds into [1.0.0](#100--unreleased) under one dated heading.

### Breaking

- **`EventMCPService` is now `AltiooEventMCPService`**, and its table `priv_event_mcp_service`
  is now `priv_altioo_event_mcp_service`. The console label is unchanged ("MCP Service Call"):
  it comes from the dictionary, which moved with the class. An unprefixed class name in a
  shared datamodel is a collision waiting for the second extension that wants it, and after
  publication this becomes a table rename on live instances rather than an edit.

### Added

- **`JsonPayload`, `ResourceOutput` and `WritePlan::Identity()` / `CheckDeletionRights()` /
  `SerializeDeletionPlan()` / `ChangesSchemaProperty()`**, all under the versioned helper
  surface. `ResourceOutput::Json()` is the resource-side twin of `ToolOutput::Json()` for a
  pack writing its own resources; the two share one encoder so they cannot drift.
- **`MCPHelper::MCP_METHOD_TOOLS_CALL`, `MCP_METHOD_RESOURCES_READ` and
  `MCP_METHOD_PROMPTS_GET`**, beside the existing `MCP_METHOD_INITIALIZE`. `tools/call`
  decided behaviour in three files as a bare literal — audit extraction, the default audited
  list, and change attribution in `ChangeTracking` — where a typo fails silently in both
  directions.

- **The `core/version` resource now reports the extension's own name, version, licence and
  source URL**, beside iTop's. This is the AGPL §13 source offer made where a caller can
  actually reach it: an MCP session has no page to carry a footer, so a client that speaks
  JSON-RPC to one endpoint had no way to learn what it was talking to or where to ask for the
  corresponding source. Additive — the existing keys are unchanged.
- **`mcp_source_url`**, for an operator running a modified copy. The offer defaults to
  upstream, which is wrong the moment the module is changed and reachable by third parties:
  §13 entitles those users to the source of what is running, not to ours. A constant nobody
  could override would have been a compliance problem with no fix.
- **[CONTRIBUTING.md](CONTRIBUTING.md)**, with the project's AI-assistance policy — disclosure
  required, responsibility with the contributor — and a statement of how this extension is
  itself built.
- **A declared support period** in [SECURITY.md](SECURITY.md): five years of security fixes
  from a minor's release, with six months' notice to end one early.
- **`MCPHelper::RejectedValue()`**, on the versioned helper surface beside
  `OpaqueFailure()`. Same provenance rule and the same log-and-reference shape; what differs is
  the advice. `OpaqueFailure()` answers a server-side failure and tells the caller not to retry,
  which is wrong for a value the caller chose — this one names the attribute and leaves another
  attempt open. A pack validating its own inputs wants this one.

### Security

- **A refused attribute value no longer comes back with iTop's SQL attached.** Every write tool
  passes caller-supplied values through `RestUtils::MakeValue()` and then `DBObject::Set()`, and
  put whatever those threw straight into the response. `MakeValue()` rethrows what it caught as
  `<attcode>: <message>`, an external key supplied as a string is run as OQL by
  `FindObjectFromKey()`, and `CoreException` folds its context array into the message it exposes
  — so a database error on that path arrives carrying the SQL that was issued, the table names
  in it and the MySQL error beside them. Sending a malformed external key returned the schema.
  All seven sites now go through `MCPHelper::RejectedValue()`: the caller learns which attribute
  was refused and gets a log reference, and iTop's own words stay in `log/error.log`.

  The rule was already written down and already tested — `OrmFailureRedactionTest` exists to
  forbid exactly this. Its brace matcher counted plain `{` and `}` tokens, and a `"{$var}"`
  interpolation opens with `T_CURLY_OPEN` and closes with a plain `}`, so the scan's depth fell
  to zero at the first interpolated string in a catch block and read no further. Every message
  it was hunting began `"Invalid value for attribute '{$sAttCode}': "`, which put the
  interpolation ahead of the leak in all seven. The scan now counts the interpolation openers,
  and is held to a known snippet so that it failing to find things is itself a failure.

- **A deletion is now refused when its cascade reaches objects the caller may not read, delete
  or modify.** iTop computes a deletion plan with rights off — deliberately, so the plan is
  complete whoever asked for it — and checks the delete right on the object the user clicked
  and on nothing the cascade drags along. That is defensible in the console, where a person
  saw the impact analysis and confirmed it. It is not defensible here: the caller is a language
  model acting on an instruction, cascade is the path by which "delete this ticket" reaches
  classes an operator withheld on purpose, and the audit row afterwards says only that the
  ticket was deleted. `core_object_delete` and `core_object_bulk_delete` now check the whole
  plan, on the dry run as well as on the real call. **This endpoint is therefore stricter than
  the iTop console: a deletion the console performs can be refused here.** Grant the rights on
  the classes the cascade reaches, which is the same permission said out loud. A class the
  caller cannot read is never named in the refusal, so the error cannot be used to map the
  datamodel.
- **The deletion plan no longer discloses objects the caller cannot see.** It reports the class
  and id of everything the cascade touches, and iTop built that list with rights off. It is
  closed by the check above rather than by filtering the output: a plan shown to a person
  before they approve it has to be complete, so the only safe way for it to be complete is for
  the incomplete case to be refused instead.

- **The endpoint no longer resets the caller's iTop session for a request that presented no
  credential.** `LoginWebPage::ResetSession()` is unauthenticated, so any website could make a
  logged-in user's browser call this URL as a top-level GET and end their console session —
  with nothing in the audit trail that looks like an attack. Such a request is now refused
  `401` before the session is touched. A cookie is not a credential here: what counts is an
  `Authorization` or `Auth-Token` header, or an identity the web server itself decided
  (`REMOTE_USER`, Basic already parsed by the SAPI), so Basic and reverse-proxy deployments are
  unaffected.
- **iTop's own exception messages no longer reach the caller.** A failure from `DBInsert()`,
  `DBUpdate()`, `DBDelete()`, `CheckToWrite()` or a query routinely carries SQL, table and
  class names. Those now go to `log/error.log` under a reference the caller is given instead.
  Messages this module composed — validation failures, `CheckToWrite()` issues, "Unknown
  attribute" — are unchanged, because they are what lets a model correct its own call.
- **An uploaded file's media type is verified against the bytes.** `core_object_attach` used to
  store whatever the caller declared, and the stored type is what iTop later serves the file
  as; markup labelled `image/png` was markup a browser would render under the instance's own
  origin. The bytes now decide, the response says so in `mimetype_note`, and inert text formats
  libmagic has no signature for (CSV, Markdown, YAML) are still honoured as declared.

- **`AGENTS.md` and `doc/itop-branch-notes.md` no longer ship in the release archive.** Both are
  CC BY-SA 4.0, and `rsync --exclude-from=exclude.txt` put them in the zip: `exclude.txt`
  reasons explicitly about `README.md`, `doc/`, `tests/` and `tools/` and never about them, and
  the release workflow asserted neither their presence nor their absence. So two
  differently-licensed files landed on every customer instance inside a package whose `LICENSE`,
  `composer.json`, `extension.xml` and README all declare AGPL-3.0-or-later with no NOTICE
  beside them. Checked twice now, and neither check names a file: `ModuleMetadataTest` over the
  working tree, the release workflow over the assembled archive.
- **`.htaccess` and `web.config` are asserted to be in the archive.** The archive check
  enumerates sixteen files and omitted the two that are the module's entire HTTP guard. Each
  file's own comments say to keep it in step with the other, and nothing read either. The
  failure would not have been a dead endpoint: the compiled copy in `env-<env>/altioo-mcp/`
  sits under a configuration whose `FilesMatch` does include `php`, granted for the whole
  subtree — so a guard missing from the zip fails *open*, on `src/` and `vendor/`, on an
  instance that installed without a warning.
- **`composer audit --locked` runs on the tag build.** It lives in `ci.yml`, which triggers on
  branches and pull requests; the release workflow triggers on tags, which match neither. The
  one build whose output an operator unzips into a live instance was the one build that never
  asked whether a locked dependency had gone bad — and it is the only check whose verdict
  changes with no commit. `ci.yml` also runs on a weekly schedule now, for the same reason.
- **Every GitHub action is pinned to a commit SHA**, with the version in a trailing comment. A
  major tag is a mutable pointer, and `softprops/action-gh-release` runs under `contents: write`
  in the job that publishes what customers download. A CI job fails on any reference that is not
  a 40-character SHA.
- **The release archive carries a build provenance attestation.** The published `.sha256` proves
  the file did not change in transit; it does not prove this repository built it, since anyone
  can publish a zip and a matching checksum. `actions/attest-build-provenance` signs the archive
  against the workflow, the commit and the repository, verifiable with `gh attestation verify`.
- **The `MCP Services User` profile has dictionary entries in both languages.** Its name and
  description were inline literals in the datamodel, so a French console showed them in English
  — the one user-facing string the `fr-fr` file did not reach, in the screen where an
  administrator decides who may reach the endpoint.

### Fixed

- **A portal-only user is told they have no console, instead of `retCode=5`.** The endpoint
  authenticates through `DoLogin()` against the backoffice portal, so an account whose profiles
  reach only the end-user portal passes the credential check and is then refused by iTop with
  an exit code `createAuthException()` did not name. It fell to the default branch: an opaque
  message to the caller and nothing in `log/error.log`. The boundary is unchanged — the MCP
  services serve what the console serves — but reaching it is an ordinary mistake, since
  granting `MCP Services User` to a portal user and issuing them a token looks correct from
  every screen involved. [Granting access](README.md#granting-access) now states the
  requirement.

- **A write no longer reports its identifier twice into the same array key.** The tools spelled
  both `'id' => $iId` and `MetaModel::DBGetKey($class) => $iId`, and `DBGetKey()` returns `id`
  for 173 of the 175 stock classes — so the two collided in one array literal and the duplicate
  the schema described only ever existed for the two link classes. `WritePlan::Identity()` now
  emits the class's own key attribute exactly when it is not `id`.

- **`initialize` is audited on a fresh install.** It was in `MCPHelper::DEFAULT_LOG_METHODS`
  and missing from the `log_mcp_method` block in the datamodel — and the datamodel is what the
  setup writes into `config-itop.php`, so the default that ran on every install was the one
  without it. The record that a client connected at all was therefore never written.
- **`mcp_allowed_hosts` is declared.** It was read by the code, named in the error message a
  `403` produces, and absent from `<module_parameters>` — so an operator told to set it found
  nothing to set.
- **`mcp_disabled_tools` and `mcp_enabled_toolsets` entries that match nothing are logged.** A
  kill-switch entry pointing at a name nobody answers to hides nothing while looking exactly
  like one that works, which is what an element renamed by an upgrade leaves behind.
- **`StatelessSessionStore` stores nothing.** It kept writes in a private static array that no
  reader could ever see — the SDK caches session data per request — and under a persistent
  worker (FrankenPHP, RoadRunner) that array would have grown without bound and carried one
  caller's session data into the next request.
- `LoginWebPage::ResetSession()` no longer gets an argument; it takes none.

- **The `mcp_enabled_toolsets` comment undercounted the toolsets, twice.** It named `datamodel`,
  `objects` and `relations`; `getToolset()` across `src/Core/` returns `documents` as well, and
  the dictionaries have shipped `MCP-toolset-documents` entries in both languages throughout. An
  operator narrowing an instance from that comment dropped the documents toolset and the tools
  stopped being advertised. It also omitted `core`: the `core/version` and `core/current-user`
  resources and the `core/my-open-tickets` prompt override nothing, so the abstracts' fallback
  gives them their namespace as a toolset — a fifth value of the setting, named nowhere and with
  no matching token scope. Both are documented now, and guarded from the elements themselves.
- **`SECURITY.md` no longer claims the security address is in `composer.json` under
  `support.security`.** That field held a URL to `SECURITY.md` on GitHub, which is the field's
  meaning and is a web page — the one thing the sentence said the address was not. It is in
  `support.email` now, and the claim says where it actually travels.
- **Two release-checklist steps that could not pass.** Both told the releaser to confirm the
  console still shows "the menu and the profile"; the datamodel declares zero `<menu>` elements,
  and the README says so two sections earlier. Replaced with the three surfaces this module has.
- **`web.config` hid a `templates/` segment** for a directory this changelog records as deleted.
  The interesting direction is the other one — a directory added later and hidden by nobody — so
  the segment list is now checked against the directories the archive ships.
- **The executable bit on `LICENSE`, `MCPHelper.php`, `MCPLog.php` and `MCPService.php`.** None
  is a program, all four unzip into a directory a web server is pointed at, and
  `model.altioo-mcp.php` was `0666` on disk besides.
- **`doc/example-pack` could not run `composer test`, which is what its own README says to run.**
  No phpunit configuration, no `autoload-dev` for the namespace its `ContractTest` declares, and
  the base extension — whose `Testing\ElementContract` that test imports — reachable from
  nowhere. It is the file a third-party pack author copies first. CI now runs the README's
  commands verbatim.

### Changed

- **Every write tool answers with one shape, whatever `simulate` was.** `core_object_create`,
  `core_object_update`, `core_object_apply_stimulus`, `core_object_delete`,
  `core_object_attach` and the three bulk tools used to return one set of keys on a dry run and
  a different set on a real write — `valid` on the first only, `id` on the second only — while
  declaring the union of the two as their output schema with `required` narrowed to the
  intersection. A schema like that describes neither response: nothing validating it could
  catch a create that came back without an id, and a model reading it as prose cannot tell
  which fields to expect when. Whatever a tool declares, it now reports on every call; it is the
  *values* that vary, not the keys. Across tools the shapes still differ, and should: the four
  single-object write tools share `class`, `id`, `simulated` and `valid` and each adds its own
  (`changes` for create and update; `stimulus`, `state`, `would_move_to` and `changes` for
  apply stimulus; `deletionPlan` for delete; `attached_to`, `document` and `mimetype_note` for
  attach), while a bulk tool answers about a batch and so reports counts and a list of entries
  carrying `row`, `id`, `status` and `message`. What changed is that within any one of them,
  `id` is now `null` rather than absent until there is one, `valid` is reported on both paths,
  `changes` is `{}` rather than absent, `would_move_to` is reported after a real transition as
  well as before a simulated one, `mimetype_note` is `null` when the stored type is the
  declared one, and a bulk entry carries its four keys whether it succeeded or failed. Pinned
  by `OutputSchemaContractTest`, which now fails on any declared property that is not required
  and on any description saying a field is "present only" under some condition.
- **Resources and resource templates encode exactly as tools do.** Both surfaces now go through
  `JsonPayload::Encode()`, so the same class label comes back spelled the same way from
  `core_class_list` and from `itop://core/classes`. Previously the resources called
  `json_encode()` bare: slashes and non-ASCII were escaped on one surface and not the other,
  and on a payload that could not be encoded the resource returned `false`, which reached the
  SDK as a boolean and came back to the caller as "unhandled type: boolean" with nothing naming
  the resource. `itop://core/classes` also returns the same `{category, filter, total, classes}`
  envelope `core_class_list` does, built once in `DatamodelReader::ClassListPayload()`.
- **The unauthorised-profile message no longer names one profile as though it were the rule.**
  It said "The profile MCP Services User is required", which omits `Administrator` — half the
  shipped default — and states a default as though `mcp_allowed_profiles` did not exist. It now
  says what the default is and names the parameter that decides. What the instance actually
  requires goes to `log/error.log` with the refused username, not into the `401`.

- **Release archives are built by CI from a tag**, with a published SHA-256, a CycloneDX SBOM
  and a licence inventory — attached to the release and carried inside the archive. `vendor/`
  ships, so what is in it is the audit surface rather than an implementation detail.
- The `extension.xml` description now says which of its claims are access controls (iTop's
  permissions, the profile, the token scopes) and which is a guardrail (the dry run).
- README: a Troubleshooting section covering the log, `log_mcp_level`, and the three failure
  modes that account for most reports — FastCGI dropping `Authorization`, a host-check `403`
  whose reason is only in the log, and a `401` on a token with no `MCP*` scope. Installation
  now leads with backup and a maintenance window, states that downgrade is unsupported and
  that the rollback is that backup, names what to remove by hand when uninstalling, and adds
  post-install checks including the `MCP Services User` profile.
- SECURITY.md carries a real disclosure channel and response commitment; the README support
  block states plainly that there is no SLA on the free extension.

### Internal

- The seven packages this module and iTop both ship are now checked rather than assumed:
  iTop's copies are the ones that load on the endpoint, and `VendoredDependencyResolutionTest`
  fails if any of them stops satisfying what this module's dependency graph declares. One
  known divergence (`psr/http-factory`) is recorded with the reason it is survivable.

- **A linter, which `AGENTS.md` §4.8 has stated as a MUST since it was written and nothing ran.**
  There was no `.editorconfig`, no PHP_CodeSniffer or php-cs-fixer configuration, no CI step and
  no line in the release checklist. `tools/phpcs/iTop` is Combodo's coding standard as a PHPCS
  standard, carrying no module name and no repository path so another iTop extension can copy the
  directory and use it unchanged; `phpcs.xml.dist` is the half that knows about this tree. Its
  first run found thirty concatenations spaced on both sides, five control structures braced
  Allman-style, eighteen multi-line signatures closing `): mixed {`, and a tools script indenting
  with spaces.
- **`src/Service/TokenScopes.php` had no test at all**, on the authentication path, while
  `AccessPolicyTest` exercised only the consumer of the scope list it produces. The cases pinned
  are the degraded ones — each is one line away from answering "no restriction" instead of
  "unknown".
- **Two guards were checking a fraction of what they named.** `ModuleMetadataTest`'s settings
  provider was a hand-written list of seven that the module had outgrown to fifteen, so eight
  operator switches were passed to neither the "declared in `module_parameters`" nor the
  "documented in the README" check; it is derived from the `GetModuleSetting()` calls now.
  `TitleDictionaryTest` reached 22 dictionary entries of 78, because it walked the elements and
  asked about each — leaving the class labels, enum values, fieldsets and token scopes to
  nobody. The languages are compared entry by entry, in both directions.
- **`tools/reconcile-since.py`** was called by nothing, named in no document, and its own usage
  line spelled its filename with an underscore. It is in the release checklist now, with the
  licence header the rest of `tools/` carries.

## [1.0.0] — unreleased

The first public release, once it is tagged — which it has not been. There is no `v1.0.0` tag
and the repository is not published yet, so this heading carries no date. The body of work
below was finished on 16 August 2026; that is when it stopped changing, not when anyone could
install it, and the two are not the same claim.

**Read this section together with [Unreleased] above.** Those entries are pre-release review
findings against *this* version rather than changes that follow it — the version was never
bumped, so they will ship inside 1.0.0. At tag time they fold in here under one dated heading,
and that date is also what starts the support window in [SECURITY.md](SECURITY.md#support-period);
[doc/release-checklist.md](doc/release-checklist.md) carries the step, and
[release.yml](.github/workflows/release.yml) refuses a tag whose version has no dated heading.

The notes below are written against the pre-release development series rather than an earlier
published version: nothing before this was released, so an installation upgrading to 1.0.0 is
one that was following the repository. Anyone installing 1.0.0 fresh can read them as a
description of the shape the extension settled on — in particular that no tool writes on a
first call, and that a tool is graded read / write / delete by the annotations it declares.

### Breaking

- **Tool, prompt and resource identifiers are `snake_case`.** `core_ObjectSearchByOQL` is now
  `core_object_search_by_oql`, and a pack's `acme_TicketAddLogEntry` becomes
  `acme_ticket_add_log_entry` — the name is derived from the class name, so no pack has to do
  anything, but a client configuration that allow-lists tools by name, and any
  `mcp_disabled_tools` entry, has to be updated. This is the shape every other MCP server in
  the ecosystem uses.
- **`core_object_create`, `core_object_update` and `core_object_apply_stimulus` no longer
  write unless called with `simulate=false`.** They join `core_object_delete` and the bulk
  tools: a client that called them and expected a write now gets a validation report of what
  would change. An MCP client is driven by a model acting on instructions that may have come
  from outside the organisation, and creating a ticket from an injected prompt is not made
  recoverable by being non-destructive.
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
  to one. The core surface declares `datamodel`, `objects`, `relations` and `documents`.
- **`CheckToWrite()` on every write.** Only the delete tool ran a pre-write check; the others
  went straight to `DBInsert()` / `DBUpdate()` / `ApplyStimulus()`, so a missing mandatory
  attribute surfaced as an ORM exception written for a developer. The dry run reports what it
  found and what would change, per object for the bulk tools.
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
- **An example tool pack, in `doc/example-pack/`.** Two tools, a prompt, the module
  declaration, the composer settings, the datamodel delta and a contract test — a complete
  pack to copy into `extensions/` and rename, rather than a documentation section to assemble
  a pack from. Its module file carries a `.tpl` suffix because the setup `eval`s every
  `module.*.php` under `extensions/`, `doc/` included, and an example that appears in the
  module list is not an example.
- **`Testing\ElementContract`**, the registration contract as a list of findings rather than an
  exception, so a pack can assert its own elements in one line per element. It ships in the
  production autoload deliberately: `autoload-dev` is absent from a production dump and iTop's
  discovery ignores `tests/`, so the version under `tests/` is the one version a downstream
  pack cannot reach. It references no dev dependency, and nothing that serves a request refers
  to it. Alongside the refusals it reports what registers and then disappoints — chief among
  them a tool with no annotations, which works for an administrator and is invisible to every
  scoped token.
- **`MCPRegistry::Check()`**, running the registration checks against one element without
  registering it. The register methods now call it, so there is one copy of the rules.
- **`MCPHelper::RequireVersion()` and `MCPHelper::AtLeast()`**, for a pack that needs a base
  newer than the one installed. The module dependency remains the real gate; this catches the
  base being downgraded under a pack already installed, where the alternative is a fatal error
  inside a request that takes the endpoint down for every other pack too.
- **`MCPHelper::SDK_CONSTRAINT`**, publishing the `mcp/sdk` line this module vendors so a pack
  can compile against what will actually be loaded.
- **`ToolOutput::Decode()`**, opening a result whichever of the three shapes it arrived in, for
  a pack that subclasses a core tool to add to what it returns.
- **Titles are translated.** `getTitle()` on all four kinds now resolves
  `MCP:<kind>:<qualified name>:title` through iTop's dictionary and falls back to the new
  `defaultTitle()` hook, so a pack that ships no dictionary reads exactly as before. Core
  titles ship in English and French. Descriptions are deliberately not translated: they are
  read by the model choosing the tool, and one that changed with the caller's language would
  change what the model does.
- **Documents can be read and stored**, without putting bytes back into results that fan out.
  A blob attribute was reported as filename, type and size and there was no way to reach the
  file at all; it now also carries a `uri`, and reading that URI — as the
  `itop://core/document/{class}/{id}/{att_code}` resource template, or through
  `core_object_get_document` for the many clients that read no resources — returns the one
  document, an image as an image so a model can look at it. `core_object_attach` goes the
  other way, storing content the caller already holds as an attachment or into a named blob
  attribute; it fetches nothing, because "download this URL and attach it" is an outbound
  request from inside iTop's network to an address the model read somewhere. What has not
  changed is the rule that made the metadata-only read right in the first place: a file never
  arrives unasked. `AttributeBlob`'s JSON form is the whole file base64-encoded, base64 costs
  a third again, and `core_object_get` defaults to every attribute — one call on a ticket with
  a 4 MB PDF would be a 5.4 MB response, and a fifty-row search fifty of them.
  `mcp_max_document_bytes` (5 MB) bounds both directions, and the two tools and the template
  form the `documents` toolset, with an `MCP-toolset-documents` token scope.

- **Changes are attributed in the object's own history.** Every write went into the change log
  under the calling user's name and nothing else, which through this endpoint says less than it
  looks: the same name appears whether the person made the change, asked an assistant to make
  it, or issued a token to an agent that has been making it nightly. A change now reads
  `Jane Doe (MCP: core_object_update)` — the tool is taken from the request rather than asked
  for, so a pack's tools are attributed exactly like the core ones without their author doing
  anything. Every writing tool also takes an optional `comment`, iTop's REST/JSON `comment` by
  another route, which adds the why. The origin stays `custom-extension`: an `mcp` value on
  that enum would read better in a filter and would cost an `ALTER TABLE` on `priv_change` at
  every setup. Packs spell the parameter with `ChangeTracking::CommentSchemaProperty()` and
  record it with `ChangeTracking::Explain()` — see the README, [Extending](README.md#extending).
- **`core_object_find_by_name`**, the console's global search as a tool. Every other reading
  tool needs the class before it can do anything, and a question rarely arrives with one. It
  is iTop's own search — the same needle splitting and quoted phrase, the same
  `full_text_needle_min` floor, `MetaModel::GetClasses('searchable')` or `EnumChildClasses()`
  when a class is named, `AddCondition_FullText()` over every searchable scalar attribute, and
  the same leaf rule that stops one object being reported once per class in its ancestry. The
  rights are `core_object_get`'s, applied per object. `full_text_chunk_duration` bounds the
  scan and `truncated` says when it stopped early.
- `has_more` and `next_offset` on both searches. A page can come back shorter than `limit`
  because object-level rights removed rows from it, and a caller that reads a short page as
  the end of the set stops early and silently.

- **The audit trail records that a client connected, and which one.** `initialize` is audited
  whatever `log_mcp_level` says — the level exists to keep successful calls out of the trail,
  and a successful connection is the one success an operator needs. Without it a token quietly
  in use by something nobody remembers issuing leaves no trace until it does something, and an
  integration that stopped connecting looks like one that connected and had nothing to do. The
  row names the client from `clientInfo`; nothing verifies it, so it identifies a well-behaved
  integration rather than authenticating anyone.

### Fixed

- **`core_object_bulk_update` validated nothing before writing.** It set its values and called
  `DBUpdate()`, so a batch could fail on the eleventh object after writing ten — and its dry
  run checked only the field list, happily reporting forty objects as fine.
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
- **`core_my_open_tickets` asks in the terms the instance actually uses.** It named
  `UserRequest` and two status codes, so it was wrong without a ticketing module, wrong for a
  profile that cannot read tickets, and wrong wherever a delta had renamed the attributes. It
  now queries `Ticket` on `operational_status`, checks every attribute for existence and read
  rights before naming it, and withdraws itself through `isAvailable()` when too little
  survives. It also still called `ObjectSearchByOQL`, which has not been the name since the
  identifiers became `snake_case`.
- Two queries per row of every search result, both for something already in hand: `Fetch()`
  returns each row as its final class, so `GetFinalClassName()` asked for a name that had just
  arrived and `GetObject()` re-read an object already loaded. Both rights checks stay. The
  probe `DBObjectSet` goes too — its constructor runs no query, so it never caught the error
  it was written for.

- **`log_mcp_method` fell back to an empty list, which reads as "audit nothing".** A fresh
  install had `log_mcp_service` defaulting to `true`, an **MCP Service Call** class sitting in
  the console, and a trail that stayed empty for ever. Nothing failed; the rows were simply
  never written. The default is now the list the README publishes, and a test holds the two
  together.
- **Two queries per object, in every tool that acts on one.** `MetaModel::GetObject()` and
  `DBObjectSet::Fetch()` both go through `GetObjectByRow()`, which reads the `finalclass`
  column and instantiates the leaf — so `GetFinalClassName()` was asking for a name that had
  arrived with the row, and `core_object_get` read the whole object a second time under a name
  it already had. Every rights check and every message is unchanged.

### Changed

- A tool's default `getTitle()` is the class name rather than the identifier, now that
  identifiers are `snake_case`.
- Sensitive attributes are masked before conversion rather than after, and one predicate
  decides what "sensitive" means for both the schema and the object tools.
- **`AbstractObjectSearch` and `AbstractBulkTool` moved from `Core\Tools\` to `Abstract\`** and
  are now covered by the versioning policy. They were the parts of this module most worth
  extending and the only ones a pack could not rely on. Both used to hardcode
  `getNamespace() = 'core'`, which the registry refuses from anything outside this module — so
  extending them from a pack could not have worked in the first place; the core tools that use
  them declare their own namespace and toolset now.

### Packaging

- The module ships its own `.htaccess` and `web.config`. iTop's `extensions/` rules deny PHP,
  so the documented endpoint answered `403` on a stock Apache or IIS install.

- **The licence is declared the same way in all three places it appears.** `composer.json` said
  `AGPL-3.0-or-later` while the file headers pointed at the OSI page for AGPL-3.0 — the
  version-only licence — and thirty-one files under `src/` carried no header at all. Every PHP
  file in `src/` and `tests/`, plus `index.php`, `register.php` and the module declaration, now
  opens with the same two lines, and the `@license` value carries the SPDX identifier verbatim
  so it matches `composer.json` as a string rather than merely in spirit. The exception is
  `model.altioo-mcp.php`, which the compiler overwrites on every setup run.
- **`@since` on the surface the versioning promise covers.** The abstracts, `MCPRegistry`,
  `MCPExtensionCollector`, `iMCPServiceProvider`, the helpers and the checker under `Testing/`
  tag their public methods; every class in `src/` tags itself. A pack author reading a method
  can now tell whether it was there in 1.0.0 without consulting this file.
- The datamodel declares schema `3.2` rather than `3.0`, matching the branch the extension
  targets. Nothing in the compiler reads the attribute — it says which reference the file was
  written against.
- Scaffold leftovers removed: the empty `templates/`, `assets/css/` and `assets/js/`
  directories, the `.gitkeep` files under directories that have had real contents for some
  time, and the `exclude.txt` and `web.config` entries that named them.
