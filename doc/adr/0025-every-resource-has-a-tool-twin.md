# 0025 — Every resource has a tool twin, built on the same handler

- Date: 2026-11-23
- Decided by: Guy Couronne, 2026-11-23 ("would be good to have all resources as also tools", taking the advice to make it a
  rule with a test rather than a one-off)
- Status: accepted, amended 2026-11-25 (prompts)

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
`core_current_user`, say) is the cheaper twin, and it is decided then, with this record amended. A pack's resources are not held to this; `doc/extending.md` recommends it.

Rejected for now: folding the version into `core_current_user` instead of adding a tool. It was weighed as the cheaper
option and the tool was preferred; it stays the fallback named above.

Enforced by: `ResourceToolTwinTest` (unit: every registered resource and template is paired in its `TWINS` list with a
registered tool served wherever it is, and both descriptions contain the tool's `RETURNS` sentence, in console and in
portal mode) and `ResourceToolTwinParityTest` (integration: the version, class-list and
class-schema pairs answer the same; the instructions pair is compared in `CoreInstructionsTest`).

## Amendment 1 (2026-11-25): a prompt has a tool twin too

- Decided by: Guy Couronne, 2026-11-24 (review 2026-11-23, L1: "yes, prompts get a tool twin. Models mostly use tools").
- **Why a prompt needs one when a resource did not seem to.** A prompt is something a person starts and the model cannot.
  Claude Code lists `core_my_open_tickets` as a slash command (`/mcp__<server>__core_my_open_tickets`) that only the user
  types, and tool search never finds it, so the one recipe written for the commonest question ("my tickets") was out of
  reach of the caller it was written for. The instructions naming it (README, `ServerInstructions`) helped a model tell its user
  the name, which is not the same as being able to use it. A resource is at least read by a client that fetches
  resources; a prompt is read by nobody but a person.
- **The rule.** Every core prompt has a tool twin, with the resource twin's four properties: the **same handler**
  (`Core\Prompts\MyOpenTickets::Messages()` is what `prompts/get` answers and what the tool answers, so the two cannot
  drift), the **same toolset** and no profile the prompt does not require, **served wherever the prompt is** (the tool asks the
  prompt's own `Offered()`, so it is absent where the prompt is, and in portal mode only because the prompt has it: the tool
  is `PortalAware` exactly when the prompt is), and the **read grade**. It says what it is for in the same words (the prompt
  class holds them as `RETURNS`, and `PORTAL_RETURNS`, and both descriptions are built from them).
- **What the twin is.** It answers the prompt's messages and runs nothing: the recipe names the call to make
  (`core_object_search_by_oql` with the OQL worded for this instance, or the portal's `core_portal_list`), and the model
  makes it. A tool that ran the search itself would be a different answer from the prompt's, which a twin may not be.
- **The first twin** is `core_my_open_tickets`, the same name as the prompt: an identifier is unique within its kind, and
  a client that lists both sees one name for one thing. The instructions' prompt line says so when the tool is served.
- **The cost** is one more tool in every console client's `tools/list`: 222 bytes of description and 13 of schema, budgeted in
  `ContextBudgetTest`. A pack's prompts are not held to this; `doc/extending.md` recommends it.
- Enforced by `ResourceToolTwinTest::PROMPT_TWINS` (every registered prompt is paired with a registered tool served wherever
  it is, answered from the same handler, and both descriptions contain the prompt's `RETURNS`, in console and in portal
  mode), `PortalModeTest` (the twin defers to a prompt that asks the portal) and `CoreExtensionsTest` (the tool is
  graded read).
