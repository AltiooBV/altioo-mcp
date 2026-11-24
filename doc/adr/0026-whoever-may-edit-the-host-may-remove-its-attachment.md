# 0026 — In console mode, whoever may edit the host object may remove its attachment

- Date: 2026-11-24
- Decided by: Guy Couronne, 2026-11-24 ("for the attachment, won't it be better to check the object associated to and check if the user has right to edit this object linked, then has the right to remove attachment ?"; "Yes, record it")
- Status: accepted

Relates to [0011](0011-portal-mode-reads-and-adds-attachments.md), which stays as it is: **portal mode never deletes an attachment**. This
record is about the console's tools only.

**Context.** A person removes an attachment in the iTop console from the **edit form of the object it belongs to**. Read in iTop 3.2.3-3
(`itop-attachments/main.itop-attachments.php`): the form draws its removable list only when `$bEditMode && !IsReadonlyState($oObject,
$oObject->GetState(), ENUM_GUI_BACKOFFICE)`, and the removal is carried out when the host is saved, by `UpdateAttachments()`, which fires
`EVENT_REMOVE_ATTACHMENT_FROM_OBJECT` on the host, deletes the `Attachment` and writes a `CMDBChangeOpAttachmentRemoved` in the host's
history. Nothing in that path asks `UR_ACTION_DELETE` on `Attachment`; what gates it is the right to edit the host and the module's
read-only states (closed tickets, by default). `IsReadonlyState()` has no user condition, so an administrator is refused for a closed ticket
as well. This module's `core_object_delete` asked the delete right on the class and on the object, which refused a non-administrator who
could do it in the console (review 2026-11-23, B8), and, once the cascade check was narrowed for that, asked nothing about the host.

**The decision.** For `core_object_delete`, when the object is an `Attachment` (or a subclass), **the check on the host object replaces
`UR_ACTION_DELETE` on the class and on the object**. The host must exist, be readable and not excluded, be modifiable by this account (the
class right *and* a set of that one object, `UR_ALLOWED_YES`), and not be read-only for the back office. Every other class is as it was.
The check lives in the tool's own source ([0014](0014-a-tool-keeps-its-security-checks-in-its-own-source.md)), for the dry run and the
real call. What is implemented, for each case:

- **The attachment's own `DEL_SILENT` change rows.** The cascade check exempts the "attachment added" row, and only it: the object deleted
  is an `Attachment`, the row is a `CMDBChangeOp`, every key the plan resets on it is a `DEL_SILENT` key to `Attachment`, and the row *as
  stored* points at this attachment (`DeletionCascade::WithoutItsOwnBookkeeping()`). iTop plans it as an update, not a deletion, because
  the key is nullable. Anything else the plan reaches is judged and refused as before.
- **The plan's root.** The plan lists the `Attachment` itself under deletions, which would be judged by `UR_ACTION_DELETE`. That one
  entry (root class and id, root an Attachment) is skipped, and only when the tool says its host check passed
  (`CheckDeletionRights(..., $bHostChecked)`); the rest of the plan is judged.
- **An unreadable or excluded host** (its class unknown, excluded under [0012](0012-this-channel-can-exclude-classes-and-attributes.md),
  not readable, or the object not visible) is answered "The object this attachment belongs to was not found." and names nothing.
- **An attachment with no saved host** (a draft upload: no `item_class`, or `item_id` below 1) is **refused**, the safe default.
- **An administrator** is not special: the same host check, and a closed ticket refuses them too, as the edit form does.
- **`core_object_bulk_delete`** keeps its own right, `UR_ACTION_BULK_DELETE`, and `checkBulkAllowed()` still asks `UR_ACTION_DELETE` on the
  class (shared plumbing, left as it is), so bulk deletion of attachments still needs the delete right on `Attachment`; **per row, the host
  check replaces the per-object delete right**. Bulk is therefore stricter than the single tool.
- **The host's history.** A real removal fires `EVENT_REMOVE_ATTACHMENT_FROM_OBJECT` on the host and writes the "attachment removed" line
  (`DeletionCascade::AttachmentRemoving()` / `AttachmentRemoved()`), as the console does; a failure to write it is logged and does not
  turn a deletion that happened into a failure.

**Consequences, honestly.** An operator who withholds the delete right on `Attachment` from a profile **no longer prevents** that profile
from removing attachments through `core_object_delete`, wherever it may edit the host; the console never asked that right either. To stop
removal, take away the edit right on the host, or the `objects` toolset or `core_object_delete` through `mcp_disabled_tools`. Conversely the
delete right on `Attachment` alone removes nothing from an object the account may not edit. A token's own grade, `AccessGrants`, the
read-only database check, the write cap and the exclusions are untouched. `core_object_attach` is **not** changed and stays stricter than
this: in console mode it asks modify on the host *and* create on `Attachment`. The two tools therefore answer differently for an account
that may edit tickets and cannot create attachments (it can remove one, not add one).

**Rejected.** Both rights together (delete on `Attachment` and edit on the host): stricter than the form it follows, and it is what refused
the console's own users. The delete right on `Attachment` alone: it lets an account remove files from objects it may not edit. A detach tool:
a second tool for one operation, against [0006](0006-one-tool-surface-the-portal-is-a-policy.md)'s one surface.

Enforced by (integration, `AttachmentAndDeletionPlanTest`): `testTheHostRightReplacesTheDeleteRightOnTheAttachment` (a `Portal user` edits
tickets and holds no delete right on `Attachment`, and removes the file; a `Change Approver` holds it and may not edit the host, and is
refused), `testAnAttachmentIsRemovedOnlyWhereItsHostAllowsIt` (modifiable host, read-only host, unreadable host, closed ticket, no host;
dry run and real; the history line), `testTheBulkToolAsksTheSameOfTheHost` and `testTheExemptionIsNarrow` (the plans a red pass asks about).
