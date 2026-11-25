# 0012 — An operator can exclude classes and attributes from this channel

- Date: 2026-11-09
- Decided by: Guy Couronne, 2026-11-09 ("Accept, amended": the two amendments below)
- Status: accepted
- Renamed: 2026-11-11. A name in this record followed the 2.0 layout ([0015](0015-2-0-lays-out-src-as-itop-does.md)); the decision is unchanged.

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
  `mcp_read_only` is. No scope lifts it; the one thing that does is the **exempt profile** an administrator grants
  ([0013](0013-an-exempt-profile-is-served-what-is-excluded.md), which amends this line).
- **Excluded is absent.** An excluded class answers exactly as an unknown class does (`Helper\ClassRefusal::Unreadable`), an
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

## Not covered

Stated plainly, because a security setting that reads as complete when it is not is worse than one that says what it
leaves out. None of these is a bug to be found later; each is a known edge of what this channel can promise.

- **Notification events can quote an excluded value.** `EventNotificationEmail` and the other `Event*` classes are
  readable through this channel (checked on iTop 3.2: `Event`, `EventNotification`, `EventNotificationEmail`, `EventIssue`,
  `EventWebService`, `EventRestService`, `EventLoginUsage` and `EventOnObject` all answer a search by an administrator),
  and a notification's `subject` and `body` are text iTop rendered from an object, which may hold an excluded value. They
  are **not** excluded by default. **Decided by Guy Couronne, 2026-11-09 ("Named gap, narrower"):** no Event class is
  excluded by default; the defaults stay empty. An operator who excludes personal fields should also add
  **`EventNotification`**, which covers `EventNotificationEmail`'s subject and body. **Not `Event`**: it is the parent of
  `AltiooEventMCPService` and of iTop's login and web-service logs, so excluding it would hide this module's own audit trail
  and `core_my_activity` with it. Checked: with `mcp_excluded_classes = array('EventNotification')`, `core_my_activity` still
  lists the account's own calls, an administrator still reads the audit trail through the channel, and
  `EventNotificationEmail` answers like an unknown class (`ExclusionRoutesTest`).
- **Objects named by class and id as text**, other than `Attachment`: a class that stores an object's class and key as
  plain attributes is not followed by the exclusion rules, which follow keys and link sets.
- **Portal tab conditions.** A portal tab is a condition from the brick's configuration; one on an excluded attribute still
  partitions the rows, so a count by that tab can still be told by that condition. The attributes tabs and areas group by
  are dropped; the conditions are not rewritten.
- **What was written before an exclusion.** Audit rows (`AltiooEventMCPService`: `Class::id` and attribute codes, never
  values) and the object history written earlier stay as they are. History **rows about an excluded attribute are** left
  out of `core_object_history`; the audit rows are the administrator's and are not rewritten.
- **A class with an excluded subclass in OQL** cannot be joined or sub-queried: refused, without naming the class.
- **Case logs and free text** are one attribute each: excluding a log excludes all of it, and an excluded value quoted
  inside another attribute's text (a description that pastes a phone number) is not found and not removed.
- **iTop 3.2's combined long friendly name** is not consulted, because iTop calls a function it does not define; the short
  and the complementary names are.
- **Whether an object is obsolete** (Amendment 4), which can be the excluded value itself.
- **The whole instance stops on a bad setting** (see Amendment 2): for everyone, with nothing served.

## Amendment 2 — a name that goes away stops the endpoint

A class or an attribute listed in a setting that the datamodel no longer has — after an iTop upgrade that renames or
removes it, or after an extension that declared it is uninstalled — makes the settings **malformed**, and the endpoint
serves nothing to anyone until the list is corrected. That is the same rule as a typo, and it is deliberate: a setting that
quietly stops excluding is the failure this one exists to prevent. **Check these two settings before upgrading iTop or
removing an extension.** The operator sees it in `log/error.log`, once per request, as "Itop configuration parameter for
exclusions: ... Nothing is served to anyone until it is corrected"; a client authenticates and is then served no tools,
resources or prompts, so it looks as if the server has nothing, and says nothing more.

## Amendment 3 — a key or a link set that can hold an excluded class is excluded

**Decided by Guy Couronne, 2026-11-19 ("Whole attribute absent")**, after the 2026-11-18 review (HA-1, HA-2, HA-9) found that
"an external key or a link set whose target class is excluded" did not reach a key **typed on a parent** of an excluded class.
`lnkContactToTicket.contact_id` points at `Contact`; with `Person` excluded, `Contact` is not, yet what the key holds is a
`Person`, so the link row served that person's name and email, every ticket's `contacts_list` served their id, a write to the set
answered differently for an existing excluded person than for an id nobody has, and a schema listed up to 100 names.

