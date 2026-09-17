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

## [1.0.0] — unreleased

The first release. Nothing precedes it, so the entries below describe what the extension **is**
rather than what changed in it — there is no instance anywhere running an earlier version, and
nothing here is a migration.

It has not been tagged, so this heading carries no date. The date is written in on the day of
the tag: [doc/release-checklist.md](doc/release-checklist.md) carries the step,
[release.yml](.github/workflows/release.yml) refuses a tag whose version has no dated heading,
and that same date starts the support window in [SECURITY.md](SECURITY.md#support-period).

Two properties are worth having before the list: **no tool writes on a first call**, and **a
tool is graded read / write / delete by the annotations it declares**.

**Tested on.** Not recorded yet, because there has been no release run. Step 7 of
[the release checklist](doc/release-checklist.md) writes the iTop patch and the PHP version the
published archive was installed and exercised on into this line; the README and
[doc/hub-listing.md](doc/hub-listing.md) both read it from here.

### Added

- **An MCP server inside iTop, at a single authenticated HTTP endpoint.** Streamable HTTP,
  stateless, authenticated by iTop itself on every request. The surface stays close to iTop's
  own primitives, in the same spirit as its REST/JSON API: list and describe classes, find
  objects by name the way the console's global search does, search by OQL or by attribute
  criteria, read one object, walk relations, read and store documents, read the change log, and
  create, update, delete or apply a lifecycle stimulus — each of the last four in bulk as well.
  Five resources and one prompt beside them. Task-shaped tools ("open an incident") are
  deliberately not here: they belong in packs built on this, which is what the extension points
  are for. The full list is in the [README](README.md#what-it-exposes).
- **No tool writes unless it is called with `simulate=false`.** `core_object_create`,
  `core_object_update`, `core_object_apply_stimulus`, `core_object_delete`,
  `core_object_attach` and the three bulk tools all default to a dry run and answer with a
  validation report of what would change. An MCP client is driven by a model acting on
  instructions that may have come from outside the organisation, and creating a ticket from an
  injected prompt is not made recoverable by being non-destructive.
- **`CheckToWrite()` on every write**, so a missing mandatory attribute is reported as what it
  is rather than surfacing as an ORM exception written for a developer. The dry run reports
  what it found and what would change, per object for the bulk tools.
- **A dry run tells a value the write would discard from one it would refuse.** `CheckToWrite()`
  runs `DoComputeValues()` before it checks anything, and a class is free to `Set()` an
  attribute there from the ones it derives from — `UserRequest::ComputeValues()` derives
  `priority` from `urgency` and `impact` on every write. The supplied value is put back,
  `ListChangedValues()` finds nothing changed and drops the attribute, and `DoCheckToWrite()`
  then validates the delta and nothing else: the value is neither applied nor looked at. So
  `core_object_create`, `core_object_update`, `core_object_apply_stimulus` and both writing bulk
  tools report an `overridden` block — attribute code => `{requested, effective}` for every
  supplied value the write would not keep, always present and empty when there is nothing to
  say — captured on the only side of `CheckToWrite()` where the object still holds what the
  caller asked for. Reported rather than refused, because setting a derived attribute alongside
  the ones it derives from is a legitimate call. A discarded value is validated in its own
  right, which is the question `DoCheckToWrite()` skips, so a `priority` the enum does not allow
  is refused instead of coming back valid; on a bulk call that refusal lands on the row, not on
  the batch.
- **A write that reports failure wrote nothing, or says what it wrote.** Every write path
  catches `\Throwable` rather than `\Exception`: an `Error` is not an `Exception`, and one
  raised between the commit and the response would otherwise reach the SDK's own handler, which
  answers a fixed string carrying no class, no message and no reference — the failures that most
  need `MCPHelper::OpaqueFailure()`'s reference being the only ones never to get one. And
  `DBInsert()` commits before it returns, so catching around it was never the same as knowing
  nothing was written: **both creation paths ask the object instead**. iTop gives an unsaved
  object a deliberately negative temporary key, so a positive one means the row reached the
  database, and the call then answers as the success it is, reporting the id and attaching the
  failure under `warning`. `DBInsert()` commits and then reloads, so anything the second half
  raises — an after-write listener, a reload of external values — arrives with the row already
  written, and a batch that reports it as failed is one a caller repeats. `WritePlan::CommittedId()` asks the question and
  `WritePlan::AsId()` decides what counts as an id — a positive number, whether it arrives as an
  int or a string, since `DBInsertSingleTable()` assigns the key as `"$iNewKey"`. Update,
  delete, apply-stimulus and attach carry the widened catch and its reference, but not the
  commit check: what "did this one write" means differs per operation, and outside a creation it
  is not answerable from the key.
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
- **Bulk tools**: `core_object_bulk_create`, `core_object_bulk_update` and
  `core_object_bulk_delete`, up to 100 objects per call. The id list takes the ids a read
  reports, which are strings — iTop's key is one all the way out of the ORM, so passing back
  exactly what came out of a search is the obvious call and has to be the working one. Up to 100
  because past it a model has stopped acting on a list a person recognised; the search tools
  report `total` for the whole matching set beside the page they return, so the size of the work
  is one call with `limit=1` rather than a walk. They check `UR_ACTION_BULK_MODIFY` /
  `UR_ACTION_BULK_DELETE` before anything else, then check every object and every attribute
  individually — before writing anything, so a batch cannot fail on the eleventh object having
  already written ten. Dry runs by default, and each object is reported separately, because a
  call can partly succeed. They take ids, so the `total` they answer with counts what they were
  given and never the set behind a criterion; the search tools report that set's `total` beside
  the page they return, so one call with `limit=1` sizes the work before it is split.
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
- **The class schema says what the caller may do, on the class and on every attribute.**
  Describing what a class looks like and nothing about what this user may do with it leaves a
  model to find out by making the call and reading the refusal — a poor way to learn, because a
  model that meets a refusal often retries a variation of it rather than reporting it. iTop's
  console answers the same question by rendering a button or not, and a client has no buttons to
  read. `core_class_schema` and `itop://core/class/{class}` carry a `rights` block grading
  `read`, `bulkRead`, `create`, `bulkCreate`, `modify`, `bulkModify`, `delete` and `bulkDelete`,
  and every attribute carries the same grade under `modify`. Each is `yes`, `no` or `depends`,
  never a boolean: `UR_ALLOWED_DEPENDS` is a third answer meaning "ask again with the object",
  and a model told `yes` or `no` instead has been told something no addon said.

  **The two levels do not promise the same thing.** The class gate is what every write tool
  checks before it fetches anything, so `no` there is final — no object exists that could get
  past a check which raises first. `yes` means only that the call gets that far, with the silo,
  the lifecycle state and the datamodel's own `DoCheckToWrite()` still ahead of it. That
  asymmetry is the useful part: both halves are something a model can act on, where a symmetric
  "either way, who knows" would be neither.

  **`bulkCreate` is derived, not read.** iTop has no `UR_ACTION_BULK_CREATE`, so
  `core_object_bulk_create` gates on `UR_ACTION_CREATE` and `UR_ACTION_BULK_MODIFY` together and
  the block reports the stricter of the two — left to infer it, the reasonable conclusions are
  that the call is ungated or that it does not exist, and both are wrong. And an attribute's
  `modify` sits **beside** `readOnly`, not in place of it: `readOnly` is the datamodel refusing
  everybody, beyond any administrator's reach, while `modify` is this caller being refused
  something somebody can grant. Collapsed into one key a model can no longer tell them apart,
  and raises the wrong one of the two with the user.
- **The class schema separates what can be written from what cannot.** A stock `UserRequest`
  runs to around a hundred attributes, and most of what a class with many external keys carries
  is mechanical: iTop attaches a `_friendlyname` companion to every external key, and an
  `_obsolescence_flag` wherever the target can go obsolete. None of that is an answer to "what
  can I set". `core_class_schema` and `itop://core/class/{class}` return `attributes` for the
  ones that can be written and `derived` for the computed and structural ones. Nothing is
  dropped, and an entry has the same shape in both blocks — `readOnly` still on it — so a caller
  that wants them together merges the two and loses nothing. The split is `IsWritable()`, the
  datamodel's own answer, rather than a list of attribute class names: that list would go
  quietly wrong the first time iTop added a type to it.
- **An attribute can say how it is written.** An optional `writeHint`, and `AttributeCaseLog` is
  the first type to need one: it reads as the whole log and is written one entry at a time, so a
  type name alone left a model either sending the rendered log back as the new value or looking
  for an add-a-log-entry tool — which is task-shaped and belongs to a pack. The hint names both
  shapes iTop accepts, the plain string and `{"add_item": {"message": "..."}}`.
- **A read says what may be done to the object it just returned.** `core_class_schema` answers
  for a class, and answers `depends` wherever the addon wants to be shown the object first — a
  silo, or any rule graded per object. A read has the object, so it is the one place that
  question can be settled, and `core_object_get` settles it: `modify`, `bulkModify`, `delete`
  and `bulkDelete` answered for that row. Only a class-level `depends` costs a query; `yes` and
  `no` were answered without reference to any object and are carried straight through.

  The lifecycle has the same shape. The schema reports every transition of every state, and only
  the object knows which state it is in — so a model would have to find the state attribute,
  match it against the graph, and hope it picked the right one. A get reports the current state
  and the stimuli that state accepts, each graded by **both** gates `core_object_apply_stimulus`
  checks: `UR_ACTION_MODIFY` on the object and `UserRights::IsStimulusAllowed()`, stricter
  winning. A class with no lifecycle answers null rather than an empty list, since "no
  transitions from here" and "this class has no states" are different claims.

  `core_object_search_by_oql` and `_by_class` take `actions: true` for the same, capped at 25
  objects like `audit`. Still gates and still a snapshot: `DoCheckToWrite()`, a mandatory
  attribute the transition requires, or another user getting there first can each refuse a write
  this reports as available.
- **Reads say when an object was created and last changed, and by whom.** `core_object_get`
  carries an `audit` block always; `core_object_search_by_oql` and `core_object_search_by_class`
  take `audit: true` for it. There is no field behind this — iTop stamps neither on the object —
  so it is two indexed single-row reads of the change log per object. That is cheap once and
  unbounded over a page, so the search tools refuse `audit` for a page of more than 25 rather
  than serving it slowly. Gated by the object and by nothing else, as the console gates it — so
  anyone who can read the ticket can see who opened it and who last touched it.
- **`core_object_history`, and with it the answer to "who changed this".** An iTop object
  carries no creation or update stamp — `DBObject` declares neither — so the endpoint could
  otherwise report what a ticket says and never when it was opened, who last touched it, or why
  a value is what it is. That record is in the change log, which this reads: each operation with
  its date, its user, the attribute affected and the values before and after, newest first.

  It is a tool rather than a documented OQL query for two reasons. The log does not normalise
  itself — `oldvalue` and `newvalue` live on `CMDBChangeOpSetAttributeScalar`, while text, case
  logs, blobs and link sets each record differently, so a query against the parent returns rows
  with no values and one against the scalar subclass silently misses every case-log entry. And
  `CMDBChangeOp` is granted per profile *as a class*: the grant says nothing about the object a
  row points at, nor about the attribute it names, and `objkey` is an integer column. Read
  directly, it is a way around both silos and per-attribute rights. This applies the object gate
  and today's attribute rights to every row — today's, not the ones in force when the row was
  written, since a revocation that left the old value readable would be a revocation in name
  only. The object is the only gate, which is how the console gates it: `ActivityPanelHelper`
  reads these rows for whatever object is on screen and asks `UserRights` nothing about
  `CMDBChangeOp`. Rows are ordered by id rather than by date, because one change writes several
  of them carrying that change's timestamp — iTop's own query says so, and orders the same way.
  Case-log entries are included, which the console renders separately instead.

  Served as its own `history` toolset, with an `MCP-toolset-history` token scope, so an operator
  can withhold the change log without withholding object reads.
- **`core_class_list` narrows by what the caller may do.** `may=create` returns the classes this
  user is allowed to create, each with the full rights block, in one call. Without it the only
  way to answer "what can I create here" is `core_class_schema` once per class — several hundred
  calls on a stock datamodel, or a partial picture from however many an agent can afford, which
  is a poor basis for planning work it intends to carry out. A class the gate refuses is dropped;
  one graded `depends` is kept and says so, because that is the datamodel asking for the object
  rather than refusing the class. `may="*"` reports the block on every class and narrows on none,
  since asking for the rights and narrowing on one of them are otherwise the same request — which
  would drop the classes a gate refuses from the very answer meant to say so. The gates are the
  ones the tools actually check, taken from the same list the rights block is built from, so the
  schema's enum, the refusal message and the block cannot come apart. With no `may` no rights are
  read at all, so `itop://core/classes` costs what it always did.
- **`core_current_user`: the identity as a tool, and what this session can reach.**
  `itop://core/current-user` answers who the session is authenticated as, and a client that never
  fetches resources never asks: a model looks for a tool, finds none, and concludes the server
  cannot say — then either puts the question to the user or answers "my tickets" with somebody
  else's. The protocol puts a fact the model needs mid-task on the tool side of its own split,
  and the resource stays for clients that do browse. Both read through
  `Helper\CurrentUserReader`, so the two surfaces cannot drift: the contact is fetched by the id
  on the caller's own user record and with `AllowAllData`, the way iTop's own `FindUser()` loads
  an account outside its holder's silo, because an identity is not something a caller has to hold
  a right on to be told. The id can only ever come from `UserRights::GetContactId()` and never
  from the caller, and a unit test holds that invariant.

  It also carries an `access` block naming the toolsets served and the capabilities held.
  Without it, "this server has no such tool" and "I am not served that toolset" are the same
  observation from `tools/list`, and a model that cannot tell them apart states the first — it
  reports a capability as absent, and stops. Only what is served is named, never the catalogue:
  naming a withheld toolset would teach the caller the shape of the withheld surface, so what
  comes back never exceeds what `tools/list` already showed. Derived element by element through
  the same check registration uses, rather than from the policy's permitted list — empty there
  means "everything", and a toolset whose only element is disabled, or whose elements all
  require a profile this user lacks, is not one the caller can reach whatever the policy allows.
- **`core_object_find_by_name`**, the console's global search as a tool. Every other reading
  tool needs the class before it can do anything, and a question rarely arrives with one. It is
  iTop's own search — the same needle splitting and quoted phrase, the same
  `full_text_needle_min` floor, `MetaModel::GetClasses('searchable')` or `EnumChildClasses()`
  when a class is named, `AddCondition_FullText()` over every searchable scalar attribute, and
  the same leaf rule that stops one object being reported once per class in its ancestry. The
  rights are `core_object_get`'s, applied per object. `full_text_chunk_duration` bounds the scan
  and `truncated` says when it stopped early.
- **`core_object_get_related`**, for impact analysis, and it describes its own answer: a node map
  keyed `"Class::id"`, a flat list of `{from, to}` edges over those keys, and a per-class
  `summary` — a graph, not the tree its `depth` argument suggests.
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
- **`output_fields`** on the reading tools, spelled as iTop's REST API spells it. Searches
  default to `id, friendlyname`; `core_object_get` defaults to `*`.
- **`order_by` / `order_direction`** on both searches. OQL has no `ORDER BY`, so without them
  there is no way to ask for "the ten most recent" — and because every other query language has
  the clause, writing one anyway is the mistake that arrives. A query carrying it is refused with
  iTop's own parser message plus the sentence naming these two parameters, rather than with a
  token position and nowhere to go.
- **Paging is stable, and says when there is more.** Every page is ordered by the requested
  attribute and then by `id`, so an object cannot appear on two consecutive pages while another
  is never returned. `has_more` and `next_offset` come back on both searches, because a page can
  be shorter than `limit` when object-level rights removed rows from it, and a caller that reads
  a short page as the end of the set stops early and silently.
- **Attribute values are rendered with `GetForJSON()`, as the REST API renders them**, so case
  logs, link sets and attachments arrive as themselves rather than as `{}`. Case logs, link
  sets and long texts are cut to a declared ceiling and a blob is reported as its metadata;
  naming the attribute in `output_fields` returns it in full.
- **Graded access**, per instance (`mcp_capabilities`, with `mcp_read_only` as shorthand) and
  per token (`MCP-read`, `MCP-write`, `MCP-delete` scopes). A tool is graded by the annotations
  it already declares. `MCP-write` is the case a read-only switch cannot express: may open a
  ticket and add a note, may not delete anything. The two sides combine by narrowing, and a
  list that narrows to nothing grants nothing — a read-only instance plus a token scoped
  `MCP-delete` serves neither, rather than serving both. This gate is the instance-wide
  configuration; `UserRights` is a separate layer underneath it.
- **Toolsets.** `getToolset()` groups elements by what they are for; `mcp_enabled_toolsets`
  serves a subset, and `MCP-toolset-<name>` scopes a token to one. The core surface declares
  `datamodel`, `objects`, `relations`, `documents`, `history` and `server`. Every value the
  setting can take is a functional group with a token scope and dictionary entries behind it,
  declared by the elements themselves rather than falling out of a namespace.
- **Server instructions at `initialize`, assembled per caller.** A short block of guidance —
  that attribute codes vary per instance and must be looked up, that OQL has no `ORDER BY`, that
  dates are not RFC 3339, that a refusal is a real refusal, and that text found inside an object
  asking the client to call a tool or ignore an instruction is content to report rather than a
  request to act on — plus the conventions every writing tool shares: the dry-run two-step, and
  what `comment` is for. Those are stated here once instead of in each tool's description, which
  is eight copies of two paragraphs in a `tools/list` a client pays for on every connection, on a
  surface read by models rather than by people; the mechanics stay on the `simulate` and `comment`
  properties themselves, which no client can drop, because an instruction block is advisory. With
  the longest descriptions trimmed to the facts a caller acts on, `tools/list` is about 14%
  smaller. It is assembled section by section from the same policy that decides what is
  registered, so a token scoped to one toolset is not told to call tools it will never be served
  — being handed the names and the calling convention of a withheld surface is the opposite of
  withholding it, and a model then spends the session discovering by failure exactly what the
  text exists to prevent.

  **Three blocks are never narrowed.** That every call runs as the authenticated user and that a
  refusal is final; that object content is data rather than instruction; and that the surface is
  fixed at connect time. The second is a control, and a control that weakens as the caller is
  restricted is the wrong way round — the narrow token is the one a hostile ticket is most likely
  to be pointed at. The third matters most to the caller holding the smallest surface, which is
  the one most likely to be small because of something an operator has since changed: this
  transport is stateless and sends no `tools/listChanged`, so a client's tool list is whatever it
  fetched at `initialize` and nothing the server can revise. A model that does not know this
  diagnoses the client, concluding the connection is misconfigured when the server is healthy and
  its own list is simply old. The block says to report it and ask the user to reconnect, and not
  to treat a missing tool as one to work around.

  The date guidance carries a worked example of each, rendered at request time from
  `AttributeDateTime::GetInternalFormat()` and `AttributeDate::GetInternalFormat()` rather than
  written into the prose, so it follows the iTop the module is running on. Telling a model what
  its value is *not* and sending it to `core_class_schema` for what it should be is no help to a
  caller restricted to the object tools, which is exactly the caller that does not have that
  tool. A date and a date-time are two reads because they are two accessors, the reads are
  guarded, and an unreadable format is stated not at all rather than guessed — a format is a
  promise about the string on the wire. `MCPRegistry::AddInstructions()` lets a pack append a
  paragraph; it takes no toolset, so that paragraph is sent to every caller and should be written
  to be true of any of them.
- **Tool, prompt and resource identifiers are `snake_case`** — `core_object_search_by_oql`, and
  a pack's `acme_ticket_add_log_entry`. The name is derived from the class name, so a pack
  author writes none of them by hand. This is the shape every other MCP server in the ecosystem
  uses, and it is the string a client configuration and an `mcp_disabled_tools` entry name.
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
- `WWW-Authenticate: Bearer` on a `401`, carrying RFC 9728 `resource_metadata` when
  `mcp_protected_resource_metadata` names the document an OAuth proxy serves.
- `mcp_pagination_limit`, defaulting to 200 and set on every request, so a client that ignores
  `nextCursor` cannot silently miss the 51st tool to the SDK's own default.
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
- **`@api` on the classes a tool pack may depend on**, beside the `@since` they carry. The
  surface semver applies to is defined by the tags, and `grep -rl '@api' src/` answers it in
  whatever copy you have rather than sending you to a list in a document that could fall
  behind — a count written here would be the same failure in miniature.
  `AbstractObjectSearch` and `AbstractBulkTool` are on it, under `Abstract\`: they are the parts
  of this module most worth extending.
- **The documentation ships inside the archive** — [doc/extending.md](doc/extending.md) for
  whoever builds a tool pack, [doc/security-summary.md](doc/security-summary.md) for whoever
  has to answer a security or procurement questionnaire, [doc/clients.md](doc/clients.md) for
  connecting one, [CONTRIBUTING.md](CONTRIBUTING.md) with the project's AI-assistance policy,
  and [SECURITY.md](SECURITY.md) with a disclosure channel, a response commitment and a
  declared support period of five years of security fixes from a minor's release date, with six
  months' notice to end one early.

### Security

- **The classes that decide what this endpoint may do cannot be written through it.** Four gates
  decide a request, and three of them — the profile gate, the instance capability grading,
  `UserRights` — hang off the user or the configuration, where no tool can reach them. The token
  scope is the exception: it is an ordinary attribute on an ordinary `DBObject`, and it is the
  only one of the four that grades a single credential rather than everyone holding it. That is
  what an operator buys when they issue an administrator an `MCP-write` token instead of
  maintaining a second user account, and a caller able to write `PersonalToken` would keep that
  bargain only by choosing to: it could widen its own scope to `MCP`, or — without touching the
  row it authenticated with — mint a fresh token that already held it. Writing `User` or a
  `URP_` link does the same thing one layer up, by granting the profiles the scope was
  narrowing.

  `PersonalToken`, `UserToken`, `User`, iTop's `URP_*` rights classes and **everything
  descending from any of them** are refused by `core_object_create`, `core_object_update`,
  `core_object_delete`, `core_object_attach`, `core_object_apply_stimulus` and the three bulk
  tools, whatever the caller's iTop rights say. Descendants are matched with `is_a()` rather
  than by name, so a datamodel extension declaring `<parent>User</parent>` is covered and not a
  way round; the `URP_` prefix is matched as well, so a rights class a later iTop adds is
  covered the day it ships.

  **The names are a floor, not the whole rule.** This is a part of iTop that moves — personal
  tokens arrived in 3.1, application tokens after them, and whatever grades a credential in 4.x
  has no name that can be written down today — so the datamodel is asked as well, about the
  class in front of it rather than about a list. A class declaring a `scope` attribute that can
  hold an `MCP*` value grades this endpoint and is refused on that basis alone. So is a class
  carrying an `AttributeOneWayPassword` — the type that stores a salted hash and nothing else,
  so its value can only ever be compared against, never read back and replayed. That is what
  makes it a credential *into iTop* rather than a secret an object happens to hold: every
  outbound credential has to be recoverable to be used, so it lives in `AttributePassword` or
  `AttributeEncryptedString` instead, as iTop's own OAuth client secret and webhook password do.
  Those recoverable types are deliberately not matched — a mailbox password on a mailbox or a
  login on a CI is ordinary object data, and refusing to write it would be an unrelated
  restriction wearing this one's name. So is a class iTop files under its `addon/userrights`
  category, which every `URP_` class carries.

  The three are a union with the names, which only ever adds: a MetaModel that cannot be read,
  or a category a later version renames, costs the dynamic half and leaves everything the names
  cover still refused. Written the other way round, an instance whose datamodel failed to load
  would open every one of these classes at once.

  iTop's own `addon/authentication` category would have been the obvious signal and is not used:
  `SynchroDataSource` carries it, and a synchro source is not a credential.

  **Reading is deliberately untouched.** A caller listing its own tokens learns nothing it did
  not arrive with — the secret is not readable once minted — and an assistant that can report
  "this token expires on Friday" is worth having. Every read stays gated by `UserRights`
  exactly as before.

  This is narrower than "an administrator can do anything anyway", which is true and is not the
  point: an administrator reaching these classes through the console was always out of scope,
  while an administrator reaching them through a credential minted to be narrow is precisely
  the escalation the scopes exist to prevent.
- **`mcp_allow_access_administration` lets an instance opt into that administration — for other
  people, never for the caller.** Onboarding a user and retiring somebody's leaked token are
  ordinary administration, and an operator who wants an assistant doing them needs a way to say
  so. The setting is `false` by default and an instance that never heard of it gets the refusal
  above.

  Turned on, one rule survives and no configuration lifts it: **a call that reaches the access
  the caller is connected with is refused.** Not the token it authenticated with, not another
  token of its own, not its own account, not a profile link naming it, and not a grant on a
  profile it holds. Without that, opting in would hand back the whole escalation — widen the
  scope of the token in your hand, or mint a second one that is wider, and the narrow credential
  an operator issued was never narrow.

  The guard is asked of the **row**, because which verb is cheapest depends on the row: a create
  carries its owner in the values it is given, an update can re-point someone else's token at the
  caller, and deleting the credential in use is the self-harm iTop's console already refuses. It
  refuses whenever it cannot tell — no login, a row that will not load, a create naming no owner,
  which is a create for the caller since iTop's own controller fills that field in. Being wrong
  the other way is the escalation; being wrong this way is a refusal an administrator satisfies
  from the console.

  It is iTop's own rule, moved one layer out. The console will not let a user delete themselves,
  strip their last profile, or demote themselves out of being able to come back — `User` checks
  all three in `DoCheckToWrite()` and `DoCheckToDelete()`. This applies the same idea to the
  credential rather than the session, because a credential is what a caller of this endpoint
  holds. Manage your own token, account and profiles in the console.
- **The change log has one way in.** `CMDBChange` and `CMDBChangeOp` are ordinary `DBObject`s,
  so every generic tool would otherwise treat them as ordinary objects — and reach the rows
  without the object gate and the per-attribute gate `core_object_history` applies one layer
  above them. `objkey` is an integer column, so `SELECT CMDBChangeOpSetAttributeScalar` would be
  a readable audit trail of every object in the database, silos and attribute rights included;
  a create on one would forge an audit record. Every object, document and relation tool refuses
  these classes and their subclasses, naming the way in. The console draws the same line:
  history is a tab on an object, never a class you search or a form you fill. Held by a test
  that derives the tools it covers from their toolsets, so one registered later is covered the
  day it appears.
- **Reading a set needs the bulk right, including through the relation graph.** The three
  reading tools that return a set check `UR_ACTION_BULK_READ` on the class before returning
  anything, and `core_object_get_related` applies the same rule to the graph it walks, per class
  and from the second object of that class onward. One related object is a single read and stays
  one, so the ordinary "what does this depend on" answer still works for a caller holding only
  `UR_ACTION_READ`. Without that rule, a credential deliberately issued without the bulk right
  reaches, through one readable object plus a relation and a depth, the objects
  `core_object_search_by_class` has just refused it: "this assistant may not sweep the CMDB" does
  not survive contact with the relation graph. It is stricter than iTop's own console, which
  gates impact analysis on read alone, and deliberately so — the console is a person clicking one
  screen, this is a credential that can walk every relation on every object it can reach.
  **Operators:** a token whose profile has read but not bulk read on a class gets relation walks
  over that class truncated, with a note. Grant bulk read on it to restore them.
- **A read that rights truncated says so.** A walk spans classes an account is graded
  differently on, and refusing the whole answer because one of them needs a right the others do
  not throws away everything the caller is entitled to — so the rest is returned and the
  response names what was held back. `core_object_get_related` carries a `withheld` block naming
  the classes and saying why, absent entirely when nothing was, and drops the relations touching
  a withheld object with it, because an edge pointing at an object the payload no longer carries
  is how a partial graph reads as a complete one. `core_object_find_by_name` carries
  `classes_withheld` for the same reason: dropped silently, a caller with no bulk read anywhere
  gets `total: 0` with no error, which a model reads as "there is no such object" and passes to
  the user as fact. The note says in as many words that this is a statement about the account's
  rights and not about what exists, and that another route to the same objects is not to be
  looked for.

  The classes are named and never counted. The names describe this account's rights on classes
  it already holds read on — the answer only ever contained objects the ORM let it see — whereas
  a count would be the datum the bulk right is withholding, which is the oracle
  [SECURITY.md](SECURITY.md) closes. Naming a class the caller may not read stays
  indistinguishable from naming one that does not exist, and a unit test holds that apart.
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
  a browser renders under the instance's own origin. The bytes decide, `core_object_attach` says
  so in `mimetype_note`, and inert text formats libmagic has no signature for (CSV, Markdown,
  YAML) are honoured as declared. Reading sniffs nothing: it returns the corrected type that was
  stored.
- **An attribute's allowed values are keyed by the code a caller has to send.** iTop answers
  code => label, and for most attributes the codes are strings — but a stopwatch sub-item is keyed
  `[0 => label, 1 => label]`, which PHP calls a list and `json_encode` strips the keys from, so
  `sla_tto_passed` would arrive as `["no","yes"]`: the labels, resolved through the dictionary, with
  the `0` and `1` the column holds gone, and `["non","oui"]` on a French instance. Nothing writes a
  sub-item, so no write path was affected; a search is, since `WHERE sla_tto_passed = 'no'` filters
  on a string the database never stores. One shape now, for every attribute.

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
