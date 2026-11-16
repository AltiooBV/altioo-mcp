# 0021 — An agent that writes without a person reviewing it uses its own account, named as automated

- Date: 2026-11-16
- Decided by: Guy Couronne, 2026-11-16, on the open question PRO-1 (disclosure of assistant-authored entries)
- Status: accepted

**Context.** Since ADR 0009 the change line of a write made here is the user's plain name, so the object's history and
a case-log entry the counterpart reads on the ticket do not say an assistant was involved. The audit row says it
internally: the tool, the client, the reason and the change it made (`change_id`), for as long as the row is kept. The
open question was whether the person reading the entry has to be told.

**The decision.**

- **A person writing with an assistant's help needs no marker.** The text is theirs: they asked for it, with their own
  credential, and the change carries their name, as it would for anything else they write.
- **An agent that writes on its own, with no person reviewing what it writes, is disclosed** — by running it under an
  iTop account of its own whose name says it is automated (for example *Service desk assistant (automated)*). iTop
  shows that name wherever it shows an author: the case-log entry in the console and the portal, the object's
  history, the audit row. The account is the disclosure.
- Nothing is added to `CMDBChange.userinfo`: ADR 0009 stands, and the anonymizer keeps matching. The account also takes
  its own profiles, its own tokens, and its own write cap (ADR 0019), so the agent is bounded separately from any person.

**Known gap.** The endpoint cannot tell a person from a script presenting that person's token; nothing in a request
says whether a human read the text first. The rule is therefore an obligation on the operator, stated in the README
under *Granting access*, not a control. One convention makes it easier to keep: an application token (`UserToken`,
made for unattended use) belongs on an agent's account, never on a person's.

Rejected: a marker in the change line or the entry text (an `(AI)` suffix) — it reverses ADR 0009 and breaks the
anonymizer's exact match. Rejected: marking every assistant-assisted write — the person is the author; a marker would
say otherwise. Rejected: deciding in code whether a call is autonomous (client name, token type) — the client name is
self-declared and a token type proves nothing about review, so the check would claim what it cannot know.

**Still for counsel:** whether a clearly named automated account is sufficient disclosure (EU AI Act Art. 50) for the
deployer's use. The internal record is the audit row, kept for `mcp_audit_retention_days`.

**Enforced by:** not machine-checkable — the account's name and who presents its token are the operator's.
