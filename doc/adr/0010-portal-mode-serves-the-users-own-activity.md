# 0010 — Portal mode serves the user's own activity, as one deliberate exception to "what the portal offers"

- Date: 2026-11-05
- Decided by: Guy Couronne
- Status: accepted

`core_my_activity` answers "what did my assistant do in my name?": the calls made with this account's credentials,
from the audit trail, only the account's own. Portal mode is defined, in the README and in ADR 0005 and 0006, as serving
**what the portal offers**. The portal has no screen for this, and the audit class is one no portal profile can read.

The decision is that portal mode serves it anyway, **as a single exception, and not as a precedent**: it is an account's
own trail, and the end user is the person with most reason to look at what an assistant did for them. Nothing else that
the portal does not offer is served in portal mode because of this one. One tool in both modes, as ADR 0006 asks.

What makes it safe, and what is enforced:

- the rows are selected by the real user's key and nothing else: no user argument, no login matching;
- **an object is named only if `ObjectAccess::Readable()` shows it now** (the portal's scopes and the user's rights);
  otherwise it is only counted, in `objects_you_cannot_see`. This replaces the earlier idea of showing a `Class::id`
  with no attribute codes, which gives the id away. An object the call deleted cannot be asked about, and in portal mode is
  counted, not named;
- **attribute codes are listed only if the portal's view form shows them** (`ShownOf`), and only while the change they
  belong to still exists: when the change log is gone, no codes in portal mode;
- `coverage` in portal mode says only that some earlier calls may not be listed: no log level, no retention, no other
  instance configuration;
- what the console version never returns stays out: `request_params`, `refusal_text`, the message, the error reference,
  the login and the token ids. The `name` of a row is the caller's own request (the tool, the resource they asked for, the
  client they declared) or a fixed word of this module, never anything the account did not itself send.

**"Own" means the account.** People who share one login see each other's calls. That is true of the console and the
portal alike, and is documented; the trail identifies an account, not a person.

An operator who does not want it served withdraws it with `mcp_disabled_tools`, as for any tool. Every portal user with
MCP access has it after the upgrade that brings it.

Rejected: console only (the person least able to look is the one who cannot). Rejected: portal writes only (reads and
refusals stay invisible).

Enforced by: `OwnActivityTest` (portal narrowing, no codes without a change, plain coverage) and `MyActivityContractTest`
(portal-aware, read grade, and no column that can quote a third party).
