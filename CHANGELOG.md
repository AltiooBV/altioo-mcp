# Changelog

All notable changes to this extension are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning is
[semver](https://semver.org/).

**What this project treats as breaking**, and therefore as a major version:

- a change to a class marked `@api` in `src/` — the surface a tool pack builds on, defined by
  the tags themselves rather than by a list written here, and described in
  [doc/extending.md](doc/extending.md);
- a change to a tool, resource or prompt **identifier**, because client configurations and
  `mcp_disabled_tools` entries name them;
- a change to the **default** of a module parameter, which alters behaviour on every instance
  that never set it.

Removal of anything on that surface comes at least one minor release after a `@deprecated`
naming its replacement, except in a major release, which may remove what the minor before it
deprecated (2.0.0 did, and its entry says what). Migration steps an administrator has to take are called out in the
entry itself, not left to be inferred from it.

## [Unreleased]

### Added

- **`core_version`, `core_current_user` and `core_my_open_tickets` declare an `outputSchema`** (review 2026-11-23, S2), as the nine write tools do: the keys that are always there and their types (`core_current_user`'s `timezone` and `now` among them), 459, 557 and 300 bytes. Their answers now also carry `structuredContent` beside the text, and the server validates each answer against its schema (mcp/sdk 0.9 and later): `ReadResultSchemaTest` calls the three through the SDK and checks the structured half with its own validator, as `WriteResultSchemaTest` does for the writes. **The other read tools declare none, on purpose.** A declared schema obliges `structuredContent`, which doubles an answer: `core_object_history` (up to 500 entries), `core_object_count` (up to 100 groups), `core_my_activity`, `core_class_list` and `core_class_schema` are the largest answers there are, and each schema would also cost about a kilobyte or more of every connection's context; `core_object_get`, the two searches, `core_object_find_by_name`, `core_object_get_related` and `core_portal_list` answer rows made of the class's own attributes, which no schema can list, so it would say "an object" and nothing else; `core_instructions` answers text and `core_object_get_document` a file. The earlier rule that no reading tool declares one (`OutputSchemaContractTest`) now names the three small ones, and `ContextBudgetTest` holds their schemas to 1,400 bytes together. Pinned hashes move for the three tools.
- **A refusal of a class or an attribute name that does not exist says "did you mean"** (review 2026-11-23, C12), and **`obsolescence.condition` is OQL or nothing**. `userrequest` is refused as before and now names `UserRequest` (and says names are case-sensitive: this is a suggestion, never an acceptance); `state` on a ticket names `status`. The refusals that carry it: an unknown class anywhere `ClassRefusal::Unreadable()` is used, an unknown attribute in `output_fields`, a search filter or `order_by`, a count's filter or `group_by`, a write's fields (create, update, the bulk tools), `core_object_attach` and `core_object_get_document`; not OQL's own refusals, which iTop words. **Only names this caller is served are ever suggested, chosen from those alone** (new `Service\Read\NameSuggestion`, `@api`, `@since 2.2.0`): the account may read the class or the attribute, the operator has not excluded it ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md)), in portal mode the portal offers the class and its view form shows the field, and for a filter, an order or a group an attribute the endpoint masks on output is not a name at all. The served names are filtered *before* the nearest three are cut, so an excluded neighbour cannot crowd a suggestion out and show by its absence: what is said for a string depends on that string and the served names, and an excluded name, an unreadable one and a name that never existed leave the same answer for the same string. Only a value shaped like a name is compared or quoted (`ClassRefusal::LooksLikeClassName()`), at most three are named, and a name that is itself served is given no neighbours. The condition in `core_class_schema`'s `obsolescence` (and in a write's `obsolescence`) was iTop's rendering of the expression it evaluates, `COALESCE((`status` = 'inactive'), 0)`; it is now the condition itself, `(status = 'inactive')`, and only if iTop parses it back as OQL, otherwise `null` (`DatamodelReader::ObsolescenceCondition()`). The pinned hashes do not move; the text of those refusals and that one value change.
- **`obsolete_ok` on `core_object_apply_stimulus`, `core_object_bulk_update` and `core_object_bulk_delete`** (review 2026-11-23, S3), with the meaning it has on `core_object_update` and `core_object_delete`: an object that counts as obsolete while the account hides obsolete data is probably a stale id, so acting on it is refused unless the call says `obsolete_ok=true`. `core_object_apply_stimulus` refuses the call in the single tools' words; a bulk call fails that row only (its entry says so and names `obsolete_ok`) and goes on with the others. **A behaviour change for those three tools**: until now they acted on such an object without a word. It touches only an account that hides obsolete data acting on an obsolete object, and `obsolete_ok=true` (or `core_set_obsolete_data`) restores the old behaviour. The flag is a trailing optional argument, so positional callers are unchanged. Not changed: the shape of `group_by`, the one input of the set that is an array (3.0). Pinned hashes move for the three tools.
- **`core_object_get_document` accepts `uri`** (review 2026-11-23, C18, the adding part). Pass the `itop://core/document/{class}/{id}/{att_code}` a read reports instead of the three arguments, or both when they agree (a uri beside arguments that name something else is refused). The uri is held to that one shape: another host or scheme (`itop://elsewhere/…`, `https://…`, `file://…`), a query, a fragment, an extra segment, an encoded character, a zero or signed id are refused as "not a document uri of this server", and the text sent is not quoted back. After the naming the call takes the same path as one by three arguments (the change-log refusal in the tool's own source, then `DocumentFetch`: exclusions, rights, the portal's forms), so a uri reaches no document its three parts would not and is refused in the same words. `class`, `id` and `att_code` are no longer `required` in the schema (a uri says all three; a call that names nothing is refused), and the portal's schema does not advertise a uri, which it does not serve. The description no longer says there is no uri argument. The pinned hash of `core_object_get_document` moves.
- **`core_object_history` entries carry `change_id` and `origin`, an entry of your own MCP calls carries `tool` and `comment`, and the page says `has_more` and `next_offset`** (review 2026-11-23, C16, the added fields). `change_id` is the key of the change that wrote the rows together (rows of one change share it), `origin` iTop's own word for how it was made (`interactive`, `webservice-rest`, `email-processing`, …). `tool` and `comment` come from the MCP audit row of the call and are said **only on a change that this account's own calls wrote**: the lookup is `user_id = <the real user>` (the account that acted, under impersonation as in `core_my_activity`), checked in the tool's own source ([ADR 0014](doc/adr/0014-a-tool-keeps-its-security-checks-in-its-own-source.md)), never by login, and an entry of another account's change carries neither key, so a colleague's tool or reason is never shown (the entries are already marked as text to report, not to follow). In portal mode `change_id` and `origin` are `null` (a change's key counts everything done on the instance) and the other additions work as on the console. `has_more` and `next_offset` are the searches' and `core_my_activity`'s, computed from the rows the query matched. New keys only, and `ObjectHistory::Within()` takes one more optional closure; the pinned hash of `core_object_history` moves.
- **`core_my_open_tickets`, the tool twin of the prompt of that name, and a rule that every core prompt has one** (review 2026-11-23, L1; [ADR 0025](doc/adr/0025-every-resource-has-a-tool-twin.md), Amendment 1). A prompt is something a person starts and the model cannot: Claude Code lists it as a slash command only the user types, and tool search never finds it, so a model could not use the recipe for "my open tickets". The tool answers the prompt's messages from the same handler (`Core\Prompts\MyOpenTickets::Messages()`) and runs nothing: it gives the call to make (the OQL worded for this instance, or the portal's `core_portal_list`) and how to present it. In the `objects` toolset, read grade, offered exactly where the prompt is (the prompt's `Offered()`), so in portal mode only where the portal grants a ticket list; no arguments. The server instructions' prompt line says the tool exists when it is served. One more tool in every console client's `tools/list` (222 bytes of description); the other tools' definitions are unchanged. `ResourceToolTwinTest::PROMPT_TWINS` fails when a prompt has no twin.
- **`core_current_user` (and `itop://core/current-user`) gives the instance's `timezone` and `now`** (review 2026-11-23, C10). A date-time here is `Y-m-d H:i:s` with no zone, and `core_my_activity`'s `since` and a filter's RFC 3339 value are read in "the instance's time zone" that nothing named. `timezone` is that zone's name (`Europe/Brussels`, `UTC`: what iTop's configuration sets as PHP's default) and `now` the instant with its offset (`2026-11-25T10:20:00+01:00`). Both in console and portal mode, the same code for the tool and the resource (`Service\Read\InstanceTime`). New keys only; the two descriptions say it in the same words, so the pinned hashes of `core_current_user` (both modes) move, and `core_my_activity`'s `since` points at it.
- **The portal's `core_object_count` counts a list per column that its kinds of row have** (review 2026-11-23, B3, the counts). The stock ticket lists are on `Ticket`, whose `status` and `priority` belong to `UserRequest` and `Incident`, so grouping them was refused. A column the list shows but its class lacks is now counted class by class: each concrete class under the list's class, the list's own query (scopes, tab and parent) narrowed to it. Each group carries a new `class` key, because the same state code means different things in different lifecycles, and groups are never merged across classes. A class the operator excluded, that the account may not read, that the portal's scopes cannot hold, or whose column is excluded or unreadable, is left out without a word; the total is then of the classes counted and the answer's note says it can be less than the list's count. The limit of 100 groups applies to the whole answer. Two columns work when every class counted has them, and are refused in plain words otherwise; a column the list hides is refused as before. Lists whose class has the column are answered exactly as before (no `class` key). Pinned hashes move for `core_object_count` (portal) and `core_instructions` (portal).
- **`core_version`, the tool twin of `itop://core/version`**: iTop's version, build and edition, and this extension's name,
  version, licence and source URL, the same payload over the same code (`Core\ServerVersion`). A client that reads no
  resources was never shown the AGPL §13 source offer that payload carries. In the `server` toolset, read grade, console mode
  only, as the resource is. One more tool in every console client's `tools/list` (302 bytes of description); the other
  core tools' definitions are unchanged, so no pinned hash moves except that a new one appears.
- **Every resource has a tool twin, and a test says so** ([ADR 0025](doc/adr/0025-every-resource-has-a-tool-twin.md)).
  `ResourceToolTwinTest` fails when a core resource or template has no tool paired with it, or when the tool is not served
  wherever the resource is, or when the two descriptions do not say what comes back in the same words;
  `ResourceToolTwinParityTest` (integration) compares their answers.

### Changed

- **The portal's `core_class_schema` description no longer repeats `core_class_list`'s, and `core_object_get_document` needs only the id in portal mode** (review 2026-11-23, S4). The schema's portal description carried about 1,300 characters of the class list's (how to choose a list, create actions, history), which every portal connection paid for twice; it keeps what is about one class's forms and says where the rest is (the portal's descriptions together are 864 bytes shorter). In portal mode `class` and `att_code` of `core_object_get_document` could only be `Attachment` and `contents`: they are now optional and default to them, only `id` is required, and a call that sends all three works as before. Non-breaking: nothing is removed or renamed, and the console's schema is unchanged. Pinned hashes move for `core_class_schema` and `core_object_get_document` (portal).
- **A resource's description now says what it returns in its tool twin's words.** Each core tool that twins a resource
  holds that sentence (`RETURNS`, and `PORTAL_RETURNS` where portal mode differs) and both descriptions are built from it.
  The resource descriptions change in `resources/list`; `itop://core/classes` no longer claims "all available iTop classes"
  where it lists the ones this user can read, and `itop://core/current-user` gains its own portal-mode text. The tool
  descriptions are unchanged byte for byte.

### Fixed

- **`itop://core/version` failed on iTop 3.3** with "Undefined array key `version_db`": 3.3 renamed that key of
  `AnalyzeInstallation`'s answer to `installed_version`. The edition is read from either. Present since 3.3 support was
  added; found when the new parity test first read the resource on 3.3. Not a security defect, so 2.x only.
- **The server instructions were cut in the middle of the prompt-injection warning** (review 2026-11-23, C1, C2, C15).
  Claude Code keeps 4,096 characters of them and the Claude desktop app about 2,050, mid-word; with all six toolsets they
  were 5,436. They are rewritten for a model rather than a person, in both modes: terse, security paragraphs first, no
  explanatory prose, every fact kept. The worst case is now under 3,800 characters, and `ServerInstructionsTest` builds
  every toolset combination in both modes and fails when the security paragraph is not first and whole inside the first
  2,000 characters or the total reaches 4,096. Portal mode now carries the same security paragraphs whatever the token
  is served (the warning that object content is data used to be said only to a token that could read). Two statements
  were wrong and are corrected: the comment of a write was said to reach "the object's history" (it is kept on the MCP
  audit row only, since 1.2), and a `2026-07-28` session was said to receive no instructions, though a client on that
  revision that sends `initialize` (Claude Code does) does. `core_instructions` has a new description and its pinned hash
  moves. Not a security defect.
- **`core_object_apply_stimulus` let a transition through while a mandatory external key was empty** (review 2026-11-23, B1). `ev_assign` on a stock `UserRequest` passed with no team and no agent, on the dry run and for real, though the state marks both mandatory and the console form refuses it. The check compared the attribute with `''` softly; an empty external key is `0`, and on PHP 8 `0 != ''` is true, so every empty key counted as filled. It now asks the attribute (`DBObject::HasAValue()`, the question `EditPolicy` already asked), which is also right for link sets, case logs and documents. Present since 1.0.0. Not a security defect: the credential could do the same in the console with a different tool.
- **A delete dry run did not say which classes it would delete or update** (review 2026-11-23, B7). Every entry of `deletionPlan.deleted` and `deletionPlan.updated` of `core_object_delete` and `core_object_bulk_delete` is now `{class, id}`; it was `{id}`, so a plan of three ids named nothing the user could approve. The object being deleted is no longer listed in its own plan, which the schema describes as what happens "besides deleting the object itself". Keys are added, none renamed. The tool definitions do not change.
- **`core_object_get_related` with `direction: "up"` always answered an empty graph, and a walk counted the object it started from** (review 2026-11-23, B6, B10, C17, S6, S7). iTop marks the nodes of a walk reached only on the way down, and the tool kept only the nodes marked: "what does this depend on" never worked, and on the way down the starting object, which iTop marks reached too, was listed and counted in `summary`. The up walk now keeps what the walk built, the starting object is in neither `objects` nor `summary` (the edges that leave or reach it still name it), and the exclusions of ADR 0012 and the bulk-read hold-back apply on both ways: the walk up is a walk from the start through anything not excluded, never seeded from the unmarked nodes. `objects` (and the schema's `relations` block) are `{}` when empty, as they are when not, instead of `[]`. The description no longer names "depends on" as a stock relation (`impacts` is, and `up` is its other direction), no longer takes `UserRequest` as an example class (it has no relations), and no longer sends the model to a `relations` block that gives no directions; `summary` is described as the string it is. Present since 1.0.0. The pinned hash of `core_object_get_related` moves.
- **The answer to a real write under-reported what it changed** (review 2026-11-23, B4). `core_object_apply_stimulus`, `core_object_update` and `core_object_create` take `changes` before the write, so a transition's own state and date, an update's computed fields and a ticket's `ref` and `start_date` read `{}` or `""`. After a real write `after.applied` now also holds every attribute iTop reports as changed by the write (and, for a create, every attribute `changes` names), read back through the serializer, so exclusions, masking and the attribute rights apply as on any read, and in portal mode only what the portal's view form shows. Keys are added, none renamed; `changes` is as before. The output schemas' description of `after` says so, so those tools' pinned hashes move.
- **`core_object_attach` accepted a class that attachments are not shown on** (review 2026-11-23, B9). iTop shows attachments only on the classes of `itop-attachments`' `allowed_classes` (`Ticket` by default), so a file attached to an `Organization` sat where no console user could see or remove it. The tool now refuses such a class (it asks `AttachmentPlugIn::IsAttachmentAllowedForObject()`, which the portal path already did; absent before iTop 3.2.1, where nothing is asked). Attaching to a document attribute with `att_code` is unchanged. Present since 1.0.0.
- **A date filter given RFC 3339 dropped its offset** (review 2026-11-23, B2). `start_date = 2026-10-28T10:46:22Z` matched a ticket stored at 10:46:22 local time, one hour out in winter and two in summer on an instance in UTC+1, where `core_my_activity`'s `since` converted the same value. A filter of `core_object_search_by_class` and of `core_object_count` on a date or date-time attribute that is given RFC 3339 **with an explicit offset** is now converted to the instance's time zone; anything else is left exactly as sent. The new `Helper\FilterDate` is `@api`, as the core tools' dependencies are (ADR 0016). The instructions said only "not RFC 3339"; they now say where an offset is converted. Writes and OQL literals are unchanged. Present since 1.0.0.
- **`core_my_activity` reported an attach as written 1 and changed `[]`** (review 2026-11-23, B11). The summary of a change had a word for creating, deleting and updating an object and none for the history row an attachment leaves, so the call was counted and not named, and the tool's own note read it as objects you can no longer see. The row now says `attached` (and `detached`) for the host object. Calls already recorded keep what they hold; the new word is read by `changed[].verb`.
- **A non-administrator could not delete an attachment, and the refusal did not say why** (review 2026-11-23, B8, S9; decided by Guy Couronne, 2026-11-24). Deleting an attachment also resets the key of its "attachment added" history row, which iTop declares `DEL_SILENT` because it is the attachment's own bookkeeping, and the console asks nothing about it; the cascade check wanted read rights on that change-log class, which no non-administrator has. That row, and only that row, is no longer judged: the object deleted is an `Attachment`, the row is a change operation, every key the plan resets on it is a `DEL_SILENT` key to `Attachment`, and the row as stored points at this attachment. Everything else a delete reaches is judged as before and refused as before. Deleting an **attachment** now also asks what the console asks when a file is removed from its host's edit form, which the change-log refusal used to stop incidentally for a non-administrator: the host (`item_class`, `item_id`) must exist, be readable and served, the account must be able to modify that very object (class right and the object itself), and `itop-attachments` must not freeze it for the back office (closed tickets by default). An unreadable or excluded host is answered as not found, an attachment with no saved host (a draft upload) is refused, in `core_object_delete` and `core_object_bulk_delete`, dry run and real. A removal now leaves what the console leaves: the host hears `EVENT_REMOVE_ATTACHMENT_FROM_OBJECT` and its history gets an "attachment removed" line. Who may delete a ticket, with its attachments, is unchanged. **The right to delete an `Attachment` is no longer asked in `core_object_delete`: whoever may edit the host may remove the attachment, as the console's edit form lets them, and the host check above replaces it** ([ADR 0026](doc/adr/0026-whoever-may-edit-the-host-may-remove-its-attachment.md)). An operator who withheld the delete right on `Attachment` to stop removals must now withhold the edit right on the host, or disable the tool. `core_object_bulk_delete` still asks its bulk right and the class's delete right, and judges each row by its host; the plan's root `Attachment` is skipped by the delete-right judgment when its host passed. `core_object_delete`'s description says so and its pinned hash moves; `core_object_attach` is unchanged. A listener of another extension on `EVENT_REMOVE_ATTACHMENT_FROM_OBJECT` keeps the veto it has in the console's form: if it throws, nothing is deleted and the answer is a refusal that does not repeat its message. The description of `core_object_delete` promised that a refusal names the class in the way; it does when the user may read it and says only that something unreadable is there when not (a class the user cannot read is not named, so that a delete is not a way to map the datamodel), and now says so. The refusal's second sentence starts with a capital. `core_object_delete`'s pinned hash moves.
- **The -32022 "unsupported protocol version" refusal listed the supported revisions without 2026-07-28** (review 2026-11-23, B5). One URL answers two eras, and the SDK's refusal of a revision it cannot place lists the handshake revisions only. A middleware on the way out adds the revisions the stateless leg serves to that list (only a 400 carrying -32022 and a list of strings is touched).
- **Three refusals said the wrong thing** (review 2026-11-23, C11). A token sent only as the `auth_token` URL parameter was answered "No credential was sent": it now says the parameter is refused and where to send the token (the token is not echoed). A foreign `Origin` was answered "This host is not served": it now says "host or origin". A `DELETE` was answered with a plain-text 405 where `GET` and `PUT` answer JSON-RPC: it now carries a JSON-RPC error body, still 405 with `Allow: POST, OPTIONS`. Not changed here, because the SDK writes them: an unknown method answers -32600 where JSON-RPC says -32601, and `resources/subscribe` and `logging/setLevel` answer -32603 ("needs a session").
- **A file refused by an image attribute was quoted back in the error** (review 2026-11-23, S8). `core_object_attach` with `att_code` on a picture and a text file answered iTop's own "Wrong format [first bytes…]", binary included. The bracketed part that holds the start of the file is replaced by a sentence; the reason stays.
- **`core_class_schema` graded an attribute nobody writes as one the caller may modify** (review 2026-11-23, C9). A derived attribute said `readOnly: true` and `modify: "yes"` together. `modify` is now `"no"` on every read-only attribute, which `readOnly` already explained; the attributes that can be written are graded as before. Not a security defect, so 2.x only.
- **`core_class_schema` with `required_only` listed attributes a create does not need** (review 2026-11-23, C4). A stock `UserRequest` answered seven attributes, though a create with three (`org_id`, `title`, `description`) passes: `status`, `impact`, `urgency` and `priority` are required by the datamodel and filled in by the write. `required_only` now keeps what is required, writable and without a default, and each attribute that has one says so under a new `default` key (absent where the default is empty). The class schema's description and the `required_only` argument say the same. Pinned hashes move for `core_class_schema`. Not a security defect, so 2.x only.
- **The class schema's lifecycle said nothing of what a state asks for** (review 2026-11-23, C3). `core_class_schema` gave each stimulus and its target state, yet `core_object_apply_stimulus` and the refusal of an update sent a model there to learn what a transition needs, so `ev_resolve` needing a `solution` was found only by being refused. Each lifecycle state now lists, when it has any, the attributes it makes `mandatory`, those it `prompted` (must change or must prompt; a transition into the state asks for them) and those that are `readOnly` in it. An attribute the account may not read or the operator excluded is not named. Keys are added, none renamed. Pinned hashes move for `core_class_schema` and `core_object_apply_stimulus` (description). Not a security defect, so 2.x only.
- **`core_class_list` with `may="create"` listed twenty abstract classes** (review 2026-11-23, C8). `Contact`, `FunctionalCI`, `Change` and the others have no objects of their own, so a create on one is refused. `may` set to `create`, `bulkCreate`, `bulkModify` or `bulkDelete` now drops an abstract class (act on its subclasses); the other gates, and `may="*"`, are unchanged. The description of `core_class_list` says so, and its pinned hash moves. Not a security defect, so 2.x only.
- **`core_class_schema` did not say how to write a link set** (review 2026-11-23, C19). A link set reads as rows and is written as the complete list of its links, and the key to set on each item (`functionalci_id` for the tickets' CIs) was found by guessing. Each link set now carries a `writeHint`, the way a case log does: `[{"functionalci_id": 1}]` links FunctionalCI 1 through `lnkFunctionalCIToTicket`, and a link left out is removed. A class or attribute the operator excluded is not named in it. Schemas grow by about 120 bytes per link set. Pinned hashes do not move (an output, not a definition). Not a security defect, so 2.x only.
- **Two tools were served that could never succeed** (review 2026-11-23, C6). `core_object_history` in portal mode on a portal that shows no history (no brick it grants reaches the change log, as in the stock portal), and `core_object_bulk_delete` for an account that holds no bulk delete on any class it could act on, were listed in `tools/list` and refused on every call. Both are now absent, decided in `ServedElements::isHidden()` as the other withheld tools are, and a call by name is answered as for a tool that does not exist. A portal that cannot be read still serves `core_object_history`, which then says why in the portal's own words. The bulk-delete check asks the account's rights only (read, delete and bulk delete on a class that has objects and is not excluded), once per `tools/list`, and stops at the first class that allows it; the per-class checks stay in the tool. Not a security defect, so 2.x only.
- **A description or a refusal named a tool the caller is not served** (review 2026-11-23, C7). `core_object_get` and the two searches told a token without the `history` toolset to call `core_object_history`, the refusal of a change-log class said that tool alone serves it, and the portal's `core_object_count` said `core_class_list` gives each list's columns, which it does not (the rows of `core_portal_list` show them). A pointer to `core_object_history` is now written only when the connection is served that tool (new `ServedAccess::Serves()`, true when no server is asking), and the change-log refusal is built by the new `ChangeLogReservation::Refusal()`, which says "this connection does not serve" otherwise. `RESERVED_REFUSAL` stays for a pack that reads it. Pinned hashes move for `core_object_count` (portal), and the text of `core_object_get` and the searches changes only for a connection without `history`. `core_object_delete` now words it the same way (it kept the old text until this release). Not a security defect, so 2.x only.
- **`core_object_history` left out what a change was about** (review 2026-11-23, C16, the fixing part). A text, long text or HTML field (a ticket's `solution`) answered `from: null, to: null`: its previous text is now `from` (iTop keeps no new text, so `to` stays null, and the description says so). A link set's change did not say which object was linked or unlinked: the entry now carries `link: {type, class, id}`, with the class the object is. An attachment added or removed said nothing of the file: the entry carries `attachment: {filename, id}` (the id only while the attachment exists). The object, and the file name, are named only where the account is shown them: a class the operator excluded is not named (a link set typed on one is not in the history at all, as before), a portal names an attachment only where its forms show them (ADR 0011), and an object the account may not read is reported as `link: {type}` alone. Keys are added, none renamed. Pinned hash moves for `core_object_history`. The answer for a deleted object stays "not found": it is the same words as for an object the account may not read, on purpose. Not a security defect, so 2.x only.
- **`core_object_get_document` said "this is how that uri is read" and has no `uri` argument** (review 2026-11-23, C18, the text; accepting a `uri` is an addition, left for the next minor). The description now says the uri is `itop://core/document/{class}/{id}/{att_code}` and that its three parts are the tool's arguments. Pinned hash moves for `core_object_get_document`. Not a security defect, so 2.x only.
- **`core_object_update` and `core_object_bulk_update` were annotated `idempotentHint: true`** (review 2026-11-23, S1). A case log's `add_item` adds a new entry on every retry, so a client that retries an idempotent call freely added the same work note twice. Both are now `false`; the other hints are unchanged. **This moves the pinned definition hashes of both tools**: a client or gateway that pins a tool's definition sees them change once, and one that decides on `idempotentHint` (an automatic retry, say) stops retrying them. `core_object_apply_stimulus`, `core_object_create`, `core_object_attach` and the deletes were already `false`. Not a security defect, so 2.x only.
- **`id` had no `minimum: 1` in the schema of `core_object_update` and `core_object_apply_stimulus`** (review 2026-11-23, S3, the fix; `obsolete_ok` on the other tools and the shape of `group_by` are additions and a 3.0 question). Both refused an id below 1 in their code and said nothing of it in the schema, where the other tools say it. Every integer `id` argument now carries `minimum: 1`, and a test holds the rule for every core tool. Pinned hashes move for the two tools. Not a security defect, so 2.x only.
- **The Claude Code section of `doc/clients.md` was one line** (review 2026-11-23, L4). It now covers OAuth instead of a token, portal setup (`portal_id`, the token's expiry), the cut on the server's instructions (Claude Code keeps 4,096 characters, per its documentation and log; the Claude desktop app's Code tab was seen keeping about 2,050, mid-word, on 2026-11-23: each attributed to its client, neither a constant), prompts as `/mcp__<server>__<prompt>` slash commands that only the user runs, and resources through `@` mentions. It links to the README and ADR 0007 for the rest. Documentation only.
- **The portal's `core_object_count` offered an example that is refused** (review 2026-11-23, B3, the wording only; counting per child class is a separate change). The description's "open requests per status" cannot run on the stock portal: its ticket lists are on `Ticket`, whose `status` and `priority` live on the subclasses. The description now gives an example that works there (list `closed-tickets-for-portal-user` with `group_by: ["finalclass"]`), says a column only a subclass has is refused, and the portal instructions say the same. The refusal for such a column no longer reads "Unknown attribute": it says the column belongs to a subclass of the list's class. A test runs the example from the description on the stock portal. Pinned hash moves for `core_object_count` (portal) and `core_instructions` (portal). Not a security defect, so 2.x only.
- **A connection served no tool that changes an object kept talking about writing, and never said why** (review 2026-11-23, C5; decided 2026-11-24). The reviewer's portal session was read-only because of the scope its OAuth authorization carried, yet the instructions explained how to create requests, `core_class_list` said `edit: true` and listed create actions, `core_object_get` listed `settable_fields`, `may_attach` and state changes, and descriptions named tools that were not served. Now, when the server serves the credential no tool graded write or delete, the instructions say so in their first lines (a new `Access` block right after the security paragraph, so inside the first 2,000 characters), say why, and say what would change it: **the scope the connection was authorized with (an OAuth approval, or the token) grants no write** and needs a write scope (`MCP-write`), **or the server's own settings serve no write to anyone** (only an administrator changes that), or, when neither explains it, nothing more than that. The scope is named only when the instance's settings alone would have served a write. It promises no right: it adds "and then only what the portal's own forms let them do" (console: "what their iTop rights allow"). The rest of the portal instructions stops naming create actions, state changes, settable fields and the writing tools; the portal's `core_class_list` and `core_class_schema` answer `create_actions`, `create`, `create_chosen_by_portal` and `stimuli` as empty lists and `edit` and `attach` as `false` (keys kept, so nothing that reads them breaks) and add a `note` saying what that means (it says what this connection can do, not what the portal gives the user); `core_object_get` leaves out `stimuli`, `settable_fields` and `may_attach`; and the descriptions of `core_object_get`, `core_class_list` and `core_class_schema` no longer name the write tools. `rights` on an object and the per-row `rights` and `stimuli` of the searches are unchanged: they report what iTop lets the account do. Unchanged for every connection that is served a write tool, so no pinned hash moves except those of a read-scoped connection (`core_class_schema`, `core_object_get`, `core_instructions` for the `read` surface). New `ServedAccess::ServesWrites()` and `WritesWithheldBy()` (`@since 2.2.0`), `PortalDescription::Reading()` and `ServedElements::InstancePolicy()`. The CI fixture gains a portal token scoped `MCP-read`, and the HTTP smoke checks all of the above over the wire.
- **The server instructions named `core_object_history` where the portal shows no history** (review 2026-11-23, C6, C7). Once that tool is withheld there, the portal's instructions still had a "History" section naming it, and the console's has it only when the tool is served. Both sections are now written only when the connection is served the tool. Pinned hash moves for `core_instructions` (portal). Not a security defect, so 2.x only.

## [2.1.0] - 2026-11-20

**The fixes of the 2026-11-18 review, the port to `mcp/sdk` 0.10, and a pack contract that no longer names the SDK's exceptions.** Eighteen entries under *Fixed* are marked Security (two of them concern tool packs); none
needs a migration. An advisory token's rehearsal is now also recorded and answered as one: a token carrying `MCP-advisory`
has always rehearsed every write whatever the caller sent, and nothing was written. What was wrong is
what said so.

**Nothing to migrate.** The datamodel gained no class or attribute (two comments and one dictionary label were reworded), the installer gained no step, no module parameter was added and no default moved. Downgrading is unsupported, as for every release.

**Read before upgrading.**

- **An OQL query that joins or sub-queries a class the account may not read is now refused** ([ADR 0023](doc/adr/0023-a-query-may-not-join-a-class-the-account-may-not-read.md)). The search
  asked the account's rights of the class a query selects and of no other, so with the stock `Support Agent` profile `SELECT Person AS P JOIN UserLocal AS U ON U.contactid = P.id WHERE U.login LIKE "p%"`
  answered whether such a login exists, and the same shape over `URP_UserProfile` and `PersonalToken` answered which accounts hold a profile or a token scope: a yes or a no at a time, within the account's silo,
  which a steered assistant can use as an oracle. iTop's own console answers those queries, which is why this was served; this channel is narrower than the console on purpose. Every class the parsed query names (a joined class, a
  sub-query's at any depth, a union branch's) is now held to the read check of the class it selects, and a refusal is worded as a direct `SELECT` of that class is. It applies to `core_object_search_by_oql`,
  `core_object_count` with `oql`, and an OQL string given as an external key's value. **Visible to operators:** a saved prompt or an assistant's query that joined a class its account does not read
  is refused where it was served; an account's own joins over classes it reads are unchanged. Not a security fix for the 1.x lines (no `release/1.N` change): it narrows the channel below iTop's own search.
- **A JSON-RPC batch is refused, so one audit row never stands for N calls.** The endpoint accepted a JSON array of up to 100 requests on every
  protocol version, though batching is optional in 2025-03-26 and was removed after it. A batch of
  `tools/call` writes left **one** audit row with no method, no tool name, no parameters and no
  `simulate` flag, so `core_my_activity`, the audit list and anything forwarding the rows saw none of the calls
  individually. A batch is now answered HTTP 400 with JSON-RPC `-32600 Invalid Request`, once the caller is
  authenticated, and recorded as one row of method `batch` (always audited, whatever `log_mcp_method`
  lists). The write cap was not bypassed: `WriteCap` reserves what each call is allowed within a request.
  A client that sent batches has to send its requests one per HTTP request, which every revision requires of it from 2025-06-18.
- **HTTP `DELETE` is now answered `405`, where 2.0.0 answered `200`.** The endpoint is stateless and stores no session, so there is none to end: a `DELETE` (the MCP transport's way to end a session) is now refused `405 Method Not Allowed` with `Allow: POST, OPTIONS`, instead of a `200` that ended nothing (`NoSessionTeardownMiddleware`). A client that sends it on shutdown and treats anything but a `2xx` as an error has to accept the `405` (the MCP specification allows a server to answer it). `GET` was already `405`.
- **A 1.x tool pack installed beside 2.x takes every page of the instance down: move it aside before running the setup.** A pack declares `altioo-mcp/1.x.y` as a minimum, so the setup accepts 2.x beside it, and its `register.php` imports a 1.3 class name that 2.0 removed with no alias ([the name table under 2.0.0](#200---2026-11-16)). iTop includes every module's datamodel files, `register.php` among them, from `env-production/autoload.php` on **every request** to the environment, outside the per-provider guard that protects a pack that fails to load at run time, so the `Class not found` is a fatal there: tried on iTop 3.2.3 with a stub 1.x `register.php` (`Registry\MCPExtensionCollector::RegisterServiceProvider(...)`), the console, the portal, the REST endpoint and the MCP endpoint all answered `500`. Before upgrading to 2.x, move every pack written against 1.x out of `extensions/`, run the setup, and put back the version of the pack that is written for 2.x.
- **For pack authors: two entries of the 2.0.0 name table are not on the surface.** The table under 2.0.0 sends `Helper\FormulaPolicy` to `Service\Write\FormulaPolicy` and `Helper\SecretPolicy` to `Service\Rights\SecretPolicy`; both are `@internal` since 2.0 (the paragraph above that table says so, and the table did not). A pack that called them goes through `WriteValues::Validated()`, which applies both rules and is on the surface. The 2.0.0 entry is left as it was published.
- **Ten core tools' definitions changed since 2.0.0, so their pinned hashes change** ([SECURITY.md](SECURITY.md) tells a client that pins a tool's definition that a changed hash means a changed definition: OWASP MCP03). Compared with `tools/tools-manifest.php` on `v2.0.0` and on this release: **`core_my_activity`**, **`core_object_apply_stimulus`**, **`core_object_attach`**, **`core_object_bulk_create`**, **`core_object_bulk_delete`**, **`core_object_bulk_update`**, **`core_object_create`**, **`core_object_delete`**, **`core_object_update`** and **`core_set_obsolete_data`**. The nine write tools gain `forced_by_credential` and `forced_reason` in their output schema (*Added*, below), and the three bulk tools also `all_succeeded` (*Fixed*); `core_my_activity` gains its `written` field, and its description now says that rows from before 2.0.0 read 0. Every other core tool's definition is byte for byte the 2.0.0 one. Nothing was added or removed; no identifier moved. Re-pin after reading the diff of the manifest (`tools-manifest.json`, a release asset).

- **`mcp/sdk` moves from 0.9 to 0.10, and the tool-pack contract stops naming the SDK's exceptions** ([ADR 0024](doc/adr/0024-the-pack-contract-names-this-modules-exceptions.md)). 0.10 moved the SDK's exceptions (`Mcp\Exception\ToolCallException` and
  `ResourceReadException`, the two [doc/extending.md](doc/extending.md) told a pack to throw, are now under `Mcp\Server\Exception\`) with no alias, and says it is being split into packages. **A pack written for 2.0 keeps working**:
  `sdk-aliases.php` (module root, loaded by composer's `autoload.files`, so before any pack code) gives the 24 server-side names of 0.9's `Mcp\Exception\` back until 3.0, thrown and caught as before. **New:** a pack throws
  `Api\ToolRefusal::Because('...')` and `Api\ResourceRefusal::Because('...')` (`@api`, since 2.1.0). The SDK declares its two classes `final`, so these return the SDK's own exception rather than extend it, and `ToolRefusal::Is($e)` asks whether something
  caught is one. The module's own tools throw through them. **Deprecated, removed in 3.0:** the old names, and naming the SDK's class directly: `Testing\ElementContract` warns about both in a pack's own source. **The pack's dev pin is `^0.10.0`**
  (`MCPModule::SDK_CONSTRAINT`, which still said `^0.9.0` and which nothing held to `composer.json` until a test now does); a pack still pinned to `^0.9.0` tests against an SDK the module no longer ships. Without the aliases a pack's tool answered
  `-32603 Error while executing tool` and its `catch` of the old name stopped matching. The `Mcp\Schema\*` types a pack uses (`ToolAnnotations`, `Annotations`, the content classes) did not move and did not change shape.
- **The endpoint no longer issues an `Mcp-Session-Id`, to any protocol revision.** It handed the SDK a store that remembered nothing, and 0.10 (which binds a session to the principal that opened it, and answers `503 Session store is unavailable` for one
  it cannot read back) refused every handshake-era call after `initialize`. The SDK now serves a handshake-era client over HTTP without sessions, which is what this endpoint always was: `initialize` answers without an id, later calls carry
  `MCP-Protocol-Version` and their credential, an id a client sends anyway is not looked up, and `GET` and `DELETE` are `405` with `Allow: POST, OPTIONS` (the `DELETE` answer is unchanged from the entry above). `Service\Server\Session\StatelessSessionStore` (`@internal`) is removed.
  **Visible to a client:** the `initialize` result no longer offers `logging` or `resources.subscribe`, which a server without sessions cannot honour past a request (the tool-surface snapshots record it, and are the only change to them: no tool, resource or prompt
  definition differs); `server/discover`, which 0.9 did not answer, now does, and carries the same instructions and capabilities as `initialize` for the same caller, and no element.
- **Arguments are checked more strictly by the SDK.** `arguments` that are not a JSON object (`[]`, which a PHP client encodes an empty map as) are refused `-32602`, and so is a value that cannot be cast to its parameter, such as a list given to
  a string parameter, where 0.9 answered `-32603` or handed the tool the word `Array`. A batch is refused by the SDK as well, `400` and `-32600`, behind this endpoint's own refusal.
- **For pack authors: a nested JSON object reaches a tool parameter typed `mixed`, `object`, `\stdClass` or not at all as a `\stdClass`**, where 0.9 handed an array; a parameter typed `array` still receives arrays at every level. Every core tool types its structured
  arguments `array`, so none of the module's checks changed. `Testing\ElementContract` warns about such a parameter on a pack's tool.
- **A refusal before any tool ran is `{"code":…,"message":…}` and nothing more.** 2.0.0 also sent `mcpMethod`, `mcpName` and `requestParams`, which are the audit row's own fields and were null or a constant there
  (*Fixed*, the CORS entry). A client that read one of them reads `undefined` now; none was ever a field of the protocol. A request whose `Authorization` header is empty or malformed (`Bearer` alone, `Basic !!!!`) is answered the `401` of a request that brought no credential, instead of reaching iTop's Basic login.
- **For pack authors: `Service\Write\RestValue` is no longer on the surface** ([ADR 0016](doc/adr/0016-what-the-core-tools-use-is-public.md), Amendment 1; *Removed*). It is `@internal`: the class stays and works in 2.1.0, with no promise after it. Write through `WriteValues::Validated()`.
  This is the one place the changelog's rule of a deprecation a minor before a removal from the surface is waived, once, and the entry says why.
- **For operators with tool packs: an `MCP-advisory` credential is no longer served a pack's write tools** ([ADR 0022](doc/adr/0022-an-advisory-credential-is-not-served-a-packs-write-tools.md)): absent from `tools/list`, an unknown tool when named. This module's own write tools still rehearse for it.
- **For operators: other behaviour changes that a client, a saved prompt or a dashboard may notice**, each with its own entry under *Fixed* or *Added*: with `Person` or another class excluded, the keys and link sets typed on its parent (`contacts_list` of every ticket and CI) are excluded as well;
  a portal user's assistant reads a link set's rows with fewer attributes, and a stimulus that sets a field the portal's transition form does not offer is refused; with `mcp_max_writes_per_hour` set, a write that sets a link set can be refused for more objects than before; the audit row's `simulate` column reads `yes` for every
  rehearsal (a forced one and one that left `simulate` out included; earlier rows keep `no`) and `duration_ms` stops at the decision of the answer; a filter or a sort on an attribute the endpoint masks is refused as an unknown one; an excluded class is no longer listed as a value of an attribute that holds class names (a trigger's `target_class`, a parent's `finalclass`); a half-failed bulk call carries `all_succeeded: false`.
- **Runtime libraries were refreshed with `composer.lock`** (*Changed*); the licences did not change.

**Tested on.** iTop **3.2.3-3** and **3.3.0-1**, each on PHP **8.2** and **8.4** — installed through iTop's own unattended setup by the local CI runner (`tools/ci/local/run.sh matrix`), then exercised by iTop's module validation suite, the module's integration suite (494 tests) and by calling the endpoint over HTTP, portal mode included. The unit suite (3209 tests, with the linter and Psalm) ran on 8.2, 8.3 and 8.4, and `composer audit --locked` is clean on both locks. **Not run on this tree:** the upgrade workflow (on the merged `main`, before the four fixes of the 2026-11-20 re-review, it upgraded from v1.0.0 and from v2.0.0 on iTop 3.2.3-3 and PHP 8.2, and passed; iTop 3.3, PHP 8.4 and the 1.x minors between are not covered by it), the model evals, a real MCP client, and the web server's file rules (Apache, IIS, php-fpm, nginx).

### Fixed

- **Security: in portal mode a link set was served with every row and every attribute.** `core_object_get` checked the object it was
  asked for and then serialised its link sets from the ORM, asking neither the remote object's read scope nor the remote class's form: on the stock portal, a
  portal user's `core_object_get Location person_list` returned a **person of another organisation** whom the portal itself hides (name, organisation, e-mail, phones,
  employee number), and the same columns of a colleague whose own page hides them; `physicaldevice_list` returned a foreign server's serial number, management IP and
  warranty dates (the portal has no `Server` scope); `ServiceFamily` `services_list` returned services no contract of the user's organisation covers; a ticket's
  `contacts_list` returned a contact the portal does not show; and a blob inside a row came back whole in base64, past the `documents` toolset and
  `mcp_max_document_bytes`. `Portal user` reads every class through UserRights, so the portal's scope is the only thing that hides those rows, and nothing asked it per row.
  The `applied`, `changes` and `after` of a write and the rows of `core_portal_list` took the same path. A link set is now served **as the portal draws it**, on every
  route (one place, `ObjectSerializer::Value()`): a row only when its remote object is in the user's read scope (a 1-n set's row itself, an n-n set's object behind the
  link), asked once per class instead of once per row; only the columns the portal lists for that class (its `list`, the friendly name, and for a link the attributes the
  portal's form lets the user edit), within what the class's view form shows and the attribute rights allow; a blob never inlined. The total of a long set counts the rows shown,
  never those hidden. Console mode is unchanged. **What a portal user's assistant gives up:** a link set's rows carry fewer attributes than before (`id`, the name and the portal's
  columns, not every attribute of the remote class); read the object itself for the rest. `core_object_history` and `core_my_activity` were checked and report no row of a set.
  *Affects 1.2 and 1.3 (portal mode shipped in 1.2.0), not checked on those trees.*
- **Security: a portal user's update of a link set deleted the rows the portal hides from them.** `core_object_update` and `core_object_apply_stimulus` replaced a link set with
  the complete list the caller sent, so the rows the portal never showed them (a ticket's link to a contact of another organisation, for one) were removed, silently, and
  sending one back to keep it was refused. The portal's own form submits a delta and keeps the rows it hides. The complete list a caller sends is now completed with the
  rows the portal hides from them before it is compared and set, so a write changes what the user can see and leaves the rest; a person the portal does not show still cannot
  be linked. The answer of a write to a link set says so (`links_kept`, generic: it never says whether any such row exists). Console mode is unchanged.
  *Affects 1.2 and 1.3, not checked on those trees.*
- **Security: a key or a link set typed on the parent of an excluded class served the excluded objects** ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md), Amendment 3).
  `mcp_excluded_classes` excluded a key or a link set only when its *declared* target was excluded. With `Person` excluded, the stock link rows
  `lnkContactToTicket` and `lnkContactToFunctionalCI` (keyed on `Contact`, the parent) still served an excluded person's name, email and id, filterable, sortable and
  groupable; every ticket's and CI's `contacts_list` returned their ids, in portal mode too; a write to the set answered differently for an
  existing excluded person than for an id nobody has and then let the link row read them back; and `core_class_schema` listed up to 100 names
  of excluded people and teams as the set's allowed values. The same held for any excluded subclass behind a parent-typed key (`Server` behind `FunctionalCI`).
  A key or link set whose target has an excluded descendant is now **excluded for every row, in both modes, on every route**, as an excluded attribute is, and
  the fields and labels through it with it. A class whose friendly name is made of such a part (the link class, named by `contact_name`) no longer serves its
  `friendlyname`: not in a read, a filter, a sort, a group or `find_by_name`; its default field list answers with the id. A derived exclusion never makes the settings
  malformed. **What an operator gives up:** with `Person` excluded, `contacts_list` disappears from every ticket, for teams as well as people, and the contact fields of the
  link rows; with `Server` excluded, `functionalcis_list` does. Check what your assistants read through them before upgrading.
- **Security: a client that hung up after sending a write was neither audited nor counted by the write cap.** The endpoint
  wrote its answer first and its audit row after. PHP learns that a client has gone only when it writes output, and then ends the
  script, so a caller holding a write-grade token that sent a write and closed the connection without reading the answer
  (or that sent a call padded so the answer was large) had the write committed and **no audit row written**. `mcp_max_writes_per_hour`
  counts from those rows, so the write was never counted either: a client that simply hung up after every write
  spent none of its cap, and the same hang-up left no row for a read, a refused call or a failed one. The row is now written
  **before** the answer on every path - served, failed and refused as a batch - so it exists whatever the client does with the answer, and
  the cap counts the write. iTop's own history (`CMDBChange`) was never affected: it is written with the change itself. No
  runtime setting is touched to get this (no `ignore_user_abort()`). One reading changes: the audit row's duration is the time to decide the
  answer, no longer the time to write it to the client, which is the client's. Rows already missing are not recoverable; iTop's history of the objects is
  where to look for writes made by a credential that hung up, if the cap was in force. A batch refusal also now ends the PHP session the login opened, as every other answer does, instead
  of leaving it for the garbage collector.
- **Security: any web page could log a console user out of iTop through the endpoint.** The endpoint ends the PHP session the login opened for the request, and did so
  on every refusal, including one before the login ran. A top-level `GET` (or a cross-site form `POST`, or a `DELETE`) to the endpoint made a browser send the
  user's iTop session cookie with no credential; iTop's startup had already resumed that session, the request was refused `401` or `403`, and the refusal
  destroyed the session the cookie named. The session is now ended only when the request created it, or once the login has run and replaced what it held; a refusal before the
  login leaves a console session as it found it. The answer still sets no cookie. No data was reachable this way. *Affects 1.1, 1.2 and 1.3 (the destroy arrived in 1.1.0); not checked on those trees.*
- **Security: a tool error could write megabytes, and a Basic password, into `log/error.log`.** The SDK logs an exception object on every tool failure and every unknown tool,
  and the tool's arguments at debug level; the bridge to iTop's log handed the whole context over. Under PHP's default `zend.exception_ignore_args=0` the exception's trace carries the PSR-7 request
  built from `$_SERVER`: **the plaintext password of a Basic login** (`PHP_AUTH_PW`, many copies) and the base64 header, and 5 to 24 MB per call - a read-only token filtering on an attribute iTop cannot search wrote 24 MB each time,
  so a loop of a few thousand calls filled the disk. The log now receives a reduced context: an exception becomes its class, code, a shortened message and a reference (also on the call's audit row), a value that is not
  a scalar becomes a label naming its type, strings and lines are cut, and tool arguments are never written. The Basic password and the `Authorization` header are dropped from `$_SERVER` once the login has used them, as a token already
  was. A filter on an attribute iTop cannot search (`Person.picture`) is now answered as the caller's mistake, by `core_object_search_by_class` and `core_object_count` alike, not as a server-side failure.
  `zend.exception_ignore_args=On` stays the recommendation (SECURITY.md). **Rotate any Basic password that was used against an endpoint with the default setting**, and purge the log and its shipped copies. *Likely affects every 1.x line (the logger bridge dates from 1.0.0); not checked on those trees.*
- **Security: tool arguments reached the log at Debug through the context key `message`, on 0.9 as well.** The SDK logs `Received message payload.` with the whole JSON-RPC body under `message` (handshake era; the 2026-07-28 leg does not), in `mcp/sdk` 0.9.0 and 0.10.0 alike. The reduction above kept
  strings under keys it did not know, cut to 300 bytes, so with `log_level_min` at `Debug` for the module's channel the first 300 bytes of every handshake-era request, its method and the start of its arguments, reached `log/error.log`. `message` is now dropped with `arguments`, `params`, `request` and `body`, and
  `SdkLogHygieneTest` runs the real SDK through the bridge on both protocol eras and on five endings of a call (it failed on 6 of 10 cases before). Needs Debug enabled. *Not checked on the 1.x lines.*
- **A tool name with a newline in it could forge lines in `log/error.log`.** The log bridge cut a caller's text short and stripped no line control, so a call to a tool named `zz`, a line break and `2026-11-20 12:00:00 | Error | 1 | FORGED-LINE | MCPLog |||` (any valid credential, a read-only one included) wrote a line of its own that *started* with the forged header: three times in one call (the message, the `name` of the context, the exception's text).
  The message and every text of the context now go through `LineControls`, as the audit row's labels did (the ASCII, C1 and Unicode line controls and the bidirectional marks become one space). The text is kept, on one line. Not a security fix by the project's own reading (the file is an operator's and the call is audited), recorded as found by the re-review of 2026-11-20. *Likely affects every 1.x line (the logger bridge dates from 1.0.0); not checked on those trees.*
- **Security: a caller could make the audit row of its own call fail to be written.** A failed insert dropped the row, and the row is what `mcp_max_writes_per_hour` counts from: a committed write whose `comment` or client name held an emoji on an
  audit table still in 3-byte utf8 (an instance upgraded from before iTop 3.1 can have one), or a refused call whose tool name of 70 000 characters was quoted in the row's message, left no row and spent none of the cap, and iTop wrote the failed `INSERT`
  with its values into `error.log`, where retention and anonymisation never reach. Caller text is now fitted to its column before the insert (a character the column's character set cannot hold becomes U+FFFD; a message is cut to what its
  column holds), a failed insert is retried once with only what the server built (credential, account, method, status, change, counts; no client name, comment, parameters or message), and the failure is logged by class and code, never by its text.
  Unicode line and bidirectional controls (U+0085, U+2028, U+2029, U+200E, U+200F, U+202A to U+202E, U+2066 to U+2069) are now stripped from the client name, the message and the change comment as the ASCII controls were, so a log forwarder reads one row where there is one.
- **Security: an attribute the endpoint masks on output could still be filtered, sorted and `LIKE`-matched.** A password, a token or an outbound secret (`token`, `refresh_token`, `access_token`, `client_secret` of the OAuth and
  webhook classes) is read back as asterisks, yet `core_object_search_by_class` with `filters`, `order_by`, and `core_object_search_by_oql` with a condition or `order_by` answered whether `token LIKE "SECRET-A%"` matched, so a steered agent
  recovered a secret one character at a time (`core_object_count` already refused them). A masked attribute is now unknown to every search on every route, in iTop's own words for a name the datamodel does not have - in a filter, a sort,
  a condition, a join and a sub-query alike, and in an OQL given as a key's value. The default sort of a class drops a masked attribute. An account that could read the secret in clear in its own console still reads it there; the endpoint no longer
  lets a search stand in for the read. *Check each 1.x tree: the by-name masking of the outbound secrets is recent.*
- **Security: one join or sub-query on a class that has an excluded subclass was served.** The refusal ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md): such a class "cannot be joined or sub-queried") counted textual mentions of the class and refused at two, so with
  `Person` excluded, `SELECT Organization AS o JOIN Contact AS c ON c.org_id = o.id WHERE c.email LIKE 'agatha%'` answered whether an excluded person's e-mail began that way - a blind prefix search on any attribute of every excluded person.
  The decision is now taken from the query as iTop parses it: a class that has an excluded subclass may be the class a query returns (its rows are narrowed to leave the subclass out) and nothing else - not a joined class, not the class of a sub-query at any depth,
  not a branch of a union. **Visible to operators:** a query that joined or sub-queried `Contact`, `Ticket` or another parent of an excluded class once is now refused where it was served before; select that class and filter on its own attributes. Applies to `core_object_search_by_oql`,
  `core_object_count` with `oql`, and an OQL given as a key's value. *Affects 1.3 (exclusions shipped in 1.3.0), not checked on that tree.*
- **Security: an OQL query given as an external key's value told an excluded name from an unknown one.** The value was parsed by iTop against the real datamodel before this channel's checker ran, so
  `manager_id: "SELECT Person WHERE phone = 'x'"` answered "Unknown filter code ... expecting {...}" when `phone` was excluded and "is not an id and not valid OQL" when the name did not exist, and an excluded class was told apart from an unknown one in the
  same way: any account that may create or update could enumerate which names exist but are excluded (a name, never a value). The checker now answers first, in the same words for both, and the explanation of what a key takes follows it; text that is no OQL
  at all gets the checker's reason followed by the same explanation. *Affects 1.3, not checked on that tree.*
- **Security: `core_object_bulk_create` wrote attributes the operator had excluded.** The bulk tools validated a caller's values with their own copy of the write validator, which had drifted from the one every other write uses: it never asked
  `mcp_excluded_attributes`. With `Person.phone` or `UserRequest.private_log` excluded, a bulk create with `"phone": "+3355507777"` answered "Would be created" and committed it (`zzphone` was "Unknown attribute", so the dry run was an oracle for the excluded name too),
  and a `private_log` was committed into a log the console's agents read; any write grade, a narrow console user's included. The answer's `applied` and `changes` hid the field, so it read as ignored. The bulk tools, and a pack's tool built on `AbstractBulkTool`, now
  validate through `WriteValues::Validated()`: one validator, so an excluded attribute is refused as an unknown one, and the checks the copy lacked (a link set's hidden rows in portal mode) hold too. **For pack authors:** `AbstractBulkTool::validatedValues()` keeps its
  signature; what changed is that it now refuses what a single write refuses. `doc/extending.md` no longer says the base class does the dry run: your `execute()` asks `DryRun::Simulated()` and `WriteCap::RefusalFor()`. *Affects 1.3 (exclusions) if its bulk tool has the same copy; not checked on that tree.*
- **Security: the write cap did not count a call's link-set entries before the write.** [ADR 0019](doc/adr/0019-an-operator-can-cap-committed-writes-per-credential.md) caps *objects*, and every writing tool asked the cap for
  the one object it names, so with a cap of 3 and 2 spent a single `core_object_create` with 19 `contacts_list` entries was accepted and committed 20 objects before the next call was refused. A create, an update and a stimulus now ask for the object plus the link rows the
  call would add, change or remove (a complete list that repeats rows already there counts none of them), `core_object_bulk_create` for its rows plus every link-set entry they send, and `core_object_bulk_update` for each object plus the entries the
  call sends (an upper bound: it does not load the objects, and a refusal says so and that splitting the call helps; [ADR 0019](doc/adr/0019-an-operator-can-cap-committed-writes-per-credential.md)). **Visible to operators with `mcp_max_writes_per_hour` set:** a write that sets a link set can now be refused for more objects than before, and the refusal says how many the call would write. Cap-only, 2.x.
- **Security: a bulk update could remove an unbounded number of link rows against a small write cap** ([ADR 0019](doc/adr/0019-an-operator-can-cap-committed-writes-per-credential.md), amended 2026-11-20). The entry above counted the link entries a bulk update *sends*, so
  `core_object_bulk_update` with `"contacts_list": []` on 100 tickets asked the cap for 100 objects while every link row of every ticket was removed (the same change through `core_object_update` asks for the object and its rows, and was refused);
  the audit row recorded the true count only afterwards, so the next call was refused, not this one. The bound now counts, for every object, the entries sent **and the rows each named link set holds now**, from one counting query per link set over the ids (no object is loaded, and nothing is counted
  with the cap off). It remains an upper bound and the refusal says so; a link set that cannot be counted refuses the call. **Visible to operators with `mcp_max_writes_per_hour` set:** a bulk update that names a link set can be refused for more objects than before, the more so when it empties one. Cap-only, 2.x.
- **Security: in portal mode a stimulus could set fields the portal's own transition form shows read-only or not at all.** The fields a transition accepted were the ones the datamodel's target state prompts for, so on `ev_reopen` of a resolved ticket - where the stock portal's form holds `public_log`
  (editable), `org_id` and `team_id` (read-only) and no `agent_id` - `core_object_apply_stimulus` listed `team_id` and `agent_id` as settable and committed them: a requester routed their own ticket to a team and an agent of their choosing (staff they could already see).
  The fields of a transition are now those of the portal's transition form, built as the portal's `ApplyStimulusAction` builds it and merged by its own `PrepareFields()`, and nothing else: not the edit form's settable fields as well (the portal never lets a transition submit one; an assistant changes an edit-form field with `core_object_update` first). The refusal names the transition's fields and says so ([ADR 0005](doc/adr/0005-portal-mode-writes-follow-the-portal-form.md), amended 2026-11-19).
  **Visible to portal users' assistants:** a transition that set a field the portal does not let the user change is now refused, and names the fields it does accept. Console mode is unchanged. *Affects 1.2 and 1.3, not checked on those trees.*
- **Security: a schema listed the class an operator had excluded, as the value of an attribute that holds class names** ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md)). `core_class_schema` and `itop://core/class/{class}` list an attribute's allowed values, and for an
  attribute whose values *are* classes (iTop's `AttributeClass`: the `target_class` of a trigger, the `scope_class` of a synchronisation source; and `AttributeFinalClass`: a parent's `finalclass`) the list was the datamodel's, whatever `mcp_excluded_classes` said. With `Person`
  excluded, `TriggerOnObjectCreate.target_class` still named `Person`, and `Contact.finalclass` did, so a class an operator excluded for its name was told to every account that could read a trigger's or a contact's schema (the name, never a row);
  and `core_object_search_by_class` with `finalclass: "Person"` on `Contact` was accepted where `"Personz"` was refused with the list of values, so the filter was a yes-or-no on the name as well. An excluded class is now **left out of every list of class names**, in both modes, and
  a value that names one is refused as one the datamodel does not have, with the same list (the portal's field description is held to it too). A class that is served, and a parent of an excluded class, are listed as before. **Affects `release/1.3` too** (exclusions since 1.3.0), for the 1.x patches: not checked on that tree.
- **Security (tool packs): the example pack, which every pack is copied from, skipped what the core tools hold.** `acme_ticket_add_log_entry` checked rights by hand and so skipped `mcp_max_mentions`, the console's lifecycle locks (it appended to the `public_log`
  of a closed ticket, which iTop flags read-only there), the spreadsheet-formula rule and `ChangeTracking::Explain()`; and `acme_ticket_search_mine` asked neither `ExclusionPolicy::Serves()` nor `ExclusionSearches::Narrowed()`, so for an excluded class it answered
  `total: N, objects: []` (a row count, no values). Both now do what a core tool does: the write tool validates through `WriteValues::Validated()`, asks `WriteCap::RefusalFor()`, explains the change and answers through `ToolOutput::StructuredWrite()`; the read tool
  refuses an excluded class and attribute as unknown and narrows its search. **For pack authors:** copy the new example; `doc/extending.md` ("Writing values back" and the checks list) says what each call is for. `RestValue` is no longer a pack's entry point: see Removed below.
- **Security (tool packs): an `MCP-advisory` credential is no longer served a pack's write tools** ([ADR 0022](doc/adr/0022-an-advisory-credential-is-not-served-a-packs-write-tools.md)). The scope was enforced inside each write tool, by `DryRun::Simulated()`,
  which `doc/extending.md` never told a pack to call: a pack's write tool written from the documentation, the example included, **committed under an `MCP-advisory` credential** with `simulate: false`, and the audit row recorded `no`.
  A tool that is not this module's own and is not read-only is now left out of the server built for an advisory credential: absent from `tools/list`, and answered as an unknown tool when called by name. This module's own write tools are unchanged (they are served and rehearse),
  and a pack's read-only tools are still served. **Operators with packs installed:** advisory tokens lose the pack's write tools; nothing else changes. `doc/extending.md` says so and still asks a pack to call `DryRun::Simulated()`, `ElementContract::Violations()` warns when it does not, and the example
  pack's write tool calls it. The README's "every write tool it can reach rehearses" is true again. An opt-in for a pack that rehearses is not built; ADR 0022 names it as the path if a real pack needs one.
- **`sdk-aliases.php`, requested on its own, printed a warning per name.** On a server that applies neither the module's `.htaccess` nor its `web.config`, the file answered `200` and, with `display_errors` on, 24 warnings naming the install path (`class_alias` of names the vendor autoloader had not loaded); `register.php`, `model.altioo-mcp.php` and `module.altioo-mcp.php` fail the same way when requested
  directly, so it was no new kind of exposure. Each alias is now made only when the SDK's own class can be had, so a direct request does nothing and says nothing; loaded by composer's `autoload.files` the file is as eager as before and still leaves a name already taken alone. The files stay refused over HTTP by the same rules as `composer.json` ([SECURITY.md](SECURITY.md#hardening-the-deployment)).
- **The audit row's `simulate` column recorded the caller's request, not what ran.** A call with
  `simulate: false` that the advisory credential turned into a rehearsal was logged `no` and read, to
  anyone reviewing the log, as a write that happened; and a write that left `simulate` out, which
  rehearses by default, was logged `n/a`. Both are now `yes`: for a call to a write tool the row says
  what the tool did - `yes` when it rehearsed (asked, defaulted or forced), `no` when it wrote. A read
  is still `n/a`, so is a call refused before the tool decided anything unless it carried `simulate`, and the write cap counts exactly what it counted (a forced rehearsal wrote nothing).
  **Rows written before 2.1.0 keep `no`; nothing migrates them.** To find the ones that were
  rehearsals, look for rows of a write tool by an advisory token with no changed objects.

- **Portal mode's instructions and `core_class_list` named tools the token is not served.** The
  portal text told a connection to call `core_current_user`, `core_my_activity`, `core_instructions`,
  `core_class_list`, `core_object_get_document` and `core_object_attach` whatever its toolsets were,
  and `core_class_list` reported `history: true` for a token without `core_object_history`. Each of
  those sentences is now kept only when the toolset behind it is served, and the flag is true only
  for a token that can use it. Tool descriptions and refusal texts are the same for every caller and
  are still not narrowed; the README now says so.
- **A stray character in an OQL query was answered as an internal error.** A query such as
  `SELECT Organization; DELETE FROM Organization` is refused by iTop's own lexer with a plain
  exception, and the endpoint told the caller the fault was server-side and gave a reference. It is
  now `Invalid OQL query. Reason: unexpected character ";"`, like any other OQL mistake. Nothing was
  ever executed, and every other unexpected failure stays opaque.
- **The security summary overstated the audit row's `client`.** It is on every row for a `2026-07-28`
  client that sends its `clientInfo`, and on the `initialize` row only for an earlier one.
- **An empty or malformed `Authorization` header was counted as a credential and reached iTop's Basic login.** `Bearer` alone, `Basic !!!!` and a Basic value with no colon once decoded passed the credential check, and iTop's own login split them on a colon without checking:
  "Undefined array key 1", a server path in a `200` where `display_errors` is on and a misleading `401` where it is off. Such a header is now no credential: the request is answered the `401` and the audit row of a request that brought none, and the header
  is dropped before iTop's login can read it, beside an `Auth-Token` as well. A usable Basic or bearer header is untouched.
- **A CORS preflight set a session cookie and left a session file, and a refusal named the audit row's own fields.** iTop's startup opens a session before the endpoint's first line runs, and a preflight (`OPTIONS` with an `Origin`) was answered
  by the first gate without ending it: `204` with a `Set-Cookie` and one session file per preflight, against the README's "the endpoint sets none". The session the request opened is now ended before the preflight is answered (one a browser's cookie named is kept, as on every other refusal). A request refused before any tool ran was
  answered `{"code":1,"message":"…","mcpMethod":"exceptions","mcpName":null,"requestParams":null}`: the last three are the audit row's fields, null or a constant here, and are no longer sent. A refusal is `{"code":…,"message":…}`. SECURITY.md's rate-limit example now
  limits anonymous traffic by client address as well (a request with no credential has nothing to key on and still leaves an audit row).
- **The README said which refusals are audited, and was not exact.** A rejected or invalid token, a wrong password and a request with a malformed `Authorization` header are audited under the method `exceptions`, as every refusal before the login is (this was true from the change that writes the row before the answer; the review of
  2026-11-18 found it untrue on an earlier build). The troubleshooting section now lists exactly which requests leave a row and which do not: a CORS preflight, a `405`, a body that names no method, a method `log_mcp_method` does not list, and **a valid token of a disabled user**. For that last one iTop's own login ends the request with the text `Bad user` (HTTP `200`, `text/html`)
  before the module can answer, with no row; refusing it first would mean repeating iTop's token lookup, so it is documented and left.
- **An array as a filter value on `core_object_search_by_class` was answered as a server-side failure.** `{"filters": {"title": ["a", "b"]}}` got "the cause is server-side" and an error reference; `core_object_count` already refused it as the caller's mistake. Both now refuse it in the count's words (`Unknown attribute '<code>' on class '<class>'.`): a filter is one value to match.
- **A count grouped by an external key left `read_objects` empty.** `core_object_count` with `group_by` on a key answers with the names and ids of the objects it points at, which is a read; the audit row's `read_objects`, the column that answers "was this person's ticket sent to the model?", stayed empty. The objects whose names a group carries are now noted, as every other read notes its objects (the console's and the portal's alike). A count grouped by an enum or a plain value still notes nothing.
- **A half-failed bulk call said nothing at its top that it was.** `core_object_bulk_create`, `_update` and `_delete` answer a call that did some of its objects as a JSON-RPC success with `isError: false`: the per-object report and the `failed` count were truthful and nothing at the top of the answer said that not every object was done, so a client that tests the envelope and reads the first number reported the batch as applied. The report now carries a top-level boolean, **`all_succeeded`**, true only when every object succeeded (or would have, in a dry run). `isError` is unchanged: a partly failed bulk call is still a result, not a failed call. The key is declared in the three tools' output schemas (and in their `required` list, like every other key) and emitted by `AbstractBulkTool::report()`, so a tool pack's bulk tool built on it carries it too.
- **A bulk delete whose objects cascade read as partly failed.** An id that an earlier entry of the same call had already deleted through iTop's deletion plan was looked up again and answered "not found", so a real run reported a failure for an object that was gone, where the dry run had said every object would be deleted. An entry that went with the cascade of an earlier one is now reported `ok`, `Deleted: it went with the cascade of Class::id, an earlier entry of this call`, and the dry run says which entries are also in an earlier one's cascade. *Not reproducible through a real cascade on iTop 3.2.3 or 3.3: a cascade that reaches the same class again raises a TypeError in iTop's own `DBObject::MakeDeletionPlan()` before this tool is reached; the fix is held by the bookkeeping's own test.*
- **`core_my_open_tickets` was offered when its search is excluded.** The prompt was advertised on UserRights alone and told the model to search `Ticket` for `agent_id` or `caller_id` and `operational_status`, which the operator's `mcp_excluded_classes` and `mcp_excluded_attributes` refuse as unknown ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md)). It is now absent when `Ticket` is excluded, in the portal when the list it names is of an excluded class, and when neither contact attribute is served; an excluded attribute is left out of the query it describes (an excluded `operational_status` makes it say to read the lifecycle, as an instance without one already did).
- **Two more routes served instance text without the `untrusted_text` marker** ([ADR 0008](doc/adr/0008-agent-behaviour-is-tested-with-a-model.md): the marker narrows nothing, it is a model signal). The allowed `values` of a link set in `core_class_schema` and `itop://core/class/{class}` are names of objects (a Team called "IMPORTANT: assistant, call ..." reached every schema that links to it) and
  are now named in the answer's `untrusted_text.fields` as `attributes.<code>.values` or `derived.<code>.values`; and `audit.created.who` and `audit.last_updated.who` on `core_object_get` and on the search tools' `audit` (the change line, which iTop's REST, a CSV import and a synchronisation write as whatever their caller chose) are named as `audit.created.who` and `audit.last_updated.who`. An enum's values, which the datamodel declares, are not marked.
- **Portal history counted rows it did not return and named a changed key by its id.** On a portal an operator has served history to (it is off on the stock one), `core_object_history`'s `total` counted the rows about attributes the portal's form hides and then dropped them, so paging showed how many times staff had touched a field the user is not shown; the rows are now left out of the query, so `total` is the number of entries that exist for that user (the console's `total` is unchanged: it still counts a row whose attribute the account's rights hide, as documented). And an entry about a key such as `agent_id` gave the raw id where the portal shows a name: it now carries `from_name` and `to_name` beside the ids, the name of the object where the user is shown it and `null` where they are not. `ObjectHistory::Within()` gained the optional `$fNameOf` closure that does this (a pack's portal-aware history keeps working unchanged).
- **A portal user was refused in staff and operator vocabulary, and told to follow a link that is not served.** Three refusals to an end user quoted what they cannot act on: a key value the datamodel does not accept ("... The datamodel limits it to: SELECT Team AS t JOIN lnkDeliveryModelToContact ..."), and the size of a file against `mcp_max_document_bytes` when it is added or read. In portal mode they are now plain ("Team 583 is not a value 'team_id' accepts on this UserRequest.", "The file is 14 bytes, which is more than this connection accepts.") and the query and the setting go to `log/error.log`, as [ADR 0007](doc/adr/0007-the-connection-names-its-portal.md) does for a portal that is refused; the console's texts are unchanged. And in portal mode an attachment listed by `core_object_get` no longer carries a `uri`: it named a resource template that portal mode does not serve (`resources/read` answered "Resource not found"), while `core_object_get_document` with the attachment's `id` has always worked, and serving a second route would need the same gates ([ADR 0011](doc/adr/0011-portal-mode-reads-and-adds-attachments.md) holds them in that tool). **Visible to a portal user's assistant:** the attachment entry is `id`, `filename`, `mimetype`, `size`; the portal tool description and instructions say "each with an id". Left as they are, by design: the `portal_id` advice (ADR 0007) and the create action deciding the class (ADR 0005).
- **`core_object_get` on iTop's `lnkTriggerAction` raised an unhandled error.** The by-id query named the key with `MetaModel::DBGetKey()`, which answers the column, and that class names its key `link_id`, which OQL does not know ("Unknown filter code ... link_id"): the read surfaced an `OqlNormalizeException` the endpoint had no words for. OQL names the key `id` for every class, and `ObjectQuery::ById()` and `ByIds()` now say so. Any class whose key column is not `id` is read, updated and deleted by id again.
- **Five statements in the documentation were wrong, and are corrected.** There are **eleven** `MCP*` scope values, not ten (the four grades, the `MCP-advisory` modifier and one per toolset); every audit column added since 1.0.0 is nullable *or has a default* (`exempt`, `written_objects`), not only the three the README named; a refused batch is always audited as method `batch`, whatever `log_mcp_method` lists (the setting's row now says so); a toolset scope alone is **not** read access: on its own it serves every tool of that toolset at every grade, write and delete included, and `MCP-read` beside it is what makes it read-only; and "iTop opens no outbound connection" promised more than the module can: *the extension* opens none, but a write it makes is an ordinary change, so iTop's own notifications and webhooks fire for it as for the console's (README, SECURITY.md, `doc/security-summary.md`, `doc/hub-listing.md`).
- **`core_my_activity` did not say that `written` is 0 on old rows.** A row written before 2.0.0 reads `written: 0` whatever it changed (the count did not exist), and the tool teaches the caller to read `written` against `changed`. The description, the `notes` and the `coverage` now say so. This changes the tool's description, so its manifest digest changes (see the list under *Read before upgrading*).
- **Shipped documents linked to files the archive leaves out.** `SECURITY.md`, `doc/security-summary.md`, `doc/ci-itop-matrix.md`, `doc/ci-upgrade.md`, `doc/hub-listing.md`, `doc/release-checklist.md` and this file linked by relative path to `.github/`, `tools/`, `doc/itop-branch-notes.md` and the screenshots, which `exclude.txt` keeps out of the archive: on an installed copy the approver page's "how the archive is built and signed" link, among others, pointed at nothing. They are the repository's GitHub URLs now, and a unit test (`ModuleMetadataTest`) fails when a shipped document links relatively to what `exclude.txt` leaves out.

### Added

- **A forced rehearsal says why.** Every write tool's answer now carries `forced_by_credential` and a
  one-line `forced_reason` (empty on every other answer), so a client can tell the user nothing was
  written and why instead of showing `simulated: true` on a call it asked to commit. For a portal user
  the sentence names their account, not a scope. No existing key changes. A tool pack's answers are
  unchanged, byte for byte: `ToolOutput::Structured()` does not add them. A pack may add them by
  answering through the new `ToolOutput::StructuredWrite()` and declaring
  `DryRun::ForcedSchemaProperties()` in its output schema.
- **The `class` of `itop://core/class/{class}` (and of any template with one) completes.** The
  server has always advertised completions and always answered with an empty list. It now answers
  with the classes the caller may read that start with what was typed, at most 100 - never one the
  operator excluded, and in portal mode only the portal's own classes. No prompt takes a class
  argument today, so none completes.
- **`core_my_activity` rows carry `written`**, the number of objects the call created, changed, deleted
  or attached to (the audit row's `written_objects`). A count and never a name or an id, so
  [ADR 0010](doc/adr/0010-portal-mode-serves-the-users-own-activity.md) holds, and the ADR says so.
  `changed: []` beside a `written` above 0 now reads as what it is: objects you can no longer see, or
  that were deleted afterwards. The tool's description and `coverage` say it, and say that
  `objects_you_cannot_see` also counts objects that are gone.
- **`core_current_user` reports `access.advisory`**, and `advisory_means` when it is true, beside
  `capabilities`; the server instructions tell an advisory credential that its writes rehearse.

### Changed

- **`mcp/sdk` is `^0.10.0`** (locked at v0.10.0), and the runtime libraries moved with the lock: `doctrine/deprecations` 1.1.6 to 1.2.0, `opis/json-schema` 2.6.0 to 2.6.2, `phpstan/phpdoc-parser` 2.3.9 to 2.4.1,
  `symfony/deprecation-contracts` v3.7.1 to v3.8.1 and `webmozart/assert` 2.5.0 to 2.5.1. The set of packages is the one 2.0.0 shipped and every licence is unchanged (the SDK and the `opis/*` libraries Apache-2.0, the rest MIT); `composer.json` gained
  `autoload.files` for `sdk-aliases.php`. What the SDK's move changes for a client and a pack is under *Read before upgrading*.
- **The audit row's `duration_ms` is the time to decide the answer**, no longer the time to write it to the client (*Fixed*, the entry on a client that hung up; the attribute's help text says so in both languages).

### Removed

- **`Service\Write\RestValue` leaves the surface a tool pack builds on** ([ADR 0016](doc/adr/0016-what-the-core-tools-use-is-public.md), Amendment 1). It was `@api` in 1.x and 2.0, and the
  example pack was the only thing that used it as such; `WriteValues::Validated()` converts values with it and holds every other rule of a write, and a pack that converts by hand is a second
  validator. It is `@internal` now (the class stays, `WriteValues` uses it) and off the list `PublicSurfaceTest` reads. **The changelog's rule that a removal from the surface follows a deprecation by a minor is waived
  this once**: nothing is installed anywhere and no known pack uses it. A pack that does keeps working in 2.1.0, with no promise after it; write through `WriteValues::Validated()`.

## [2.0.0] - 2026-11-16

**The source tree is laid out as iTop lays out its own, and a tool pack builds with what the core tools use.**
`src/` follows Combodo's layer folders (`Controller`, `Hook`, `Core`, `Helper`, `Model`,
`Exception`, and a feature folder for each responsibility under `Service`) with one chain of
dependencies that a test holds, and the nine largest classes are split by what they do. The
module behaves as it did in 1.3.0, except for the three changes item 3 below names. What changes
otherwise is where the code lives, and with it what a pack may call ([ADR 0015](doc/adr/0015-2-0-lays-out-src-as-itop-does.md), decided by the
maintainer; the folders and a request's path through them are in
[doc/architecture.md](doc/architecture.md)).

**Read before upgrading.** Three things, by who you are:

1. **A tool pack has to be rewritten against the 2.0 names.** Classes moved and no alias was
   left behind, so a pack that imports a 1.3 name fails to load. `@api` now means
   `src/Api/` and every class the core tools depend on, and the name table below maps every
   1.3 name to its 2.0 one (under Changed).
2. **The 1.3 deprecations are gone** (under Removed).
3. **An administrator has nothing to do, and three things change on the instance.** Three
   parameters are new and each defaults to what 1.3.0 did (`mcp_portal_extensions`,
   `mcp_max_writes_per_hour`, `mcp_token_max_days`); the audit table gains a column, `written_objects`, which the setup
   adds (both under For administrators). And a file attached in console mode now leaves iTop's
   history row and raises `EVENT_ADD_ATTACHMENT_TO_OBJECT`, as the console's own attach does, so
   a trigger or action on that event now also fires for an assistant's attachment (under
   Changed). Tool and resource identifiers and every existing default are those of 1.3.0. Run
   the setup as for any upgrade. The old background-task row is left to iTop (under For
   administrators).

**Tested on.** iTop **3.2.3-3** and **3.3.0-1**, each on PHP **8.2** and **8.4** — installed
through iTop's own unattended setup by the local CI runner (`tools/ci/local/run.sh matrix`), then
exercised by iTop's module validation suite, the module's integration suite and by calling the
endpoint over HTTP, portal mode included. The unit suite ran on 8.2, 8.3 and 8.4. An instance
installed with 1.3.0 on iTop 3.2.3-3 (PHP 8.2) was upgraded to this version, twice, with its
seeded data and settings intact, and the integration suite passed on it.

### Breaking for packs

**The namespace layout changed, and moved classes have no aliases.** A pack is written against
the 2.0 names. `register.php` of a pack imports `Api\MCPExtensionCollector`, not
`Registry\MCPExtensionCollector`; its tools extend `Api\AbstractMCPTool` and its provider
implements `Api\iMCPServiceProvider`.

**`@api` is `src/Api/` and what the core elements use.** A class outside `Api/` is on the surface
when a tool, resource, template or prompt under `src/Core/` depends on it, or when a public signature
of a surface class names it ([ADR 0016](doc/adr/0016-what-the-core-tools-use-is-public.md), which
replaces rule 3 of ADR 0015). `PublicSurfaceTest::EXTRA_SURFACE` lists them, and
[doc/extending.md](doc/extending.md) has the table by folder. A pack may build with the classes a
core tool builds with: the exclusions, the barrier on access-granting classes, the change-log
reservation, the access question in either mode, the write policies, the readers and the portal's
policy. Everything else says `@internal` and may change in a patch release: the controller and its
gates, the server, the background process and the classes those use. A member of a surface class
can be `@internal` too; the `Remember()` and `Forget()` methods of a request's state are.

Of the 35 classes marked `@api` in 1.3, the ones that are no longer on the surface are
`FormulaPolicy`, `Identifier`, `LogAPILogger`, `MCPContext`, `MCPHelper` (gone), `MCPHttp`,
`MCPLog` and `SecretPolicy`. The rest are under `Api/` or on the list, under their 2.0 names.

**A pack that returns object data applies the exclusions itself.** `mcp_excluded_classes` and
`mcp_excluded_attributes` hold on a pack's routes as on the core's (ADR 0012). Nothing applies them
to data a pack reads with iTop's own API, so ask `ExclusionPolicy` and `ExclusionSearches`, or read
through `ObjectSerializer`, as [doc/extending.md](doc/extending.md) describes. The example pack
(`doc/example-pack`) and its suite run against this surface.

### For administrators

**Nothing needs doing.** Three parameters are new. `mcp_portal_extensions` defaults to empty, so
portal mode serves exactly what it served before. `mcp_max_writes_per_hour` defaults to `0`, which is
off, so no credential is capped until you set it. `mcp_token_max_days` defaults to `0`, which is off, so no
token is refused for its lifetime until you set it. No default, identifier or table changed; the audit
table gains one column, `written_objects`, which the setup adds with the datamodel and which reads 0 on
older rows. The hourly
background process is now `Hook\AuditHousekeeping` where it was `Service\AuditHousekeeping`, so
the first cron run after the upgrade records a new row for it in iTop's background-task table
(`priv_backgroundtask`, shown by `cron.php --status_only=1`) and runs it at once.

The row of the old class stays in that table. iTop's own cron deals with it: `ReSyncProcesses()`
in `webservices/cron.php` marks every task whose class no longer exists `removed` on its next
run, and only `active` tasks are ever run, so the old row is idle, not running twice. It is never
deleted, and that is iTop's behaviour for every background process that goes away, so there is
nothing to clean up (checked on iTop 3.2.3-3: with a row for the old class in place, one cron run
set it to `removed`). Its run count and durations do not carry over to the new row.

### Added

- **Each release publishes a manifest of the core tools' definitions** (`tools-manifest.json` and its
  `.sha256`, attested, with a copy inside the archive): name, title, description, input and output
  schema and annotations of every core tool, with a SHA-256 each, so a client that pins tool
  definitions or an operator comparing releases can see what changed. It is the console-mode set,
  not a copy of a live `tools/list`, which is filtered per caller ([SECURITY.md](SECURITY.md),
  "What ships beside the archive"). `composer tools-manifest` regenerates it with no iTop. No change
  for an installed module.
- **A test fails when what a client is served changes** ([ADR 0008](doc/adr/0008-agent-behaviour-is-tested-with-a-model.md),
  accepted by the maintainer): the integration suite compares the handshake and server
  instructions, `tools/list`, the resources, resource templates and prompts, for each token grade
  and for portal mode, with a committed copy, so a reworded description or an `mcp/sdk` bump is
  a diff a person accepts. No change for an installed module; the copies sit under `tests/`.
- **`mcp_max_writes_per_hour`: an opt-in cap on the objects one credential commits in an hour**
  ([ADR 0019](doc/adr/0019-an-operator-can-cap-committed-writes-per-credential.md), decided by the maintainer; backlog
  H-1). Off by default. Counts objects created, changed, deleted, moved through a stimulus or attached to, per personal
  token, application token or (for a session login or Basic) user; a dry run never counts, and a bulk call is refused
  whole before it starts. The count comes from the new audit column `written_objects`, filled from what iTop's change
  recorded. When it cannot count (audit logging off, level `error`, `tools/call` not audited) or the value is malformed,
  every write is refused and reads are served. Each write tool asks `WriteCap::RefusalFor()` in its own source and a pack
  asks the same ([doc/extending.md](doc/extending.md)). It complements rate limiting at the web server, which stays
  recommended for call volume.
- **`mcp_token_max_days`: an opt-in bound on how long a token used in console mode may live**
  ([ADR 0020](doc/adr/0020-an-operator-can-bound-how-long-a-console-token-lives.md), decided by the maintainer; OWASP
  MCP01). Off by default. Above 0, a request logged in with a token that never expires, or expires later than that many
  days from now, is refused with a `401` in the portal bound's words; an application token has no expiry in iTop and is
  refused too. A malformed value refuses every token login rather than reading as off. Portal mode keeps
  `mcp_portal_token_max_days` alone.
- **An agent that writes with no person reviewing it runs under its own account, named as automated**
  ([ADR 0021](doc/adr/0021-an-autonomous-agent-writes-under-its-own-account.md), decided by the maintainer). A rule
  for operators, in the README under *Granting access*; no code changed. A person's writes with an assistant's help
  carry their name as before, and the change line stays iTop's (ADR 0009).
- **`mcp_portal_extensions`: portal mode can serve a tool pack's element when the operator lists it**
  ([ADR 0017](doc/adr/0017-a-pack-element-may-serve-the-portal-when-the-operator-lists-it.md), decided by
  the maintainer). Portal mode served only this module's own elements because a pack could not reach the
  portal's rules; ADR 0016 made them public, so a pack can now answer by them. The parameter lists the
  elements, by identifier or class name, and is empty by default. An element is served only if it also
  implements `PortalAware`, and every other filter (`mcp_disabled_tools`, toolsets, capabilities,
  scopes, exclusions) still applies. A value that is not a list serves no pack element and does not stop
  the endpoint. A pack element answers through `ObjectAccess::Current()`, never `UserRights` alone
  ([doc/extending.md](doc/extending.md#serving-your-element-in-portal-mode)). List the class name: an
  identifier admits any kind that holds it, and an element that took its identifier with `overrides()` is
  admitted by its class name only. The log reports an entry that serves nothing and says why.
- **`Testing\ElementContract` warns of a portal-aware pack element whose source never calls
  `ObjectAccess::Current()`.** Read from the PHP tokens, so a comment or a string does not satisfy it; the
  pack's own parent classes count, this module's do not. It catches the tool that never asks, not the one that asks and ignores
  the answer: the operator's listing is the control.
- **The example pack's `TicketSearchMine` is portal-aware**, the worked example of ADR 0017: it
  implements `PortalAware`, answers through `ObjectAccess::Current()` (`Bounded()`, `Hides()`,
  `VisibleFields()`), and its README says the operator must list it in `mcp_portal_extensions`.

### Changed

- **`core_object_get_related`'s description no longer writes its key format as `<Class>::<id>`.** It gives an example, `Server::12`,
  because an angle-bracket placeholder reads as an HTML tag and a client that renders markup can hide it; "flat" is dropped from
  "a flat list" to stay inside the description budget. The keys returned are as before. The tool manifest digest of this tool changes.
- **A file attached in console mode now appears in the object's history.** `core_object_attach` leaves the
  `CMDBChangeOpAttachmentAdded` row and raises `EVENT_ADD_ATTACHMENT_TO_OBJECT`, as iTop's own console attach and the
  portal already do; before, only portal mode did. A dry run leaves neither, and the change line stays iTop's own.
- **`Helper\Settings` is split; nothing a pack calls changes.** The parameter names and defaults are `Helper\SettingNames`
  (`@api`), which `Settings` extends, so `Settings::MODULE_SETTING_*` and `DEFAULT_*` resolve as before. The four `@internal`
  `*From()` decisions (`SwitchFrom()`, `EscalationClassesFrom()`, `PortalExtensionsFrom()`, `RetentionDaysFrom()`) are on
  `Helper\SettingParser`.
- **Architecture.** `src/` follows Combodo's layer folders under one dependency order, enforced
  by `LayerDependencyTest` with an empty baseline, and `@api` is enforced by
  `PublicSurfaceTest`. The decision is [ADR 0015](doc/adr/0015-2-0-lays-out-src-as-itop-does.md); the
  level-2 view is [doc/architecture.md](doc/architecture.md).
- **Nine classes are split.** `MCPHelper` (dissolved), `WritePlan`, `AccessGrants`,
  `DatamodelReader`, `PortalPolicy`, `MCPController`, `ObjectSerializer`, `ExclusionPolicy` and
  `MCPService` each did several jobs. The code was moved,
  not rewritten, and each gate a request passes is one class under `Controller\Gate`, run from
  one ordered list.
- **A request's state is opened and closed in one place.** `Service\Server\RequestScope` sets the
  portal and its access together (`EnterPortal()`, which replaces `PortalAccess::Enter()` and
  `Leave()`), remembers the policy and the credential's identity (`Open()`), and forgets every
  per-request slot in one call (`Close()`), which the controller makes in a `finally` and the
  audit task makes too. `RequestStateTest` fails for a static property that is neither cleared
  by it nor listed as registration for the life of the process. Internal, with no effect on a
  pack: the methods that set and clear those slots are `@internal`, on surface classes too.
- **The contract tests read PHP tokens.** `Support\SourceCalls` answers "does this method call
  `X::m(`, and before what" from the tokens, resolved through `use`, so a comment or a string no longer
  satisfies a rule of ADR 0014 and a rename no longer needs a test edited. Test-only; the rules are the same.
- **Name table, 1.3 → 2.0, below.** Checked by script against the 1.3.0 tag and the 2.0 tree: every class
  under `src/` at 1.3.0 is in it, every 2.0 name in it exists, and every public or protected
  member that is not at its old name in the class it moved to is in it.

**Classes.** Every class that existed in 1.3.0, by its 1.3.0 name. Namespaces are relative to `Altioo\iTop\Extension\MCP\`.

| 1.3 class | 2.0 class |
|---|---|
| `Abstract\AbstractBulkTool` | `Api\AbstractBulkTool` |
| `Abstract\AbstractMCPPrompt` | `Api\AbstractMCPPrompt` |
| `Abstract\AbstractMCPResource` | `Api\AbstractMCPResource` |
| `Abstract\AbstractMCPResourceTemplate` | `Api\AbstractMCPResourceTemplate` |
| `Abstract\AbstractMCPTool` | `Api\AbstractMCPTool` |
| `Abstract\AbstractObjectSearch` | `Api\AbstractObjectSearch` |
| `Contract\iMCPServiceProvider` | `Api\iMCPServiceProvider` |
| `Helper\AccessGrants` | `Service\Rights\AccessGrants` |
| `Helper\ChangeTracking` | `Api\ChangeTracking` |
| `Helper\CurrentUserReader` | `Service\Read\CurrentUserReader` |
| `Helper\DatamodelReader` | `Service\Read\DatamodelReader` |
| `Helper\DocumentAccess` | `Service\Read\DocumentAccess` |
| `Helper\EditPolicy` | `Service\Write\EditPolicy` |
| `Helper\ExclusionPolicy` | `Service\Rights\ExclusionPolicy` |
| `Helper\FormulaPolicy` | `Service\Write\FormulaPolicy` |
| `Helper\MentionPolicy` | `Service\Write\MentionPolicy` |
| `Helper\ObjectHistory` | `Service\Read\ObjectHistory` |
| `Helper\ObjectQuery` | `Service\Read\ObjectQuery` |
| `Helper\ObjectSerializer` | `Service\Read\ObjectSerializer` |
| `Helper\ResourceOutput` | `Api\ResourceOutput` |
| `Helper\RestValue` | `Service\Write\RestValue` |
| `Helper\SecretPolicy` | `Service\Rights\SecretPolicy` |
| `Helper\ToolOutput` | `Api\ToolOutput` |
| `Helper\WritePlan` | `Service\Write\WritePlan` |
| `Models\MCPResult` | `Model\MCPResult` |
| `Registry\MCPExtensionCollector` | `Api\MCPExtensionCollector` |
| `Registry\MCPRegistry` | `Api\MCPRegistry` |
| `Server\ServerInstructions` | `Core\ServerInstructions` |
| `Server\Session\StatelessSessionStore` | `Service\Server\Session\StatelessSessionStore` |
| `Service\AccessPolicy` | `Service\Rights\AccessPolicy` |
| `Service\AuditHousekeeping` | `Hook\AuditHousekeeping` |
| `Service\AuditRetention` | `Service\Audit\AuditRetention` |
| `Service\ChangeSummary` | `Service\Audit\ChangeSummary` |
| `Service\ExcludingModelReflection` | `Service\Rights\ExcludingModelReflection` |
| `Service\MCPService` | `Service\Server\MCPService` |
| `Service\ObjectAccess` | `Service\Access\ObjectAccess` |
| `Service\PortalAccess` | `Service\Portal\PortalAccess` |
| `Service\PortalAware` | `Service\Portal\PortalAware` |
| `Service\PortalBrickTemplates` | `Service\Portal\PortalBrickTemplates` |
| `Service\PortalDescription` | `Service\Portal\PortalDescription` |
| `Service\PortalMode` | `Helper\PortalMode` |
| `Service\PortalPolicy` | `Service\Portal\PortalPolicy` |
| `Service\ReadSummary` | `Service\Audit\ReadSummary` |
| `Service\TokenScopes` | `Service\Rights\TokenScopes` |
| `Testing\ElementContract` | `Api\Testing\ElementContract` |
| `Helper\MCPHelper` | gone, member by member, below |

Not in the table because they did not move: `Controller\MCPController`; `Core\CoreExtensions`; `Core\Prompts\MyOpenTickets`; `Core\ResourceTemplates\{ClassDetail, ObjectDocument}`; `Core\Resources\{ClassList, CurrentUser, Instructions, Version}`; `Core\Tools\{AbstractPortalTool, ClassList, ClassSchema, CurrentUser, Instructions, MyActivity, ObjectApplyStimulus, ObjectAttach, ObjectBulkCreate, ObjectBulkDelete, ObjectBulkUpdate, ObjectCount, ObjectCreate, ObjectDelete, ObjectFindByName, ObjectGet, ObjectGetDocument, ObjectGetRelated, ObjectHistory, ObjectSearchByClass, ObjectSearchByOQL, ObjectUpdate, PortalList, SetObsoleteData}`; `Exception\{MCPAuthException, MCPDocumentException, MCPPortalException, MCPRegistrationException, MCPRequestRejectedException}`; `Helper\{Identifier, JsonPayload, LogAPILogger, MCPContext, MCPHttp, MCPLog, Settings}`.

**Members.** Every public or protected constant, method or property of those classes that is not at the same name in the class above, so a name not listed here exists as it did. **Renamed** marks a member whose name changed as well as its class; the others only moved.

| 1.3 member | 2.0 member |
|---|---|
| `Helper\MCPHelper`: `MODULE_NAME`, `VERSION`, `SDK_CONSTRAINT`, `LICENSE`, `SOURCE_URL`, `AtLeast()`, `RequireVersion()` | `Api\MCPModule`, same names |
| `Helper\MCPHelper::MCP_METHOD_EXCEPTION` | `Helper\MCPMethod::EXCEPTION`, **renamed** |
| `Helper\MCPHelper::MCP_METHOD_INITIALIZE` | `Helper\MCPMethod::INITIALIZE`, **renamed** |
| `Helper\MCPHelper::MCP_METHOD_TOOLS_CALL` | `Helper\MCPMethod::TOOLS_CALL`, **renamed** |
| `Helper\MCPHelper::MCP_METHOD_RESOURCES_READ` | `Helper\MCPMethod::RESOURCES_READ`, **renamed** |
| `Helper\MCPHelper::MCP_METHOD_PROMPTS_GET` | `Helper\MCPMethod::PROMPTS_GET`, **renamed** |
| `Helper\MCPHelper`: `LOG_LEVEL_INFO`, `LOG_LEVEL_DEBUG`, `LOG_LEVEL_ERROR` | `Helper\Settings`, same names |
| `Helper\MCPHelper::UnreadableClassRefusal()` | `Helper\ClassRefusal::Unreadable()`, **renamed** |
| `Helper\MCPHelper`: `LooksLikeClassName()` | `Helper\ClassRefusal`, same names |
| `Helper\MCPHelper`: `Translate()` | `Helper\Dictionary`, same names |
| `Helper\MCPHelper::LogError()` | `Helper\ErrorReport::Log()`, **renamed** |
| `Helper\MCPHelper::NewErrorReference()` | `Helper\ErrorReport::NewReference()`, **renamed** |
| `Helper\MCPHelper::MintedErrorReferences()` | `Helper\ErrorReport::MintedReferences()`, **renamed** |
| `Helper\MCPHelper::ForgetErrorReferences()` | `Helper\ErrorReport::ForgetReferences()`, **renamed** |
| `Helper\MCPHelper`: `OpaqueFailure()`, `RejectedValue()` | `Helper\ErrorReport`, same names |
| `Helper\MCPHelper`: `MAP_TYPE` | `Helper\JsonPayload`, same names |
| `Helper\MCPHelper::RequestedPortal()` | `Helper\PortalMode::Requested()`, **renamed** |
| `Helper\MCPHelper`: `CalledEndpointUrl()` | `Helper\OAuthResource`, same names |
| `Helper\MCPHelper::GetProtectedResourceMetadataUrl()` | `Helper\OAuthResource::MetadataUrl()`, **renamed** |
| `Helper\MCPHelper::OAUTH_TOKEN_AUDIENCE_CLASS` | `Helper\OAuthResource::TOKEN_AUDIENCE_CLASS`, **renamed** |
| `Helper\MCPHelper::__construct()` | **removed**; `MCPLog::EnableForModule()` opens the module log |
| `Helper\MCPHelper`: every `@deprecated 1.3.0` constant (`MODULE_SETTING_*`, `DEFAULT_*`) and method (`AllowsAccessAdministration()`, `AllowsPrivilegeEscalation()`, `EscalationClassesFrom()`, `GetAllowedHosts()`, `GetAllowedOrigins()`, `GetAuditRetentionDays()`, `GetAuditedMethods()`, `GetCapabilities()`, `GetDisabledIdentifiers()`, `GetEnabledToolsets()`, `GetEscalationClasses()`, `GetMaxMentions()`, `GetPaginationLimit()`, `GetPortalTokenMaxDays()`, `GetServedPortals()`, `GetSourceUrl()`, `IsAccessRestricted()`, `IsReadOnly()`, `LogsCalls()`, `RefusesFormulaValues()`, `RetentionDaysFrom()`, `SwitchFrom()`) | removed; `Helper\Settings` has each under the same name |
| `Helper\Settings::AccessSettingsAreMalformed()` and its `Helper\MCPHelper` stub | `Service\Rights\AccessPolicy::SettingsAreMalformed()`, **renamed**; both 1.3 methods are removed |
| `Helper\WritePlan::RefusalForExternalKey()` | `Service\Write\ExternalKeyPolicy::RefusalFor()`, **renamed** |
| `Helper\WritePlan::ValidatedValues()` | `Service\Write\WriteValues::Validated()`, **renamed** |
| `Helper\WritePlan`: `RefusalForDroppedSetValues()` | `Service\Write\WriteValues`, same names |
| `Helper\WritePlan`: `DeletionPlanSchema()`, `CheckDeletionRights()`, `SerializeDeletionPlan()` | `Service\Write\DeletionCascade`, same names |
| `Helper\WritePlan`: `CredentialNote()`, `OutcomeSchema()`, `ObsoleteOkSchemaProperty()`, `AfterSchemaProperty()`, `After()`, `Map()`, `ChangesSchemaProperty()`, `DefaultedSchemaProperty()`, `Defaulted()`, `OverriddenSchemaProperty()`, `Identity()`, `CommittedId()`, `IsGone()`, `Untrusted()`, `Changes()` | `Service\Write\WriteOutcome`, same names |
| `Helper\WritePlan`: `SIMULATE_BY_DEFAULT`, `Simulated()`, `SimulateSchemaProperty()`, `Check()` | `Service\Write\DryRun`, same names; `WritePlan` keeps `Requested()`, `Overridden()`, `CheckRequested()` and `RefuseHiddenObsolete()` and is `@internal` |
| `Helper\WritePlan::AsId()` | `Helper\ObjectId::Normalise()`, **renamed** |
| `Helper\AccessGrants`: `AUTOMATION_REFUSAL`, `DELEGATED_RIGHTS_REFUSAL`, `DELEGATED_TARGET_REFUSAL`, `DELEGATION_REFUSAL`, `DETECTION_REFUSAL`, `DETECTION_TAMPER_REFUSAL`, `GRANT_REFUSAL`, `NOT_YOURS_REFUSAL`, `OPERATOR_ESCALATION_REFUSAL`, `RECIPIENT_REFUSAL`, `RECORDING_REFUSAL`, `SELF_REFUSAL` | `Service\Rights\AccessGrantRefusals`, same names |
| `Helper\AccessGrants`: `IsGranting()`, `IsDelegating()`, `IsAutomation()`, `IsOperatorListed()`, `IsOutboundSecret()`, `IsRecording()`, `IsDetection()`, `IsPersonal()`, `IsBarred()`, `Forget()` | `Service\Rights\AccessGrantFamilies`, same names |
| `Helper\DatamodelReader`: `OBJECT_RIGHTS_KEYS`, `RIGHTS_ALL`, `RightsKeys()`, `MayValues()` | `Service\Rights\GateGrades`, same names |
| `Helper\DatamodelReader`: `PatternFromDateFormat()` | `Service\Read\AttributeFormat`, same names |
| `Helper\DatamodelReader`: `MAX_ALLOWED_VALUES`, `ValueHint()` | `Service\Read\AttributeDescription`, same names |
| `Helper\DatamodelReader`: `RightsOf()` | `Service\Rights\ClassRights`, same names |
| `Helper\DatamodelReader`: `Categories()`, `ClassListPayload()`, `ListClasses()`, `FilterByText()`, `WithRights()`, `FilterByRight()` | `Service\Read\ClassCatalog`, same names |
| `Service\PortalPolicy`: `BRICK_MANAGE`, `BRICK_BROWSE`, `BRICK_CREATE`, `BrickKind()`, `CreateActionOf()` | `Service\Portal\PortalBricks`, same names |
| `Service\PortalPolicy`: `CompileLockPath()` | `Service\Portal\PortalLoader`, same names |
| `Service\PortalPolicy`: `BricksReaching()`, `CanHold()` | `Service\Portal\PortalReach`, same names |
| `Service\PortalPolicy`: `WithReadOnlyHonoured()`, `ShowsAttachments()` | `Service\Portal\PortalForms`, same names |
| `Controller\MCPController`: `TOOL_REFUSED_MESSAGE` | `Controller\MCPResponse`, same names |
| `Helper\MCPHttp`: `BearerChallenge()` | `Controller\MCPResponse`, same names |
| `Helper\ObjectHistory`: `IsReserved()`, `RESERVED_REFUSAL` | `Service\Rights\ChangeLogReservation`, same names |
| `Helper\DocumentAccess`: `Fetch()` | `Service\Access\DocumentFetch`, same names |
| `Helper\ChangeTracking::Compose()` | **removed**; nothing has written its line since 1.3 |
| `Service\MCPService::InstructionsFor()` | `Core\ServerInstructions::ForCaller()`, **renamed** |
| `Service\PortalMode`: `Serves()` | `Service\Portal\PortalAccess`, same names |
| `Service\PortalAccess`: `Enter()`, `Leave()` | `Service\Server\RequestScope`: `EnterPortal()`, `Close()`, **renamed**; `Close()` forgets the whole request, not only the portal |
| `Core\Tools\ObjectSearchByOQL`: `CheckQuery()` | `Service\Read\ObjectQuery`, same names |
| `Core\Tools\PortalList`: `RowOf()` | `Service\Portal\PortalRows`, same names |
| `Service\MCPService`: `ServedAccess()`, `AccessPolicyOfCurrentRequest()` | `Service\Server\ServedElements`, same names |
| `Helper\ExclusionPolicy`: `AddFullText()`, `RelationQueries()`, `FilterRelationQueries()`, `IsFiledUnderExcluded()`, `Narrowed()`, `ExcludedBelow()`, `OqlRefusal()` | `Service\Rights\ExclusionSearches`, same names |
| `Helper\ExclusionPolicy`: `SETTING_CLASSES`, `SETTING_ATTRIBUTES`, `SETTING_EXEMPT_PROFILES`, `Parse()`, `ParseExempt()` | `Service\Rights\ExclusionSettings`, same names |
| `Helper\ObjectSerializer`: `MAX_TEXT_CHARS`, `MAX_CASELOG_ENTRIES`, `MAX_LINKS` | `Service\Read\ValueCeilings`, same names |
| `Helper\ObjectSerializer`: `ALL_FIELDS`, `ParseFieldList()`, `UnknownAttributes()`, `FieldsSchemaProperty()` | `Service\Read\OutputFields`, same names |
| `Helper\ObjectSerializer`: `UNTRUSTED_NOTICE`, `UNTRUSTED_DOCUMENT_NOTICE`, `UntrustedText()`, `UntrustedTextOfRows()`, `MarkRows()` | `Service\Read\UntrustedMarker`, same names |
| `Helper\ObjectSerializer`: `RightsOn()`, `StimuliOn()` | `Service\Read\ObjectGrades`, same names |

### Removed

The 1.3 deprecations, removed early as ADR 0015 decided, since no 1.x release had been
installed anywhere:

- the `MCPHelper` settings stubs and constants (use `Helper\Settings`, which has each under the
  same name), and `MCPHelper` itself, which no longer exists (its other members went to the
  classes that own them: see the table);
- `MCPHelper::__construct()` (open the module log with `MCPLog::EnableForModule()`);
- `ChangeTracking::Compose()`, which nothing has called since 1.3;
- `Settings::AccessSettingsAreMalformed()`, which asked the exclusions' parser from the layer that
  reads settings. The question is `Service\Rights\AccessPolicy::SettingsAreMalformed()`.

### Fixed

- **`core_portal_list` tab counts leave out the classes an operator excluded.** A tab's count was
  made without the narrowing the list's rows have, so on a list of a parent class (a ticket list)
  it counted the objects of an excluded subclass that the rows themselves never showed
  ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md)). The defect was in
  1.3.0 too.
- **The README states the upload ceiling over HTTP.** An upload stops near 3 MB even when
  `mcp_max_document_bytes` is higher, because the SDK's transport caps the request body at 4 MiB and
  base64 adds a third; reading is not affected.

### Security

- **`ObjectAccess::Current()` refuses a request in portal mode that nothing entered.** Splitting
  the choice between the console's rules and the portal's out of `ObjectAccess` would have left
  it answering with the console's when the portal mode was remembered but never entered through
  `RequestScope::EnterPortal()`. It now throws `MCPPortalException`, so portal mode serves nothing
  rather than the console's reach, as 1.3 did. No path in `src/` does this today; the check holds
  the next one to it.
- **A class declared in the `Core\` PHP namespace no longer passes for this module's own
  element.** Portal mode without a listing in `mcp_portal_extensions`, the reserved `core` tool
  namespace and `ElementContract`'s portal check all trusted that namespace, which any pack can
  declare. They now ask `Helper\CoreElement::IsOwn()`: the class must be defined in a file under
  this module's own `src/Core/`.

## [1.3.0] - 2026-11-11

**The operator decides what this channel never serves, and can see what it did.** Classes and
attributes listed in `mcp_excluded_classes` and `mcp_excluded_attributes` are absent on every
route, in both modes, whatever the caller's rights. The audit trail becomes something to work
from: a retention run by iTop's cron that follows iTop when a person is anonymised, an *MCP
activity* dashboard, and `core_my_activity`, which lets anyone ask what their assistant did in
their name. Portal users can read and add attachments as their portal allows. `core_object_count`
answers "how many, per value" in one call.

**Read before upgrading.** Five things change on an instance that does nothing, each described
below:

1. **The setup has to run.** The audit table gains columns (`exempt`, `refusal_text`,
   `change_comment` and the three under Added); rows written before read empty or *no*.
2. **Counts of `status = 'error'` rise.** A call a tool refuses is now recorded as an error
   (under Changed).
3. **An object's history line no longer names the tool.** `CMDBChange.userinfo` is the user's
   plain name, so `SELECT CMDBChange WHERE userinfo LIKE '%(MCP:%'` finds only changes written
   before 1.3; use `SELECT AltiooEventMCPService WHERE change_id != 0` (under Changed).
4. **An hourly background process runs.** At the default `mcp_audit_retention_days => 0` it
   deletes nothing; it does bring audit rows up to date when a person is anonymised (under Added).
5. **Portal users gain two things**: `core_my_activity`, and attachments read and added where
   their portal's form allows. `mcp_disabled_tools` withdraws the first (under Added).

Tool packs: the settings readers moved from `MCPHelper` to `Settings`, and the old names keep
working, deprecated (under Changed).

**Tested on.** iTop **3.2.3-3** and **3.3.0-1**, each on PHP **8.2** and **8.4** — installed
through iTop's own unattended setup by the local CI runner (`tools/ci/local/run.sh matrix`), then
exercised by iTop's module validation suite, the module's integration suite and by calling the
endpoint over HTTP, portal mode included. The unit suite ran on 8.2, 8.3 and 8.4.

### Added

- **`mcp_exclusion_exempt_profiles`: an account that is served what the exclusions keep out**
  ([ADR 0013](doc/adr/0013-an-exempt-profile-is-served-what-is-excluded.md), decided by the maintainer; amends 0012). Empty by
  default. An account holding a listed profile is served the excluded classes and attributes: a **whole** exemption. It is
  decided **by the account only** — never by a token scope, a client's declared name or an OAuth client, and **never in portal
  mode**. A value that is not a list, or a profile this instance does not have, **stops the endpoint for everyone** (a typo
  must never exempt anybody). **New audit column `exempt`** (yes/no, default no; rows written before read *no*): every exempt call
  is listable. Give one client the exemption with a dedicated service account holding the profile.

- **`mcp_excluded_classes` and `mcp_excluded_attributes`: what this channel never serves**
  ([ADR 0012](doc/adr/0012-this-channel-can-exclude-classes-and-attributes.md), decided by the maintainer).
  Both are empty by default, so nothing changes until an operator sets one. Classes (with their subclasses) and
  attributes (per class, inherited by subclasses) listed there are **absent** from this endpoint for every caller in
  both modes, Administrator and Basic authentication included: every tool answers as it would for a class or an attribute
  the datamodel does not have, in a read, a field list, a filter, a sort, a group, a count, a full-text search, an OQL
  query (checked by iTop's own checker against the datamodel without them, so the refusal is its words for an unknown
  name), a history (rows and total), a schema, a write and its dry run, the relations walk, `core_my_activity` and the
  portal's lists and forms. What points at an excluded class goes with it: fields resolving through it, keys and link
  sets to it, the files filed under its objects, and what is reachable only through it. **This narrows the channel, not
  the account**: the same account reads the same data in the console. **A setting that cannot be honoured — not a list,
  a name the datamodel does not have, an attribute that is not on its class or is part of its class's friendly name —
  makes the endpoint serve nothing to anyone** until it is corrected, and `log/error.log` says why. A mandatory
  attribute that is excluded makes creating that class impossible here; a class that has an excluded subclass cannot
  appear in a join or sub-query of OQL. `ExclusionPolicy` is on the surface a tool pack may build on (`@api`): a pack
  that reads objects itself should ask it.
  The relations are covered too: a relation query that selects an excluded class, or whose defining class is excluded, is not listed
  by `core_class_schema` (the itop://core/class resource included) and a relation that exists only to reach one is not accepted by
  `core_object_get_related` - the queries are named by the class defining them and the neighbour they lead to, so they are checked on
  what they select, not by name.
  **Not covered** (listed in the README and the ADR): notification events (`EventNotificationEmail` and the other `Event*`
  classes are readable and can quote an excluded value; if you exclude personal fields, also add `EventNotification` — not `Event`,
  which would hide the audit trail and `core_my_activity`), objects
  named by class and id as text other than attachments, portal tab conditions, and what was written before an exclusion.
  **Before an iTop upgrade, or before removing an extension, check these two settings**: a name the datamodel no longer has
  makes the settings malformed and stops the endpoint for everyone until it is corrected. `log/error.log` says so at every
  request ("Itop configuration parameter for exclusions: … Nothing is served to anyone until it is corrected"); a client
  authenticates and is served no tools, resources or prompts, with no error of its own.

- **Portal mode reads and adds attachments, as the portal does; deleting stays in the portal**
  ([ADR 0011](doc/adr/0011-portal-mode-reads-and-adds-attachments.md), decided by the maintainer).
  `core_object_get_document` and `core_object_attach` are served in portal mode, and `core_object_get` gives each
  attachment a `uri` and the object a `may_attach`. A file is readable when the object it is filed under is in the
  user's scope and its forms show attachments; the class `Attachment` is never read through UserRights, a draft is
  refused, and unknown, hidden and draft answer in the same words. A file is added only where the portal's upload would
  be editable (write scope, state not read-only for the portals, an edit form with a settable field), leaves the
  portal's history line and event, and is a dry run unless asked otherwise. **After upgrading, every portal user with
  MCP access can have their assistant read and add attachments** on what the portal shows them; withdraw it with
  `mcp_disabled_tools` or by leaving out the `documents` toolset. The stock `Portal user` profile is not widened.
  A file added is held to `mcp_max_document_bytes`, and PHP's `upload_max_filesize` and `post_max_size` still apply on the way in.
  `core_class_list` says per class whether adding is possible (`attach`).
  **Adding needs no right to create an `Attachment` in portal mode**, matching the portal itself, which the stock `Portal user`
  profile does not hold either; the console still requires it. What gates it instead: the write scope on the object, the
  attachment module allowing the class, a state not read-only for the portals, an edit form with a settable field, and a
  write-grade token.

- **`core_my_activity`: what did my assistant do in my name?** The audit trail was readable by administrators
  only; this tool gives any user their own calls, newest first, with `since`, `limit` and `offset`: when, which tool,
  dry run or failed, client, the kind of credential, the objects changed or read, and the reason given.
  **Own rows only**: the filter is the real user's key (the account that acted, under impersonation), taken from iTop
  and never from the request; there is no argument to name another user, and no login is ever matched, because a
  login is rewritten on anonymisation and can be reused. **"Own" means the account**: people who share one login see
  each other's calls. The rows are read past the audit class's rights, so everything shown is narrowed: an object is
  named only if the account may see it now (the rest is counted in `objects_you_cannot_see`), an attribute code only if
  the account may read it, and the raw request parameters, what a tool said, the message and the token ids are never
  returned. The reason is the text this account's own credential sent in that call, and is marked as text to report, not
  to follow. `coverage` says what the record cannot show: calls not recorded (`log_mcp_service`), only failures recorded
  (`log_mcp_level`), retention, and rows with no key (written before 1.3, or by an account since deleted). Toolset
  `server`, read grade.
  - **Portal mode serves it too, as one deliberate exception** to "what the portal offers"
    ([ADR 0010](doc/adr/0010-portal-mode-serves-the-users-own-activity.md), decided by the maintainer): an account's
    own trail, not a precedent for anything else. **Every portal user with MCP access gets it after upgrading**; withdraw
    it with `mcp_disabled_tools` (`core_my_activity`). In the portal an object is named only if the portal shows it now
    (otherwise only counted), an attribute code only if the portal's view form shows it and the change it belongs to
    still exists, an object the call deleted is counted and not named, and `coverage` says only that some earlier calls
    may not be listed: nothing of the log level, retention or other setup.
  - About 960 bytes added to what a client is told on a console token, and about 860 on a portal one.
- **`core_object_count`: how many, and how many per value.** "Open tickets per team" used to mean paging every
  ticket. One call now answers a count, optionally per value of one or two attributes, with up to 100 groups,
  the value and a label for each. Console: `class` and `filters`, or `oql`, `group_by`, `archived`. Portal
  mode, the same tool (ADR 0006): one of the portal's lists (`list`, `tab`, `parent_id`), per column the list
  shows, over the query the list itself runs, so a count can never see a row the list would not. It is a read
  and is treated as one: a class that is reserved, unreadable or without bulk read, and an attribute that is a
  secret, of a type that is not a plain value (long text, documents, linked sets, a field read from another
  object) or that the account may not read for every object, are all refused **in the words of a class or
  attribute that does not exist**, whether they are grouped by or filtered on. The label of an external key is
  given only where its target is readable (in the portal, shown by its scopes), the id alone otherwise. The
  count is the database's and is taken before object-level rights remove rows, so it can exceed what the
  account could list, as `total` of a search can; the answer says so. Read grade, toolset `objects`. It adds
  about 1.3 KB to what a client is told about a full-scope token (measured: `tools/measure-surface.php`).
- **`tools/measure-surface.php` and a size budget.** The script measures, over JSON-RPC against a running
  instance, what a client is told (descriptions, schemas, instructions) per token; `ContextBudgetTest` fails when a
  core tool's description or input schema, or the instructions, grow past the measured sizes, so growth is
  a decision. One repeated sentence was cut from `core_object_search_by_class` (79 bytes).
- **Two entries under Administration: an *MCP activity* dashboard and the *MCP service calls* list.**
  Both are administrator-only; neither is shown to anyone else, and a direct URL renders nothing for
  them. *MCP service calls* is `SELECT AltiooEventMCPService`, which until now had no menu: the README
  sent administrators to an "event log" that iTop does not have. *MCP activity* is iTop's own
  dashboard, shipped as data (no dashboard code): the last seven days of calls by outcome, writes by
  tool and by day (dry runs excluded), writes and errors by login as recorded, portal users
  refused at login, calls by client and by portal, and response bytes by tool. An administrator
  customises it with iTop's editor and reverts it from the same menu; the copy is per user. Read it as
  the README says: a login is not a person, an "error" is a call that failed or that a tool refused
  (see Changed), and the client is what it declared. No alerts: the trail is watched, not
  pushed.
- **The audit row names the client, the objects a read returned, and the account as a link.**
  `AltiooEventMCPService` gains three nullable columns, so the upgrade needs no step beyond the
  setup the module always asks for, and a row from before holds nothing in them.
  - `client`: the name and version the caller declared, from `clientInfo` on `initialize` or from
    `_meta["io.modelcontextprotocol/clientInfo"]` on any request. A `2026-07-28` client never
    sends `initialize`, so it was never named; it now is, on every row. It is declared by the
    caller and verified by nobody. A client that sends neither has none on its other rows: the
    endpoint is stateless and invents no session to remember it from.
  - `read_objects`: which objects a read returned, as class and id (`UserRequest: 12, 15 (+41
    more); Person: 7`), never a value or a name. It covers searches, `core_object_get`, the
    related graph, history, documents and a portal's attachment list. At `log_mcp_level =>
    'error'`, which writes no successful rows, it is not written either.
  - `user_id`: an external key to the account the call ran as, the real user under impersonation
    as `CMDBChange.user_id` is. `userinfo` stays the record; deleting a user is not blocked by
    these rows, and the rows survive it with `userinfo` intact and `user_id` reset (`DEL_AUTO`, as
    the token links). iTop resets them one at a time inside the deleting request, about 2 ms a
    row, so deleting an account with a very long history is slow.
- **`mcp_audit_retention_days`: a retention for the audit trail, run by iTop's cron.** iTop has no
  retention for its event log (checked on 3.2.3 and 3.3.0: it purges log files, read notifications
  and backups, nothing in `priv_event`), and the documentation that told operators to set it "as for
  iTop's other event classes" pointed at nothing. The default is `0`, which keeps every row, so no
  instance changes behaviour. A positive number has a new hourly background process,
  `AuditHousekeeping`, delete rows older than that many days, oldest first, through `DBDelete()` so
  `priv_event` and the module's table both lose them; it stops at the time cron allows and resumes
  the next hour. A value that is not a non-negative integer reads as `0` and is logged. Nothing is
  deleted where iTop's cron does not run. Deleted rows are gone with the tool, the reason and the
  objects they named.
- **The same process follows iTop when a person is anonymised.** When the person behind a row's
  account (`user_id`) is anonymised by Combodo's Personal data anonymizer, the row's *User* line is set
  to the account's current login and its request parameters and reason are cleared; *Objects changed*
  and *Objects read* stay, as `Class::id` does in iTop's own history. It runs whether retention is
  on or not, up to an hour after, and does nothing where the anonymizer is not installed.
  **Not reached, and documented:** rows of a user deleted before their person was anonymised (their
  `user_id` was reset, so nothing leads to the person), and rows from before `user_id` (1.2.0 and
  earlier). They keep the login, which is personal where logins are email addresses, until
  retention removes them.

### Changed

- **A call a tool refuses is recorded as an error.** A tool answers a refusal (an object the caller may not
  change, a class they may not read) with `isError`, which the protocol sends as HTTP 200 and a JSON-RPC
  success, so the audit row said *Success* for it. It now says *Error*, with the fixed message "The tool
  refused the call": the tool's own text is not copied, since it can quote what was refused. What the
  caller receives does not change, nor does the HTTP status. What an operator sees does: *Error* rows
  appear for refused calls, `status = 'error'` queries return more, and at `log_mcp_level => 'error'`
  these calls are now written, where they were not. **Counts of `status = 'error'` rise after upgrading**:
  they now include every refusal, which used to be counted as a success.
  - The row's message is fixed, "The tool refused the call", at every level. **Only at
    `log_mcp_level => 'debug'`** does the row also hold the tool's own text, in a new nullable column,
    *Refusal text* (`refusal_text`), cleaned to one line like every audit text. It can quote the value
    that was refused, so it has the standing of `request_params`: debug is a troubleshooting setting, and
    both columns are cleared, by the hourly process, when the account's person is anonymised.
- **A change made through the endpoint now carries the user's plain name in `CMDBChange.userinfo`,
  with no `(MCP: tool)` suffix** ([ADR 0009](doc/adr/0009-the-change-line-is-the-users-plain-name.md)).
  Combodo's Personal data anonymizer finds a person's case-log entries by that line being exactly
  their name, so an entry written through this endpoint kept the person's name, author and text
  after they were anonymised (proved on iTop 3.2.3-3 with anonymizer 1.3.4). The line is now what
  iTop writes for the console and REST, impersonation text included, and the origin stays
  `custom-extension`.
  - **The history line no longer names the tool or the reason.** They are on the audit row: the tool
    is *Tool / resource*, the optional `comment` of the writing tools is the new *Reason given*
    (`change_comment`, one line, 255 characters, nullable). Once those rows are purged, or if
    `log_mcp_service` is off, they are gone.
  - **The query changes.** `SELECT CMDBChange WHERE userinfo LIKE '%(MCP:%'` finds only changes
    written by 1.0 to 1.2. Use `SELECT AltiooEventMCPService WHERE change_id != 0`.
  - Changes written by 1.0 to 1.2 keep their suffix, are not migrated, and stay out of the
    anonymizer's case-log step.
  - `ChangeTracking::Compose()` (`@api`) still returns what it did and is `@deprecated`: nothing
    writes its line any more.
  - **Released in a minor, though `ChangeTracking` is `@api`.** Read strictly, the rule above makes
    this a major. `CommentSchemaProperty()` and `Explain()`, the calls a tool pack is told to use, keep
    their signatures and their purpose, and `Compose()` stays for at least one minor. What a pack
    should know: the reason it passes to `Explain()` is now kept on the audit row only, so with
    `log_mcp_service => false` it is not kept at all.
- **`AbstractMCPTool::portalRefusal()`: one place turns a portal's `MCPPortalException` into the `ToolCallException` a client
  is answered with** (`protected static`, `@since 1.3.0`, additive). The core tools had seventeen copies of that `try`/`catch`.
  What a tool returns does not change, and neither does a signature a pack subclasses. A pack's own tool may use it:
  `self::portalRefusal(static fn () => ObjectAccess::Current())`.
- **The module settings moved from `MCPHelper` to a new `Settings` class** (`Altioo\iTop\Extension\MCP\Helper\Settings`,
  `final`, `@api`, `@since 1.3.0`). Every `MODULE_SETTING_*` and `DEFAULT_*` constant and every reader of a setting
  (`GetPaginationLimit()`, `IsReadOnly()`, `GetCapabilities()`, `GetServedPortals()`, `SwitchFrom()` and the rest) is there now,
  with the code unchanged. What a setting reads, defaults to and logs when it is mistyped is the same.
  - **Nothing was removed.** Each public constant and method is still on `MCPHelper`, as an alias or a one-line stub that
    calls `Settings`, and is `@deprecated 1.3.0 use Settings::X()`. They go no sooner than a minor after this release.
    A tool pack that names one keeps working; moving to `Settings::` is the whole migration.
  - `MCPHelper` keeps the version (`VERSION`, `AtLeast()`, `RequireVersion()`), `MODULE_NAME`, `SDK_CONSTRAINT`, `LICENSE`,
    `SOURCE_URL`, the logging helpers, the error references, the dictionary lookup and the URL derivation.
- **`MCPLog::EnableForModule()` opens the module's log, and `new MCPHelper()` no longer has to.** The request entry point built a
  helper only for its constructor, which opened `log/error.log`; it and the hourly clean-up now call the one method.
  `MCPHelper::__construct()` is `@deprecated 1.3.0` and still does the same. `MCPLog` keeps its untyped `$m_oFileLog`, as iTop's
  `LogAPI` declares it.

### Fixed

- **`core_class_schema` failed on iTop 3.3 with its default blocks.** The relations block listed a class's relations through
  `MetaModel::EnumRelations()`, which iTop 3.3 no longer has, so the call ended in `Call to undefined method` for every class whenever
  the block was asked for - and it is asked for unless `include` says otherwise. Since 1.2.0, which declared iTop 3.3 supported. It now
  asks `MetaModel::EnumRelationsEx()`, which exists on 3.2 and 3.3, and the block answers the same on both: the relations a class has a
  downstream query for, each with the queries it reaches. A test runs the default blocks on a class with relations on both branches.

- **A portal's requests no longer wait on another portal's.** Every portal compiled its rules under
  one lock file; each now has its own, `<portal>.compile.lock`. The `compile.lock` a 1.2.0 instance
  has in its cache is no longer used, is empty, and goes with the cache at the next setup.
- **A portal id is checked before it becomes a file name.** A portal whose id is not letters, digits,
  `_` and `-`, up to 64 of them, is refused rather than read, with the reason in the iTop error log.
  An id with a dot or a space could not be named in `portal_id` already, but could be served as a
  user's first portal: **check the ids of any custom portal before upgrading.** A `portal_id` ending
  in a newline no longer passes as well-formed.
- **`core_object_get_related` answered "Failed to walk" on every call.** It read the relation graph
  with `RelationGraph::GetNodes()`, `GetEdges()` and `RelationObjectNode::GetObject()`, none of
  which iTop has (it has `_GetNodes()`, `_GetEdges()` and `GetProperty('object')`). No test had
  called the tool against a live datamodel; one now does.

## [1.2.0] - 2026-11-05

**Portal mode is the headline.** A user of iTop's customer portal, with no console, can now
connect an assistant and be served what their portal offers: its lists, its forms' fields, its
create actions and state changes, read from that portal's own configuration. The other half of
the release holds every write to what the console's form would allow, and marks text other
people wrote as untrusted. Tagged 2026-11-05, which opens this minor's five-year window in
[SECURITY.md](SECURITY.md#support-period).

**Read before upgrading.** Four things change on an instance that does nothing, each described
below with what to do about it:

1. **Portal mode is on.** To keep it off, set `'mcp_portals' => array()` *before* upgrading
   (under Changed).
2. **A write the console's form would not allow is refused**, `status` through
   `core_object_update` included: move an object with `core_object_apply_stimulus` (under
   Security).
3. **A password or encrypted attribute is no longer writable by default**, on any class (under
   Security).
4. **A request carrying an `Origin` header must come from an origin in `mcp_allowed_origins`**,
   or from the endpoint's own (under Packaging).

The setup has to run, as for any upgrade: it adds the `portal` column to the audit table. Tool
packs move their `require-dev` to `mcp/sdk` `^0.9.0` (under Packaging).

**Tested on.** iTop **3.2.3-3** and **3.3.0** (build 21411), each on PHP **8.2** and **8.4** —
installed with the sample data through iTop's own unattended setup, by the CI install matrix and
the local CI runner (`tools/ci/local/run.sh matrix`), then exercised by iTop's module validation
suite, the module's integration suite and by calling the endpoint over HTTP, portal mode
included. The unit suite ran on 8.2, 8.3 and 8.4. The upgrade workflow upgraded from v1.1.2 and
from v1.0.0.

### Added

- **Portal mode: a portal-only user is let in by their profiles, and served what their portal
  offers.** A user with no console used to be refused outright. Now they are let in when they
  hold a profile in `mcp_allowed_profiles` — that one even with `secure_mcp_services` off — with a
  token, linked to a contact, in the first portal by rank they may enter that the new
  `mcp_portals` setting serves. Its default, `array('*')`, serves every portal, so holding the MCP
  profile is the switch; `array()` refuses them as before. The token must expire within the new
  `mcp_portal_token_max_days` (90 by default): every personal token needs its owner's profile in
  authent-token's `personal_tokens_allowed_profiles`, which lets a portal session mint its own
  tokens with no expiry at all, and portal mode does not accept one that never ends. iTop's
  `Portal user` profile reads every class, and what narrows it is the portal's own configuration,
  so a tool is served there only when it adds that configuration to its own checks, a pack's
  never. Console logins take exactly the path they took before, because portal mode starts
  only from the refusal iTop already returned for them.
- **`core_class_list` and `core_class_schema` say what a portal user's portal offers**
  (doc/adr/0006). They report the classes a brick granted to the
  user reaches, whether the portal's scopes let them read and change objects of each, its create
  actions and stimuli, and the fields its forms show when viewing, editing and creating one. It
  is read with the portal's own code — its brick collection, scope and lifecycle helpers and form
  merge — so a customised portal is described as customised. A class
  with a read scope that no granted brick reaches is not offered (doc/adr/0001), nor is a subclass
  whose inherited scope pins `finalclass` to other classes — in the stock portal, `Change` and
  `Problem`, which an end user never sees there. ManageBrick, BrowseBrick and CreateBrick are read,
  and a custom brick class extending one is read as that type; one extending none of them offers
  nothing (doc/adr/0002). A form field
  marked `read_only` in `<fields>` is reported read-only, although iTop's portal shows it
  writable. Needs iTop 3.2.2 or later; on an earlier one, or for a portal whose configuration
  cannot be read, nothing is served and the log says why. In the `datamodel` toolset, so an
  operator or a token narrowed to other toolsets does not get it. A create action whose class
  the portal picks with a method, as the stock services brick does wherever requests and
  incidents are both installed, is listed with no class and `class_chosen_by_portal`, and each
  class the user may create lists such actions in `create_chosen_by_portal`. Each stimulus names
  the states it applies in (`from_states`), each browse list the list above it (`parent`), each
  create action the list its starting object is picked from (`from_list`), and a class says
  whether the portal shows its objects' attachments.
- **The portal's lists, objects and writes.** The portal's lists, and
  the console's reads and writes served there with the portal's configuration added
  (doc/adr/0006):
  - `core_portal_list` runs a list by id — a ManageBrick, one of its tabs, a browse level under
    a parent row — as the portal runs it: the brick's query intersected with the scope, the
    tab's condition, the parent checked against its own level, and the portal's search on the
    columns shown. "What is the status of my requests" and a FAQ search are both a list. The
    client never sends a query (doc/adr/0001).
  - `core_object_get` is served in portal mode too, rather than a portal copy of it
    (doc/adr/0006): it reads one object by the scopes, with its view form's fields for the object
    as it is, its attachments (listed, not served), its rights narrowed to the portal's, the
    stimuli the portal offers and the fields its edit form leaves settable.
  - `core_object_create`, `core_object_update` and `core_object_apply_stimulus` are served in
    portal mode too, a creation taking one of the portal's create actions (`action`, `from_id`)
    instead of a class. They write as the portal would, dry runs by default: a field must be
    settable in the portal's form and pass the console's rules too; what a value points at must
    be readable; a stimulus must be offered on the object in its state; a creation starts from a row of its action's list, takes the
    class the action names or its factory method chooses, and is preset by the action's rules,
    run by the portal's own helper (doc/adr/0005).

  `core_object_history` is served in portal mode too, when the portal shows history — a brick
  granted to the user reaches `CMDBChangeOp` and a read scope covers it — and
  `core_class_list` says so in `history`. It is the console's tool with the portal's limits
  added: the object must be one the portal shows, the rows those the change log's scope holds, and
  the attributes those the object's view form shows. The portal's lists and reads refuse the
  change log, and no write in portal mode reaches it, whatever the portal grants.

  `core_current_user` and `itop://core/current-user` are served in portal mode, and now report
  `current_organization` and `current_portal` in both modes. The `core_my_open_tickets` prompt is
  offered there too, answered from the portal's ticket lists. A token graded `MCP-read` is served
  the reads only, as everywhere.
- **What portal mode refuses, and what it records.**
  - **An external key, or a link item's (`contacts_list` on the stock portal), takes an id only.**
    A query or criteria object would run under the account's rights, wider than the portal's
    scope, and which refusal came back would say whether it matched. An id the portal does not
    show is answered alike whether or not the object exists.
  - **A write reports what the portal shows.** `changes` (and so `defaulted`) of
    `core_object_create`, `core_object_update` and `core_object_apply_stimulus` list the
    attributes the view form shows and the ones the caller sent, not every preset, default,
    computed or lifecycle value the write set.
  - **Refusals say no more than the portal shows.** A form-hidden attribute code is refused in
    the same words as one the class lacks, in `core_object_get`, `core_object_history` and the
    field lists, and a code not shaped like one is not quoted back. Refusals name no tool portal
    mode does not serve, nor the console's "rights model", and `core_object_history` leaves the
    raw iTop `user_id` empty. A refused field key is quoted only when it is shaped like an
    attribute code, since the text is recorded in the audit row.
  - **It leaves a trail.** A portal-only user refused at login is a row in the audit table
    (method `login`, element `portal_admission`, the refusal's code). The change information of
    a write names the portal, so history says it without joining to the audit row.
  - **It is bounded.** `core_portal_list` search takes at most 10 words, since each is a
    condition on every column the list shows. The first build of a portal's compiled rules is
    done under a lock, so two requests starting together cannot load a half-written file.
- **The server instructions are served in portal mode** (`core_instructions`,
  `itop://core/instructions`), with portal-mode descriptions. A client on protocol `2026-07-28`
  has no `initialize`, and is told there how to read the portal's lists and write through them,
  narrowed to what its token is served.
- **A connection names its portal, and the audit row records it** (doc/adr/0007). The endpoint
  URL may carry `portal_id`, as iTop's portal URLs do: a portal-only user is then served that
  portal, when they may enter it and `mcp_portals` serves it, and refused otherwise — never served
  another in its place. Without it, the first by rank. With altioo-oauth-server
  configured with one resource per portal, the `401` points at the resource of the URL called,
  and a token granted for one portal's URL is refused at another's. `AltiooEventMCPService`
  gains a `portal` field, empty for a console call; the setup adds the column.
- **altioo-oauth-server is an optional dependency** (doc/adr/0003). Declared in
  `module.altioo-mcp.php` in a form that is always satisfied, so it never stops an install and
  only sets that module up first when it is being installed. With it installed and
  `mcp_protected_resource_metadata` empty, the `401` now advertises the document it serves for
  this endpoint; before, the operator copied that URL into the setting by hand. Without it,
  nothing changes, and a value in the setting always wins.
- **How a portal-only user gets a token**, in the README: the profile it needs in authent-token's
  `personal_tokens_allowed_profiles`, that an administrator mints it, and what listing that
  profile opens — a portal session can then mint its own tokens of any scope through
  authent-token's endpoint, bounded here by what portal mode serves. SECURITY.md's hardening
  advice says the same, and `doc/security-summary.md` now names portal mode and the compiled
  portal rules it has iTop write under `data/cache-<env>/`.
- **`core_object_get` names the free text other people wrote.** Ticket titles, descriptions and
  logs came back verbatim with nothing marking them as untrusted; the only protection was the
  server instruction that object content is data, which a client that ignores instructions never
  reads. The result now carries `untrusted_text`: the fields holding strings, long text, HTML or
  case logs, and the names built from them (a ticket's title included), with a note to treat
  them as data. `core_object_search_by_class`, `core_object_search_by_oql`,
  `core_object_find_by_name`, `core_object_get_related` and `core_portal_list` carry it too, once per page. It marks and
  Linked-set names and document file names are covered by the marker;
  `core_object_create`, `core_object_update` and `core_object_apply_stimulus` carry it for the
  free text in `changes` and for `after.friendlyname`; `core_object_history` carries it when it
  has entries, and a document is returned after a notice that its contents are data. It marks
  and restricts nothing — the write barriers are unchanged.
- **`mcp_privilege_escalation_classes`: classes an operator adds to the escalation barrier.**
  The built-in list names what iTop ships and cannot know an extension's class that stores work
  something else carries out later — a scheduled mass update, a script runner — whose rows were
  ordinary writes here while the job they stage runs from the cron with nobody's rights checked.
  Listed classes, and their subclasses, are refused by every write tool unless
  `mcp_allow_privilege_escalation` is on, and `core_class_schema` says so. It only adds refusals.
  A value that is not a list of class names is logged and read as every class; a name the
  datamodel does not have is logged at every request. Empty by default, so nothing changes until
  it is set.

### Changed

- **Portal mode is on after an upgrade.** `mcp_portals` defaults to `array('*')`, so a portal-only
  user who holds the `MCP Services User` profile (or another in `mcp_allowed_profiles`), has a
  token and is linked to a contact is served what their portal offers, where they were refused
  before. Nobody gains the console. **To keep it off, set `'mcp_portals' => array()` before
  upgrading** (or `array('itop-portal')` to serve one portal only); see the README's
  [Granting access](README.md#granting-access).
- **A refused class is quoted back only when it is shaped like a class name.** Every tool that
  refuses a class repeated the value it was given, so
  `UserRequest' OR 1=1 --` came back in the error and went into the audit row's message
  verbatim. iTop escapes that row in the console, so nothing rendered it as markup; a value
  that is not letters, digits and underscores is now answered "That is not a class name"
  instead, and a typo like `UserReqest` is still quoted. The check reads the value only, never
  the datamodel, so unknown and unreadable classes still answer alike. The
  `itop://core/class/{class}` resource answered with a plain "Unknown class 'X'." of its own;
  it now gives the same refusal as `core_class_schema`.

### Security

- **A secret the instance logs in elsewhere with is no longer writable by default, on any
  class.** The outbound credentials iTop ships — the OAuth clients, the remote connections, the
  webhook action's password — were already refused by class name, but a class an extension adds
  had no name to list: a mail-to-ticket mailbox's password could be replaced, beside its login and
  server, and the instance's own mail collection pointed at a server the caller chose. Every write
  tool now refuses a value for an `AttributePassword` or `AttributeEncryptedString` attribute (and
  so `AttributeEncryptedPassword`), set or cleared, unless `mcp_allow_privilege_escalation` is
  on; `core_class_schema` says so on the attribute as a `writeHint`. The other attributes of the
  object stay writable. **This changes behaviour for an instance whose extensions store passwords
  an assistant used to set** — a device login on a CI, a mailbox password: those are now set in
  the console, or the operator turns that setting on, which lifts the outbound class barriers too.
  SECURITY.md has the reasoning; this closes the question it had left open.

- **A write changes only what the console's form would let the user edit** (doc/adr/0004). The
  create, update and stimulus tools and their bulk forms used to accept any attribute the ORM
  calls writable, as REST `core/update` does. So one call could close a ticket without a
  transition, set a field its state locks, reset an SLA timer, rewrite a case log, or delete the
  children of a link set. Each of these is now refused, and the refusal names the tool that can
  make the change:
  - the state attribute (`status`): use `core_object_apply_stimulus`. A create starts in the
    initial state;
  - an attribute hidden or read-only in the object's state, or in the initial state for a
    create. A stimulus may still set what its transition form prompts for, such as `agent_id`
    on `ev_assign`;
  - stopwatches (`tto`, `ttr`). Writing any value to one reset it to zero;
  - a case log value that is not text or `{"add_item": {"message": …}}`. A full log replaced
    the earlier entries, and an entry could carry a `date` that backdated it;
  - a link set the console does not edit from its host (`edit_when`, `edit_mode`). An
    `add_only` set refuses removals. Adding, changing or removing items now needs the matching
    right on the linked class: removing an item from a direct set whose key cannot be empty
    deleted the child object with no delete check;
  - an external key outside the attribute's allowed values, such as an `agent_id` who is not in
    the ticket's team. Only the target's existence used to be checked.

  **What a client must do:** a call that set `status` (or any other field above) through
  `core_object_update` or `core_object_create` is now refused. Move the object with
  `core_object_apply_stimulus`, passing the fields the transition asks for in its `fields`.

### Fixed

- **`core_current_user` reported the instance's grades to a narrowed token.** The `access`
  block was worked out again after the token had been dropped, found none, and listed `read`,
  `write` and `delete` for a read-only token even though `tools/list` served only the read tools.
  It now reports the policy decided for the request.
- **A refused token is now named as one.** A `401` for a token that is unknown, expired, revoked
  or carries no MCP scope said only `Invalid login`, with the same challenge as a request that
  sent nothing, so an OAuth client could not tell that it should refresh or connect again. The
  challenge now carries `error="invalid_token"` (RFC 6750) in that case, and the message says
  what may be wrong with the token. A wrong password says so, and keeps no error code.
- **A request with no credential is told so.** Its message spoke of a browser session even when
  no cookie had been sent; it now says that no credential was sent, and which ones are accepted.
- **Every object a tool returns says which class it is, in `class`.** A search on `Ticket`
  answers across `UserRequest` and `Incident`, and the descriptions of both search tools say
  each row reports its class — but no release has done it: the field was keyed on what iTop
  answers for a class with no subclass, which is an empty name, and that key had been dropped.
  `core_object_search_by_class`, `core_object_search_by_oql`, `core_object_get` and the write
  tools' results now carry `class` on the object itself, as `core_object_find_by_name` and
  `core_object_get_related` already did. `class` may also be named in `output_fields`, which
  used to refuse it as an unknown attribute.
- **`audit` and `actions` on the search tools are bounded by the page served, not the limit
  asked.** The bound is 25 objects and the default limit 50, so either argument on its own was
  refused for every query, four matches included. A page that would hold more than 25 is still
  refused, and the refusal now says to pass `limit=25` and page.
- **`core_object_bulk_update` reports an empty `overridden` as an object.** Each row's `changes`
  and `overridden` were the only write-result maps not built by `WritePlan::Map()`, so a row
  where nothing was overridden — nearly every row — answered `[]` where its declared output
  schema promises an object. A client validating `structuredContent` refused the result, and
  from `mcp/sdk` 0.9.0 the server does too: it replaces the result with `isError: true`, after
  the rows have been written.
- **`core_current_user` reports only the capabilities a served tool uses.** Its `capabilities`
  were the token's grade, so a token graded `MCP-delete` was reported able to delete on an
  instance whose delete tools are all disabled. They are now the grades of the tools actually
  served, within what the token grants, as the toolsets beside them already were.
  The block also names the served `tools`, since the portal's tools share the console's
  toolset names and `objects` alone does not say which kind a connection has.

### Packaging

- **The archive is signed by a job that runs none of this repository's code.** The release used
  to install the development dependencies, run the unit suite, build the zip and sign it in one
  job holding write and signing permissions, so a compromised test dependency could have edited
  `vendor/` or `src/` and the provenance attestation would have vouched for it. Tests and the
  build now run in read-only jobs; the job that attests and publishes only downloads the built
  files and checks them against the digest the build job reported. Verification is unchanged
  (`gh attestation verify`).
- **Files inside vendored packages are no longer stripped by the root's exclude list.** Entries
  such as `tools`, `build` or `.github` matched at every depth, so the same names inside a
  dependency were silently dropped from the archive. They are now matched at the root only. On
  this release the visible difference is a few files from the dependencies' own repositories: the
  `.gitignore` and `.gitattributes` of `mcp/sdk` and `psr/*`, and the `.github/` directory of
  `phpdocumentor/reflection-common`. None is reachable over HTTP: `.htaccess` and `web.config`
  serve `index.php` only.
- **`mcp/sdk` 0.9.0, up from 0.8.1.** Three things change outside this module:
  - **A request carrying an `Origin` header must come from an origin listed in
    `mcp_allowed_origins`**, or from the endpoint's own origin. The SDK used to compare the host of
    the `Origin` with `mcp_allowed_hosts`; it now compares the whole origin — scheme, host and
    port — with a list of origins, and that list is `mcp_allowed_origins`, the setting that
    already decides which browser origins may read a response. **What to do:** if a browser-based
    client, or a desktop client that sends `Origin`, is answered `403 Forbidden: Invalid Origin
    header.`, add its origin to `mcp_allowed_origins`. Behind a proxy that terminates TLS without
    setting `HTTPS` for PHP, the endpoint sees itself as `http://` and a request from its own
    `https://` origin needs listing too. Clients that send no `Origin` — command-line and
    server-side ones — are unaffected.
  - **A tool result that does not match the tool's declared `outputSchema` is answered as an
    error** (`isError: true`) instead of being sent as it was. Every core tool's results are
    checked against their schemas by the test suite.
  - **Tool packs** compile against the SDK this module vendors, so their `require-dev` moves to
    `^0.9.0`, which is what `MCPHelper::SDK_CONSTRAINT` now says. The SDK classes a pack uses —
    `ToolAnnotations`, `Annotations`, `ToolCallException`, `ResourceReadException` — have not
    changed.

## [1.1.2] - 2026-11-02

A security patch for 1.1.1: two fixes and nothing else, released from the `maint/1.1.x` branch
rather than from `main`, so an instance on 1.1.x can take them without the behaviour changes
waiting for 1.2.0 above. No datamodel change, no new setting and no changed default.

**Both fixes apply to 1.0.x as well**, and ship for it as **1.0.2**.

**Tested on.** iTop **3.2.3-3** and **3.3.0** (build 21411), each on PHP **8.2** and **8.4** —
installed through iTop's own unattended setup by the local CI runner
(`tools/ci/local/run.sh matrix`), then exercised by the module's integration suite. The unit
suite ran on 8.2 and 8.4.

### Security

- **An outbound credential in `combodo-webhook-integration` is masked on read.**
  `RemoteiTopConnectionToken.token` — the token this instance authenticates to a remote iTop
  with — is kept in an `AttributeString`, the same shape of gap `OAuthClient`'s `refresh_token`
  had, in a sibling hierarchy the OAuth-only mask list did not reach. A caller that could read
  the class got the live token in clear through every read tool, the change history and the
  schema's `isSensible` flag. The `RemoteApplicationConnection` hierarchy is now in
  `AccessGrants::OUTBOUND_CREDENTIAL_ROOTS`, so the value is masked everywhere the OAuth secrets
  are; its sibling connection classes carry no clear secret of their own. A new class or
  attribute that holds an outbound secret is added to that list and to
  `OUTBOUND_SECRET_ATTRIBUTES` beside it, with a line in
  `AccessGrantsTest::testALiveOutboundTokenIsMaskedEvenInAPlainTextAttribute` pinning it. Found
  by a red-team read, not by the mask list having been derived. No credential of the caller's
  own was ever at stake — this is an outbound secret of the instance's.

- **Old versions of documents could be read through the document resource, by an account
  granted the change log.** iTop keeps the replaced contents of a file in the change log, as
  `CMDBChangeOpSetAttributeBlob.prevdata`. `core_object_get_document` refused that class with
  the rest of the change log; the `itop://core/document/{class}/{id}/{att_code}` template, which
  reads the same documents, did not. Change-log rows carry no organisation and no attribute right
  of their own, so an account allowed to read `CMDBChangeOp` and served the `documents` toolset,
  which is on by default, could walk the ids and read the earlier version of any file replaced on
  any object, outside its silo and past an attribute right refused to it today. Among iTop's
  stock profiles only Administrator holds that read, and it gains nothing by it: `*` covers
  `bizmodel` classes only, the change log is `grant_by_profile`, and SuperUser's grant on it is
  bulk read, which this read does not consult — so Portal user and the other stock profiles were
  never exposed. An instance with a custom profile granting read on `CMDBChangeOp` was. Affects
  1.0.0 to 1.1.1. The template now refuses it as the tool does, and `DocumentAccess::Fetch()`,
  which both go through, refuses it again beneath them. Clients reading documents of ordinary
  objects are unaffected. Until an instance is upgraded, leaving `documents` out of
  `mcp_enabled_toolsets`, or the read out of the custom profile, closes it.

## [1.0.2] - 2026-11-02

The two security fixes of 1.1.2, backported to 1.0.x, and nothing else — released from the
`maint/1.0.x` branch rather than from `main`. The 1.1.2 entry above describes both. 1.0.2 was
tested on iTop 3.2.3-3, PHP 8.2 and 8.4, the branch 1.0.x supports.

## [1.1.1] - 2026-10-05

A security patch for 1.1.0: two fixes and nothing else, so an instance on 1.1.0 can take it
without a feature review. No datamodel change, no new setting and no changed default.

**Tested on.** iTop **3.2.3-2** and **3.3.0**, each on PHP **8.2** and **8.4** — installed
through iTop's own unattended setup by the install matrix, then exercised by the module's
integration suite and by calling the endpoint over HTTP. The unit suite runs on 8.2, 8.3 and 8.4.
The token fix was also reproduced over HTTP before and after, on 3.2.3-2 with PHP 8.4.

**Both fixes apply to 1.0.0 as well**, and ship for it as **1.0.1**, so an instance on 1.0.x can
take them without taking 1.1.0's behaviour changes.

**After upgrading, read `log/error.log`.** A setting this release now refuses to guess at is
reported there on every request, and may have changed what the instance serves — see the second
Security entry.

### Security

- **A scoped token could run without its scopes.** iTop's `authent-token` accepts a token from
  the `Auth-Token` header or, without it, from an `auth_token` URL or form parameter; this
  endpoint read the header only. On an instance whose `allowed_login_types` puts `token` ahead
  of `basic`, or leaves `basic` out, a request carrying the token as a parameter, beside any
  `Authorization` header that was not a Bearer, logged in as the token's owner and was served
  the instance-wide policy: an `MCP-read`, `MCP-toolset-*` or `MCP-advisory` token could do
  whatever the instance allowed, and its audit row named no token. Reproduced on iTop 3.2 with
  `form|external|token|basic`: an `MCP-read` token was served `core_object_create`. iTop's
  default order, `form|external|basic|token`, refused the same request, because `basic` claims
  any `Authorization` header first. Such a request is now refused
  before login, and a login iTop records as a token login is refused after it unless the
  endpoint saw that token in a header. Clients sending the token in `Authorization: Bearer` or
  `Auth-Token`, which is every configuration the README describes, are unaffected.
- **A mistyped security setting no longer fails open.** `secure_mcp_services`, `mcp_read_only`
  and `log_mcp_service` were compared against `true`, so `'true'` or `1` turned the profile check
  off, left the instance writable, or stopped the audit, with nothing logged. `mcp_capabilities`
  or `mcp_enabled_toolsets` written as anything but an array served everything. Every boolean
  setting now takes a PHP boolean only, and any other value is logged and read as the answer
  that refuses more; the two lists, when they are not arrays, serve nothing. **This changes
  behaviour for an instance that wrote one of these as a string:** `'mcp_read_only' => 'false'`
  now makes it read-only, and `'mcp_capabilities' => 'read'` now serves nothing — write
  `false` and `array('read')`. The README has the rule beside the settings table.

## [1.0.1] - 2026-10-05

The two security fixes of 1.1.1, backported to 1.0.x, and nothing else — released from the
`maint/1.0.x` branch rather than from `main`. The 1.1.1 entry above describes both, and what an
instance whose settings were written as strings will see. 1.0.1 was tested on iTop 3.2.3-2, PHP
8.2 and 8.4, the branch 1.0.x supports.

## [1.1.0] - 2026-09-30

iTop 3.3 becomes a supported branch, and a red-team pass over 1.0.0 is fixed. Tagged
2026-09-30, which opens this minor's five-year window in
[SECURITY.md](SECURITY.md#support-period).

**Read the Security section before upgrading.** It carries one migration and two behaviour
changes. The migration: `MCP-advisory` is now declared on tokens, but a token saved under 1.0.0
lost that scope silently and keeps writing for real until an administrator re-adds it after the
setup has recompiled. The behaviour changes: writes now refuse values a spreadsheet would run as
a formula, and @mentions beyond what the console allows. Both come with a setting to relax them.

**Tested on.** iTop **3.2.3-2** and **3.3.0**, each on PHP **8.2** and **8.4** — installed
through iTop's own unattended setup by the CI install matrix, then exercised by the module's
integration suite and by calling the endpoint over HTTP. The unit suite runs on 8.2, 8.3 and 8.4.

### Added

- **iTop 3.3 is now a supported branch**, alongside 3.2. The install matrix runs against iTop
  3.3.0 on PHP 8.2 and 8.4, so the claim means what it means for 3.2: the setup selects and
  installs the module there, Combodo's module validation suite and this module's own integration
  suite pass against the compiled instance, and the endpoint answers over HTTP. Nothing in the
  module changed to make that true — no source file, no datamodel, no dependency — and an
  instance already running 1.0.0 on 3.2 has nothing to do about this entry.

  **If you are moving an instance to 3.3, mind the PHP floor, which is iTop's rather than
  ours:** 3.2 accepts PHP 8.1, 3.3 refuses anything below 8.2. This extension has always needed
  8.2, so an instance already running it is already above 3.3's floor — but a 3.2 instance still
  on 8.1 has to raise PHP before it can go to 3.3 at all.

### Fixed

- **A call no longer answers with eight `Set-Cookie` headers, or leaves a session behind.**
  iTop's login opens a PHP session even for this stateless endpoint, and reopens it for every
  value it stores. Each reopening sent a fresh cookie to a client that had presented none, and
  every call left a session file for the garbage collector. The session is now destroyed before
  the answer is written, and its cookies are withdrawn. The endpoint never read that session
  back, so no client behaviour changes. A client that stored the cookie was holding a
  token-mode session, which iTop refuses without its token, and now it is not sent at all.

### Security

- **Text a spreadsheet would run as a formula is refused on the way in.** A value starting —
  after any leading spaces — with `=`, `+`, `-`, `@`, a tab or a carriage return — `=HYPERLINK(…)` stored in `Server.name` was
  the red-team case — runs as soon as the data reaches a spreadsheet: iTop's CSV export does not
  neutralise it, REST returns it as stored, and an assistant reading it here may build the CSV
  itself. Every write tool now refuses such a value on a text attribute, and says why. A value
  that is only a sign, or a sign followed by digits, spaces and `( ) . / -`, passes, so phone
  numbers, negative numbers and dates are unaffected; case logs and secret attributes are not
  checked. Reads return values unchanged, and every reader is told to quote such cells when it
  builds a CSV. **Behaviour change:** a write that relied on a leading `=`, `+`, `-` or `@` — `@jdoe`
  included — is refused; `mcp_refuse_formula_values => false` turns the check off. SECURITY.md
  has an OQL query for values that arrived another way.
- **An @mention written here is held to what the console would allow, and capped.** A mention
  is markup iTop reads out of a new case-log entry and turns into a `TriggerOnObjectMention`,
  usually a mail. The console only writes that markup through its autocomplete, but this
  endpoint took it as typed, so one `core_object_update` could mention any class, any id, as
  many as fit; a red-team pass sent 27 mails that way. Every write tool now refuses an entry
  that mentions a class outside iTop's `mentions.allowed_classes`, an object the caller cannot
  see, or more distinct objects in one call than the new `mcp_max_mentions` setting allows
  (default 5, `0` turns mentions off here). The refusal says which rule failed, and a dry run
  reports it the same way. The parsing is iTop's own, so markup sent as plain text is caught
  too. **Behaviour change:** a caller that relied on mentioning more than five people in one
  call, or a class other than those configured, is now refused; raise `mcp_max_mentions` if
  that is intended.
- **`MCP-advisory` now exists on a token.** In 1.0.0 it was read, tested and documented, but
  the datamodel never declared it on `PersonalToken` or `UserToken`. iTop drops an undeclared
  value from a scope set when it is saved, without an error, so a token meant to be
  `MCP-write` + `MCP-advisory` was stored as plain `MCP-write` and wrote for real — the one
  outcome that scope exists to rule out. The value is now declared on both classes, and a test
  requires every scope the code interprets to be declared there. **Migration:** after the
  setup has recompiled the datamodel, open every token that was meant to rehearse and add
  **MCP: advisory** to its scope. The value it lost was dropped when the token was saved, so
  nothing brings it back on its own. Until you do, those tokens write.
- **A caller can no longer write a line break into the audit trail.** The element column of an
  `AltiooEventMCPService` row — which is also the row's name in every list of them — is filled
  from the caller's own text: the `clientInfo` a client sends at `initialize`, the tool or prompt
  name of a call (whether or not it matches anything), the URI of a resource read. It was stored
  as sent, so any valid token, with no scope and before any capability check, could make one row
  read as several, or leave text for whoever — person or model — reviews the trail later. The
  row's message carried the same text a second way, because an unknown tool is answered with its
  name quoted in the error. In both columns control characters are now replaced by a space, and
  a value that is not a string is recorded as nothing. The element is cut to the 255 characters
  its column holds for all four sources, where before only `clientInfo` was. Rows written before
  this release keep what they were given.
- **The README no longer implies that disabling a tool disables what it does.** Its example for
  `mcp_disabled_tools` was `core_object_delete`, and it never said that
  `core_object_bulk_delete` stays callable, or that the same holds for the create and update
  pairs. An operator following it literally believed deletion was off when it was not. The
  setting now says that it withdraws exactly the names listed, and points to
  `mcp_capabilities`, which grades every tool by what it declares, for stopping a kind of
  operation, and `log/error.log` names the bulk or single sibling an entry left callable, at
  every request until it is listed too. **If you listed a tool there in order to stop an
  operation, check the instance:** leaving `delete` out of `mcp_capabilities` is what stops it.
- **The README names the array the settings belong in.** It said "under `module_settings`";
  iTop reads module settings only from the top-level `$MyModuleSettings`, and silently ignores
  a block nested inside `$MySettings`, so a setting written there looked like a setting that
  did not work.
- **The documentation now says the endpoint has no rate limit, and where to put one.** Every
  call is bounded, but calls are not counted, so a single valid token can load the instance for
  all its users. SECURITY.md's threat model says so, and its hardening list gives a web-server
  throttle keyed on the credential. Nothing in the module changed.

## [1.0.0] - 2026-09-20

The first release. Nothing precedes it, so the entries below describe what the extension **is**
rather than what changed in it — there is no instance anywhere running an earlier version, and
nothing here is a migration.

The work that produced it is nonetheless in the repository: the pre-release history is published
rather than squashed away, so the entries below can be read against the commits that made them.
[CONTRIBUTING.md](CONTRIBUTING.md#how-this-extension-is-built) says what that history does and
does not carry — in particular that nothing older than publication is signed, because the key
did not exist yet.

Tagged 2026-09-20, which is also the date the five-year support window opens in
[SECURITY.md](SECURITY.md#support-period).

Two properties are worth having before the list: **no tool writes unless it is asked to** — the
rehearsal is the default rather than a boundary, and `MCP-advisory` is the scope that makes it
one — and **a tool is graded read / write / delete by the annotations it declares**.

**Tested on.** iTop **3.2.3-2** on PHP **8.2** and **8.4** — installed through iTop's own
unattended setup, then exercised by the module's integration suite and by calling the endpoint
over HTTP. The unit suite runs on 8.2, 8.3 and 8.4, and again against the tree inside the
published archive. Combinations outside that are expected to work from the ranges in the README
rather than observed; the README and [doc/hub-listing.md](doc/hub-listing.md) both read this
line from here.

### Added

- **An MCP server inside iTop, at a single authenticated HTTP endpoint.** Streamable HTTP,
  stateless, authenticated by iTop itself on every request. One URL answers two protocol eras:
  a client on `2026-07-28` is served without a handshake and without a session id, and one on
  an earlier revision still performs `initialize` and receives an id that carries nothing,
  because this endpoint stores nothing past the request that made it. The guidance a client is
  normally handed at `initialize` is also fetchable, as the `core_instructions` tool and the
  `itop://core/instructions` resource, so a session that was given none can ask for it. The surface stays close to iTop's
  own primitives, in the same spirit as its REST/JSON API: list and describe classes, find
  objects by name the way the console's global search does, search by OQL or by attribute
  criteria, read one object, walk relations, read and store documents, read the change log, and
  create, update, delete or apply a lifecycle stimulus — each of the last four in bulk as well.
  Five resources and one prompt beside them. Task-shaped tools ("open an incident") are
  deliberately not here: they belong in packs built on this, which is what the extension points
  are for. The full list is in the [README](README.md#what-it-exposes).
- **No tool writes unless it is called with `simulate=false`.** `core_object_create`,
  `core_object_update`, `core_object_apply_stimulus`, `core_object_delete`,
  `core_object_attach` and the three bulk tools all default to a dry run and answer with a
  validation report of what would change. An MCP client is driven by a model acting on
  instructions that may have come from outside the organisation, and creating a ticket from an
  injected prompt is not made recoverable by being non-destructive.
- **`CheckToWrite()` on every write**, so a missing mandatory attribute is reported as what it
  is rather than surfacing as an ORM exception written for a developer. The dry run reports
  what it found and what would change, per object for the bulk tools.
- **A dry run tells a value the write would discard from one it would refuse.** `CheckToWrite()`
  runs `DoComputeValues()` before it checks anything, and a class is free to `Set()` an
  attribute there from the ones it derives from — `UserRequest::ComputeValues()` derives
  `priority` from `urgency` and `impact` on every write. The supplied value is put back,
  `ListChangedValues()` finds nothing changed and drops the attribute, and `DoCheckToWrite()`
  then validates the delta and nothing else: the value is neither applied nor looked at. So
  `core_object_create`, `core_object_update`, `core_object_apply_stimulus` and both writing bulk
  tools report an `overridden` block — attribute code => `{requested, effective}` for every
  supplied value the write would not keep, always present and empty when there is nothing to
  say — captured on the only side of `CheckToWrite()` where the object still holds what the
  caller asked for. Reported rather than refused, because setting a derived attribute alongside
  the ones it derives from is a legitimate call. A discarded value is validated in its own
  right, which is the question `DoCheckToWrite()` skips, so a `priority` the enum does not allow
  is refused instead of coming back valid; on a bulk call that refusal lands on the row, not on
  the batch.
- **A write that reports failure wrote nothing, or says what it wrote.** Every write path
  catches `\Throwable` rather than `\Exception`: an `Error` is not an `Exception`, and one
  raised between the commit and the response would otherwise reach the SDK's own handler, which
  answers a fixed string carrying no class, no message and no reference — the failures that most
  need `MCPHelper::OpaqueFailure()`'s reference being the only ones never to get one. And
  `DBInsert()` commits before it returns, so catching around it was never the same as knowing
  nothing was written: **both creation paths ask the object instead**. iTop gives an unsaved
  object a deliberately negative temporary key, so a positive one means the row reached the
  database, and the call then answers as the success it is, reporting the id and attaching the
  failure under `warning`. `DBInsert()` commits and then reloads, so anything the second half
  raises — an after-write listener, a reload of external values — arrives with the row already
  written, and a batch that reports it as failed is one a caller repeats. `WritePlan::CommittedId()` asks the question and
  `WritePlan::AsId()` decides what counts as an id — a positive number, whether it arrives as an
  int or a string, since `DBInsertSingleTable()` assigns the key as `"$iNewKey"`. Update,
  delete, apply-stimulus and attach carry the widened catch and its reference, but not the
  commit check: what "did this one write" means differs per operation, and outside a creation it
  is not answerable from the key.
- **Every write tool answers with one shape, whatever `simulate` was.** Whatever a tool
  declares in its output schema, it reports on every call; it is the *values* that vary, not
  the keys — `id` is `null` rather than absent until there is one, `changes` is `{}` rather
  than absent, `valid` is reported on both paths. The five single-object write tools share
  `class`, `id`, `simulated` and `valid` and each adds its own (`changes` for create and
  update; `stimulus`, `state`, `would_move_to` and `changes` for apply stimulus; `deletionPlan`
  for delete; `attached_to`, `document` and `mimetype_note` for attach), while a bulk tool
  answers about a batch and reports counts plus a list of entries carrying `row`, `id`,
  `status` and `message`. Pinned by `OutputSchemaContractTest`, which fails on any declared
  property that is not required and on any description saying a field is "present only" under
  some condition.
- **Bulk tools**: `core_object_bulk_create`, `core_object_bulk_update` and
  `core_object_bulk_delete`, up to 100 objects per call. The id list takes the ids a read
  reports, which are strings — iTop's key is one all the way out of the ORM, so passing back
  exactly what came out of a search is the obvious call and has to be the working one. Up to 100
  because past it a model has stopped acting on a list a person recognised; the search tools
  report `total` for the whole matching set beside the page they return, so the size of the work
  is one call with `limit=1` rather than a walk. They check `UR_ACTION_BULK_MODIFY` /
  `UR_ACTION_BULK_DELETE` before anything else, then check every object and every attribute
  individually — before writing anything, so a batch cannot fail on the eleventh object having
  already written ten. Dry runs by default, and each object is reported separately, because a
  call can partly succeed. They take ids, so the `total` they answer with counts what they were
  given and never the set behind a criterion; the search tools report that set's `total` beside
  the page they return, so one call with `limit=1` sizes the work before it is split.
- **Changes are attributed in the object's own history.** A write recorded under the calling
  user's name and nothing else says less through this endpoint than it looks: the same name
  appears whether the person made the change, asked an assistant to make it, or issued a token
  to an agent that has been making it nightly. A change reads `Jane Doe (MCP:
  core_object_update)` — the tool is taken from the request rather than asked for, so a pack's
  tools are attributed exactly like the core ones without their author doing anything. Every
  writing tool also takes an optional `comment`, iTop's REST/JSON `comment` by another route,
  which adds the why. The origin stays `custom-extension`: an `mcp` value on that enum would
  read better in a filter and would cost an `ALTER TABLE` on `priv_change` at every setup.
  Packs spell the parameter with `ChangeTracking::CommentSchemaProperty()` and record it with
  `ChangeTracking::Explain()` — see [doc/extending.md](doc/extending.md).
- **The class schema says what the caller may do, on the class and on every attribute.**
  Describing what a class looks like and nothing about what this user may do with it leaves a
  model to find out by making the call and reading the refusal — a poor way to learn, because a
  model that meets a refusal often retries a variation of it rather than reporting it. iTop's
  console answers the same question by rendering a button or not, and a client has no buttons to
  read. `core_class_schema` and `itop://core/class/{class}` carry a `rights` block grading
  `read`, `bulkRead`, `create`, `bulkCreate`, `modify`, `bulkModify`, `delete` and `bulkDelete`,
  and every attribute carries the same grade under `modify`. Each is `yes`, `no` or `depends`,
  never a boolean: `UR_ALLOWED_DEPENDS` is a third answer meaning "ask again with the object",
  and a model told `yes` or `no` instead has been told something no addon said.

  **The two levels do not promise the same thing.** The class gate is what every write tool
  checks before it fetches anything, so `no` there is final — no object exists that could get
  past a check which raises first. `yes` means only that the call gets that far, with the silo,
  the lifecycle state and the datamodel's own `DoCheckToWrite()` still ahead of it. That
  asymmetry is the useful part: both halves are something a model can act on, where a symmetric
  "either way, who knows" would be neither.

  **`bulkCreate` is derived, not read.** iTop has no `UR_ACTION_BULK_CREATE`, so
  `core_object_bulk_create` gates on `UR_ACTION_CREATE` and `UR_ACTION_BULK_MODIFY` together and
  the block reports the stricter of the two — left to infer it, the reasonable conclusions are
  that the call is ungated or that it does not exist, and both are wrong. And an attribute's
  `modify` sits **beside** `readOnly`, not in place of it: `readOnly` is the datamodel refusing
  everybody, beyond any administrator's reach, while `modify` is this caller being refused
  something somebody can grant. Collapsed into one key a model can no longer tell them apart,
  and raises the wrong one of the two with the user.
- **The class schema separates what can be written from what cannot.** A stock `UserRequest`
  runs to around a hundred attributes, and most of what a class with many external keys carries
  is mechanical: iTop attaches a `_friendlyname` companion to every external key, and an
  `_obsolescence_flag` wherever the target can go obsolete. None of that is an answer to "what
  can I set". `core_class_schema` and `itop://core/class/{class}` return `attributes` for the
  ones that can be written and `derived` for the computed and structural ones. Nothing is
  dropped, and an entry has the same shape in both blocks — `readOnly` still on it — so a caller
  that wants them together merges the two and loses nothing. The split is `IsWritable()`, the
  datamodel's own answer, rather than a list of attribute class names: that list would go
  quietly wrong the first time iTop added a type to it.
- **An attribute can say how it is written.** An optional `writeHint`, and `AttributeCaseLog` is
  the first type to need one: it reads as the whole log and is written one entry at a time, so a
  type name alone left a model either sending the rendered log back as the new value or looking
  for an add-a-log-entry tool — which is task-shaped and belongs to a pack. The hint names both
  shapes iTop accepts, the plain string and `{"add_item": {"message": "..."}}`.
- **A read says what may be done to the object it just returned.** `core_class_schema` answers
  for a class, and answers `depends` wherever the addon wants to be shown the object first — a
  silo, or any rule graded per object. A read has the object, so it is the one place that
  question can be settled, and `core_object_get` settles it: `modify`, `bulkModify`, `delete`
  and `bulkDelete` answered for that row. Only a class-level `depends` costs a query; `yes` and
  `no` were answered without reference to any object and are carried straight through.

  The lifecycle has the same shape. The schema reports every transition of every state, and only
  the object knows which state it is in — so a model would have to find the state attribute,
  match it against the graph, and hope it picked the right one. A get reports the current state
  and the stimuli that state accepts, each graded by **both** gates `core_object_apply_stimulus`
  checks: `UR_ACTION_MODIFY` on the object and `UserRights::IsStimulusAllowed()`, stricter
  winning. A class with no lifecycle answers null rather than an empty list, since "no
  transitions from here" and "this class has no states" are different claims.

  `core_object_search_by_oql` and `_by_class` take `actions: true` for the same, capped at 25
  objects like `audit`. Still gates and still a snapshot: `DoCheckToWrite()`, a mandatory
  attribute the transition requires, or another user getting there first can each refuse a write
  this reports as available.
- **Reads say when an object was created and last changed, and by whom.** `core_object_get`
  carries an `audit` block always; `core_object_search_by_oql` and `core_object_search_by_class`
  take `audit: true` for it. There is no field behind this — iTop stamps neither on the object —
  so it is two indexed single-row reads of the change log per object. That is cheap once and
  unbounded over a page, so the search tools refuse `audit` for a page of more than 25 rather
  than serving it slowly. Gated by the object and by nothing else, as the console gates it — so
  anyone who can read the ticket can see who opened it and who last touched it.
- **`core_object_history`, and with it the answer to "who changed this".** An iTop object
  carries no creation or update stamp — `DBObject` declares neither — so the endpoint could
  otherwise report what a ticket says and never when it was opened, who last touched it, or why
  a value is what it is. That record is in the change log, which this reads: each operation with
  its date, its user, the attribute affected and the values before and after, newest first.

  It is a tool rather than a documented OQL query for two reasons. The log does not normalise
  itself — `oldvalue` and `newvalue` live on `CMDBChangeOpSetAttributeScalar`, while text, case
  logs, blobs and link sets each record differently, so a query against the parent returns rows
  with no values and one against the scalar subclass silently misses every case-log entry. And
  `CMDBChangeOp` is granted per profile *as a class*: the grant says nothing about the object a
  row points at, nor about the attribute it names, and `objkey` is an integer column. Read
  directly, it is a way around both silos and per-attribute rights. This applies the object gate
  and today's attribute rights to every row — today's, not the ones in force when the row was
  written, since a revocation that left the old value readable would be a revocation in name
  only. The object is the only gate, which is how the console gates it: `ActivityPanelHelper`
  reads these rows for whatever object is on screen and asks `UserRights` nothing about
  `CMDBChangeOp`. Rows are ordered by id rather than by date, because one change writes several
  of them carrying that change's timestamp — iTop's own query says so, and orders the same way.
  Case-log entries are included, which the console renders separately instead.

  Served as its own `history` toolset, with an `MCP-toolset-history` token scope, so an operator
  can withhold the change log without withholding object reads.
- **`core_class_list` narrows by what the caller may do.** `may=create` returns the classes this
  user is allowed to create, each with the full rights block, in one call. Without it the only
  way to answer "what can I create here" is `core_class_schema` once per class — several hundred
  calls on a stock datamodel, or a partial picture from however many an agent can afford, which
  is a poor basis for planning work it intends to carry out. A class the gate refuses is dropped;
  one graded `depends` is kept and says so, because that is the datamodel asking for the object
  rather than refusing the class. `may="*"` reports the block on every class and narrows on none,
  since asking for the rights and narrowing on one of them are otherwise the same request — which
  would drop the classes a gate refuses from the very answer meant to say so. The gates are the
  ones the tools actually check, taken from the same list the rights block is built from, so the
  schema's enum, the refusal message and the block cannot come apart. With no `may` no rights are
  read at all, so `itop://core/classes` costs what it always did.
- **A prompt is discoverable without a client that lists prompts.** `prompts/list` is a method
  plenty of clients never call and some do not implement, so `core_my_open_tickets` — written for
  the commonest question this server gets — was invisible to exactly the caller it was written for.
  The served prompt names are now named in the `initialize` instructions and in
  `core_current_user`'s `access` block, both narrowed through the same check registration uses, so
  no caller is handed a name it cannot fetch. No tool per prompt: a model that knows the name can
  ask its user for it.

- **`archive_mode` says what it means.** It named iTop's archive mode correctly — a property of
  the session's view, under which searches also return archived and obsolete objects — in two words
  that read like neither: a caller is as likely to take them for "archiving is switched on" or for
  "this instance is locked read-only". The second is the expensive misreading, since it has a model
  decline a write the instance would have accepted. The field carries `enabled` and a sentence
  ruling that reading out.

- **`core_set_obsolete_data`, to switch what searches return.** "Show obsolete data" decides
  whether obsolete objects reach a result set, and it is a preference on the account — not a session
  flag and nothing a URL can carry — so the only way to change what searches answered here was to
  open the console and tick it. The tool writes the caller's own preference and nobody else's:
  `appUserPreferences::SetPref()` takes no user argument. Dry run by default like every other write,
  since the preference persists and is the same one the console reads. It reports the previous value
  as well as the new one, read through `utils::ShowObsoleteData()`, so "already on" is not confused
  with "nobody ever said" and the config default answers for an account that never expressed one.

- **`core_current_user`: the identity as a tool, and what this session can reach.**
  `itop://core/current-user` answers who the session is authenticated as, and a client that never
  fetches resources never asks: a model looks for a tool, finds none, and concludes the server
  cannot say — then either puts the question to the user or answers "my tickets" with somebody
  else's. The protocol puts a fact the model needs mid-task on the tool side of its own split,
  and the resource stays for clients that do browse. Both read through
  `Helper\CurrentUserReader`, so the two surfaces cannot drift: the contact is fetched by the id
  on the caller's own user record and with `AllowAllData`, the way iTop's own `FindUser()` loads
  an account outside its holder's silo, because an identity is not something a caller has to hold
  a right on to be told. The id can only ever come from `UserRights::GetContactId()` and never
  from the caller, and a unit test holds that invariant.

  It also carries an `access` block naming the toolsets served and the capabilities held.
  Without it, "this server has no such tool" and "I am not served that toolset" are the same
  observation from `tools/list`, and a model that cannot tell them apart states the first — it
  reports a capability as absent, and stops. Only what is served is named, never the catalogue:
  naming a withheld toolset would teach the caller the shape of the withheld surface, so what
  comes back never exceeds what `tools/list` already showed. Derived element by element through
  the same check registration uses, rather than from the policy's permitted list — empty there
  means "everything", and a toolset whose only element is disabled, or whose elements all
  require a profile this user lacks, is not one the caller can reach whatever the policy allows.
- **`core_object_find_by_name`**, the console's global search as a tool. Every other reading
  tool needs the class before it can do anything, and a question rarely arrives with one. It is
  iTop's own search — the same needle splitting and quoted phrase, the same
  `full_text_needle_min` floor, `MetaModel::GetClasses('searchable')` or `EnumChildClasses()`
  when a class is named, `AddCondition_FullText()` over every searchable scalar attribute, and
  the same leaf rule that stops one object being reported once per class in its ancestry. The
  rights are `core_object_get`'s, applied per object. `full_text_chunk_duration` bounds the scan
  and `truncated` says when it stopped early.
- **`core_object_get_related`**, for impact analysis, and it describes its own answer: a node map
  keyed `"Class::id"`, a flat list of `{from, to}` edges over those keys, and a per-class
  `summary` — a graph, not the tree its `depth` argument suggests.
- **Documents can be read and stored**, without putting bytes back into results that fan out.
  A blob attribute is reported as filename, type and size, and carries a `uri`; reading that
  URI — as the `itop://core/document/{class}/{id}/{att_code}` resource template, or through
  `core_object_get_document` for the many clients that read no resources — returns the one
  document, an image as an image so a model can look at it. `core_object_attach` goes the other
  way, storing content the caller already holds as an attachment or into a named blob
  attribute; it fetches nothing, because "download this URL and attach it" is an outbound
  request from inside iTop's network to an address the model read somewhere. The rule behind
  the metadata-only read is that a file never arrives unasked: `AttributeBlob`'s JSON form is
  the whole file base64-encoded, base64 costs a third again, and `core_object_get` defaults to
  every attribute — one call on a ticket with a 4 MB PDF would be a 5.4 MB response, and a
  fifty-row search fifty of them. `mcp_max_document_bytes` (5 MB) bounds both directions, and
  the two tools and the template form the `documents` toolset, with an `MCP-toolset-documents`
  token scope.
- **The searches honour the account's "Show obsolete data" preference.** It is stored against the
  user, not the session — `utils::ShowObsoleteData()` reads `appUserPreferences` and falls back to
  `obsolescence.show_obsolete_data` — and a search honours it only if the surface asks, which is why
  iTop's own export service and portal call `UpdateContextFromUser()`. `core_object_search_by_class`
  and `core_object_search_by_oql` never did, so one user asking one question got three answers:
  obsolete objects from those two, none from `core_object_find_by_name`, and none in the console.
  **Operators:** searches now return fewer objects for an account whose preference is off, which is
  the default — the same set the console shows. A read by id is unaffected and still returns an
  obsolete object.

- **Archived objects are a per-call choice on both searches.** `archived` takes `exclude` (the
  default), `include` or `only`. Seeing them used to be a property of the connection — iTop reads
  `with_archive` off the request and `DBSearch` takes archive mode from it at construction — so two
  identical calls answered differently depending on how the client was connected. The parameter is
  applied on every path, so it is the authority. `only` on a class with no archived state is refused
  rather than answered with an empty set. Note iTop's own coupling: including archived objects
  includes obsolete ones too.

- **A single-object update or delete refuses an obsolete object this account hides.** The case is a
  stale id: an account that does not show obsolete objects — the default — acting on an id from
  before the object became obsolete, unable to find it or check what it now is. Refused rather than
  forbidden: obsolescence is a display filter in iTop, not a right, and modifying the object is the
  only way to *stop* it being obsolete, so a guard with no way past it would strand exactly the
  accounts nobody has configured. The refusal quotes the condition that decided and names both ways
  forward — `obsolete_ok=true` on the call, or `core_set_obsolete_data`. Not on the bulk tools: a
  bulk call is handed its ids explicitly and answers per row.

- **`core_object_attach` no longer reports a stored file as a failure.** A real attach stored the
  document and answered "Error while executing tool" — the SDK's fixed string, no reference, no id,
  with the row in the database, so the reasonable next move was to attach it again. Two causes, and
  the structural one matters more than the typo: `DBInsert()` commits and then returns, and the
  response was built *outside* the try, where a failure in it could not be caught at all. Both
  branches now build the answer inside the catch, and a stored attachment whose description failed
  comes back as the success it is with the failure under `warning`. The trigger was
  `DocumentAccess::Describe()` declaring `int` for an id that `DBInsert()` returns as a string —
  the same boundary-type mistake `WritePlan::Identity()` was corrected for, one function along.

- **`MCP-advisory`: a token that may call the write tools and never writes.** The trust tier
  between "may read" and "may write", and the answer to wanting one without inventing a session:
  this endpoint is stateless, so it lives on the token, where every request reads it anyway. A
  token carrying the scope may call every write tool its other scopes allow, and each one forces
  `simulate` true whatever the caller passed — propose changes, show a person what they would do,
  commit nothing. It is a modifier rather than a grade: it survives `MCP` (a token asking for
  everything, as a rehearsal) and narrowing only ever adds it, never drops it. Every write tool
  routes its argument through one decision rather than reading `simulate` directly, and a unit test
  fails naming any tool that does not — a tool reading it directly is a tool the scope does not
  reach. With no policy remembered the caller's own answer stands, which is the only direction this
  can fail in without turning a rehearsal into a write.
- **`mcp_allow_automation_administration` is now `mcp_allow_privilege_escalation`.** Same switch,
  named for what it does instead of for the classes it governs. The old name read as "let the
  assistant manage our notifications", which is not what turning it on means, and an operator
  reading a settings table decides from the name — so the sentence that had been added to the
  documentation belongs in the name itself. Nothing is released, so the rename costs nothing.
- **The endpoint does not write the record of what it did.** The one that undermines the rest, and
  it was this module's own doing. `AltiooEventMCPService` — a row per inbound request, carrying the
  method, the tool, the status and the error — was left writable by the very session it records, so
  a caller could delete the evidence of what it had just done and then the row recording that
  deletion, in reverse order, leaving no MCP-side trace. Every other control here rests on "we can
  review what the agent did". The rule is on the parent rather than on this class:
  `AltiooEventMCPService` declares `<parent>Event</parent>`, as do iTop's own `EventNotification`,
  `EventIssue`, `EventWebService`, `EventRestService` and `EventLoginUsage`, so gating `Event`
  covers this module's class, iTop's, and whatever a pack adds, with none of them listed. **No
  setting lifts it** — an audit trail the audited party may edit with the operator's permission is
  an audit trail the audited party may edit — and reading stays open, since an assistant that can
  answer "what did I call, and what failed" is useful and reading a record does not alter it.
- **The endpoint does not queue work for the cron, or hold the keys it would use.** `AsyncSendEmail`
  extends `AsyncTask` and *is* the outbound mail queue iTop's cron drains, with free-text `to`,
  `subject` and `message` and a status of `planned`: one `core_object_create` sends a real email
  from the instance's own identity to any address, with none of the trigger/action/connection
  scaffolding the automation barrier was written for. Gated at `AsyncTask`, not at
  `AsyncSendEmail`, because the queue is the primitive. `ActionEmail` was already covered by
  `Action` — worth recording that its `to`/`cc`/`bcc` are `AttributeOQL`, so the recipient list is
  a query and `SELECT Person` reaches every contact in the CMDB. And `Oauth2Client`, its five
  subclasses and `OAuthClient` join them: they carry `client_secret`, `refresh_token` and
  `access_token` as `AttributeEncryptedPassword`, and the reasoning that kept them out before — a
  recoverable secret is the object's own data — covers a device password and does not cover a token
  this instance authenticates to a third party with. `AuditRule`, `AuditCategory` and `AuditDomain`
  are behind the same setting: not an escalation, but the other half of covering your tracks, since
  deleting the rule that would have flagged a mess makes the mess look like the data.
- **A live outbound token is masked even where iTop stored it in a plain-text attribute.** Asked
  for as a follow-up and worth its own entry, because the answer was "half of them". Masking here
  keys off `iAttributeNoGroupBy`, the interface iTop's own code tests for, and the two OAuth
  datamodels disagree about which type to use: `Oauth2Client` keeps `client_secret`,
  `refresh_token` and `access_token` in `AttributeEncryptedPassword`, which implements it and was
  already masked, while `OAuthClient` keeps `client_secret` in `AttributePassword` (masked) and
  `refresh_token` and `token` in **`AttributeText`**, which is not sensitive by type — so a live
  refresh token this instance authenticates to a mail provider with was reported in clear. The type
  stays the rule everywhere else; those four attribute codes on those two hierarchies are masked
  regardless of it, matched exactly rather than by pattern so `refresh_token_expiration` stays
  readable, since "this token expires on Friday" is the useful half. `ObjectSerializer::IsSensitive()`
  now takes the class alongside the attribute, and a test fails if any call site drops it — the
  change history and the schema's `isSensible` flag included, a secret leaked through either being
  leaked just the same.
- **The barrier is now checked against this module's own datamodel, by a test.** The structural
  criticism behind all of the above, in the reviewer's words: the gate is "an allowlist of blocked
  classes, not a security property" — a class is "writable because nobody added it to the
  blocklist, not because it was evaluated and judged safe". That is accurate, and
  `AltiooEventMCPService` is what it costs: an audit feature added without ever being held up
  against the barrier protecting everything else. Two things narrow it. Every root is chosen as
  high in its hierarchy as the meaning holds — `Event` not `AltiooEventMCPService`, `AsyncTask` not
  `AsyncSendEmail`, `Action` not `ActionEmail` — so a class added underneath one is covered before
  anyone here has heard of it. And a unit test walks every class this module's datamodel declares,
  resolves its declared parent chain against the barrier, and fails unless each is either behind a
  rule or listed in that test with a reason: adding a class here without making that decision now
  fails at the moment it is added. It does not make the barrier a derived property, and
  `SECURITY.md` says so rather than implying otherwise.
- **The endpoint does not leave standing instructions behind it.** The third family the same
  red-team pass turned up, independently exploitable and needing nothing outside classes this
  endpoint already wrote: a `RemoteApplicationConnection` whose `url` is a plain text attribute
  with no scheme or host validation — an attacker's collector, or an internal address the web
  server can reach and the caller cannot — an `ActioniTopWebhook` pointed at it, and a `Trigger`
  linked to that action by `lnkTriggerAction`. Enabled, it fires on every matching change made by
  anyone, from inside iTop's own request handling, for as long as nobody notices: one burst of
  write access becomes a standing exfiltration or SSRF channel, and nothing else on this endpoint
  outlives the call that made it. Refused wholesale rather than graded on a target, and the
  difference from the synchronisation rule is the argument for it — a synchro delegates writing
  objects of a class, which this endpoint grants and can grade against the caller, while a trigger
  delegates sending mail, calling a URL and invoking a static method by name, which it grants
  nobody. A rule whose answer is the same for every caller is a refusal. `Trigger`, `Action`,
  their descendants, any class carrying an external key to one of them and
  `RemoteApplicationConnection` are refused unless **`mcp_allow_privilege_escalation`** is on
  — a new setting rather than a share of the access one, because "may an assistant administer
  other people's access" and "may an assistant make this instance call out on its own" are
  separate decisions. Also worth knowing, though not itself a hole: `ActioniTopWebhook`'s
  `prepare_payload_callback` and `process_response_callback` take a `Class::method` string and
  invoke it as a public static callback — not code injection, since the method must already
  exist, but "call any loaded public static method by name".
- **A webhook call log is a log, even though it is not an `Event`.** `EventWebhook` declares
  `<parent>DBObject</parent>` in the webhook module while `_ActionWebhook` writes one per call —
  url, headers, payload and the response body — so it records something that happened by every
  test except the one the `Event` root applies. Listed by name for that reason. A class named
  `Event*` that is not an `Event` is exactly what a root match misses in silence, which is why a
  reviewer found this and the rule did not.
- **An edit lock is only ever your own.** An `iTopOwnershipToken` row names an arbitrary
  `obj_class` and `obj_key` and attributes the lock to an arbitrary `user_id`, so writing one
  freely is claiming "somebody else is editing this" about any object in anybody's name, or
  clearing a lock a person is relying on. No tool here takes a lock or needs one. Scoped to the
  caller rather than hard-blocked — the lighter of the two the review offered, and the one that
  fits, since a row of your own is harmless and a row in someone else's name is the whole abuse.
  It declares `user_id` where `appUserPreferences` declares `userid`, so the owner rule now asks
  the datamodel which spelling a class uses instead of assuming one.
- **The error reference now resolves to something.** A review read 118 error rows in the audit
  table and found `error_ref` empty on every one — so the identifier a caller is handed and told to
  quote resolved to nothing, for anyone, anywhere. The cause was two independent mints: the
  controller's own reference reached `MCPResult` and so reached the row, while the ones minted
  inside `OpaqueFailure()` and `RejectedValue()` were embedded in the message string and went no
  further than a line in the iTop log file. Every reference a request mints is now recorded and
  written to that request's own audit row, so it points at a queryable object instead of a file
  nothing on this endpoint reads. `error_ref` grew from 32 to 255 characters because a bulk call
  mints one per failing row and at 32 only the first fitted — a caller quoting the third had
  nothing to find — and the list is capped at twelve so the column stays a handle and not a log.
  Both messages say where the reference lives and which call finds it, without promising the caller
  can: `AltiooEventMCPService` is not readable by an ordinary account, and the message says whose
  is.
- **An audit row names the credential and what it changed.** It recorded that a write succeeded
  and neither who nor what: `userinfo` was a login string with no way to reach the token behind
  it, and nothing said which objects were touched. `user_token_id` and `personal_token_id` are
  two keys rather than one because iTop's token classes are siblings under `cmdbAbstractObject`,
  so the populated field is what answers "personal or application"; `change_id` links iTop's own
  record of every old and new value; `changed_objects` repeats the answer in readable form
  because `CMDBChange` retention is the operator's to set, and a purge would otherwise leave
  every historical row saying a write happened without saying to what. The list is `Class::id`
  and attribute names, never values. All three keys are nullable, which is what makes deleting a
  token reset the link instead of taking the row with it — an audit trail the audited party can
  clear by rotating a credential is not one.
- **The endpoint's own audit rows say whether the call was a rehearsal.** `AltiooEventMCPService`
  gains `simulate` — `yes`, `no`, or `n/a` where there is no dry run to speak of. Raised in review:
  the row carried status, duration and size but nothing distinguishing a dry run from a real write,
  and the parameters are kept only at debug level, so a later security review of this log could not
  tell a rehearsal from a change that happened. That is the first question such a review asks.
  Graded from the call's own arguments rather than carried down from the tool, so a pack's tool
  spelling `simulate` the way the core ones do is covered; absent reads as `n/a` rather than `no`,
  because a tool defaulting it to true is rehearsing when the caller says nothing, and reporting
  that as a write would be the lie the field exists to prevent.
- **Another account's preferences are behind a setting rather than refused outright.** Raised in
  review: of the barriers with no override at all, the audit ones earn it — nobody administers an
  instance by editing its own history — but this one did not. A service desk resetting a
  colleague's broken saved view, or the obsolete-data preference hiding half their console from
  them, is a real full-admin use with no other way to reach it here, and every other barrier on
  this endpoint is closed-by-default-with-an-opt-in. Having no hatch made this the odd one out
  rather than the strict one. It now sits behind `mcp_allow_access_administration`, which already
  means "this endpoint may administer other people's accounts", and the refusal names it so a
  caller can report what to change. Your own row was never in question either way.
- **One account may not rewrite another's preferences.** `appUserPreferences` carries a `userid`,
  iTop's own `GetPref()`/`SetPref()` only ever touch the account they are called by, and the
  console offers no way to edit somebody else's — but the object tools did, because a preference
  row is an ordinary `DBObject` with an ordinary id and `UserRights` has nothing to say about it.
  A dry-run update against another user's row came back `valid: true`. This is the mirror of the
  self-guard — the one refusal here that is about *somebody else's* row rather than your own — and
  it matters more than "what a console shows by default" suggests, since one of those preferences
  is whether obsolete objects are visible: rewriting an administrator's row changes what they see
  without changing anything they would look at to find out why. A write naming neither an id nor a
  `userid` is your own and goes through, which is what `core_set_obsolete_data` does.
- **The class schema reports the change log as refused, because it is.** Every tool refuses
  `CMDBChangeOp` and `CMDBChange` outright — the reads too, since `core_object_history` is the way
  in — and the rights block graded them `yes` with `restricted: null`, which is exactly the shape
  of a class nothing guards. Found by a red-team pass that tried to tamper with the audit log,
  could not, and pointed out that the schema had said the opposite. It fails safe, which is why it
  is worth fixing rather than shrugging at: a reviewer reading that block concludes the audit log
  is writable, and the same block on a class where the refusal was *not* there would read
  identically. It is also the one entry that narrows the read gates, since the refusal is not "you
  may not change this" but "another tool serves this".
- **The write barriers reduce to one rule: you cannot arrange what you cannot do.** The rules
  above were arrived at one family at a time, each closing the path a review had just walked. The
  rule they were groping towards, and now the rule they are written as: if a caller cannot read,
  create, update or delete a class directly through a tool, it may not use an indirect mechanism —
  a synchronisation source, a trigger, an action, a queued task — to have the same thing done for
  it. Every mechanism is graded as the direct call would be, against the same barriers and the same
  `UserRights`. Three things follow, and two of them were missing. **Reads count**: a mechanism is
  a way to get data out as much as a way to write, since a trigger hands its object to an action
  whose body takes `$this->attribute$` placeholders and a source mirrors the rows it matches — so a
  class the caller may not read is one it may not arrange to have read out, and the mechanism stops
  being an export of what the read tools would have masked. **Attributes count**: a
  `SynchroDataSource` fills its own mapping in on creation, one row per attribute, each carrying
  `update` — the flag deciding whether the engine writes that attribute, declared with a default of
  `true` — so creating it hands the engine every attribute of the class and the caller must hold
  every attribute of the class; a per-attribute grant it lacks is one the mechanism would have got
  round. The rule keys on `update`, not on `update_policy`, which is a three-valued enum
  (`master_locked`, `master_unlocked`, `write_if_empty`) describing what happens around the write
  rather than whether there is one — all three write. Each attribute is graded on **both**
  `UR_ACTION_MODIFY` and `UR_ACTION_CREATE`, since a source creates what it does not find as well
  as updating what it does, and those are separate action codes an addon may grade differently:
  asking only about modify graded the wrong half of what a data source performs. And **a target behind any barrier is
  refused**, asked as `IsBarred()` rather than as the rights model alone, so a source pointed at
  `Event` or at `AsyncTask` is refused by the rules that refuse `Event` and `AsyncTask` without
  either being listed a second time — a hole the family-by-family version had. Where no direct
  equivalent exists at all — nothing here sends mail, calls a URL, or invokes a static method by
  name — the rule yields "no" for every caller, and `mcp_allow_privilege_escalation` is an
  operator overriding it rather than a grade; even overridden, a trigger is still graded against
  the class it watches.
- **An email action's recipients are graded as the read they are.** The gap left open when the
  automation barrier went in, and closed by tracing the mailer rather than reasoning from the field
  names. `ActionEmail::FindRecipients()` takes the raw OQL out of `to`/`cc`/`bcc`, builds a search,
  calls **`AllowAllData()`** on it — deliberately, so a notification can reach people the acting
  user cannot see — then walks the selected class for its *first* `AttributeEmailAddress` and
  collects that attribute from every matching row. One field is an arbitrary query over an
  arbitrary class with the rights and the silo switched off, returning one column: `SELECT Person`
  is every contact address in the CMDB. So each recipient field is graded as a read — the class,
  in bulk, and the one attribute the address is taken from, and no more than that, since no other
  attribute is ever read out. The message **body** is deliberately not graded the same way: it goes
  through `MetaModel::ApplyParams()` against the trigger's context, reaching the object that fired
  and the acting contact, which grading the trigger's `target_class` already covers.
- **A check that already exists is not the caller's to edit.** The same sentence turned around. An
  `AuditRule` or `AuditCategory` that exists is a control *on* the caller, not a thing the caller
  configures: turning it off, making the change it would have flagged and turning it back on leaves
  nothing for anyone to notice, unless somebody was already watching the rule — which is the thing
  that was supposed to do the watching. Creating one stays allowed, since a new check flags more
  rather than less. The audited class is read from the category's `definition_set` OQL, through
  `category_id` for a rule; where it cannot be established the edit is refused, which makes an
  `AuditDomain` — a grouping, with no query of its own — not editable here at all.
- **`mcp_allow_privilege_escalation` is an escalation switch, not a feature switch**, and now
  says so wherever anyone reads it — the refusals a caller gets, the module setting's own comment,
  the README table and `SECURITY.md`. The name invites exactly the wrong reading. Every other
  refusal on this endpoint means "you could not do this directly, so you may not arrange it"; the
  classes behind this one are refused because **nobody** can do them directly — no tool sends mail,
  calls a URL, invokes a method by name or authenticates outward as this instance, and no profile
  changes that, administrators included. So turning it on is an operator consenting to the endpoint
  granting *more than the credential it was called with*, not an operator letting an assistant tidy
  up the notification rules. It is also **not needed for ordinary automation work**: a caller that
  can already write a class directly may arrange the same writes through a synchronisation source
  without it, graded on its own rights, because that escalates nothing. Leaving it off does not
  stop an assistant automating what it is already entitled to do. A unit test pins the wording in
  all four places, since the misreading is the expensive one.
- **What the rule does not cover, stated rather than implied.** A mechanism graded as permitted
  still writes later, unattended, under a different actor. No privilege is gained — the caller
  could have made those writes directly — and the dry run is no real barrier either, since the same
  call with `simulate=false` does it in one step. What is lost is **attribution**: a direct write
  lands in this endpoint's audit row and in iTop's change log as the caller, while an engine write
  lands in a later batch attributed to the run. A data source's `status` is no help there —
  `implementation` is the default and `PrepareProcessing()` refuses only `obsolete`, so a source
  runs whether or not anyone promoted it to production.
- **All of this is deliberately stricter than the console and the REST API**, which permit every
  one of these paths. Recorded in `SECURITY.md` as a decision rather than left as a discrepancy for
  someone to reconcile: the caller here is a model acting on instructions that may have come from
  outside the organisation, at a rate no person works at, and a dry run and a rights check are
  worth nothing if a mechanism will carry out later, unwatched, what the tool refused now.
- **A write handed to iTop's synchronisation engine is graded on where it lands.** The access
  barrier is built on one sentence — this endpoint never writes the things that decide what it
  may write — and a red-team pass against a live instance found the half that sentence does not
  cover. A `SynchroDataSource` decides nothing about this endpoint's rights and carries no
  credential, so the rule by attribute type passes it by, deliberately and with a note saying so.
  What it is, is a standing instruction to the synchronisation engine: `scope_class` takes any
  class in the datamodel, iTop fills the attribute mapping in for you — `password` and
  `profile_list` included, `update:true` by default — and the engine applies it later through
  `synchro_exec.php` or the console, never through this endpoint. It **checks no rights on what it
  writes**: `CreateObjectFromReplica()` is `NewObject()`, `Set()`, `DBInsert()`, with no
  `UserRights` call anywhere in that path. What it does gate is who may *run* a source — an
  administrator, or the account named in the source's own `user_id` — and that is not much of a
  gate here, because `user_id` is an attribute on the row the caller stages and `synchro_exec.php`
  accepts an ordinary web login: the attacker names itself the owner and triggers its own
  definition. No administrator, and no cron, which iTop ships no background process for anyway. So
  a caller that may not write `UserLocal` could stage an administrator account with a chosen
  password and run it, and nothing in the call that staged it touched a refused class. A
  definition is a write with the object rights check removed, and
  it is graded as one: pointed at a class behind the barrier it is refused and **no setting lifts
  it**, because the engine is graded against nothing and staging must not be the way past the
  self-guard; pointed at anything else it is allowed only where the caller holds create, modify,
  delete and the bulk rights on that class itself, since staging a write you could have performed
  is a scheduling decision and staging one you were refused is the bypass; and a definition that
  names no target is refused, the target being the whole basis on which the other two grade it.
  The family is `SynchroDataSource`, its descendants, and any class carrying an external key to
  one — the mappings, the replicas, the run logs — asked of the datamodel rather than matched on a
  name prefix, so a customer class called `SynchroWidget` stays nobody's business. Reading is
  unaffected, and `core_class_schema` grades these `depends` with a `restricted` line saying what
  would settle the call. **Not covered:** per-attribute rights. iTop populates a new source's
  mapping itself, without any call reaching this endpoint, so there is no write here to refuse —
  the rule is honestly a class-level one, and `SECURITY.md` says so.
- **Creating a token says that the token is not in the answer.** Reported in review as a
  credential-provisioning gap, and it is one — with a cause neither of the offered explanations
  quite had. The endpoint is not masking a value it could return: a token's usable value never
  exists as an attribute at all. `AbstractPersonalToken::AfterInsert()` generates it, keeps it on
  the object as a plain PHP property, stores only its **hash** in `auth_token` — overwriting
  whatever the caller supplied for that attribute, so a caller that chose its own secret does not
  have the credential it thinks it has — and hands the plaintext to the console as a session
  message, which a stateless endpoint cannot deliver. `auth_token` comes back masked over a salted
  hash with nothing behind the mask worth having, and the attribute's own "Readable only at
  generation time" describes that console message rather than anything an API can read. So
  `core_object_create` now returns a `note` saying exactly that, on the dry run as well as the real
  write, and naming the console as the way to the value. Returning the credential itself was
  declined deliberately: a token is portable and works against iTop's other APIs, outside every
  gate on this one, so handing it back through a tool result would put a live secret in a model's
  context, in the transport, and in this endpoint's own audit row.
- **"Unknown class" stopped asserting something false half the time.** Reported in review after a
  table of `URP_UserProfile` writes: a self-refusal for one profile id, `Unknown class
  'URP_UserProfile'` for the others. The diagnosis offered was that the tool collapses an
  unresolvable external key into a class error — it does not, and the difference matters. The
  refusals run in order: `AccessGrants` first, the class-level read right after. A profile id the
  caller holds fires the self-guard and returns before the read check; anything else falls through
  to it, and a non-administrator may not read `URP_UserProfile` at all, because iTop answers
  `UR_ALLOWED_NO` for every action on a class that is neither `bizmodel` nor `grant_by_profile`.
  The external key never entered into it. What was wrong was the sentence: the class is not
  unknown, and a reviewer reading it reasonably went looking for why the datamodel was missing a
  class it was not missing. Both cases must still answer **identically** — telling them apart is a
  class enumerator for a datamodel whose class names are information — so the fix is not to
  disclose but to stop claiming: the answer now names both possibilities and settles neither. Every
  refusal site routes through one helper, so the two cases are the same sentence by construction
  rather than by two literals somebody keeps in step; the existing anti-enumeration test now pins
  that structure instead of counting occurrences.
- **Maintenance mode is named instead of blamed on your profile.** `access_mode` withholds writing
  from everyone (`ACCESS_READONLY`) or from everyone but administrators (`ACCESS_ADMIN_WRITE`, the
  value `2`), and `UserRights::IsActionAllowed()` answers `UR_ALLOWED_NO` for every writing action
  while it holds. The mechanism rule therefore enumerated create, modify and delete as though the
  caller's profile were the problem — and it is reached *before* a tool's own read-only check,
  since the tools ask the barrier first, so on a mechanism class that was the message a caller got.
  It now says the instance is in read-only mode and that this has nothing to do with rights on the
  class, which is the difference between an operator granting a profile that changes nothing and an
  operator waiting for the maintenance window to end.
- **A set attribute that lost part of what was sent is refused, not applied.** Reproduced in
  review on a token's `scope`: sending `"mcp"` came back `valid: true` with
  `applied: {"scope": ""}`. The caller asked for a narrow credential and got one that grants
  nothing, with nothing in the answer saying the value had been rejected. The cause is in iTop and
  is unconditional — `AttributeSet::MakeRealValue()` walks the elements it parsed and unsets every
  one that is not allowed, `if (!isset($aAllowedValues[$sValue])) { unset($aValues[$i]); }`, with no
  exception and no flag. A scalar enum refuses the same mistake by name; the set form was the
  inconsistency, not the refusal. Every conversion site now compares what the caller sent with what
  survived and refuses the difference, naming the elements that were dropped and the allowed
  values. Worth recording why `overridden` never caught it: that block compares the object before
  `CheckToWrite()` with the object after, which is what catches `DoComputeValues()` — this happens
  earlier still, inside the conversion, so the asked-for value is already gone before anything is
  `Set()`. The comparison has to be against the supplied value, and a test pins that it is, since a
  refactor that read the object back would restore the bug in silence.
- **An external key given as a string says what is wrong with it.** The one invalid-value case
  that still dead-ended: `org_id: "abc"` came back as an opaque reference into the instance log,
  which no tool here reads, while every other bad value - an enum, a date - is named in full.
  Reported in review as the one place an agent cannot self-correct. The cause is that a non-numeric
  string is not a malformed id at all: `FindObjectFromKey()` runs it as **OQL**, so `"abc"` is a
  malformed query, and the pre-check that catches a bad id returned early on anything non-numeric.
  It now covers the string form too and names which of the three things is wrong - not OQL at all,
  OQL selecting the wrong class, or a query matching no object or several where a key needs exactly
  one - along with the three accepted forms. The caller's own string is quoted back; iTop's
  exception still is not, for the reason `RejectedValue()` gives.
- **An empty map is no longer a type error.** `fields: {}` was refused with "Invalid type. Expected
  `object`, but received `array`" — a type error for a value whose type was right. The cause is
  PHP's: a JSON body is decoded with `json_decode($sBody, true)`, so `{}` and `[]` are both the
  empty array, and the SDK's validator converts an array back to an object only when it is
  non-empty (`SchemaValidator::convertDataForValidator()`). An empty map therefore arrived as a
  list. `fields: {}` on an update is a caller with nothing to update — an ordinary mistake whose
  ordinary answer is "No fields provided for update." — so the input maps are declared
  `MCPHelper::MAP_TYPE` and the empty case reaches the tool that can say that. Output maps are
  unaffected: they are built by `WritePlan::Map()`, which casts to `stdClass` for this same reason
  on the way out. Pinned by a test that runs the real validator rather than reading the schema,
  since the bug was in the conversion and not in what the schema said.
- **A deletion that happened is reported as one.** The creation paths ask "is there a row" after a
  throw, because `DBInsert()` commits and then keeps going; `DBDelete()` has the same shape and
  `core_object_delete` asked nothing, turning every throw into an outright failure — including the
  ones that arrive with the row already gone. The case that found it was a red-team pass cleaning
  up its own proof-of-concept object: an operator removing something on purpose is told the
  removal did not work, and goes looking for a record that is not there. `WritePlan::IsGone()` now
  asks, and a deletion that happened comes back as the success it is with the failure under
  `warning`. It answers the opposite way to the creation rule on purpose: only positive evidence
  of absence counts, because saying a thing is gone when it is not is the worse mistake here,
  while retrying a deletion that worked costs nothing.
- **`changes` says when it was read.** It is taken before the write and has to be, so an
  attribute the write itself fills is empty there rather than wrong — a ticket answers `""` for
  `ref` and `friendlyname`, which are assigned as the row is inserted, while `after` carries
  what the object ended up with. The schema said only "the value this write set", which reads as
  a contradiction to a caller looking at an empty `friendlyname` beside a populated one. It now
  says which side of the write it describes, and the bulk tools — which report no `after` per
  row — name `core_object_get` as the way to the rest.
- **`core_object_search_by_class` says its filters are equalities.** Every pair becomes
  `AddCondition(..., '=')`, so a word out of a title matches nothing, and a caller arriving from
  the console's quick search reads an empty result as "no such object" rather than "not how this
  filters". The property now says so and names the two tools that do match text:
  `core_object_search_by_oql` with `LIKE`, and `core_object_find_by_name`.

- **A refused deletion names the way that is open.** A profile that may not delete is the normal
  case — service desks retire tickets through the lifecycle, and deletion belongs to
  administrators — but the refusal said only that the door was shut, so a caller had to know the
  datamodel to find the one that is not. It now names the transitions available from the object's
  current state, and only those this caller may actually apply.

- **A bad external key is answered rather than deferred.** `org_id: 999999` on an instance with no
  such organisation came back as "iTop refused it, the reason is in the log under reference X,
  quote that reference" — opaque about a reason that is knowable and harmless, and pointing at a
  log **nothing on this server reads**. The value is now checked before it reaches the ORM and the
  refusal names the class to search and the tools that search it. Missing and unreadable share one
  sentence, so a caller cannot enumerate what it may not see.

- **The opaque refusal points somewhere the caller can go.** It said "quote that reference"; the
  reference indexes iTop's log, which no tool here reads. It now says to check the attribute
  against `core_class_schema`, keeps the reference for the user, and states that nothing here
  reads that log.

- **A stimulus dry run says the state will move.** It answered `valid: true` and `would_move_to`
  with `changes`, `overridden` and `defaulted` all empty — so the one change a transition is
  guaranteed to make appeared in no diff at all, on the tool whose whole purpose is to move a
  state. The dry run does not apply the stimulus, so nothing has moved in memory; the state
  attribute is taken from the lifecycle instead, on the simulated path only.

- **An empty change map is still a map.** `changes`, `overridden` and `applied` are attribute code
  => value, and PHP's empty array encodes as a list — so a write that changed nothing answered `[]`
  where one that changed something answered `{}`, breaking a typed reader on exactly the case it is
  least likely to have tested.

- **A write's `after.applied` answers about the fields that were sent.** It was being handed the
  attributes the *write changed*, and on a creation those are every attribute of the new object —
  so a dry run that set five fields answered with a seventy-attribute object twice, once under
  `changes` and once under `after.applied`.

- **`output_fields` states everything it always returns.** The id, and whether the object is
  archived or obsolete — a row described by the fields a caller named looks the same whether it is
  live or soft-deleted, which is why the flags are always there. A contract that named one
  exception and kept three is one a caller stops trusting.

- **A write describes the object it left behind.** `changes` is taken before the write and has to
  be — `DBInsert()` clears the pending values — so it reports what was asked for and what
  `DoComputeValues()` rewrote, and nothing the write itself did: an `AfterInsert` hook, an event
  listener, the `ref` a ticket is given. `obsolescence_flag` is worse: it is not a stored column but
  an expression the database evaluates when the row is queried, so nothing in memory carries it.
  Create, update and apply-stimulus now re-read the row once, on the real path only, and answer with
  an `after` block — friendlyname, the supplied attributes as they now stand, and obsolescence
  (flag, the condition the datamodel evaluates, and **`hidden_from_searches`**). That last one is
  the case worth the read: an agent sets a status the datamodel counts as obsolete, the write
  succeeds, the object leaves every search that account makes, and nothing said so — so it searches
  for what it wrote, finds nothing, and reports the write as failed.

- **A read by id answers about the object, obsolete or not.** The searches honour the account's
  preference; a lookup by id is a different question, since the caller has the identifier — usually
  because it just wrote the object — and "not found" for a row that exists is the wrong answer.
  Set explicitly rather than left to `DBSearch`'s default, so a guarantee this module makes does not
  rest on a default it does not own.

- **`core_class_schema` says why an object of the class can be obsolete.** The flag says that one
  is and never says why, and the stock conditions come in three shapes — a state on the object, a
  state inherited through an external key, and a date that has passed — so `true` alone leaves a
  caller unable to tell which it is looking at, or what to change.

- **Every read says whether the object is archived or obsolete.** `archive_flag` and `archive_date` are magic
  attributes iTop adds to an archivable class, so a full read always carried them — but the searches
  default to `id, friendlyname`, which describes a soft-deleted object and a live one identically,
  and archive mode is reachable here (iTop reads `with_archive` through `utils::ReadParam`). The
  same goes for `obsolescence_flag`, and that is the half a stock instance actually has: nothing in
  iTop 3.2 declares archiving, while `itop-structure`, `itop-config-mgmt`, `itop-storage-mgmt` and
  `itop-virtualization-mgmt` all declare obsolescence. Both flags are now in every read, with three
  states: `true` and `false` are iTop's own, and **`null` means the class has no such state at all** — a Person or a Team is not "not archived", the
  question does not apply, and `false` there would have a caller filtering on it drop objects that
  were never candidates. The same reasoning the lifecycle block follows when it answers `null`
  rather than an empty list of transitions.

- **`output_fields`** on the reading tools, spelled as iTop's REST API spells it. Searches
  default to `id, friendlyname`; `core_object_get` defaults to `*`.
- **`order_by` / `order_direction`** on both searches. OQL has no `ORDER BY`, so without them
  there is no way to ask for "the ten most recent" — and because every other query language has
  the clause, writing one anyway is the mistake that arrives. A query carrying it is refused with
  iTop's own parser message plus the sentence naming these two parameters, rather than with a
  token position and nowhere to go.
- **Paging is stable, and says when there is more.** Every page is ordered by the requested
  attribute and then by `id`, so an object cannot appear on two consecutive pages while another
  is never returned. `has_more` and `next_offset` come back on both searches, because a page can
  be shorter than `limit` when object-level rights removed rows from it, and a caller that reads
  a short page as the end of the set stops early and silently.
- **Attribute values are rendered with `GetForJSON()`, as the REST API renders them**, so case
  logs, link sets and attachments arrive as themselves rather than as `{}`. Case logs, link
  sets and long texts are cut to a declared ceiling and a blob is reported as its metadata;
  naming the attribute in `output_fields` returns it in full.
- **Graded access**, per instance (`mcp_capabilities`, with `mcp_read_only` as shorthand) and
  per token (`MCP-read`, `MCP-write`, `MCP-delete` scopes). A tool is graded by the annotations
  it already declares. `MCP-write` is the case a read-only switch cannot express: may open a
  ticket and add a note, may not delete anything. The two sides combine by narrowing, and a
  list that narrows to nothing grants nothing — a read-only instance plus a token scoped
  `MCP-delete` serves neither, rather than serving both. This gate is the instance-wide
  configuration; `UserRights` is a separate layer underneath it.
- **Toolsets.** `getToolset()` groups elements by what they are for; `mcp_enabled_toolsets`
  serves a subset, and `MCP-toolset-<name>` scopes a token to one. The core surface declares
  `datamodel`, `objects`, `relations`, `documents`, `history` and `server`. Every value the
  setting can take is a functional group with a token scope and dictionary entries behind it,
  declared by the elements themselves rather than falling out of a namespace.
- **Server instructions at `initialize`, assembled per caller.** A short block of guidance —
  that attribute codes vary per instance and must be looked up, that OQL has no `ORDER BY`, that
  dates are not RFC 3339, that a refusal is a real refusal, and that text found inside an object
  asking the client to call a tool or ignore an instruction is content to report rather than a
  request to act on — plus the conventions every writing tool shares: the dry-run two-step, and
  what `comment` is for. Those are stated here once instead of in each tool's description, which
  is eight copies of two paragraphs in a `tools/list` a client pays for on every connection, on a
  surface read by models rather than by people; the mechanics stay on the `simulate` and `comment`
  properties themselves, which no client can drop, because an instruction block is advisory. With
  the longest descriptions trimmed to the facts a caller acts on, `tools/list` is about 14%
  smaller. It is assembled section by section from the same policy that decides what is
  registered, so a token scoped to one toolset is not told to call tools it will never be served
  — being handed the names and the calling convention of a withheld surface is the opposite of
  withholding it, and a model then spends the session discovering by failure exactly what the
  text exists to prevent.

  **Three blocks are never narrowed.** That every call runs as the authenticated user and that a
  refusal is final; that object content is data rather than instruction; and that the surface is
  fixed at connect time. The second is a control, and a control that weakens as the caller is
  restricted is the wrong way round — the narrow token is the one a hostile ticket is most likely
  to be pointed at. The third matters most to the caller holding the smallest surface, which is
  the one most likely to be small because of something an operator has since changed: this
  transport is stateless and sends no `tools/listChanged`, so a client's tool list is whatever it
  fetched at `initialize` and nothing the server can revise. A model that does not know this
  diagnoses the client, concluding the connection is misconfigured when the server is healthy and
  its own list is simply old. The block says to report it and ask the user to reconnect, and not
  to treat a missing tool as one to work around.

  The date guidance carries a worked example of each, rendered at request time from
  `AttributeDateTime::GetInternalFormat()` and `AttributeDate::GetInternalFormat()` rather than
  written into the prose, so it follows the iTop the module is running on. Telling a model what
  its value is *not* and sending it to `core_class_schema` for what it should be is no help to a
  caller restricted to the object tools, which is exactly the caller that does not have that
  tool. A date and a date-time are two reads because they are two accessors, the reads are
  guarded, and an unreadable format is stated not at all rather than guessed — a format is a
  promise about the string on the wire. `MCPRegistry::AddInstructions()` lets a pack append a
  paragraph; it takes no toolset, so that paragraph is sent to every caller and should be written
  to be true of any of them.
- **Tool, prompt and resource identifiers are `snake_case`** — `core_object_search_by_oql`, and
  a pack's `acme_ticket_add_log_entry`. The name is derived from the class name, so a pack
  author writes none of them by hand. This is the shape every other MCP server in the ecosystem
  uses, and it is the string a client configuration and an `mcp_disabled_tools` entry name.
- **Every workflow has run on GitHub, and the first run found three things.** The pipeline was
  published before it had ever executed, with a notice saying so and saying what had been
  exercised locally instead. The first real run proved the notice right: `upgrade.yml` and
  `itop-matrix.yml` both used `${{ runner.temp }}` in a job-level `env:` and had therefore never
  parsed, the install was gated on a consistency check iTop's own datamodel fails, and the
  integration suite's dictionary list had gone stale against a rename. All three sat in the half
  of the pipeline that only CI exercises. Fixed, guarded where a guard was possible, and the
  notice removed now that `ci.yml`, `itop-matrix.yml`, `upgrade.yml` and `release.yml` have each
  run — the last by `workflow_dispatch`, which builds and checksums the archive without
  publishing it.
- **The audit trail records every audited call, on an instance nobody configured.**
  `log_mcp_level` defaults to `info`, so "every call is audited" — which the Hub listing and the
  sentence the setup wizard shows both say without qualification — is true of a default install
  rather than of one whose operator found the setting. The cost is a row per call and a table
  with no built-in purge, which is an operator's retention decision; `error` narrows the trail
  to failures and connections for an instance where the volume is not worth it, and that is a
  deliberate choice to record less than the documentation claims.
- **The audit trail records that a client connected, and which one.** `initialize` is audited
  whatever `log_mcp_level` says, so it survives even the narrowed `error` level: a successful
  connection is the one success an operator needs whatever else is being kept. Without it a token quietly
  in use by something nobody remembers issuing leaves no trace until it does something, and an
  integration that stopped connecting looks like one that connected and had nothing to do. The
  row names the client from `clientInfo`; nothing verifies it, so it identifies a well-behaved
  integration rather than authenticating anyone.
- **`mcp_disabled_tools` and `mcp_enabled_toolsets` entries that match nothing are logged**, at
  every request, naming the stale entries and listing the toolsets the instance actually has. A
  kill-switch entry pointing at a name nobody answers to hides nothing while looking exactly
  like one that works.
- **Every module parameter the code reads is declared in `<module_parameters>` and documented in
  the README**, and a test derives the list from the `GetModuleSetting()` calls rather than from
  a hand-written copy — so an operator told to set something finds it, and the compiled-in
  fallbacks are the values the datamodel ships.
- `WWW-Authenticate: Bearer` on a `401`, carrying RFC 9728 `resource_metadata` when
  `mcp_protected_resource_metadata` names the document an OAuth proxy serves.
- `mcp_pagination_limit`, defaulting to 200 and set on every request, so a client that ignores
  `nextCursor` cannot silently miss the 51st tool to the SDK's own default.
- **The `core/version` resource reports the extension's own name, version, licence and source
  URL**, beside iTop's. This is the AGPL §13 source offer made where a caller can actually reach
  it: an MCP session has no page to carry a footer, so a client that speaks JSON-RPC to one
  endpoint would otherwise have no way to learn what it is talking to or where to ask for the
  corresponding source.
- **`mcp_source_url`**, for an operator running a modified copy. The offer defaults to upstream,
  which is wrong the moment the module is changed and reachable by third parties: §13 entitles
  those users to the source of what is running, not to ours. A constant nobody could override
  would be a compliance problem with no fix.
- **Titles are translated.** `getTitle()` on all four kinds resolves
  `MCP:<kind>:<qualified name>:title` through iTop's dictionary and falls back to a
  `defaultTitle()` hook, so a pack that ships no dictionary still reads correctly. Core titles
  ship in English and French, as do the profile, the token scopes, the class labels, the enum
  values and the fieldsets. Descriptions are deliberately not translated: they are read by the
  model choosing the tool, and one that changed with the caller's language would change what
  the model does.
- **An example tool pack, in `doc/example-pack/`.** Two tools, a prompt, the module
  declaration, the composer settings, the datamodel delta and a contract test — a complete pack
  to copy into `extensions/` and rename, rather than a documentation section to assemble a pack
  from. Its module file carries a `.tpl` suffix because the setup `eval`s every `module.*.php`
  under `extensions/`, `doc/` included, and an example that appears in the module list is not
  an example. CI runs the commands its README tells a reader to run.
- **`Testing\ElementContract`**, the registration contract as a list of findings rather than an
  exception, so a pack can assert its own elements in one line per element. It ships in the
  production autoload deliberately: `autoload-dev` is absent from a production dump and iTop's
  discovery ignores `tests/`, so the version under `tests/` is the one version a downstream
  pack cannot reach. It references no dev dependency, and nothing that serves a request refers
  to it. Alongside the refusals it reports what registers and then disappoints — chief among
  them a tool with no annotations, which works for an administrator and is invisible to every
  scoped token.
- **`MCPRegistry::Check()`**, running the registration checks against one element without
  registering it. The register methods call it, so there is one copy of the rules.
- **`MCPHelper::RequireVersion()` and `MCPHelper::AtLeast()`**, for a pack that needs a base
  newer than the one installed. The module dependency remains the real gate; this catches the
  base being downgraded under a pack already installed, where the alternative is a fatal error
  inside a request that takes the endpoint down for every other pack too.
- **`MCPHelper::SDK_CONSTRAINT`**, publishing the `mcp/sdk` line this module vendors so a pack
  can compile against what will actually be loaded.
- **`Helper\ToolOutput::Json()`**, the recommended return for a tool, and
  **`ToolOutput::Decode()`**, opening a result whichever of the three shapes it arrived in, for
  a pack that subclasses a core tool to add to what it returns.
- **`JsonPayload`, `ResourceOutput` and `WritePlan::Identity()` / `CheckDeletionRights()` /
  `SerializeDeletionPlan()` / `ChangesSchemaProperty()`**, all on the versioned helper surface.
  Tools and resources encode through the one `JsonPayload::Encode()`, so the same class label
  comes back spelled the same way from `core_class_list` and from `itop://core/classes`, and
  `itop://core/classes` returns the same `{category, filter, total, classes}` envelope
  `core_class_list` does. `ResourceOutput::Json()` is the resource-side twin of
  `ToolOutput::Json()` for a pack writing its own resources.
- **`MCPHelper::RejectedValue()`**, beside `OpaqueFailure()`. Same provenance rule and the same
  log-and-reference shape; what differs is the advice. `OpaqueFailure()` answers a server-side
  failure and tells the caller not to retry, which is wrong for a value the caller chose — this
  one names the attribute and leaves another attempt open. A pack validating its own inputs
  wants this one.
- **`MCPHelper::MCP_METHOD_TOOLS_CALL`, `MCP_METHOD_RESOURCES_READ`, `MCP_METHOD_PROMPTS_GET`
  and `MCP_METHOD_INITIALIZE`**, so the method names that decide audit extraction, the default
  audited list and change attribution are one constant rather than a bare literal in three
  files where a typo fails silently in both directions.
- **`@api` on the classes a tool pack may depend on**, beside the `@since` they carry. The
  surface semver applies to is defined by the tags, and `grep -rl '@api' src/` answers it in
  whatever copy you have rather than sending you to a list in a document that could fall
  behind — a count written here would be the same failure in miniature.
  `AbstractObjectSearch` and `AbstractBulkTool` are on it, under `Abstract\`: they are the parts
  of this module most worth extending.
- **The documentation ships inside the archive** — [doc/extending.md](doc/extending.md) for
  whoever builds a tool pack, [doc/security-summary.md](doc/security-summary.md) for whoever
  has to answer a security or procurement questionnaire, [doc/clients.md](doc/clients.md) for
  connecting one, [CONTRIBUTING.md](CONTRIBUTING.md) with the project's AI-assistance policy,
  and [SECURITY.md](SECURITY.md) with a disclosure channel, a response commitment and a
  declared support period of five years of security fixes from a minor's release date, with six
  months' notice to end one early.

### Security

- **The classes that decide what this endpoint may do cannot be written through it.** Four gates
  decide a request, and three of them — the profile gate, the instance capability grading,
  `UserRights` — hang off the user or the configuration, where no tool can reach them. The token
  scope is the exception: it is an ordinary attribute on an ordinary `DBObject`, and it is the
  only one of the four that grades a single credential rather than everyone holding it. That is
  what an operator buys when they issue an administrator an `MCP-write` token instead of
  maintaining a second user account, and a caller able to write `PersonalToken` would keep that
  bargain only by choosing to: it could widen its own scope to `MCP`, or — without touching the
  row it authenticated with — mint a fresh token that already held it. Writing `User` or a
  `URP_` link does the same thing one layer up, by granting the profiles the scope was
  narrowing.

  `PersonalToken`, `UserToken`, `User`, iTop's `URP_*` rights classes and **everything
  descending from any of them** are refused by `core_object_create`, `core_object_update`,
  `core_object_delete`, `core_object_attach`, `core_object_apply_stimulus` and the three bulk
  tools, whatever the caller's iTop rights say. Descendants are matched with `is_a()` rather
  than by name, so a datamodel extension declaring `<parent>User</parent>` is covered and not a
  way round; the `URP_` prefix is matched as well, so a rights class a later iTop adds is
  covered the day it ships.

  **The names are a floor, not the whole rule.** This is a part of iTop that moves — personal
  tokens arrived in 3.1, application tokens after them, and whatever grades a credential in 4.x
  has no name that can be written down today — so the datamodel is asked as well, about the
  class in front of it rather than about a list. A class declaring a `scope` attribute that can
  hold an `MCP*` value grades this endpoint and is refused on that basis alone. So is a class
  carrying an `AttributeOneWayPassword` — the type that stores a salted hash and nothing else,
  so its value can only ever be compared against, never read back and replayed. That is what
  makes it a credential *into iTop* rather than a secret an object happens to hold: every
  outbound credential has to be recoverable to be used, so it lives in `AttributePassword` or
  `AttributeEncryptedString` instead, as iTop's own OAuth client secret and webhook password do.
  Those recoverable types are deliberately not matched — a mailbox password on a mailbox or a
  login on a CI is ordinary object data, and refusing to write it would be an unrelated
  restriction wearing this one's name. So is a class iTop files under its `addon/userrights`
  category, which every `URP_` class carries.

  The three are a union with the names, which only ever adds: a MetaModel that cannot be read,
  or a category a later version renames, costs the dynamic half and leaves everything the names
  cover still refused. Written the other way round, an instance whose datamodel failed to load
  would open every one of these classes at once.

  iTop's own `addon/authentication` category would have been the obvious signal and is not used:
  `SynchroDataSource` carries it, and a synchro source is not a credential.

  **Reading is deliberately untouched.** A caller listing its own tokens learns nothing it did
  not arrive with — the secret is not readable once minted — and an assistant that can report
  "this token expires on Friday" is worth having. Every read stays gated by `UserRights`
  exactly as before.

  This is narrower than "an administrator can do anything anyway", which is true and is not the
  point: an administrator reaching these classes through the console was always out of scope,
  while an administrator reaching them through a credential minted to be narrow is precisely
  the escalation the scopes exist to prevent.
- **`mcp_allow_access_administration` lets an instance opt into that administration — for other
  people, never for the caller.** Onboarding a user and retiring somebody's leaked token are
  ordinary administration, and an operator who wants an assistant doing them needs a way to say
  so. The setting is `false` by default and an instance that never heard of it gets the refusal
  above.

  Turned on, one rule survives and no configuration lifts it: **a call that reaches the access
  the caller is connected with is refused.** Not the token it authenticated with, not another
  token of its own, not its own account, not a profile link naming it, and not a grant on a
  profile it holds. Without that, opting in would hand back the whole escalation — widen the
  scope of the token in your hand, or mint a second one that is wider, and the narrow credential
  an operator issued was never narrow.

  The guard is asked of the **row**, because which verb is cheapest depends on the row: a create
  carries its owner in the values it is given, an update can re-point someone else's token at the
  caller, and deleting the credential in use is the self-harm iTop's console already refuses. It
  refuses whenever it cannot tell — no login, a row that will not load, a create naming no owner,
  which is a create for the caller since iTop's own controller fills that field in. Being wrong
  the other way is the escalation; being wrong this way is a refusal an administrator satisfies
  from the console.

  It is iTop's own rule, moved one layer out. The console will not let a user delete themselves,
  strip their last profile, or demote themselves out of being able to come back — `User` checks
  all three in `DoCheckToWrite()` and `DoCheckToDelete()`. This applies the same idea to the
  credential rather than the session, because a credential is what a caller of this endpoint
  holds. Manage your own token, account and profiles in the console.
- **The change log has one way in.** `CMDBChange` and `CMDBChangeOp` are ordinary `DBObject`s,
  so every generic tool would otherwise treat them as ordinary objects — and reach the rows
  without the object gate and the per-attribute gate `core_object_history` applies one layer
  above them. `objkey` is an integer column, so `SELECT CMDBChangeOpSetAttributeScalar` would be
  a readable audit trail of every object in the database, silos and attribute rights included;
  a create on one would forge an audit record. Every object, document and relation tool refuses
  these classes and their subclasses, naming the way in. The console draws the same line:
  history is a tab on an object, never a class you search or a form you fill. Held by a test
  that derives the tools it covers from their toolsets, so one registered later is covered the
  day it appears.
- **Reading a set needs the bulk right, including through the relation graph.** The three
  reading tools that return a set check `UR_ACTION_BULK_READ` on the class before returning
  anything, and `core_object_get_related` applies the same rule to the graph it walks, per class
  and from the second object of that class onward. One related object is a single read and stays
  one, so the ordinary "what does this depend on" answer still works for a caller holding only
  `UR_ACTION_READ`. Without that rule, a credential deliberately issued without the bulk right
  reaches, through one readable object plus a relation and a depth, the objects
  `core_object_search_by_class` has just refused it: "this assistant may not sweep the CMDB" does
  not survive contact with the relation graph. It is stricter than iTop's own console, which
  gates impact analysis on read alone, and deliberately so — the console is a person clicking one
  screen, this is a credential that can walk every relation on every object it can reach.
  **Operators:** a token whose profile has read but not bulk read on a class gets relation walks
  over that class truncated, with a note. Grant bulk read on it to restore them.
- **A read that rights truncated says so.** A walk spans classes an account is graded
  differently on, and refusing the whole answer because one of them needs a right the others do
  not throws away everything the caller is entitled to — so the rest is returned and the
  response names what was held back. `core_object_get_related` carries a `withheld` block naming
  the classes and saying why, absent entirely when nothing was, and drops the relations touching
  a withheld object with it, because an edge pointing at an object the payload no longer carries
  is how a partial graph reads as a complete one. `core_object_find_by_name` carries
  `classes_withheld` for the same reason: dropped silently, a caller with no bulk read anywhere
  gets `total: 0` with no error, which a model reads as "there is no such object" and passes to
  the user as fact. The note says in as many words that this is a statement about the account's
  rights and not about what exists, and that another route to the same objects is not to be
  looked for.

  The classes are named and never counted. The names describe this account's rights on classes
  it already holds read on — the answer only ever contained objects the ORM let it see — whereas
  a count would be the datum the bulk right is withholding, which is the oracle
  [SECURITY.md](SECURITY.md) closes. Naming a class the caller may not read stays
  indistinguishable from naming one that does not exist, and a unit test holds that apart.
- **A deletion is refused when its cascade reaches objects the caller may not read, delete or
  modify.** iTop computes a deletion plan with rights off — deliberately, so the plan is
  complete whoever asked for it — and checks the delete right on the object the user clicked and
  on nothing the cascade drags along. That is defensible in the console, where a person saw the
  impact analysis and confirmed it. It is not defensible here: the caller is a language model
  acting on an instruction, cascade is the path by which "delete this ticket" reaches classes an
  operator withheld on purpose, and the audit row afterwards says only that the ticket was
  deleted. `core_object_delete` and `core_object_bulk_delete` check the whole plan, on the dry
  run as well as on the real call. **This endpoint is therefore stricter than the iTop console:
  a deletion the console performs can be refused here.** Grant the rights on the classes the
  cascade reaches, which is the same permission said out loud.
- **The deletion plan is complete, and a caller who may not see all of it is refused rather
  than shown a filtered one.** A plan shown to a person before they approve it has to name
  everything the cascade touches, so the only safe way for it to stay complete is for the
  incomplete case to be refused. A class the caller cannot read is never named in the refusal,
  so the error cannot be used to map the datamodel.
- **A request that presents no credential is refused `401` before the session is touched.**
  `LoginWebPage::ResetSession()` is unauthenticated, so an endpoint that reached it first could
  be called by any website as a top-level GET in a logged-in user's browser and end their
  console session, with nothing in the audit trail that looks like an attack. A cookie is not a
  credential here: what counts is an `Authorization` or `Auth-Token` header, or an identity the
  web server itself decided (`REMOTE_USER`, Basic already parsed by the SAPI), so Basic and
  reverse-proxy deployments are unaffected.
- **iTop's own exception messages never reach the caller.** A failure from `DBInsert()`,
  `DBUpdate()`, `DBDelete()`, `CheckToWrite()` or a query routinely carries SQL, table and class
  names. Those go to `log/error.log` under a reference the caller is given instead. Messages
  this module composed — validation failures, `CheckToWrite()` issues, "Unknown attribute" — are
  returned as they are, because they are what lets a model correct its own call.
- **A refused attribute value comes back naming the attribute, and nothing else.** Every write
  tool passes caller-supplied values through `RestUtils::MakeValue()` and then `DBObject::Set()`;
  `MakeValue()` rethrows what it caught as `<attcode>: <message>`, an external key supplied as a
  string is run as OQL by `FindObjectFromKey()`, and `CoreException` folds its context array into
  the message it exposes — so a database error on that path would otherwise arrive carrying the
  SQL that was issued, the table names in it and the MySQL error beside them. All seven sites go
  through `MCPHelper::RejectedValue()`, and `OrmFailureRedactionTest` scans for the shape of the
  leak rather than for a list of known sites.
- **An uploaded file's media type is verified against the bytes.** The stored type is what iTop
  later serves the file as, so markup stored as `image/png` on a caller's say-so would be markup
  a browser renders under the instance's own origin. The bytes decide, `core_object_attach` says
  so in `mimetype_note`, and inert text formats libmagic has no signature for (CSV, Markdown,
  YAML) are honoured as declared. Reading sniffs nothing: it returns the corrected type that was
  stored.
- **An attribute's allowed values are keyed by the code a caller has to send.** iTop answers
  code => label, and for most attributes the codes are strings — but a stopwatch sub-item is keyed
  `[0 => label, 1 => label]`, which PHP calls a list and `json_encode` strips the keys from, so
  `sla_tto_passed` would arrive as `["no","yes"]`: the labels, resolved through the dictionary, with
  the `0` and `1` the column holds gone, and `["non","oui"]` on a French instance. Nothing writes a
  sub-item, so no write path was affected; a search is, since `WHERE sla_tto_passed = 'no'` filters
  on a string the database never stores. One shape now, for every attribute.

- **The instructions say that nothing arrives unasked, and that nothing is kept between calls.**
  The block already said the *surface* is settled at connect time, which is a statement about
  `tools/list`. It never said the larger thing: this server answers calls and sends nothing else —
  no notification when an object changes, no subscription to wait on, no event when a ticket opens
  or an SLA runs out. A reviewer reading the whole surface could not tell, and an agent that
  promises a user it will be told waits forever. Beside it: each call stands alone, so what
  persists does so because it was written into iTop.

- **The rights block answers for this endpoint, not only for iTop.** It exists so a model can pick a
  call that will succeed instead of discovering the refusal by making it — and on the classes that
  decide what this endpoint may do it did the opposite: iTop says an administrator may modify
  `UserToken`, so the block said `modify: "yes"`, and the write was then refused by a barrier the
  block never mentioned. A caller could learn that its own escalation was blocked only by attempting
  one. The write gates are now graded `"no"` where the barrier refuses outright, `"depends"` where
  `mcp_allow_access_administration` is on and only the caller's own access is off limits, and a
  `restricted` line says which it is — because "no" alone sends a caller to ask an administrator for
  a right no profile can grant. Reads are untouched.

- **A category is listed once, and narrowing on one finds every class in it.** A class declares its
  categories as one string — `"core/cmdb, grant_by_profile"` — and `MetaModel` explodes it on the
  comma without trimming, so a spaced declaration registers `" grant_by_profile"` and an unspaced
  one registers `"grant_by_profile"`. The duplicate in the refusal was the visible half; the half
  that mattered is that `GetClasses()` trims what it is *asked for* while registration did not trim
  what it *stored*, so narrowing on that category silently missed every class declared with the
  space — most of iTop's own core among them.

- **A bulk-created row says what it supplied and what was defaulted.** The single-object create
  answers with `applied` and `defaulted` beside `changes`; the bulk one answered with `changes` and
  `overridden` alone, so on the tool most likely to be creating objects from assembled data there
  was no way to tell what the caller set from what iTop filled in. Read from the object in hand
  rather than re-read — a hundred rows must not become a hundred queries.

- **`core_object_find_by_name` says what a truncated scan cost.** The limit is filled class by
  class in datamodel order, not by relevance — iTop's full-text search has no score to rank by — so
  a common word fills the page from the first classes searched and never reaches the rest. The
  answer already carried `truncated: true`; it now also carries a note saying how many classes were
  reached and the two ways round it, and the description says so before the call.

- **A write names the changes the caller never asked for.** `changes` answers "what this write
  sets", and on a creation that is every attribute — a `lnkContactToTicket` created with a contact
  and a ticket reports `role_code` too, and nothing separated the default it was handed from the
  value it sent. `overridden` already drew the neighbouring line (asked for, not kept), so
  `defaulted` names the third case: not asked for, applied anyway. Codes, not values — the value is
  in `changes`.

- **A secret does not come back through the change log.** A read masks an attribute whose type
  iTop marks secret — the password and encrypted types implement `iAttributeNoGroupBy` — but the
  change log is a second copy of the same values and was not masked: `AttributeDefinition` records
  `oldvalue`/`newvalue` generically and `AttributePassword` does not override it, so changing an
  OAuth client's secret writes both into a `CMDBChangeOpSetAttribute` row, which
  `core_object_history` returned to anyone holding read rights on the attribute. Rights were
  checked and sensitivity was not — different questions, since iTop answers the second by type
  rather than by profile. The row survives (attribute, when, who); the values are masked, and so
  are those of an attribute the datamodel no longer declares.

- **A case-log entry's history row says what was written.** `core_object_history` answered
  `from: null, to: null` for every work note. Not the wrong column:
  `CMDBChangeOpSetAttributeCaseLog` declares `lastentry` — an integer — and no `oldvalue` or
  `newvalue`, so iTop never records the text there; it is on the object, which is why the console
  renders those entries from the object. The text is now read from the object, after the attribute
  rights have allowed it, and matched on date and user rather than on `lastentry` — an index later
  entries push along, and an edited log leaves pointing at someone else's words.

- **An attribute derived from others says which ones.** A `UserRequest`'s `priority` is writable,
  mandatory, and reported as both — and every value a caller sets is discarded, because the class
  derives it from `urgency` and `impact` on every write. A model reading the schema literally, which
  the tool tells it to do, set it and learned otherwise from the `overridden` block afterwards. The
  schema knew: the class XML declares `<dependencies>` on that field. Reported as `dependsOn`, read
  through `GetPrerequisiteAttributes()` rather than listed here, so a pack deriving something else
  reports it the same way.

- **An unknown class in an OQL query is answered with a suggestion, not with the datamodel.**
  `SELECT NotARealClass` came back with every class in the instance, alphabetically — a few hundred
  names as the answer to one typo. The list is iTop's and so is the fix: `CheckOQL()` keeps only
  `getMessage()` and discards an `UnknownClassOqlException` whose `GetUserFriendlyDescription()`
  already runs a closest-match search, over candidates its constructor filtered by read rights. The
  parse is now done here so the exception survives. Every other OQL error keeps `getMessage()`,
  which carries the position and the offending token — minus the trailing "I would suggest to use
  ''" that `OQLException` appends whenever the parser had expectations, even when none was close.

- **`core_class_schema` answers a subset of itself when asked.** A stock `UserRequest` describes
  about forty writable attributes, thirty derived ones, its relations and its whole lifecycle
  graph — and a model calls this before nearly every create and every stimulus, often to learn one
  thing. `include` picks the blocks, `attributes` picks the codes, and `required_only` keeps just
  what a write must set — dropping the derived block with it, since nothing can supply a computed
  attribute and leaving it in made the cheap call the expensive one. Every default is the whole answer, and what comes back carries a
  `reported` block echoing the narrowing: an absent block cannot otherwise be told apart from a
  block that was empty. `itop://core/class/{class}` still answers in full — a resource takes no
  arguments.

- **A refused value says which string would have worked, where that is knowable.** iTop stores a
  date-time as `Y-m-d H:i:s` — a space, no offset — and every other system a model has met uses
  RFC 3339, so the ISO form is what a first attempt sends. The pattern was already in the schema
  and the refusal still cost a round trip to go and read it; the refusal now carries the converted
  string. Suggested, never applied: an offset-bearing value is an instant and iTop stores
  wall-clock time, so moving a timestamp silently would be the worse failure.

- **`core_object_get_related` flags a partial graph beside its summary.** `withheld` names the
  classes held back for want of bulk read, but a caller had to go looking to learn there was
  anything to look for — and a graph quietly missing a class reads exactly like a complete one.
  `truncated` is always present and false on the ordinary answer.

- **`core_class_schema` reports the target class of an external key rather than the rows behind
  it.** Describing `UserRequest` otherwise means returning every `Person` in the database under
  `caller_id`, on every call, and those rows have not been through object-level rights.
- **Sensitive attributes are masked before conversion**, and one predicate decides what
  "sensitive" means for both the schema tools and the object tools.
- **The `MCP Services User` profile grants no data rights of its own.** Like iTop's
  `REST Services User`, it marks a user as allowed through the endpoint; what a client can read
  or write still comes from the user's other profiles and is enforced by `UserRights` on every
  call.

### Packaging

- **The module ships its own `.htaccess` and `web.config`.** iTop's `extensions/` rules deny
  PHP, so without them the documented endpoint answers `403` on a stock Apache or IIS install.
  The compiled copy under `env-<env>/altioo-mcp/` sits where PHP *is* granted for the whole
  subtree, so both files are asserted to be in the archive: a guard missing from the zip would
  fail open, on `src/` and `vendor/`, on an instance that installed without a warning.
- **Release archives are built by CI from a tag**, never by hand, with a published SHA-256, a
  CycloneDX SBOM and a licence inventory attached to the release and carried inside the archive,
  and a build provenance attestation signed against the workflow, the commit and the repository
  (`gh attestation verify`). `vendor/` ships, so what is in it is the audit surface rather than
  an implementation detail — and an instance found in a year's time can answer what it is
  running without reaching the internet.
- **AGPL-3.0-or-later is declared the same way everywhere it appears.** Every PHP file in `src/`
  and `tests/`, plus `index.php`, `register.php` and the module declaration, opens with the same
  two lines, and the `@license` value carries the SPDX identifier verbatim so it matches
  `composer.json` as a string rather than merely in spirit. The exception is
  `model.altioo-mcp.php`, which the compiler overwrites on every setup run.
- **`@since` on the surface the versioning promise covers.** The abstracts, `MCPRegistry`,
  `MCPExtensionCollector`, `iMCPServiceProvider`, the helpers and the checker under `Testing/`
  tag their public methods; every class in `src/` tags itself. At 1.0.0 every one of them reads
  `1.0.0` — the tag starts telling a pack author something from the first release that adds to
  the surface.
- **The datamodel declares schema `3.2`**, matching the branch the extension targets. Nothing in
  the compiler reads the attribute; it says which reference the file was written against.
- **The `extension.xml` description** says which of its claims are access controls (iTop's
  permissions, the profile, the token scopes) and which is a guardrail (the dry run).
- **The supported iTop branches and PHP range are stated once.**
  [`.github/itop-support.json`](https://github.com/AltiooBV/altioo-mcp/blob/main/.github/itop-support.json) and `composer.json` own the answer;
  the copies in the README, the Hub listing and `extension.xml`'s `<description>` are delimited
  and checked against them by `ModuleMetadataTest`.
