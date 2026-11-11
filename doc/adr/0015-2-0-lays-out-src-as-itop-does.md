# 0015 — 2.0 lays out `src/` as iTop does: Combodo's layer folders, features inside, one-way dependencies

- Date: 2026-11-11
- Decided by: proposed, for Guy Couronne to accept or amend
- Status: proposed

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
  `Service\PortalAccess` imports a tool, `Core\Tools\PortalList`. So no part can be read or
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
   | `Controller/` | The endpoint. Each gate a request passes is one class under `Controller/Gate/`, run from one ordered list. Also CORS and the response. | `Controller\MCPController`, `Helper\MCPHttp` |
   | `Service/Server/` | Building the MCP server from the registry for one request, the server instructions, the session | `Service\MCPService`, `Server\` |
   | `Service/Access/` | Who may do what: the token scopes, the access policy, the barrier on access-granting classes, the exclusions, the console's object access, the rights grading | `Service\TokenScopes`, `AccessPolicy`, `ObjectAccess`, `ExcludingModelReflection`, `Helper\AccessGrants`, `ExclusionPolicy`, the grading now in `DatamodelReader` |
   | `Service/Portal/` | Portal mode: the mode, the portal's policy, its object access, its description | `Service\Portal*` |
   | `Service/Read/` | Reading objects out: queries, serialisation, history, documents, the current user, the datamodel description | `Helper\ObjectQuery`, `ObjectSerializer`, `ObjectHistory`, `DocumentAccess`, `CurrentUserReader`, `DatamodelReader` |
   | `Service/Write/` | Turning a caller's fields into a checked write: value conversion, the rules a value must pass (`Edit`, `Secret`, `Mention`, `Formula`), the dry run, the deletion plan | `Helper\WritePlan` split, the four `*Policy`, `RestValue` |
   | `Service/Audit/` | The audit trail: retention, change and read summaries | `Service\AuditRetention`, `ChangeSummary`, `ReadSummary` |
   | `Core/` | The core tools, resources, templates and prompts. They stay where they are, like mcp/sdk's `Capability/`. | `Core\` |
   | `Registry/` | Registration, collecting the providers, identifiers | `Registry\`, `Helper\Identifier` |
   | `Hook/` | What iTop calls into: the background process | `Service\AuditHousekeeping` |
   | `Helper/` | Small stateless utilities only, as in Combodo's modules: settings, logging, JSON encoding, error references, dictionary lookup | `Helper\Settings`, `MCPLog`, `LogAPILogger`, `JsonPayload`, `MCPContext`, the rest of `MCPHelper` |
   | `Model/` | Value objects, written in the singular as Combodo writes it | `Models\MCPResult` |
   | `Exception/` | No change | |
   | `Api/` | What a pack extends or calls: the abstract elements, the provider interface, the registry facade, tool and resource output, the change reason, the version check, `Testing\ElementContract`. iTop has no equivalent; it is the one folder of our own. | `Abstract\`, `Contract\`, `Testing\`, `Helper\ToolOutput`, `ResourceOutput`, `ChangeTracking`, the version check in `MCPHelper` |

2. **Dependencies point one way.** The order, from top to bottom:
   1. `Controller`
   2. `Service\Server`
   3. `Core`
   4. two independent chains: `Service\Write` → `Service\Read` → `Service\Access`, and
      `Service\Portal` → `Service\Access`
   5. `Service\Audit`
   6. `Helper`, `Model`, `Exception` at the bottom

   `Hook` and `Registry` depend downward only. Everything may depend on `Api`, and `Api` itself
   depends only downward. There is no cycle, and nothing below `Core` imports a tool: what
   `PortalAccess` takes from `Core\Tools\PortalList` today moves down into `Service\Portal`.
   **A unit test enforces the order.** It reads each file's `use` lines, in the style of the
   existing source-contract tests, and adds no new dependency.

3. **`@api` covers what a pack needs and nothing else.** Everything under `Api/` is `@api`.
   Everything outside it is `@internal`, so it can be restructured in a minor release.
   `doc/extending.md` and `doc/example-pack` are rewritten against `Api\` only, and the example
   pack's suite is the proof that this is enough.

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
   `ChangeTracking::Compose()`. Moved classes get no aliases: a 2.0 pack is written against
   `Api\`. The changelog carries a table from each old name to its new one.

**How it lands.** One pull request per step. The full suite and the iTop matrix are green at
every step.

1. This record is accepted. The dependency test lands with today's violations listed as a known
   baseline, so the count can only go down.
2. Move-only steps, one area at a time, in this order: `Helper`/`Model`, `Service/Audit`,
   `Service/Access`, `Service/Portal`, `Service/Read`, `Service/Write`, `Controller` and
   `Service/Server` together, `Hook`, `Api`. Namespaces and files change; logic does not. The
   source-contract tests are updated in the same commit: 36 of the 79 unit test files read a
   source file by its path.
3. Splits, one class per pull request:
   - `MCPHelper`, which disappears.
   - `WritePlan`.
   - `AccessGrants`. Its class lists are data; its refusals are logic.
   - `DatamodelReader`. The rights grading moves to `Service/Access`.
   - `PortalPolicy`. Loading and compiling a portal is split from answering questions about it.
   - `MCPController`, into one class per gate, run from one ordered list.
4. `@api` is narrowed to `Api/` and the deprecations are removed. `doc/extending.md` and the
   example pack are rewritten. The dependency test's baseline is now empty.
5. `doc/architecture.md` gains the level-2 view it says it is waiting for, drawn from the folders
   above. Then 2.0.0 is released, with the name table in its changelog entry.

**Rejected alternatives.**

- **Folders named only for responsibilities, with no `Service/` or `Helper/`.** This was the
  first draft of this record. It is the usual layout for a standalone library, and mcp/sdk uses
  it. But an iTop developer looks for Combodo's layer folders first. The trouble was never the
  names, only what had been put in them.
- **An application layout with `Controller/Model/Repository/Service`,** as Symfony, Laravel and
  ASP.NET applications use. That layout serves an application with its own tables. Here, iTop
  owns the datamodel and the queries (`DBObjectSearch`, `DBObjectSet`), so a `Repository/` would
  wrap iTop for nothing.
- **Restructuring in place over several 1.x minors, with aliases and deprecations.** That is the
  cautious path for a published API. Nobody has installed this one, and the path would leave
  both layouts in the tree for a year.
- **A dependency injection container.** iTop's core has none, and static helpers are the idiom
  this code shares with its host.
- **`deptrac` or a similar tool to check the dependency rule.** A unit test in the existing
  style does the same check without a new dependency.

**Enforced by:** the dependency test from step 1, plus `ModuleMetadataTest` and `ElementContract`
as today. Neither the size guide nor the "one job" rule is checked by a machine.
