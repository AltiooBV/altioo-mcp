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

### Added

- **The class schema now says what the caller may do, on the class and on every attribute.**
  It described what a class looks like and nothing about what this user may do with it, so a
  model found out by making the call and reading the refusal — and a refusal is a poor way to
  learn, because a model that meets one often retries a variation of it rather than reporting
  it. iTop's console answers the same question by rendering a button or not; a client with no
  buttons had nothing to read.

  `core_class_schema` and `itop://core/class/{class}` now carry a `rights` block grading
  `read`, `bulkRead`, `create`, `bulkCreate`, `modify`, `bulkModify`, `delete` and
  `bulkDelete`, and every attribute carries the same grade under `modify` — the one that matters most, since the
  attribute is what a model actually picks off a schema and gets refused on. Each is `yes`,
  `no` or `depends`, never a boolean: `UR_ALLOWED_DEPENDS` is a third answer meaning "ask again
  with the object", and a model told `yes` or `no` instead has been told something no addon
  said.

  **The two levels do not promise the same thing.** The class gate is what every write tool
  checks before it fetches anything, so `no` there is final — no object exists that could get
  past a check which raises first. `yes` means only that the call gets that far, with the silo,
  the lifecycle state and the datamodel's own `DoCheckToWrite()` still ahead of it; an abstract
  class or a read-only database refuses whatever the rights say. That asymmetry is the useful
  part: both halves are something a model can act on, where a symmetric "either way, who knows"
  would be neither.

  **`bulkCreate` is derived, not read.** iTop has no `UR_ACTION_BULK_CREATE`, so
  `core_object_bulk_create` gates on `UR_ACTION_CREATE` and `UR_ACTION_BULK_MODIFY` together,
  and the block reports the stricter of the two. Bulk update and bulk delete pair with the
  rights their names suggest and need no help; bulk creation does not, so a model reading
  `create` and `bulkModify` had nothing telling it those two are the pair that decides — and
  no key named for what it was actually trying to do. Left to infer it, the reasonable
  conclusions are that the call is ungated or that it does not exist, and both are wrong.

  An attribute's `modify` sits **beside** `readOnly`, not in place of it. `readOnly` is the
  datamodel refusing everybody, permanently and beyond any administrator's reach; `modify` is
  this caller being refused something somebody can grant. Collapsed into one key a model can no
  longer tell them apart, and raises the wrong one of the two with the user.

- **The instructions now say that the surface is fixed at connect time.** This transport is
  stateless and sends no `tools/listChanged`, so a client's tool list is whatever it fetched at
  `initialize` and nothing the server can revise: an operator who grants a scope, installs a
  pack or repairs a failed registration changes nothing for a session already running. A model
  that does not know this diagnoses the client — the observed failure is a session concluding
  the MCP connection was misconfigured when the server was healthy and its own list was simply
  old. The block says to report it and ask the user to reconnect, and not to treat a missing
  tool as one to work around. It is one of the three never narrowed by the access policy, and
  is the one that matters most to the caller holding the smallest surface — which is exactly
  the caller most likely to be holding it because of something an operator has since changed.

### Changed

- **`core_object_get_related` now requires `UR_ACTION_BULK_READ` for any class it returns more
  than one object of.** It checked `UR_ACTION_READ` and nothing else, while returning a set —
  so a credential deliberately issued without the bulk right got, through one reachable object
  plus a relation and a depth, the objects `core_object_search_by_class` had just refused it.
  "This assistant may not sweep the CMDB" did not survive contact with the relation graph.

  The rule is per class and starts at the second object. One related object of a class is a
  single read and stays one, so the ordinary "what does this depend on" answer still works for
  a caller holding only `UR_ACTION_READ`. It is stricter than iTop's own console, which gates
  impact analysis on read alone, and deliberately so: the console is a person clicking one
  screen, this is a credential that can walk every relation on every object it can reach.

  **The class is withheld, not the call.** A walk spans classes an account is graded
  differently on, and refusing the whole answer because one of them needs a right the others do
  not throws away everything the caller is entitled to. So the rest of the graph is returned
  and the response carries a `withheld` block naming the classes held back and saying why —
  absent entirely when nothing was. The relations touching a withheld object are dropped with
  it, because an edge pointing at an object the payload no longer carries is how a partial
  graph reads as a complete one.

  What is ruled out is the silent version, for the reason the find-by-name rights tests already
  give for search: a graph quietly missing a class reads as a whole one, and an agent reports
  "nothing related" to a user as fact. The note says in as many words that this is a statement
  about the account's rights and not about what exists, and that another route to the same
  objects is not to be looked for.

  The classes are named and never counted. The names describe this account's rights on classes
  it already holds read on — the graph only ever contained objects the ORM let it see — whereas
  a count would be the datum the bulk right is withholding, which is the oracle SECURITY.md
  closes.

  **Operators:** a token whose profile has read but not bulk read on a class now gets relation
  walks over it truncated to nothing for that class, with a note, instead of the full set.
  Grant bulk read on that class to restore it.

