# 0023 — An OQL query may not join or sub-query a class the account may not read

- Date: 2026-11-20
- Decided by: Guy Couronne, 2026-11-20, on the persona review's RT-6
- Status: accepted

**Context.** Rights were asked of the class an OQL query selects and of no other. A join or a sub-query reached classes the
account may not select: with the stock `Support Agent` profile, `SELECT Person AS P JOIN UserLocal AS U ON U.contactid = P.id
WHERE U.login LIKE "p%"` answered whether a login beginning so exists, and the same shape over `URP_UserProfile` and
`PersonalToken` (`scope LIKE "%MCP-delete%"`) answered which accounts hold a profile or a scope. Each answer is one bit at a
time, within the account's silo. iTop's own console search answers the same queries for the same account, and `SECURITY.md`
said that reading those classes is not refused, so this was iTop's semantic and not a barrier failing (review 2026-11-18, RT-6).
Meanwhile a direct `SELECT UserLocal` is refused here with "Unknown class ... this account may not read it", which tells the
caller those classes are out of reach: the refusal overclaimed.

**The decision.** Every class the parsed query names is held to the read check of the class it selects: the class it returns,
every joined class, the class of every sub-query at any depth and every branch of a union. A class the account may not read
(`UR_ACTION_READ` or `UR_ACTION_BULK_READ` refused, or the change log's reservation) refuses the whole query, in the words
a direct `SELECT` of that class gets (`ClassRefusal::Unreadable`). It applies on every route that takes OQL from the caller:
`core_object_search_by_oql`, `core_object_count` with `oql`, and an OQL string given as an external key's value in a write
(`ExternalKeyPolicy`). Each tool asks `UserRights` in its own source (ADR 0014); the walk of the parsed tree is the one the
exclusions already stand on (`ExclusionSearches::ClassesNamed()`, ADR 0012).

Rejected: parity with the console. iTop's search answers these joins, but the console is the account's own screen and the
person reading it chose the query; here a steered agent chooses it, and a join is a yes/no oracle over `UserLocal`,
`URP_UserProfile` and `PersonalToken` for whoever steers it. The channel is narrower than the console on purpose, as the
exclusions are (ADR 0012).
Rejected: refusing only the credential classes. A list of names goes stale with the next iTop version, and the rule "a class
is readable or it is not in the query" needs no list.

**Operator-visible.** A query that joined or sub-queried a class its account may not read, which was served, is now refused
and says so as a direct read does. An account's own joins over classes it reads are unchanged.

Enforced by: `OqlJoinReadTest` (integration, with a `Support Agent` account: the refused joins and sub-queries on each of the
three routes, in the words of the direct read; a join over readable classes still answered).
