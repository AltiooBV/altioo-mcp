# 0015 — 2.0 lays out `src/` as iTop does: Combodo's layer folders, features inside, one-way dependencies

- Date: 2026-11-11
- Decided by: proposed, for Guy Couronne to accept or amend
- Status: proposed
- Amended: 2026-11-11. The first version's layer order was checked against the code with
  `LayerDependencyTest` and was wrong in five places. This version corrects it; see
  "What the measurement changed".

**Context.** The architecture review of 2026-11-10 found that the code works and is well tested,
but that someone new to it cannot find their way around. When checked against PHP and iTop
conventions, the cause turned out to be how the folders are used, not what they are called. All
figures below were measured at 1.3.0.

- **`Helper/` holds most of the logic.** It has 25 classes and 11,800 lines, including the
  1,915-line barrier on access-granting classes. In Combodo's modules, `Helper/` holds small
  utilities: `authent-token` keeps a config reader, a logger and one helper there. `Service/`
  holds both the request pipeline and the portal's policy. Five `*Policy` classes are split
  across those two folders.
- **The folders depend on each other in a cycle.** `Helper` imports `Service` 13 times, and
  `Service` imports `Helper` 30 times. `Service` and `Server` import each other.
  `Service\PortalAccess` and `Helper\WritePlan` each import a tool. So no part can be read or
  changed without the rest.
- **Six classes do several jobs each:** `AccessGrants` (1,915 lines), `WritePlan` (1,723),
  `MCPController` (1,421), `DatamodelReader` (1,331), `PortalPolicy` (1,254) and `MCPHelper`
  (897). `WritePlan`'s own docblock says it does "two things", and it has 29 public methods.
- **35 classes are marked `@api`**, almost all of `Helper/`. That makes every restructuring a
  breaking change. Yet the documented pack (`doc/example-pack`) uses 13 classes, and
  `doc/extending.md` names about as many.

The module is 91 files and 30,000 lines. That is several times any Combodo module: the largest
in iTop 3.2.2's `datamodels/2.x` keeps every file under about 600 lines. It is closer to iTop's own
`sources/`, which has 333 files. Nobody has installed the extension yet, so 2.0 can break that
surface once, cleanly, instead of carrying deprecations.

**What iTop does.** Checked in the iTop 3.2.2 source:

- **Combodo's modules** use layer folders under `src/`. Across `datamodels/2.x` these are
  `Service/` (9 modules), `Controller/`, `Helper/` and `Hook/` (5 each), `Model/` (3) and
  `Exception/`.
- **iTop's core** uses the same layer folders at the top and feature folders inside them:
  `sources/Service/Events/`, `Service/Notification/`, `Service/Router/`,
  `Controller/Notifications/`, `Controller/OAuth/`.
- **The portal** follows the Symfony bundle layout, which is the same idea.

An iTop developer opening this module should find that shape.

**The decision.** 2.0.0 reorganises `src/` under these rules:

