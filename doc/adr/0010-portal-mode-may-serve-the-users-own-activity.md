# 0010 — May portal mode serve the user's own activity, which the portal has no screen for?

- Date: 2026-11-05
- Decided by: not decided yet — the maintainer's to decide
- Status: proposed

`core_my_activity` answers "what did my assistant do in my name?": the calls made with this account's credentials, from
the audit trail, only the account's own, never anyone else's. The console half is built and served to console users. The
question is the portal half.

Portal mode is defined, in the README and in ADR 0005 and 0006, as serving **what the portal offers**: its lists, its
forms' fields, its actions, narrowed by its own configuration. The portal has no screen for this. Serving it would be a
new kind of element in portal mode, one whose rows come from an audit class that no portal profile can read, read with
`AllowAllData()` and narrowed by this module alone.

**What would make it safe, if it is served:** the rows are selected by the real user's key and nothing else (no user
argument); an object is shown only if the portal shows it (`ObjectAccess::Readable()`), its changed attributes only if
the portal's form shows them (`ShownOf`), and otherwise as a `Class::id` with no attribute codes or as "an object you can
no longer see"; the tool and the client are the account's own; nothing recorded as raw (`request_params`) or third-party
(`refusal_text`) text is ever returned. The audit row is also where an end user would learn that an assistant acted for
them, which is the end-user persona's interest.

**Options:**
1. Serve it in portal mode, narrowed as above. One tool in both modes, as ADR 0006 asks. Cost: a portal user can read
   the shape of what their assistant did (tool, time, object ids they may see), which the portal itself never shows;
   and a new element in a mode defined by "what the portal offers".
2. Console only. A portal user has no way to see it. Cost: the person least able to look is the one who cannot.
3. Portal mode, but only the user's *writes* (the rows that carry a change), because the portal's own history is the
   counterpart. Cost: reads and refusals stay invisible.

Until this is decided, the tool is not served in portal mode: it does not declare itself portal-aware, so a portal user
never sees it.

Rejected here: deciding it by building it. The console half does not depend on the answer.

Enforced by: `OwnActivityTest` (the tool is absent from portal mode until a decision is recorded).
