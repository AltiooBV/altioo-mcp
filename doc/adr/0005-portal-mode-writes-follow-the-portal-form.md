# 0005 — Portal mode writes what the portal's form allows, and the console's too

- Date: 2026-10-30
- Decided by: Guy Couronne, 2026-11-11 ("Accept, amended": the two amendments on the next lines)
- Status: accepted
- Amended: 2026-11-11, by the records that came after it, and the rules for fields, targets,
  stimuli, creations, the change log and the write scope are unchanged. The three portal tools
  are now the console's `core_object_create`, `core_object_update` and
  `core_object_apply_stimulus`, served in portal mode under these rules
  ([0006](0006-one-tool-surface-the-portal-is-a-policy.md)). Attachments are read and added, never
  deleted ([0011](0011-portal-mode-reads-and-adds-attachments.md)), which replaces "Not covered"
  for them.
- Renamed: 2026-11-11. `WritePlan::ValidatedValues()` became `Service\Write\WriteValues::Validated()` in 2.0 ([0015](0015-2-0-lays-out-src-as-itop-does.md)); the decision is unchanged.

Portal mode now writes: `core_portal_create`, `core_portal_update` and
`core_portal_apply_stimulus` (since [0006](0006-one-tool-surface-the-portal-is-a-policy.md),
`core_object_create`, `core_object_update` and `core_object_apply_stimulus`). A portal user's rights are not what UserRights says (the `Portal
user` profile reads every class), so each write is bounded by the portal's configuration, read
with the portal's own code where it runs outside the portal's kernel. The decisions:

- **A field must pass both forms.** It must be settable in the portal's form for the object as
  it is (`ObjectFormManager::PrepareFields()`: not hidden, not read-only, not a slave) *and* pass
  every rule a console write passes ([0004](0004-writes-follow-the-console-form.md)), through the
  same `Service\Write\WriteValues::Validated()`. The intersection is never wider than either: a case log
  still only gains entries, a stopwatch is still refused, and a layout an operator made wider
  than the lifecycle does not widen what is written.
- **What a value points at must be readable.** An external key's target, and a link's remote
  object, must be in the user's read scope. That is all the portal's pickers would have offered:
  they search through the scope, and answer "does not exist" for a class that has none.
- **A stimulus must be offered on the object now:** allowed by the portal's lifecycle, granted by
  UserRights on the object (DEPENDS is a no), and a transition out of its current state. Its
  fields are those the transition form prompts for and those the edit form leaves settable.
- **A creation runs a create action, on the server.** The client names the action and the object
  it starts from; that object must be a row of the action's list, and each level above it a row
  of its own list. The class is the one the action names, or the class of what its factory method
  returns for that object — the method named in the brick's configuration, never one the client
  sent. The action's rules preset the new object through the portal's own
  `ContextManipulatorHelper::PrepareObject()`, with the path's objects as sources, as the portal
  passes them. The client's fields are then held to the create form.
- **The change log is never written.** No write set holds it, whatever the portal grants
  (`PortalPolicy::WriteSet()`): a write there forges an audit record, and a portal that offers
  it is showing history, not asking for it to be edited. Reading it is `core_object_history`'s,
  served in portal mode under the portal's limits as well as its own.
- **The object must be in the write scope**, and a creation's class must have one and
  UserRights' create grant, as the portal's create page checks. A new object is not checked
  against the write scope, which the portal does not do either.

Rejected: running the portal's own form submission (`ObjectFormManager::OnSubmit()`). It needs
the form built and rendered, with the portal's request, router and renderer, which is the
kernel this module does not run; and it would carry no dry run.

Rejected: the portal's form alone. Its edit form for a ticket offers the public log as a field,
and nothing in the form says that a value replacing the log would erase it; the console's rules
are what refuse that.

Not covered: attachments are listed, never served or added (amended by
[0011](0011-portal-mode-reads-and-adds-attachments.md): read and added, never deleted); the portal's exception letting a
user change their own Person is not reproduced.

Enforced by: `PortalWriteTest` (integration suite), `PortalModeTest` (unit suite) for what is
served to whom, and the source-shape tests that look for each value rule in
`Service\Write\WriteValues::Validated()`.

The tools named here merge into the console's write tools by
[0006](0006-one-tool-surface-the-portal-is-a-policy.md); what this record decides stands.
