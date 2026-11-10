# 0013 — An account holding an exempt profile is served what is excluded

- Date: 2026-11-10
- Decided by: Guy Couronne, 2026-11-09
- Status: accepted

Amends [0012](0012-this-channel-can-exclude-classes-and-attributes.md), whose "for everyone" line it qualifies: the
exclusions apply to everyone **except** an account the administrator has deliberately exempted. Someone has to be able to
read what the channel keeps out: the operator checking that the exclusion works, a data-protection officer, one trusted
integration. The alternative was to remove the exclusion for them in the console, which is not what the exclusion is about.

## Decision

- **A setting, `mcp_exclusion_exempt_profiles`, default empty.** An account holding one of the listed profiles is served the
  excluded classes and attributes. **It is a whole exemption**: no per-class and no per-attribute lifting.
- **By the account, and nothing else.** Never lifted by a **token scope**: a portal user can mint personal tokens with any
  scope through `authent-token`. Never by **the client's declared name**: nothing verifies it. Never by an **OAuth client**:
  clients can register themselves. The account is the one thing the administrator controls, so the account is what counts:
  the real user, the one that authenticated, not one it impersonates.
- **Portal mode is never exempt**, even for an account that holds the profile.
- **Fail closed.** A value that is not a list, an entry that is not a profile name, or a name this instance has no profile for
  stops the endpoint **for everyone**, like a malformed exclusion: a typo must never exempt anybody. A malformed setting
  exempts nobody, an exempt account included.
- **Every exempt call is on the audit row.** A column `exempt` (yes/no, default no, not null) on `AltiooEventMCPService`
  says that the account behind the call held an exempt profile; an operator lists every exempt call by filtering on it. Rows
  written before the setting existed read `no`, which is accurate. It is not free text and names no person, so retention and
  the anonymisation step leave it as it is, like `simulate`.
- **A dedicated service account holding the profile is the documented way** to give one client the exemption: the account is
  then the thing that can be revoked, listed and audited, and nobody's own login is exempt for the convenience of a tool.

## Consequences

An exempt account sees the data the exclusions exist to keep from a model. That is the point and the risk: the exemption is
exactly as safe as the account is held, so it belongs on a service account with a token of the narrowest grade, not on a
person. Granting the profile is an administrator's act in the console and is in iTop's own record of who holds what.

Rejected: lifting by token scope or by client name (the caller chooses both). Rejected: a per-attribute exemption (a second
list to keep in step with the first, and a way to leave a hole by omission). Rejected: letting portal users be exempt (their
visibility is the portal's, and the exemption is not).

Enforced by: `ExclusionExemptionTest`, and the HTTP smoke (an exempt account reads the excluded data, a portal user holding
the profile does not, and the audit row says which calls were exempt).
