# 0007 — A connection may name its portal with `portal_id`; a portal it may not have is refused

- Date: 2026-10-30
- Decided by: Guy Couronne
- Status: accepted

Portal mode serves one portal per request: the first by rank that the user may enter and
`mcp_portals` serves, as iTop's own login picks one (`PortalMode::Choose()`). A user allowed
into two portals could not reach the second one except by the operator leaving the first out of
`mcp_portals`, for everyone.

The decision is that the endpoint URL may name the portal, with the parameter iTop's portal
itself reads (`portal_id`, `itop-portal-base/portal/config/bootstrap.php`):

- the named portal must be one the user may enter (`UserRights::GetAllowedPortals()`) and one
  `mcp_portals` serves. Otherwise the request is refused, with `log/error.log` naming the
  account and the id — never served another portal in its place, since that would be rules the
  person did not choose;
- without the parameter, the first by rank, as before;
- it is chosen by the person when they configure the client, once per connection. Two portals
  are two client entries. Still one portal per request, never two combined
  ([0006](0006-one-tool-surface-the-portal-is-a-policy.md));
- `core_current_user` reports which portal is served (`current_portal`).

Rejected: a tool to switch portals within a session — the assistant would choose its own rules,
the wider portal included, and protocol `2026-07-28` has no session to hold the choice.

Rejected: binding the portal to the token — it needs an attribute on authent-token's
`PersonalToken`, which this module does not own, and portal users' tokens are minted by an
administrator, so the choice would not be the person's.

Rejected: an HTTP header — many MCP clients configure a URL and nothing else.

Enforced by: not yet — planned. When it lands, a `PortalModeTest` case that a named portal the
user may not enter, or that `mcp_portals` does not serve, is refused and never replaced by
another.
