# 0006 — One tool per capability in both modes: the portal is a policy, not a second set of tools

- Date: 2026-10-30
- Decided by: Guy Couronne
- Status: accepted

Portal mode served tools of its own (`core_portal_describe`, `_get`, `_update`,
`_apply_stimulus`, `_create`) beside the console's, because `Portal user` reads every class
through UserRights and a console tool served to the portal would answer from that. Each new
capability then needed a portal copy, and the fixed set of copies fell behind what a configured
portal offers — history, attachments, whatever an extension adds.

The decision is that a capability is one tool, served in both modes. What a user may do is what
every layer allows, each one only narrowing the one before: this extension's limits, the
instance's settings, the credential, iTop's own rules (datamodel, lifecycle, the console's form,
other modules' checks), the user's rights, and for a portal user the portal's scopes and allowed
actions. A record about another object — a history row, an attachment, a link — passes them on
that object too. In portal mode a tool adds `PortalPolicy` to the checks it already makes; it
never replaces them.

- `core_portal_get`, `_update`, `_apply_stimulus` and `_create` merge into their
  `core_object_*` counterparts, and `core_portal_describe` into `core_class_list` and
  `core_class_schema`. In portal mode a creation still runs one of the portal's create actions.
- `core_portal_list` stays, the only portal-only tool: a portal list — a brick, its tabs, its
  browse levels, the list a create action starts from — has no console counterpart.
- In portal mode `core_object_search_by_oql` is refused, since a query can probe classes
  outside the scopes through joins and subqueries; the bulk tools, `core_object_get_related`
  and `core_object_delete` are refused until a portal grants what they do.
- The safe default is unchanged: a tool that does not declare `PortalAware` is not served in
  portal mode, and one that does asks the one mode-aware policy, never UserRights alone.
- The mode stays decided per request: one portal, the first by rank that the user may enter and
  `mcp_portals` serves, never two combined (README, "Portal-only users: portal mode").
- `core_object_history` was the first (commit `7e48288`). [0001](0001-portal-mode-bricks-bound-discovery.md)
  and [0005](0005-portal-mode-writes-follow-the-portal-form.md) stand; what they decide moves
  into the shared policy.

Rejected: a portal copy of each console tool — the duplication this ends, and a surface that is
always one capability behind the portal's configuration.

Rejected: folding the portal's lists into `core_object_search_by_class` — options that mean
something in one mode only, in a tool both modes share.

Enforced by: not yet — the merge is planned, not done. When it lands, `PortalModeTest` asserts
that `core_portal_list` is the only `core_portal_*` tool registered, and a contract test that
every `PortalAware` tool reaches the mode policy.