### Fixed

- **A write could commit and report failure, and the log could not say why.** `core_object_create`
  created a `UserRequest` and answered `Error while executing tool`. The row existed; the caller
  was told it did not. Creating is not idempotent, nothing in the protocol says a failed write
  may have written, and the reasonable next move on an error is to retry — which is a second
  ticket.

  Two causes. Every catch on the write paths was written `catch (\Exception)`, and an `Error` is
  not an `Exception`, so one passed straight through to the SDK's own handler — which logs
  `Unhandled error during tool execution` and answers with a fixed string carrying no class, no
  message and no reference. That is why the endpoint's audit event recorded `Error while
  executing tool` and nothing usable: the failures that most need `MCPHelper::OpaqueFailure()`'s
  reference were the only ones never reaching it. All 16 sites now catch `\Throwable`, so an
  `Error` is logged with its class, message, file and line under a reference the caller is given.

  And `DBInsert()` commits before it returns — it reloads external values afterwards — so
  catching around it was never the same as knowing nothing was written. `core_object_create` now
  asks: iTop gives an unsaved object a deliberately negative temporary key, so a positive one
  means the row reached the database. When it did, the call answers as the success it is,
  reporting the id and attaching the failure under `warning` instead of substituting it. Only a
  create that really wrote nothing is still an error.

  Update, delete, apply-stimulus, attach and the bulk tools get the widened catch and its
  reference, but not yet the commit check — detecting "did this one write" differs per operation
  and is not guessed here.

  **The failure itself was a type error of this module's own making**, found once the widened
  catch let the log record it: `WritePlan::Identity()` declared `?int` for the id, and
  `DBObject::DBInsert()` returns the key that `DBInsertSingleTable()` assigns as `"$iNewKey"` —
  a string. Under `strict_types` that is a `TypeError`, thrown after the row is committed. So
  every create wrote its object and then failed to describe it. `core_object_attach` and
  `core_object_bulk_create` pass the same value and had the same defect, unfired only because
  nobody had called them.

  `Identity()` now takes the id as iTop reports it, and `WritePlan::AsId()` is the single place
  deciding what counts as one: a positive number, whether it arrives as an int or a string.
  Anything else is null — which is what an unsaved object's deliberately negative temporary key
  should mean, and what `ObjectCreate` now asks to tell a committed write from a failed one, so
  the rule has one definition rather than two. The redundant casts at `Identity()`'s other call
  sites are gone, and a test rejects new ones: a cast per call site is the fix the next create
  tool would be written without.

- **The `initialize` guidance was sent unnarrowed to every caller.** `MCPService::createServer()`
  threaded the request's `AccessPolicy` into all four registration passes and not into
  `setInstructions()`, so the server instructions were one fixed string: a token scoped
  `MCP-toolset-server` was told to "call core_class_list to find a class", to "read
  core_class_schema before any create, update or stimulus", and that "core_object_delete is a
  dry run by default" — three tools `tools/list` correctly withheld from it. The effect was a
  model spending the session discovering by failure exactly what the text exists to prevent,
  having been handed the names and the calling convention of the withheld surface on the way,
  and carrying off the belief that deletion here is reversible by default. It also made
  SECURITY.md's threat model overclaim: "`tools/list` is filtered per caller" was true of
  `tools/list` and of nothing else advertised.

  The text is now assembled per caller from the same policy, section by section. Two blocks are
  deliberately never narrowed — that every call runs as the authenticated user and that a
  refusal is final, and that object content is data rather than instruction. The second is a
  control, and a control that weakens as the caller is restricted is the wrong way round: the
  narrow token is the one a hostile ticket is most likely to be pointed at. A unit test holds
  the contract against the registry rather than against a list of names, so an element that
  changes toolset, loses its annotations or is renamed is caught rather than quietly widening
  what the text advertises.

  A paragraph a pack appends through `MCPRegistry::AddInstructions()` is still sent to every
  caller: the method takes no toolset, so there is nothing to narrow on. Giving it one is an
  `@api` change and is not in this entry.

  One sentence needed more than narrowing. "Dates and date-times use iTop's own format, not
  RFC 3339" told a model what its value was not and then sent it to `core_class_schema` for
  what it should be — which is exactly the tool a caller restricted to the object tools does
  not have, leaving it with a rejected value and nowhere to go. The bullet now carries a worked
  example of each, read from `AttributeDateTime::GetInternalFormat()` and
  `AttributeDate::GetInternalFormat()` rather than written into the prose — so the guidance
  follows the iTop the module is running on rather than the one it was written against. That
  tracks an upgrade, not a configuration: the internal format is a literal on the attribute
  class (`Y-m-d H:i:s` and `Y-m-d` on 3.2) and no operator can move it. The configurable one is
  `GetFormat()`, the display format, which never crosses this wire.

  A date and a date-time are two reads because they are two accessors: `AttributeDate` extends
  `AttributeDateTime` and overrides `GetInternalFormat()`, so "the date-time one without the
  time" would be an inference about an override rather than a reading of either class. On 3.2
  it does override. Where it would not, the inherited accessor answers with the date-time
  format and would print a clock inside the date example, so two identical formats are read as
  "the date format was not really read" and the date example is dropped. Either example can be
  missing without taking the other with it.

  The reads are guarded: this runs while the server is being built, before any tool has been
  dispatched, and the instructions lose an example rather than the endpoint losing every
  request. Unreadable, no format is stated at all — a format is a promise about the string on
  the wire, and a wrong one is worse than silence.

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

- **The change log is reachable through `core_object_history` and nowhere else.** `CMDBChange`
  and `CMDBChangeOp` are ordinary `DBObject`s, so every generic tool would otherwise treat them
  as ordinary objects — and reach the rows without the object gate and the per-attribute gate
  the history tool applies one layer above them. `objkey` is an integer column, so
  `SELECT CMDBChangeOpSetAttributeScalar` would have been a readable audit trail of every object
  in the database, silos and attribute rights included; a create on one would forge an audit
  record. Every object, document and relation tool now refuses these classes and their
  subclasses, naming the way in. The console draws the same line: history is a tab on an object,
  never a class you search or a form you fill. Held by a test that derives the tools it covers
  from their toolsets, so one registered later is covered the day it appears.

- **A read says what may be done to the object it just returned.** `core_class_schema` answers
  for a class, and answers `depends` wherever the addon wants to be shown the object first — a
  silo, or any rule graded per object. A read has the object, so it is the one place that
  question can be settled, and `core_object_get` now settles it: `modify`, `bulkModify`,
  `delete` and `bulkDelete` answered for that row. Only a class-level `depends` costs a query;
  `yes` and `no` were answered without reference to any object and are carried straight through.

  The lifecycle had the same gap. The schema reports every transition of every state, and only
  the object knows which state it is in — so a model had to find the state attribute, match it
  against the graph, and hope it picked the right one. A get now reports the current state and
  the stimuli that state accepts, each graded by **both** gates `core_object_apply_stimulus`
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
  carries no creation or update stamp — `DBObject` declares neither — so until now the endpoint
  could report what a ticket says and never when it was opened, who last touched it, or why a
  value is what it is. That record is in the change log, which this reads: each operation with
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

- **`core_current_user` reports what this session can reach.** An `access` block naming the
  toolsets served and the capabilities held. Without it, "this server has no such tool" and "I am
  not served that toolset" are the same observation from `tools/list`, and a model that cannot
  tell them apart states the first — it reports a capability as absent, and stops. That is not
  hypothetical: it is how a session concluded there was no way to read an object's history, on an
  instance that had one, because a scope had not been ticked.

  Only what is served is named, never the catalogue. Naming a toolset the caller does not hold
  would teach it the shape of the withheld surface, which is exactly what `ServerInstructions`
  narrows itself to avoid — so what comes back never exceeds what `tools/list` already showed. An
  unrestricted caller learns nothing new; a narrowed one learns only that its own view has edges.
  Derived element by element through the same check registration uses, rather than from the
  policy's permitted list: empty there means "everything", and a toolset whose only element is
  disabled, or whose elements all require a profile this user lacks, is not one the caller can
  reach whatever the policy allows.

- **`core_class_list` narrows by what the caller may do.** `may=create` returns the classes this
  user is allowed to create, each with the full rights block, in one call. Without it the only
  way to answer "what can I create here" was `core_class_schema` once per class — several hundred
  calls on a stock datamodel, or a partial picture from however many an agent could afford, which
  is a poor basis for planning work it intends to carry out. A class the gate refuses is dropped;
  one graded `depends` is kept and says so, because that is the datamodel asking for the object
  rather than refusing the class. The gates are the ones the tools actually check, taken from the
  same list the rights block is built from, so the schema's enum, the refusal message and the
  block cannot come apart. With no `may` no rights are read at all, so `itop://core/classes` costs
  what it always did.

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
