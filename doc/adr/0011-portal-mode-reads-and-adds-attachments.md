# 0011 — Portal mode reads and adds attachments, as the portal does; it never deletes them

- Date: 2026-11-09
- Decided by: Guy Couronne
- Status: accepted

Extends [0005](0005-portal-mode-writes-follow-the-portal-form.md). The decision is the one given in the original request:
**"Allow attachments"** — a portal user's assistant may **read** the files attached to what the user can see, and **add**
a file where the portal's own form would let the user add one. **Delete stays in the portal.** Anything beyond reading and
adding is a new decision, not an extension of this one.

The portal does not check the `Attachment` class through UserRights, and neither does this. It checks the **host object**
and the form, and so does portal mode:

- **Reading.** A file is readable when the portal would show it: the host object is readable in the user's scope and its
  forms show attachments (`AttachmentPlugIn::IsAttachmentAllowedForObject`). The class `Attachment` is never read through
  UserRights, whose `Portal user` grant would be wider than the portal. A draft (non-empty `temp_id`, no `item_id`) is
  somebody's unsent upload and is refused. An unknown id, a hidden host and a draft get the **same words**.
- **Adding.** Allowed when the host is in the user's write scope (`RefusalToChange`), the attachment module allows
  attachments on its class, its state is not read-only for `ENUM_GUI_PORTALS`, and the portal's edit form has at least one
  editable field — the three conditions under which the portal's upload field is not read-only. The stock `Portal user`
  profile has no right to create an `Attachment` (the class sits in the `Ticketing` group, which the profile does not hold),
  and the portal does not need one: it inserts the row itself. Portal mode does the same and **widens no profile**.
- **Same trace as the portal.** The attachment event fires, a `CMDBChangeOpAttachmentAdded` is written, and the change line is
  the user's plain name ([0009](0009-the-change-line-is-the-users-plain-name.md)); the reason goes in `change_comment`.
- **Kept as they are:** dry run by default, MIME verification, `mcp_max_document_bytes`, the untrusted-content notice on what a
  file holds, file names marked untrusted.
- **One tool surface** ([0006](0006-one-tool-surface-the-portal-is-a-policy.md)): `core_object_get_document` and
  `core_object_attach` become portal-aware. No `core_portal_*` copy.

An operator who does not want it withdraws the `documents` toolset or the tools with `mcp_disabled_tools`.

Rejected: serving `Attachment` as a class in portal mode (reads and lists through UserRights, wider than the portal).
Rejected: deleting (the portal's `allow_delete` is a configuration this module would have to mirror; not asked).

Enforced by: `PortalAttachmentsTest`.