1. **Combodo's layer folders at the top, one feature folder per responsibility inside them.**
   Each folder keeps the meaning Combodo gives it. The target:

   | Folder | Holds | From |
   |---|---|---|
   | `Controller/` | The endpoint. Each gate a request passes is one class under `Controller/Gate/`, run from one ordered list. Also CORS and the response. | `Controller\MCPController`, the response half of `Helper\MCPHttp` |
   | `Hook/` | What iTop calls into: the background process | `Service\AuditHousekeeping` |
   | `Service/Server/` | Building the MCP server from the registry for one request; the session | `Service\MCPService`, `Server\Session\` |
   | `Core/` | The core tools, resources, templates and prompts. They stay where they are, like mcp/sdk's `Capability/`. It also gets the server instructions, which two of its elements serve. | `Core\`, `Server\ServerInstructions`, `MCPService::InstructionsFor()` |
   | `Service/Access/` | The question every tool asks about an object: may this caller see it, change it, set this field? It answers by the console's rules; the portal's subclass is in `Service/Portal/`. It also holds the rights half of reading a document. | `Service\ObjectAccess`, `DocumentAccess::Fetch()` as `DocumentFetch` |
   | `Service/Write/` | Turning a caller's fields into a checked write: value conversion, the rules a value must pass (`Edit`, `Mention`, `Formula`), the dry run, the deletion plan | `Helper\WritePlan` split, `EditPolicy`, `MentionPolicy`, `FormulaPolicy`, `RestValue` |
   | `Service/Portal/` | The portal: its policy, its object access (which specialises `Access`), its description, the row lookup its lists use | `Service\Portal*` except `PortalMode`, `PortalList::RowOf()` |
   | `Service/Read/` | Reading objects out: queries and the OQL check, serialisation, history, documents, the current user, the datamodel description | `Helper\ObjectQuery`, `ObjectSerializer`, `ObjectHistory`, `DocumentAccess`, `CurrentUserReader`, `DatamodelReader`, `ObjectSearchByOQL::CheckQuery()` |
   | `Service/Rights/` | The rules everything above asks: the token scopes, the access policy and what the request is served, the barrier on access-granting classes, the exclusions, the secrets, the rights grading. Named after iTop's `UserRights`. | `Service\TokenScopes`, `AccessPolicy`, `ExcludingModelReflection`, `Helper\AccessGrants`, `ExclusionPolicy`, `SecretPolicy`, `MCPService::ServedAccess()`, the grading now in `DatamodelReader` |
   | `Service/Audit/` | The audit trail: retention, change and read summaries | `Service\AuditRetention`, `ChangeSummary`, `ReadSummary` |
   | `Helper/` | Small stateless utilities and per-request facts only, as in Combodo's modules: settings, logging, JSON encoding, error references, dictionary lookup, the portal-mode flag, identifiers, the id normaliser, the request's token and host | `Helper\Settings`, `MCPLog`, `LogAPILogger`, `JsonPayload`, `MCPContext`, `Identifier`, `Service\PortalMode`, `WritePlan::AsId()`, the request half of `MCPHttp`, the rest of `MCPHelper` |
   | `Model/` | Value objects, written in the singular as Combodo writes it | `Models\MCPResult` |
   | `Exception/` | No change | |
   | `Api/` | What a pack extends or calls: the abstract elements, the provider interface, the registry and the extension collector, tool and resource output, the change reason, the version check, `Testing\ElementContract`. iTop has no equivalent; it is the one folder of our own. | `Abstract\`, `Contract\`, `Registry\`, `Testing\`, `Helper\ToolOutput`, `ResourceOutput`, `ChangeTracking`, the version check in `MCPHelper` |

2. **Dependencies point one way**, from top to bottom, one chain:
   1. `Controller`, `Hook`
   2. `Service\Server`
   3. `Core`
   4. `Service\Portal`
   5. `Service\Write`
   6. `Service\Access`
   7. `Service\Read`
   8. `Service\Rights`
   9. `Service\Audit`
   10. `Helper`, `Model`, `Exception`, which may import each other

   Any layer may import `Api`. `Api` imports `Service\Write` and everything below it, and is
   imported by `Core`, which it does not import back. Nothing below `Core` imports a tool: a check
   a tool shares moves down into the layer that uses it, and the tool keeps calling it visibly in
   its own source (ADR 0014). `ObjectSearchByOQL::CheckQuery()` moving to `Service\Read` is a check
   of that kind, so the contract tests are taught its new name in the same commit.
   **`LayerDependencyTest` enforces the order.** It reads each file's imports and same-namespace
   references from the PHP tokens, and it adds no dependency.

3. **`@api` covers what a pack needs and nothing else:** everything under `Api/`, plus a short,
   named list of the `Service\Read` and `Service\Write` classes the example pack calls
   (`ObjectQuery`, `ObjectSerializer`, `RestValue`, and the pack-facing half of `WritePlan`).
   A unit test holds that list, so it grows only by a deliberate change. Everything else is
   `@internal` and can be restructured in a minor release. A pack calls those classes directly;
   no facade wraps them. `doc/extending.md` and `doc/example-pack` are rewritten against this
   surface, and the example pack's suite is the proof that it is enough.

4. **A class does one job.** This is a guide, not a law: a file well past 500 lines is a split
   waiting to happen. Combodo's module files stay under about 600. The six classes above are
   split along the jobs they already do; the code is moved, not rewritten.

5. **What stays.**
   - Static helpers and static per-request state, as iTop itself uses them (PHP is
     share-nothing).
   - `execute()` bound by parameter name, with `: mixed`.
   - Combodo's coding conventions.
   - ADR 0014: each tool's security checks stay in its own source, and the contract tests follow
     the files when they move.
   - ADRs 0006 and 0009 to 0013. This decision changes where code lives, never what it decides.

6. **The 1.3 deprecations go:** the `MCPHelper` settings stubs, `MCPHelper::__construct()` and
   `ChangeTracking::Compose()`. Moved classes get no aliases: a 2.0 pack is written against the
   2.0 names. The changelog carries a table from each old name to its new one.

**What the measurement changed.** The first version of this record was checked by running
`LayerDependencyTest` on the 1.3.0 code. Five things in it were wrong.

- **Portal and Read are not independent.** The portal reads objects through `ObjectHistory`,
  `DocumentAccess` and `DatamodelReader`, as it should. So the order is one chain, with Portal
  above Read.
- **"Access" was two levels.** The scopes, policy, barrier and exclusions sit below Read, which asks
  them. They are now `Service\Rights`.
- **Access is not above Portal.** The second version put `ObjectAccess` above Portal because it
  chose between the console's rules and the portal's. It is the other way round. `ObjectAccess` is
  the console's rules and a base class; `PortalAccess` extends it, so Portal is above Access. The
  choice moved up with it: `ObjectAccess::Current()` answers with a factory that the request's
  setup sets (`PortalAccess::Enter()`, called by the controller when it admits a portal user),
  and with the console's access when none was set. `WritePlan` asks `ObjectAccess` whether a
  referenced object is readable, so Write is above Access, and `ObjectAccess` reads through
  `ObjectQuery`, `ObjectSerializer` and `ObjectHistory`, so Access is above Read. Portal imports
  nothing from Write. So the order is Portal, Write, Access, Read.
- **A document is read in two halves.** `DocumentAccess` asks `ObjectAccess` for rights, and
  `ObjectSerializer` (Read) describes a blob through `DocumentAccess`. Kept whole it would make
  Read import Access. So `Fetch()` and the rights it checks moved to `Service\Access\DocumentFetch`,
  and the URI, the ceiling, the type check and the content stay in `Service\Read\DocumentAccess`.
- **Some "feature" classes are bottom-level facts.** `PortalMode`, `Identifier`, `AsId()` and the
  request's token and host are asked by every layer. They go to `Helper`.
- **Packs call the registry.** The example pack registers through `MCPRegistry` and
  `MCPExtensionCollector`, so they are `Api`. Leaving them in `Registry/` made a cycle with the
  abstract elements.
- **`@api` cannot be `Api\` alone.** The example pack calls Read and Write classes directly.

**How it lands.** All on one branch, `refactor/2.0`, one step per commit or small group of
commits. Each step runs the local gates before the next one starts:
- **every step:** the unit suite, lint and Psalm, on PHP 8.2 and 8.4;
- **steps that move or split code:** also the iTop matrix;
- **the last step:** the full matrix.

One pull request at the end. Because the local runner runs the same scripts as CI
(`tools/ci/local/`), a green local run is the CI result, and a pull request per step would only
repeat it.

1. `LayerDependencyTest`, with today's violations as a baseline that can only shrink.
2. Move-only steps, one area at a time, in this order: `Helper`/`Model`/`Hook`, `Service/Audit`,
   `Service/Rights`, `Service/Read`, `Service/Portal`, `Service/Write`, `Service/Access`,
   `Service/Server` and `Core`'s instructions, `Api`. Namespaces and files change; logic does not.
   The source-contract tests are updated in the same commit: 36 of the 79 unit test files read a
   source file by its path. Each step deletes its classes from the test's class map and its
   resolved lines from the baseline.
3. The small moves the measurement found: `PortalMode`, `Identifier` and `AsId()` to `Helper`;
   `CheckQuery()` to `Service\Read`; `RowOf()` to `Service\Portal`; `ServedAccess()` to
   `Service\Rights`; the instructions to `Core`.
4. Splits, one class at a time:
   - `MCPHelper`, which disappears.
   - `WritePlan`.
   - `AccessGrants`. Its class lists are data; its refusals are logic.
   - `DatamodelReader`. The rights grading moves to `Service/Rights`.
   - `PortalPolicy`. Loading and compiling a portal is split from answering questions about it.
   - `MCPController`, into one class per gate, run from one ordered list. `MCPHttp`'s request
     half goes to `Helper` and its response half to `Controller`.
5. `@api` is narrowed to the surface in rule 3, and the deprecations are removed.
   `doc/extending.md` and the example pack are rewritten. The dependency test's baseline is now
   empty.
6. `doc/architecture.md` gains the level-2 view it says it is waiting for, drawn from the folders
   above. The version becomes 2.0.0, Unreleased, with the name table in its changelog entry. Then
   the pull request.

**Rejected alternatives.**

- **Folders named only for responsibilities, with no `Service/` or `Helper/`.** This was the
  first draft of this record. It is the usual layout for a standalone library, and mcp/sdk uses
  it. But an iTop developer looks for Combodo's layer folders first. The trouble was never the
  names, only what had been put in them.
- **An application layout with `Controller/Model/Repository/Service`,** as Symfony, Laravel and
  ASP.NET applications use. That layout serves an application with its own tables. Here, iTop
  owns the datamodel and the queries (`DBObjectSearch`, `DBObjectSet`), so a `Repository/` would
  wrap iTop for nothing.
- **Facades in `Api/` over the Read and Write classes packs use.** They would add a wrapper
  layer to keep up for no behaviour, when a named `@api` list does the same job.
- **Restructuring in place over several 1.x minors, with aliases and deprecations.** That is the
  cautious path for a published API. Nobody has installed this one, and the path would leave
  both layouts in the tree for a year.
- **A dependency injection container.** iTop's core has none, and static helpers are the idiom
  this code shares with its host.
- **`deptrac` or a similar tool to check the dependency rule.** A unit test in the existing
  style does the same check without a new dependency.

**Enforced by:** `LayerDependencyTest`, the `@api` list test from step 5, `ModuleMetadataTest`
and `ElementContract`. Neither the size guide nor the "one job" rule is checked by a machine.
