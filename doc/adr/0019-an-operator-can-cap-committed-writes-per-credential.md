# 0019 — An operator can cap the objects one credential commits in an hour

- Date: 2026-11-12
- Decided by: Guy Couronne, 2026-11-12 (accepted the recommendation: an opt-in cap, default off), on backlog item H-1
- Status: accepted
- Reverses in part: the 2026-11-05 implementation brief §9 ("gateway outside, no in-process hook for now"). The gateway
  stays the answer for call volume; this adds a limit it cannot give.

**Context.** The reviews since 2026-11-05 (H-1, R-6) found that a steered or hostile agent holding a write-grade
token commits writes as fast as it can call, up to 100 objects per bulk call. A web-server limiter counts requests and
cannot tell a write from a read, nor a dry run from a commit. The token is known only to iTop.

**The decision.** `mcp_max_writes_per_hour`, default **0 (off)**, so nothing changes on upgrade. Above 0 it is the
most objects one credential may have created, changed, deleted, moved through a stimulus or attached to in a rolling hour.
It **complements** rate limiting at a gateway and does not replace it: the gateway still limits calls, concurrency and
addresses; this limits committed objects.

- **What counts.** Objects, not calls: a bulk call counts its objects. A dry run never counts and never asks. An
  attachment counts its host object. A delete counts the object asked for; what the cascade takes with it is recorded
  in the change and counted afterwards, so it can make the next call refuse sooner, never this one.
- **Bulk is refused whole, before it starts.** A bulk call asks once for all its ids; if it would pass the cap nothing is
  written. The alternative, a partial bulk, leaves an operator reading which half was committed. A call larger than the cap
  itself says so, since waiting would never free enough room.
- **Per credential, on what the audit row already records.** A personal token (`personal_token_id`; this is also what an
  `altioo-oauth-server` grant is, ADR 0003), else an application token (`user_token_id`), else the user's own rows that
  carry no token (`user_id`: a console session or Basic). Two tokens of one user are two budgets; a user's token calls never
  spend the user's session budget. Rejected: per user, which would make a person's second, legitimate token share the first
  one's hour. OAuth was read, not exercised live.
- **Counted from the audit rows, by a column the controller writes.** `AltiooEventMCPService.written_objects` is the
  number of distinct objects among the `CMDBChangeOp` rows of the call's own change, filled in by `CallLog`, so the count
  is what iTop recorded and not what a tool said: a pack tool that never asks is still counted, and its credential's next
  call is refused. Rejected: `CMDBChange` rows alone (no token on them, so only a per-user count); a counter table of our own
  (a second thing to purge and keep in step); parsing `changed_objects` text (cut at 50 objects). `mcp_audit_retention_days`
  is in whole days, so it never purges inside the hour.
- **What it cannot do, and fails closed on.** When the audit trail cannot be trusted to hold the count, **every write is
  refused and the reason logged**: `log_mcp_service` off, `log_mcp_level` at `error` (successful calls leave no row), `tools/call`
  absent from `log_mcp_method`, a credential that cannot be named, an unreadable count, or a malformed setting (anything
  but a non-negative integer, which would otherwise read as "off"). **Only writes stop, not the endpoint:** ADR 0012 stops
  everything because an exclusion that is not honoured leaks data on every route; a write cap not honoured exposes
  nothing a read could, and taking reads down would add an outage for no safety. The refusal names the setting and says
  reads are not affected.
- **Where it is checked (ADR 0014).** Each writing tool calls `Service\Rights\WriteCap::RefusalFor($iObjects)` in its own
  source, once the call is known to commit and before it writes; no shared helper hides it. `WriteCapTest::testEveryWritingToolAsksTheCap`
  discovers the tools through the registry and reads PHP tokens, and requires the call before `ChangeTracking::Explain()`.
  One named exemption, `core_set_obsolete_data`: a display preference of the caller's own, with no change and no object.
  A core tool's signatures do not change (ADR 0014). A call allowed is reserved for the rest of its request, so a request
  carrying several calls cannot spend the room twice.
- **A pack (ADR 0016).** `WriteCap` is `@api` and on `PublicSurfaceTest::EXTRA_SURFACE`; `doc/extending.md` says a pack's
  write tool asks it with its object count.
- **The refusal** is one line: the cap, the setting's name, how many more the call would write and the minutes until
  enough of this credential's oldest writes leave the hour. It never names another credential or another count.

**Known limits, not hidden.** Calls running at the same moment can each pass before either row is written, so the
overshoot is bounded by the concurrency times 100 objects; a gateway limiting concurrency closes it. A row lost to a failed
audit write is a call not counted (logged in `error.log`). Rows written before the upgrade hold 0, so the first hour after
turning the cap on starts empty.

**Enforced by:** `WriteCapTest` (the decision: counting, a dry run not asking, bulk refused whole, the malformed setting,
the unreadable trail, the contract above) and, in the integration suite, `WriteCapIntegrationTest` (cap 2: the third
committed update refused, a dry run answered, a second token unaffected).
