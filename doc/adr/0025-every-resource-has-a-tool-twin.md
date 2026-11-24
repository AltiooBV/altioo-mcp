# 0025 — Every resource has a tool twin, built on the same handler

- Date: 2026-11-23
- Decided by: Guy Couronne, 2026-11-23 ("would be good to have all resources as also tools", taking the advice to make it a
  rule with a test rather than a one-off)
- Status: accepted

**Context.** Plenty of MCP clients never fetch resources, support for resource templates is thinner still, and every client
reads `tools/list`. Five of the six core resources and templates already had a tool that returns the same thing, each added
for that reason on its own: `core_instructions`, `core_current_user`, `core_class_list`, `core_class_schema` and
`core_object_get_document`. `itop://core/version` had none. Its `extension` block is the AGPL §13 source offer (README, "The
source offer is served, not just documented"), so a client that reads no resources was never shown it.

**The decision.** Every core resource and resource template has a tool twin, and `core_version` is added for
`itop://core/version`. The twin:

- is built on the **same handler** as the resource: both call one function (`Core\ServerVersion::Payload()`,
  `CurrentUserReader::Payload()`, `ClassCatalog::ClassListPayload()`, …) and encode it with the shared encoder, so the two
  cannot drift apart. The tool may add narrowing a fixed URI cannot offer, never a different answer;
- is **served wherever its resource is**: the same toolset, no profile the resource does not require, portal mode if the
  resource has it, and the read grade. It may be served in more places (`core_class_list` is served in portal mode and
  `itop://core/classes` is not), never in fewer. One exception predates this record and holds for every pair: resources are
  not graded, so a token scoped to `MCP-delete` alone is served all of them and none of the read tools
  (`Snapshots/surface-delete.json`). Whether resources should follow the grade is a separate decision;
- **says what it returns in the same words** as its resource: the tool class holds that sentence as `RETURNS` (and
  `PORTAL_RETURNS` where portal mode answers differently), and both descriptions are built from it. Sharing the handler
  keeps the answers the same; it did nothing for the two descriptions, which were written twice and had drifted
  (`itop://core/classes` said "all available iTop classes" where the tool said, correctly, the ones this user can read).
  The rest of each text - when to call it, its arguments, its URI - stays its own, since a tool carries advice a
  resource does not. The sentences were taken from the tool descriptions as they stood, so no tool definition changed;
  the resource descriptions did.

The rule follows the resource into portal mode and no further. `itop://core/version` is not served in portal mode, so neither
is `core_version`: portal mode serves what the portal offers ([0006](0006-one-tool-surface-the-portal-is-a-policy.md)), and
[0010](0010-portal-mode-serves-the-users-own-activity.md) allows one exception and no other. Serving the version to portal
users is a separate decision.

**The cost** is one more tool in every console client's `tools/list`: 302 bytes of description and 36 of schema, budgeted in
`ContextBudgetTest`. If resources multiply, folding a small one into an existing tool's answer (the version into
`core_current_user`, say) is the cheaper twin, and it is decided then, with this record amended. Prompts are not covered.
A pack's resources are not held to this; `doc/extending.md` recommends it.

Rejected for now: folding the version into `core_current_user` instead of adding a tool. It was weighed as the cheaper
option and the tool was preferred; it stays the fallback named above.

Enforced by: `ResourceToolTwinTest` (unit: every registered resource and template is paired in its `TWINS` list with a
registered tool served wherever it is, and both descriptions contain the tool's `RETURNS` sentence, in console and in
portal mode) and `ResourceToolTwinParityTest` (integration: the version, class-list and
class-schema pairs answer the same; the instructions pair is compared in `CoreInstructionsTest`).
