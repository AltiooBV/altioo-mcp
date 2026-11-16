# 0020 — An operator can bound how long a token used in console mode lives

- Date: 2026-11-16
- Decided by: Guy Couronne, 2026-11-16, after the desk review of OWASP MCP Top 10 (MCP01, long-lived tokens), candidate action 6
- Status: accepted

**Context.** Portal mode refuses a token that never expires or expires later than `mcp_portal_token_max_days` from now
(90 by default), because a portal user may have minted it themselves. A token used in console mode had no comparable
bound: an administrator's or console user's personal token that never expires is a standing key to the endpoint, with
nothing the operator can set to stop it being issued that way.

**The decision.** `mcp_token_max_days`, default **0 (off)**, so nothing changes on upgrade — the stance ADR 0019 took for
the write cap, and unlike the portal setting, whose default is 90 because it shipped with portal mode. Above 0, a request
logged in with a token is refused when the token never expires or expires later than N days from now, by exactly the
portal check (`PortalMode::TokenIsBounded()`: expiry at or before now plus N × 86400 seconds, whenever the expiry was set,
since what is bounded is how long the credential can still be used).

- **Which tokens.** The check reads `expiration_date` of the presented token. A `PersonalToken` has one. A `UserToken`
  (application token) has none: authent-token's datamodel defines no such attribute (iTop 3.2.2, the same fact the README
  states for portal mode). It therefore cannot be bounded and **is refused** when the setting is above 0, by analogy with
  portal mode, which reads it as a token that never expires. An operator who sets a bound and still needs an application
  token leaves the setting at 0 or moves that integration to a personal token. Rejected: letting application tokens
  through, which would leave the one credential an administrator is likeliest to forget outside the bound.
- **Console mode only.** A request that entered portal mode is held to `mcp_portal_token_max_days` and to nothing here;
  a request not logged in with a token (a console session, Basic) is not this setting's business. The two settings never
  stack on one request, so the portal value alone governs portal users.
- **Malformed value fails closed.** Anything but a non-negative integer reads as no value, not as 0: **every request logged
  in with a token is refused** and the setting is named in `log/error.log`, as `mcp_max_writes_per_hour` refuses writes
  (ADR 0019). Not the portal setting's behaviour, which falls back to its default of 90 — that fallback is safe for a
  default that restricts; for one that is off it would be an ignored typo. Requests that carry no token are served.
- **Where it is checked (ADR 0014).** A gate of its own, `Controller\Gate\ConsoleTokenLifetimeGate`, after
  `TokenAudienceGate` and before the token is dropped (its expiry is read from the token), asks `Settings::GetTokenMaxDays()`
  and `PortalMode::TokenIsBounded()` in its own source. `PresentedToken::Expiration()` is the plumbing both it and
  `PortalAdmission` read the field with. `RequestPipelineOrderTest` pins the gate list.
- **The refusal** is a `401` with the portal's words, minus "In portal mode": *A token must expire within N days. Ask for a
  personal token with an expiry date no later than YYYY-MM-DD, or set one on this token.* The log line names who and the
  setting. The refusal for a malformed value says only that an administrator must correct the setting.

**Known limit.** The bound is on the token as presented now, not on when it was issued: a token that has 89 days left
passes, and passes again until it expires. Setting an expiry no later than N days is what makes every token it accepts stop
within N days, which is what the setting promises.

**Enforced by:** `ConsoleTokenLifetimeTest` (the setting's name and default, the parser, the shared arithmetic, the gate's
calls and its place in the pipeline) and `RequestPipelineOrderTest::testTheGatesAreTheseAndRunInThisOrder`. No integration
test: the gate runs inside a request, which the unit and integration suites cannot drive, and the portal bound has none.