The rule, in the words of this record's own precedent for an attribute on its parents ("the parent therefore loses the attribute
for all its rows"): an external key, or a link set, **whose target class has an excluded descendant — or is excluded — is
excluded for every row, on every route, in both modes**, as an excluded attribute is. The target of a direct set is its linked
class; of an indirect one, the class its key to the remote object points at. It follows, by the rules above, that the external
fields through such a key (`contact_name`, `contact_email`), its `_friendlyname` and every other derived label are absent too.
The rows are not narrowed instead: a key on `Contact` would need a different answer per row, and "this row's value is hidden"
tells which rows are the excluded ones.

- **A friendly name made of an excluded attribute is not served.** A class whose short or complementary friendly name reaches
  an excluded attribute — the link class is named by `contact_name`, so `lnkContactToTicket` and `lnkPersonToTeam` with `Person`
  excluded — has its `friendlyname` **excluded as an attribute**: not in a read, not as a filter, a sort or a group, not matched by
  `find_by_name`, and not as the `_friendlyname` or the label of a key that points at the class. Chosen as the narrowest correct
  answer because iTop builds the name from its parts and there is no name left once a part is gone; an attribute that is absent
  is already how every route answers for an excluded name, where a masked name would say that one exists. It is asked of the
  class and of the served classes below it, since a query on the class returns their rows and names them by their own. The default
  field list (`id, friendlyname`) answers with the id alone for such a class; typed out, `friendlyname` is an unknown attribute.
  A derived exclusion **never makes the settings malformed**: only an attribute the operator lists is checked against the friendly
  name when the settings are read, as before, so excluding `Person` keeps the endpoint serving.
- **A schema lists no name of an excluded object.** `core_class_schema` and `itop://core/class/{class}` give a link set's `values`
  only when the objects it lists are of a class that is served and named by what is served; the sets of the rule above are
  absent altogether.
- **The cost is the operator's to know.** With `Person` excluded, `contacts_list` disappears from every ticket and `Team`
  members through it, and with `Server` excluded `functionalcis_list` does on every ticket and every other class that links
  to `FunctionalCI`; a link class that is named by an excluded part serves its id and its other fields, not its name. An
  operator who wants an attribute of the ticket kept should not exclude the class that sits under its key.
- The first sentence of "What points at an excluded thing is excluded too", above, now reads with this: a target that is
  **or holds** an excluded class.

## Amendment 4 — excluding an attribute does not hide whether an object is obsolete

**Decided by Guy Couronne, 2026-11-25 ("A, note the limit")**, after the 2026-11-23 review found that the obsolescence condition
named the attributes it reads. The condition is no longer stated when it reads an excluded attribute or cannot be checked. What
follows from the condition stays as it was: `obsolescence_flag` and `obsolescence_date` are served, a filter on the flag is
accepted, an account that hides obsolete data does not find obsolete objects, and the stale-id guard refuses a write to one.

**That can be the excluded value itself.** The stock conditions are public. `Organization` is obsolete when `status = 'inactive'`
and `status` has two values, so with `Organization.status` excluded the flag still says which it is; a contract's flag says whether
its end date is more than fifteen months past. A class whose condition reads another object's flag (`MiddlewareInstance` through
its `Middleware`) says it of that object, even when that object's class is excluded.

**An operator who must hide such a value excludes the class, not the attribute, and every class whose obsolescence follows from
it.** Excluding the attribute alone is not enough.

Rejected: hiding `obsolescence_flag` and `obsolescence_date` where the condition reads an excluded attribute. It breaks the fields
clients read and does not close the gap: switching `core_set_obsolete_data` and comparing two searches tells the same thing. Closing
it would also mean ignoring that preference for those classes and dropping the stale-id guard, so that a write to an obsolete object
goes through without a warning.

## Consequences

An operator who excludes `Person.phone` loses it from every read, filter, sort and write on this channel, including the
operator's own assistant in the console profile of Administrator. The cost of a wrong entry is high (nothing is served);
the cost of a silently ignored one would be a data leak, which is why the first was chosen.

Rejected: a profile-based mechanism (changes the console, which is the point it should not). Rejected: masking an excluded
value as `***` as sensitive attributes are (it confirms the attribute exists and holds something). Rejected: a warning for an
unknown entry (a security setting that does nothing is worse than one that stops the service).

Amendment 1 (the "Not covered" section) and Amendment 2 are the maintainer's, 2026-11-09; Amendment 3 is the maintainer's, 2026-11-19;
Amendment 4 is the maintainer's, 2026-11-25.

Enforced by: `ExclusionPolicyTest`, `ExclusionRoutesTest` (every tool × route, hidden equals absent), `ExclusionParentTypedTest` (Amendment 3,
with `Person` and with `Server`), `ExclusionPortalTest`, `ObsolescenceExclusionTest`
(the condition of Amendment 4; nothing holds that the flag stays served) and the HTTP smoke.
