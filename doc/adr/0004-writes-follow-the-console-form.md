# 0004 — A write may change only what the console's form would let the user edit

- Date: 2026-10-29
- Decided by: Guy Couronne
- Status: accepted

The write tools used to accept any attribute iTop calls writable (`IsWritable()`), as REST
`core/update` does. Writable means only that the ORM will store a value. It does not mean that
the console's form would offer the field. So a single call could move a ticket to `closed`
without a transition, edit a field the lifecycle locks, reset an SLA timer, rewrite a case log,
or delete the children of a link set. Neither the per-attribute rights nor iTop's
`DoCheckToWrite()` stops any of these.

The decision is that `core_object_create`, `core_object_update`, `core_object_bulk_create`,
`core_object_bulk_update` and the `fields` of `core_object_apply_stimulus` hold each attribute to
what the console's form offers in the object's state. A create also cannot do what an update
cannot. Each refusal names the tool that can make the change.

- **The state attribute** is refused by every write tool. A transition is the only way to change
  it: `core_object_apply_stimulus`. A create starts in the initial state.
- **Lifecycle flags.** An attribute flagged hidden or read-only (`OPT_ATT_HIDDEN`,
  `OPT_ATT_READONLY`) is refused. For an update that means in the object's current state
  (`GetAttributeFlags()`). For a create it means in the initial state
  (`GetInitialStateAttributeFlags()`). A stimulus may also set any attribute its transition form
  would prompt for: one the target state flags must-change or must-prompt, or one it makes
  mandatory while it is empty. That is the rule of iTop's `DisplayStimulusForm()`.
- **Stopwatches** are refused. Any value written to one replaces it with a new, zeroed stopwatch
  (`AttributeStopWatch::MakeRealValue()`), which resets the SLA it measures.
- **Case logs** accept a string or `{"add_item": {"message": …}}`, and nothing else. The other
  JSON form replaces the whole log (`ormCaseLog::FromJSON()`), which erases earlier entries. A
  caller-supplied `date` backdates an entry, and a supplied `user_id` or `user_login` signs it
  as someone else.
- **Link sets** follow the console's rules. The set must be editable in an edit form
  (`edit_when` is `always` or `on_host_edition`), and `edit_mode` must not be `none`. An
  `add_only` set accepts additions only. The caller also needs rights on the linked class: create
  to add, modify to change. To remove, they need delete, or modify where removing only detaches
  the child. Removing an item from a direct set whose key cannot be null deletes the child
  object (`ormLinkSet::DBWrite()`), and that is a delete with no check of its own.
- **External keys** must be among the attribute's allowed values, worked out against the object
  with every requested value in place. The datamodel's filter (`<filter>`, such as an agent
  outside the ticket's team) used to be ignored, because `CheckValue()` checks only that the
  target exists.

Rejected: matching REST `core/update`. REST is written for integrations an administrator has
configured. This endpoint is driven by a model, with less supervision.

Rejected: answering with a warning instead of a refusal. A warning arrives after the write, and
nothing undoes a closed ticket or a deleted child.

Out of scope here: classes that store a credential (`AttributeOneWayPassword`). They are already
read-only through this endpoint (`AccessGrants`).

Enforced by: `EditPolicyTest` (unit suite) and `EditPolicyIntegrationTest` (integration suite).
