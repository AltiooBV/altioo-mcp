# Review personas — altioo-mcp

A review here runs the guide's passes — [`doc/itop-extension-guide.md`](../doc/itop-extension-guide.md),
"Review procedure — persona passes" — in the guide's order, with the passes below inserted where
each says. The guide's passes are the personas every iTop extension faces, and are not repeated
here; this file holds only what this extension adds.

**Why these.** Every guide pass takes a person's point of view, while this module's main client
is a program: an AI agent calling the MCP endpoint with a user's token. Nothing in the guide asks
what that client does, what can be made to do it, or where the data it reads goes next.
[`SECURITY.md`](../SECURITY.md), "Threat model", answers much of it, and these passes are what
checks that it still does. And the guide's low-privilege user is measured against iTop's
`UserRights`, while the narrowest person this endpoint serves — someone at a client organisation —
may get their rights from a portal configuration that `UserRights` knows nothing about.

**Findings** go beside this file, one per review, named `<date>-<kind>.md`. They are gitignored —
they name real gaps — and only this file is tracked. `exclude.txt` keeps the folder out of the
release archive.

## Added passes

### Hostile or steered agent client — red, after the guide's red team

- **Question:** a program holding a valid token, acting at machine speed and following
  instructions its user never gave — planted in a ticket, an email, a page it reads — what can it
  make the module do that the token's owner never asked for?
- **Open:** every tool's output (`src/Core/Tools/`, and the toolsets a pack registers) for fields
  someone other than the caller can write — descriptions, logs, email bodies, attachment text,
  names; then every tool that writes, deletes or sends, for what one planted instruction makes it
  do with the caller's rights. Check what holds without the client's cooperation: token scopes
  (`src/Service/Rights/TokenScopes.php`), `mcp_read_only` and `mcp_capabilities`, the write barriers in
  `SECURITY.md` ("The rule the write barriers reduce to"), and whether "dry run by default" is
  enforced by the server or only asked of the client. A control that works only if the model
  obeys a tool description is not a control. Probe the write cap (`mcp_max_writes_per_hour`, ADR 0019): the cap
  reached by single and bulk writes and not by a dry run, a second token unaffected, and every write refused when
  the audit trail cannot count or the value is malformed. Then the exclusions (`mcp_excluded_classes`,
  `mcp_excluded_attributes`, ADR 0012): probe them as an oracle, never only as a read — an
  excluded attribute named in a search filter, an OQL condition or `order_by`, a count, a
  related-objects walk, a history or attachment read, an error text that differs from the one for
  an unknown name. The class or attribute must be absent, with the same answer as one that does
  not exist.
- **Blocks:** release.

### Agent client and the person behind it — purple, after the guide's installing client / operator

- **Question:** a client that means well — do its tool descriptions, errors and dry-run results
  let it act correctly, with no silent partial write? One that wants its task done — does it reach
  a refused action another way, use more access or data than the task needs, or read a refused,
  dry-run or partial result as done? And does the person who delegated get only what they asked
  for, and an honest account of what was not done?
- **Open:** each tool's description and input schema as the only documentation the client has;
  each refusal it can return, looking for another tool, parameter or sequence reaching the same
  result; the bulk tools (`src/Api/AbstractBulkTool.php`) for partial application and how it
  is reported; the defaults a client gets without asking (`mcp_pagination_limit`, field lists,
  search scope); and the change history a write leaves (README, "Audit trail"), for whether it
  names the person and the client, not only an account.
- **Blocks:** release when a refused action succeeds another way or a partial result reads as
  success; otherwise backlog.

### Pack author — purple, after the agent client above

- **Question:** a developer who built a tool pack against 1.x, or who opens
  [`doc/extending.md`](../doc/extending.md) cold: can they port it, can they tell from the
  changelog what changed and what replaces what they lost, and does the `@api` surface they are
  left with let them stay correct — honour the operator's exclusions, log, read settings,
  check access — without reimplementing a core tool's checks and missing one?
- **Open:** the changelog's name table against the pack's own imports; `doc/extending.md` read as
  the only documentation, for every class it tells a pack to call and every one it forbids;
  the surface itself, which is `src/Api/` plus whatever `src/Core` depends on, as
  [ADR 0016](../doc/adr/0016-what-the-core-tools-use-is-public.md) defines it
  (`PublicSurfaceTest`), for a check a core tool asks that a pack cannot; the rule that a tool
  returning object data asks `Service\Rights\ExclusionPolicy`; and `doc/example-pack` and its suite,
  built and run as a pack author would, for whether it still shows the way.
- **Blocks:** backlog; release when a pack that follows the documentation returns data an
  operator excluded.

### Company end-user — purple, after the pack author above

- **Question:** someone at a client organisation holding the narrowest access the instance hands
  out — a console account with a profile or two, filtered to its own organisation, or a portal
  user — whose assistant asks on their behalf. Does it get exactly
  what that person's own screens give them, no more, and no less without saying why? Can they
  get from no token to a working client without an administrator at every step? Do refusals and
  tool descriptions make sense to someone who has never seen the console? Can they see, and
  revoke, what was done in their name?
