# 0012 — An operator can exclude classes and attributes from this channel

- Date: 2026-11-09
- Decided by: proposed, for Guy Couronne to accept or amend
- Status: proposed

The only lever an operator has on what leaves through this endpoint is the profile of the account, which also changes
what that account may do in the console. There is no way to say "`private_log` and phone numbers do not go to a model, and
everything else stays as it is". `mcp_excluded_classes` and `mcp_excluded_attributes` are that way.

**This narrows the channel, not the account.** It is not an access control: anyone holding the same account reads the same
data in the console, and what already reached a model is not recalled. It is a statement about what this extension will
put in front of an assistant.

## Decisions

- **Two settings, both empty by default.** `mcp_excluded_classes` (`array('Person')`) and `mcp_excluded_attributes`
  (`array('Person' => array('phone', 'mobile_phone'))`). Both are inherited by subclasses: excluding a class excludes its
  children, and an attribute excluded on a class is excluded on its children. **An attribute is also excluded on the
  parents that declare it**: a query on `Ticket` returns the rows of `UserRequest`, and `UserRequest.caller_id` is the
  same column as `Ticket.caller_id`, so excluding it on one and not the other would leave a filter, a sort or a search on
  the parent as an oracle for the child. The parent therefore loses the attribute for all its rows.
- **For everyone, in both modes.** Administrator, Basic authentication, a token of any grade and portal mode included, as
  `mcp_read_only` is. No profile or scope lifts it.
- **Excluded is absent.** An excluded class answers exactly as an unknown class does (`MCPHelper::UnreadableClassRefusal`), an
  excluded attribute as an unknown attribute. Not "excluded": that would be an oracle for which classes and attributes exist
  and are sensitive. A listing, a schema, a search, a filter, a sort, a count and its buckets, a history, a document, a write
  and its dry run behave as if the datamodel had no such class or attribute.
- **What points at an excluded thing is excluded too.** An external field resolving to an excluded attribute, an external key
  or a link set whose target class is excluded, and the labels derived from them (`org_name`) are not served. A search or
  count cannot reach them either.
- **OQL is proved or refused.** A query is accepted only if it can be parsed and every class it names (selected, joined,
  in a sub-query) and every attribute it references is neither excluded nor resolves to one. If it cannot be proved, it is
  refused, in the words of any refused OQL.
- **Counts reveal nothing by predicate.** Counts, `group_by` buckets and row totals are computed over the same boundary as the
  rows; a predicate on an excluded attribute is refused as an unknown attribute, so no count differs between hidden and
  absent.
- **`core_my_activity` names no excluded class.** Objects of an excluded class are counted in `objects_you_cannot_see`, as a
  hidden object is; an excluded attribute code is not listed in `changed_objects`. The audit rows themselves are unchanged
  (they hold names, never values).
- **Malformed refuses.** A setting that is not an array of names, a name the datamodel does not have, an attribute that is not
  on its class, or an attribute that is part of its class's friendly name (excluding it would leave the friendly name serving
  its value) makes the endpoint serve **nothing** to anyone, loudly in `log/error.log`, as a malformed `mcp_capabilities`
  does. It never reads as empty: a typo must not leave a field flowing.
- **A mandatory excluded attribute makes creating the class impossible through this channel.** The refusal says that this
  setting is why, without naming the field.

## Consequences

An operator who excludes `Person.phone` loses it from every read, filter, sort and write on this channel, including the
operator's own assistant in the console profile of Administrator. The cost of a wrong entry is high (nothing is served);
the cost of a silently ignored one would be a data leak, which is why the first was chosen.

Rejected: a profile-based mechanism (changes the console, which is the point it should not). Rejected: masking an excluded
value as `***` as sensitive attributes are (it confirms the attribute exists and holds something). Rejected: a warning for an
unknown entry (a security setting that does nothing is worse than one that stops the service).

Enforced by: `ExclusionPolicyTest`, `ExclusionRoutesTest` (every tool × route, hidden equals absent) and the HTTP smoke.
