# 0014 — A tool keeps its security checks in its own source; shared code carries plumbing only

- Date: 2026-11-10
- Decided by: proposed, for Guy Couronne to accept or amend
- Status: proposed

The 2026-11-10 architecture review found the same guard prelude repeated across the
single-object writes (`ObjectUpdate`, `ObjectApplyStimulus`, `ObjectDelete`): class valid, not the
change log, not an access-granting class, readable, database writable, class concrete, action
allowed. It proposed extracting it into one shared helper.

On inspection the repetition is the design the unit suite enforces. `AccessGrantsTest`,
`ObjectHistoryContractTest` and `PortalModeTest` read each registered tool's source and require
the barrier (`AccessGrants::RefusalFor`), the change-log reservation (`ObjectHistory::IsReserved`)
and the mode's refusals (`$oAccess->Hides(…)`, `RefusalToChange`, `RefusalToSet`, …) to be in it.
They find tools through the registry, so a tool added later is held to them on the day it is
registered. A reviewer opening one tool sees every check it makes.

The decision:

- **The security checks stay in each tool's own source.** Shared code may factor out plumbing: turning an
  `MCPPortalException` into a `ToolCallException`, shaping a result, building schema fragments,
  loading an object once its checks have passed. It never hides one of the checks above behind a call.
- **A shared helper that does carry a check is taught to the contract tests by name**, as
  `AbstractBulkTool::checkBulkAllowed()` is, with a test pinning that helper's own body. Moving a
  check without that is a regression, however much code it saves.
- **A core tool's signatures never narrow.** Packs subclass core tools
  ([doc/extending.md](../extending.md), "subclass the core tool"), so `execute()` keeps `: mixed`
  and no public or protected method of a core tool gains a narrower return type or a new
  required parameter. In PHP either one is a fatal error in the pack's subclass, not a deprecation.

Rejected: one `AbstractWriteTool::loadForWrite()` running the whole prelude. It saves about
thirty lines in each of three tools, and in exchange the checks leave the files the contract tests read.
Each test would have to learn the helper, and the reviewer of a new tool would have to know it exists.
Rejected: narrowing `execute(): mixed` to `CallToolResult` for accuracy. It breaks every pack
that redeclares `execute()` with the documented `: mixed`.

Enforced by: `AccessGrantsTest::testEveryWritingToolReachesTheBarrier`,
`ObjectHistoryContractTest::testNoObjectToolWillTouchTheChangeLog`,
`PortalModeTest::testTheWritesAreServedInBothModesWithThePortalsRefusals`. The exclusions of
[0012](0012-this-channel-can-exclude-classes-and-attributes.md) are enforced by route instead, in the
integration suite (`ExclusionRoutesTest`). The rule on signatures is not machine-checked.