- **Open:** the tools run as such an account, never as an administrator: a profile-limited user
  with `URP_UserOrg` rows, and a portal-only user wherever one is served. Compare every read and
  write against what that person's console or portal shows. For a portal user the baseline is
  the portal's scopes and forms, not `UserRights`, which grants `Portal user` read on every
  `bizmodel` class — and the guide's red team, run for this account, measures against that
  baseline too. Then refusal texts, for console vocabulary (class codes, OQL, profile names)
  and for what they reveal about other organisations' objects; README "Granting access", as the
  path from no token to a working client; and the token list and audit rows this person can
  reach, for what they can see of their own calls.
- **Blocks:** release when the account reads or writes past what its own console or portal
  shows it; otherwise backlog.

### Counterpart on the ticket — purple, after the company end-user above

- **Question:** the support agent or colleague on the other side of a ticket, reading what someone's
  assistant wrote or did: can they tell it came through an assistant rather than from the person
  typing, and where? Can they answer it, correct it and trust who it names? Does the assistant's
  speed or volume change their work in ways nobody decided: repeated entries, state changes
  arriving faster than they can follow, replies to a question already answered?
- **Open:** what a write through this endpoint looks like on the counterpart's own screens, in
  the console and the portal, never only in the audit table: a case log entry's author line, the
  Activity panel, notifications a write fires, and the mentions it makes. Then what those screens
  draw on: `src/Api/ChangeTracking.php` and `doc/security-summary.md`, for what is recorded
  against what is displayed; `mcp_max_mentions`, for who gets notified; and the end-user's own
  account of the write, for whether the two sides can be reconciled.
- **Blocks:** backlog. Say so as a question for a professional where disclosure to the counterpart
  may be a legal duty, not a ruling.

### Data owner — purple, after the counterpart above

- **Question:** the people the data is about — callers, agents, contacts: where does their data go
  that iTop alone would not send it, is it limited to what the caller needed, and can the instance
  owner see it, switch it off and tell them?
- **Open:** every tool and resource that returns object data, against iTop's own visibility
  (profiles, organisation filtering, per-attribute read rights); what leaves through the client to
  its model provider, which README "Data flow and privacy" tells the instance owner — check it is
  still true; logs and audit rows (`AltiooEventMCPService`) for personal data they carry; and the
  settings that switch a route off (`mcp_disabled_tools`, `mcp_enabled_toolsets`,
  `mcp_allowed_profiles`, `mcp_portals`) and the levers that narrow what any route returns:
  `mcp_excluded_classes`, `mcp_excluded_attributes`, `mcp_exclusion_exempt_profiles` (the only
  lift: a profile, never a token scope, a client name or a portal user) and
  `mcp_audit_retention_days` (how long the audit rows, which name people, are kept). Every route
  that returns object data must ask `Service\Rights\ExclusionPolicy` (ADR 0012, 0013): list the
  routes, tool by tool and resource by resource, and find where each asks, because a new route
  that does not is silent. In portal mode, iTop's visibility is the wrong
  yardstick — `Portal user` reads every bizmodel class — so check instead every element
  `PortalAccess::Serves()` admits against the portal it is served in: what it returns must come
  from `PortalPolicy` (`src/Service/Portal/PortalPolicy.php`'s granted bricks, scopes and forms), never
  from a UserRights check alone, and never be more than that portal shows the same user.
- **Blocks:** release when personal data leaves the instance by a route the operator cannot see or
  switch off; otherwise backlog.

### Architecture — blue, after the guide's maintainer

- **Question:** does `src/` still keep the rules the decision records set, so that the next
  change cannot quietly undo one? A rule nothing re-checks is a rule that lapses.
- **Open:** the layer order of [ADR 0015](../doc/adr/0015-2-0-lays-out-src-as-itop-does.md) and its
  empty baseline; the `@api` surface of [ADR 0016](../doc/adr/0016-what-the-core-tools-use-is-public.md),
  against what `src/Core` depends on; each tool's security checks in its own source, with
  signatures that never narrow ([ADR 0014](../doc/adr/0014-a-tool-keeps-its-security-checks-in-its-own-source.md));
  and every route that returns object data asking `Service\Rights\ExclusionPolicy` (ADR 0012, 0013).
  The mechanical half is `LayerDependencyTest`, `PublicSurfaceTest`, `RequestStateTest` and the
  three contract tests ADR 0014 names, run first and trusted for what they read; the rest is
  what they cannot read: a new tool or surface, a class that outgrew its folder's description,
  and a doc that names a rule the code no longer keeps.
- **Blocks:** backlog; release when a rule is broken and its test was edited or baselined to pass.

The guide's blue team reviews the findings of these passes like any other's.
