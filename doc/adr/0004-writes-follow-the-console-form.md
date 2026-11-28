# 0004 — A write may change only what the console's form would let the user edit

- Date: 2026-10-29
- Decided by: Guy Couronne
- Status: accepted
- Amended: 2026-11-28, a write also gives and keeps what the form makes mandatory (Amendment 1).

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

## Amendment 1 (2026-11-28): a write gives what the form makes mandatory, and does not empty it

- Decided by: Guy Couronne, 2026-11-28, on the retest of that day (B13): refuse such a create ("yes"); "if only one
  allowed value, it's like a 'default', so maybe fill it"; "refuse" an update that empties such a field; in 2.2.0.

**Context.** A form also makes fields mandatory, by the lifecycle's flags. The console holds a user to that in the
browser; neither `CheckToWrite()` nor REST `core/create` does. So a dry run of a `UserRequest` without a caller answered
`valid: true`, and an update could empty the caller of an open ticket. Only a transition asked (`core_object_apply_stimulus`).

**The decision.**

- **A create** (`core_object_create`, each row of `core_object_bulk_create`) is refused, dry run and real, when an
  attribute the initial state flags mandatory (`GetInitialStateAttributeFlags()`, hidden ones aside) holds no value once
  the requested values are set and iTop has computed its own. The refusal names the attributes.
- **A single allowed value is filled in**, as the console's creation form preselects it, and listed in `defaulted`. Only
  where the account may write the attribute and, in portal mode, reach what it points at, and never over a value the
  caller sent: one sent empty is refused.
- **As on the console, a mandatory attribute is not read-only on a create** (`GetFormAttributeFlags()`).
- **An update** (`core_object_update`, `core_object_bulk_update`) may not empty an attribute the object's current state
  makes mandatory. One that is already empty is not asked for, so an old object stays editable; the console's edit form
  would ask for it.
- **In portal mode** the fields are those the portal's create or edit form lets the user set and makes mandatory, by its
  own flags or the datamodel's. The stock portal's request form makes the service and subcategory mandatory so.
- **No refusal names an attribute this channel excludes** (ADR 0012), one the portal's form hides or shows read-only, or
  one the account may not read. A class whose initial state makes an excluded attribute mandatory cannot be created
  through this channel, decided by the datamodel and the setting alone and said in the setting's name, as for an
  excluded attribute iTop requires.
- `core_class_schema` marks these attributes `mandatoryOnCreate`, and `required_only` lists those without a default.
  `required` keeps meaning that the datamodel forbids null.

This endpoint is stricter than REST `core/create`, for the reason the record above gives.

Not covered: a transition, whose own rule is unchanged; a pack's tool, which asks the same with
`ObjectAccess::MandatoryOn()` and `EditPolicy` in its own source ([0014](0014-a-tool-keeps-its-security-checks-in-its-own-source.md)).

Enforced by: `MandatoryFieldsContractTest` (unit suite), `MandatoryFieldsWriteTest` and `PortalWriteTest` (integration suite).

