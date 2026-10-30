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
- `core_current_user` reports which portal is served (`current_portal`), and each audit row
  records it: `AltiooEventMCPService` gains a `portal` field, empty for a console call, so an
  administrator can tell which portal served every call.

**With OAuth, the portal is part of what is consented to** (amended the same day). A URL that
names a portal is a resource of its own: altioo-oauth-server matches a flow's `resource` to its
`resources` setting by the exact URL, and RFC 8707 allows a query where it is what scopes the
resource. So:

- **One resource per portal.** The operator lists one OAuth resource per portal served, its URL
  carrying `portal_id`. The consent page then names the portal the person is connecting to, and
  the grant's `resource` records it, under *OAuth connections* and the person's *Connected
  applications*.
- **The token is held to its portal.** A token granted for one resource URL is refused on
  another — a consent for portal A does not work on `?portal_id=B`. altioo-mcp asks the OAuth
  server, when it is installed (detected as in [0003](0003-optional-dependency-on-altioo-oauth-server.md)),
  which resource a token was granted for. A token with no grant — one an administrator minted —
  is held only by the rules above.
- **Discovery follows the URL called.** The `401` advertises the resource whose URL is the one
  the client called, not always `for=mcp`, so a client connecting to a portal's URL is sent to
  consent for that portal.

What this needs from altioo-oauth-server must stay generic, since it keeps no code path
specific to altioo-mcp (its ADR 0003): an audience check that any resource can ask ("which
resource was this token granted for"), and an optional `label` per resource so the consent page
shows a name rather than a URL. Both are that repository's to decide.

Rejected: a tool to switch portals within a session — the assistant would choose its own rules,
the wider portal included, and protocol `2026-07-28` has no session to hold the choice.

Rejected: an attribute naming the portal on `PersonalToken`. This module already extends that
class (its MCP `scope` values), so ownership is not the obstacle. But an administrator mints
portal users' tokens, so the choice would be theirs and not the person's; and an OAuth grant
already records its resource, which is where the binding belongs.

Rejected: an HTTP header — many MCP clients configure a URL and nothing else.

Enforced by: not yet — planned. When it lands, `PortalModeTest` cases that a named portal the
user may not enter, or that `mcp_portals` does not serve, is refused and never replaced by
another, and that a token granted for one portal's resource is refused on another's URL.
