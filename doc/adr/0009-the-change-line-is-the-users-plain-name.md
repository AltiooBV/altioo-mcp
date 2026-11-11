# 0009 — A change this endpoint makes carries the user's plain name, and nothing else, in `CMDBChange.userinfo`

- Date: 2026-11-05
- Decided by: Guy Couronne
- Status: accepted

Until 1.2 the change's information line was `Jane Doe (MCP: core_object_update) - reason`, so the
object's history said which tool made a change. Combodo's Personal data anonymizer (1.3.4, with
combodo-background-task-extended 1.0.3) finds the objects holding a person's case-log entries by
`CMDBChange.userinfo = <their friendly name>`, exactly. Proved on iTop 3.2.3-3 / PHP 8.2: after
anonymising the person, a case-log entry written through the console was masked, and one written through
`core_object_update` kept the person's name, author and text, while the change rows themselves were
rewritten (they are found by `user_id`). iTop's REST channel leaves the line to iTop and is matched.

The decision is that this endpoint writes the line the way iTop does for any writer, which is the user's
friendly name, with the text iTop adds itself under impersonation, and that it records what the line used
to carry on its own audit row:

- the tool is the row's `mcp_name`, the portal its `portal`, the caller's optional reason its new
  `change_comment` (one line, cut to 255 characters); the row links the change through `change_id`;
- the origin stays `custom-extension`;
- the way to list every change made through the endpoint is
  `SELECT AltiooEventMCPService WHERE change_id != 0`, no longer
  `SELECT CMDBChange WHERE userinfo LIKE '%(MCP:%'`.

What this costs, and is accepted: the object's history line no longer names the tool or the reason. Once
audit rows are purged, or with `log_mcp_service` off, the tool and the reason are gone. Changes written by
1.0 to 1.2 keep their suffix and stay out of the anonymizer's case-log step; they are not migrated.
`change_comment` is free text and may name people: it is cleared with `request_params` when the row's
person is anonymised (ADR-free rule of the retention process, README "Data flow and privacy"), and nothing
can find a person named in a comment about someone else.

`ChangeTracking::Compose()` is `@api`. It is kept, unchanged, and deprecated: nothing calls it.

Rejected: leaving it and documenting the gap — a data-subject tool would miss one channel for ever.
Rejected: asking Combodo to match on `user_id` as the only fix — not ours to schedule; it is still worth
raising, and is the maintainer's to do. Rejected: cleaning case-log entries ourselves — it duplicates a
third-party tool and edits the most sensitive text on an instance.

Not touched, and not ours: that Combodo's case-log action fails on its own at 1.3.4 (`Unknown column 'id'`
on a temporary table whose key is `objkey`, caught and counted as done).

Enforced by: `ChangeTrackingTest::testNothingWritesTheLine` (unit), `ChangeAttributionTest`
(integration) and step 4c of `tools/ci/http-smoke.sh`, which reads a real write's `userinfo`, origin and
`change_comment` back.
