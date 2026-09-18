# Security

This extension adds an authenticated HTTP entry point to iTop and lets a language model act
through it. That deserves a page of its own, so that the people who have to approve the
install can read what it does before it is on their instance.

> **Filling in a security or procurement questionnaire?**
> [doc/security-summary.md](doc/security-summary.md) answers the standard set — SBOM and
> licences, provenance, vulnerability process, footprint and least privilege, data processing —
> on one page, assembled from this file and the README so that you do not have to.

## Reporting a vulnerability

Report it privately, through either channel:

- **GitHub** — [Report a vulnerability](https://github.com/altioo/mcp-server-extension/security/advisories/new)
  on `altioo/mcp-server-extension`. Preferred: it keeps the report, the discussion and the
  eventual advisory in one place, and it works without you having to trust an email route.
- **Email** — <security@altioo.com>, if you would rather not use GitHub, or if you cannot
  reach it.

What we commit to:

| | |
|---|---|
| Acknowledgement | Within **5 working days** |
| Assessment, with a severity and a plan | Within **10 working days** of acknowledgement |
| Fix or documented mitigation, confirmed high severity | Within **90 days** of acknowledgement |
| Credit | In the CHANGELOG entry and the advisory, unless you ask us not to |

If 90 days pass without a fix or an agreed extension, publish. We will not ask you to sit on a
report indefinitely, and a deadline that only one side can move is not coordinated disclosure.

Please do not open a public issue for a suspected vulnerability. Include the iTop version, the
extension version (`MCPHelper::VERSION`, also sent to clients as `serverInfo`), the PHP version,
and the request that reproduces it.

**Out of scope**, because they are decisions rather than defects: iTop's own permissions
letting a user reach something you did not expect (that is what `mcp_allowed_profiles`,
`mcp_capabilities` and the token scopes are for); a model being talked into calling a tool it
was allowed to call; and anything reachable only by an administrator, who can already edit the
datamodel. A tool that lets a caller exceed *their own* iTop permissions is very much in
scope — that is the property this extension exists to keep — and so is one that lets a
credential exceed the scope it was minted with, which is the same property one layer down. An
administrator editing a token in the console is a decision; an assistant editing one through
this endpoint is not.

## Supported versions

| Version | Status |
|---|---|
| 1.0.x | **Not released yet.** Supported from the day it is |

Fixes are issued as a new patch of every minor still inside its support period below, not only
of the newest one. The extension follows iTop's own branch policy: a version supported here runs on the iTop branches named in the README, and a
branch that Combodo has retired is not tested against.

### Support period

**Five years of security fixes from the release date of a minor version.**

`1.0.x` has not been released, so its window has not started and no end date is given here.
Both dates are written into this section when the tag is pushed —
[doc/release-checklist.md](doc/release-checklist.md) carries the step. A commitment dated from
anything other than the day the archive actually became installable is one that runs short by
however long the release slipped, and the five years below is a floor, not a target: it is not
a number to spend on a delay in the repository.

Five years is the floor the EU [Cyber Resilience Act](https://eur-lex.europa.eu/eli/reg/2024/2847/oj)
sets for a product with digital elements, and it is committed to here whether or not this
extension ends up inside that Act's scope. Whether it does turns on whether it is supplied in
the course of a commercial activity — a question about how Altioo offers it, not about anything
in this repository, and not one an operator planning a deployment should have to wait on.

Ending support for a minor earlier than that would be announced in the CHANGELOG and in the
GitHub releases **six months** ahead. It will not happen quietly.

### Single point of contact

<security@altioo.com>, alongside the GitHub advisory channel above. The same address is in the
README, in this file, and in `composer.json` under `support.email` — and all three ship inside
the release archive, so it travels with the extension instead of living only on a web page,
which is the point of the requirement. (`support.security` is a URL to this file on GitHub,
which is the field's meaning and is not a contact address.)

Where the CRA's 24-hour and 72-hour reporting duties for an *actively exploited* vulnerability
apply, they are met from that address, and they run in parallel with the timetable above rather
than replacing it: a reporter still gets an acknowledgement within 5 working days.

### What ships beside the archive

| File | What it answers |
|---|---|
| `<archive>.zip.sha256` | "is the file I downloaded the file CI built" |
| `sbom.cyclonedx.json` | CycloneDX inventory of every production dependency |
| `licenses.json` | the licence of each of those dependencies |

The SBOM is what makes a same-day vulnerability answer possible. When a CVE lands on something
under `vendor/`, "does this extension ship it, and at which version" has to be answerable in
minutes. Regenerate both with `composer sbom` and `composer licenses`.

## What the extension does, in security terms

**It adds one public URL**: `extensions/altioo-mcp/index.php`. The module's `.htaccess`
re-grants web access to exactly that file and leaves iTop's blanket deny over everything else
under `extensions/` in place, so `src/`, `vendor/`, `tests/` and `composer.json` stay
unreachable.

**It opens no outbound connection.** iTop never contacts an AI vendor, a model provider or any
other host on behalf of this extension. Traffic is inbound only: an MCP client connects, and
that client is what talks to a model. See *Data flow* in the README.

**Every request is authenticated by iTop itself** (`LoginWebPage::DoLogin()`), on its own — the
endpoint is stateless, and a request that carries no credential of its own is refused before the
session is consulted, while one that carries a credential resets the session before logging in,
so a browser cookie cannot be replayed against it. Four gates apply, and all of them must pass:

1. the profile gate (`secure_mcp_services` / `mcp_allowed_profiles`),
2. the credential, with an `MCP*` token scope where a token is used,
3. the instance capability grading (`mcp_capabilities` / `mcp_read_only`) and the toolset filter,
4. iTop's own `UserRights` — class, object-level, per-attribute and stimulus rights, checked
   inside every tool.

A token scope can only ever make a credential **narrower** than the user's own profiles. It
never widens anything.

### The rule the write barriers reduce to

**You cannot arrange what you cannot do.** If this endpoint will not let a caller read, create,
update or delete a class directly through a tool, that caller may not use an indirect
mechanism — a synchronisation source, a trigger, an action, a queued task — to have the same
thing done on its behalf. Every mechanism is graded as the direct call would be graded: the same
barriers, the same `UserRights`, class **and** per-attribute, on reads as well as on writes.

Three consequences, and they are where the earlier one-family-at-a-time rules came from:

- **Reads count.** A mechanism is a way to get data out, not only a way to write. A trigger hands
  the object it fired on to an action whose body takes `$this->attribute$` placeholders; a data
  source mirrors the rows it matches into its replicas. So a class the caller may not read is one
  it may not arrange to have read out — otherwise the mechanism is an export of exactly what the
  read tools would have masked or refused.
- **Attributes count, not just classes.** A `SynchroDataSource` fills its own mapping in on
  creation: one `SynchroAttribute` per attribute of the class, each with `update` set — the flag
  that decides whether the engine writes that attribute, and whose declared default is `true`.
  Creating the source therefore hands the engine *every* attribute, so the caller must hold every
  attribute. A per-attribute grant it does not have is one the mechanism would otherwise have got
  round. The rule keys on `update` and not on `update_policy`, which is a three-valued enum
  (`master_locked`, `master_unlocked`, `write_if_empty`, defaulting to the first) describing what
  happens around the write — whether the console may still edit the attribute, whether the engine
  only fills a blank — rather than whether there is one. All three write. And the caller is asked
  about **both write actions**, not just `UR_ACTION_MODIFY`: a source creates the objects it does
  not find as well as updating the ones it does, `UR_ACTION_CREATE` is a separate action code in
  iTop that an addon may grade differently, so an attribute the caller may not set when an object
  is first written is one the engine would set on its behalf.
- **Where there is no direct equivalent at all, the answer is no.** No tool here sends mail,
  calls a URL, invokes a static method by name, or authenticates outward as this instance. So for
  `Trigger`, `Action`, `AsyncTask` and the credentials they act with, "could the caller have done
  this itself" has one answer for **every** caller, administrator included — there is no profile
  that makes it yes — and `mcp_allow_automation_administration` is an operator **overriding** that,
  not a grade. Even overridden, a trigger is still graded against the class it watches and an email
  action against the class its recipients select.

**What `mcp_allow_automation_administration` is, said plainly, because its name misleads.** It is
an **escalation switch, not a feature switch**. Every other refusal here says "you could not do
this directly, so you may not arrange it"; this setting governs the cases where *nobody* could do
it directly, so turning it on is an operator consenting to the endpoint granting **more than the
credential it was called with**. It is not "let the assistant manage our notifications", even
though the classes it names are the notification classes.

It is also **not required for ordinary automation work**. A caller that can already write a class
directly — full create, modify, delete and bulk on it, and read and write on every attribute — may
arrange the same writes through a synchronisation source without this setting, because that is
graded on its own rights and escalates nothing. Leaving it off does not stop an assistant
automating things it is already entitled to do; it stops the endpoint handing out what nobody
asked for it to hand out.

**One thing that is *not* covered by any of this, and is worth knowing before you decide.** A
mechanism graded as permitted still writes **later, unattended, and under a different actor**. The
caller could have made those same writes directly, so no privilege is gained and the dry run is no
real barrier either (the same call with `simulate=false` does it in one step) — but a direct write
lands in this endpoint's audit row and in iTop's change log attributed to the caller, while an
engine write lands in a later batch attributed to the run. That is an **attribution** cost, not a
rights one, and it is the honest residue of the rule. A `SynchroDataSource`'s `status` does not
help here: `implementation` is the default and `PrepareProcessing()` refuses only `obsolete`, so a
source runs whether or not anyone has promoted it.

And the same sentence, turned around, for the checks that watch the caller: an `AuditRule` or
`AuditCategory` that **already exists** cannot be changed or deleted here, because turning a check
off, making the change it would have flagged and turning it back on leaves nothing for anyone to
notice. Creating one is allowed — a new check flags more, not less.

**This is deliberately stricter than the console and the REST API, which permit all of it.** That
is not an oversight to be reconciled later: the caller here is a model acting on instructions that
may have come from outside the organisation, it can make a hundred calls in the time a person
makes one, and the whole value of a dry run and a rights check is lost if a mechanism will carry
out later, unwatched, what the tool refused now. A maintainer tempted to relax one of these to
match iTop's other entry points should read this paragraph first.

**The endpoint never writes the things that decide what the endpoint may write.** The scope
above is an ordinary attribute on an ordinary object, so a tool able to write `PersonalToken`
would let a credential widen itself — or mint a second one that is already wider — and writing
`User` or a `URP_` link does the same thing one layer up, through the profiles. So
`PersonalToken`, `UserToken`, `User`, the `URP_*` rights classes and everything descending from
any of them are refused by every write tool, whatever the caller's iTop rights say. Those names
are a floor rather than the whole rule. Whatever it is called, a class is refused if it declares
a `scope` attribute that can hold an `MCP*` value, if it carries an `AttributeOneWayPassword` —
the type iTop verifies a login against, which stores a salted hash that cannot be read back, as
opposed to the recoverable types an object's own secrets live in — or if iTop files it under its
`addon/userrights` category. So a
credential class a later iTop version introduces is covered before anyone here has heard of it. Reading them is not refused.

`mcp_allow_access_administration` (default `false`) lets an instance opt into that
administration where an operator wants it — onboarding a user, retiring a leaked token. It opts
in to administering **other people's** access only: a call that reaches the access the caller is
connected with stays refused, and that guard does not read the setting. Not the token it
authenticated with, not another of its own, not its own account, not a profile link naming it,
not a grant on a profile it holds. The guard is asked of the row rather than the verb — minting a
second, wider token is easier than editing the one in hand — and it refuses whenever it cannot
establish whose access a row is.

That last sentence is not only advice. iTop enforces its own token-management rule —
`personal_tokens_allowed_profiles` — in the console controller and again at login, never on the
object: `PersonalToken` declares no `DoCheckToWrite()`, and the `user_id` protection is an
attribute *flag*, which a write path that checks `UserRights` and `IsWritable()` does not
consult. So any door other than the console reaches the object without meeting that rule. The
barrier above is this endpoint declining to be such a door.

**The rights model has the same shape, and it is the reason `URP_*` is behind the barrier too.**
iTop's protections against a user dismantling their own access — no deleting yourself, no
stripping your last profile, no demoting yourself out of being able to come back, no granting
yourself a profile that denies the backoffice — all live in `User::DoCheckToWrite()` and
`User::DoCheckToDelete()`, and all of them fire only when `profile_list` appears in the changes
**on the `User` object**. The link row itself carries no such check: in the rights addon a
standard install actually runs (`userrightsprofile.db.class.inc.php`, which is what setup writes
into the configuration), `URP_UserProfile` declares no `DoCheckToWrite()` and no
`DoCheckToDelete()`. The `CheckIfProfileIsAllowed()` routine that blocks a non-administrator from
granting the Administrator profile exists only in the *other* addon file, which a standard
install does not load. And a link row written directly takes effect immediately —
`UserRightsBaseClass` and `UserRightsBaseClassGUI` call `UserRights::FlushPrivileges()` on
insert, update and delete, which is a hook that exists because direct writes happen.

So one inserted row grants a profile without passing a single one of those checks. Whether any
credential other than this endpoint's can reach that row is iTop's question and not one this
document answers; what matters here is that **this** endpoint will not be the one that does, and
that the self-guard keeps holding when `mcp_allow_access_administration` is on — a profile link
naming the caller, and a grant on a profile the caller holds, stay refused.

**A write handed to something that writes for you is graded on where it lands.** The barrier
above is about classes that decide what this endpoint may do. A `SynchroDataSource` decides
nothing of the kind and carries no credential — the rule by attribute type passes it by,
deliberately. What it is, is a standing instruction to iTop's synchronisation engine:
`scope_class` names any class in the datamodel, the attribute mapping names the fields to
overwrite, and the engine applies it later — through `synchro/synchro_exec.php`, from the
console, or from whatever external scheduler feeds the source. It never passes through this
endpoint.

Being precise about what the engine does and does not check, since it decides how the rules
below are drawn. `SynchroExecution::PrepareProcessing()` *does* gate **who may run** a source:
an administrator, or the account named in the source's own `user_id`. What it does not do
anywhere is check rights on **what it writes** — `CreateObjectFromReplica()` is
`MetaModel::NewObject()`, `Set()`, `DBInsert()`, with no `UserRights` call in the path at all
(the only two in that file guard a console display). And `user_id` is an attribute on the same
row a caller stages, while `synchro_exec.php` accepts an ordinary web login — so an attacker
names themselves as the owner and triggers their own definition, no administrator and no cron
required. iTop ships no background process that runs data sources on its own.

So a definition is a write with the *object* rights check removed, and three rules follow.

A definition pointed at a class behind the barrier above is refused, and **no setting lifts
it** — not `mcp_allow_access_administration`, which buys administration of *other* people's
access through this endpoint, where every such call is graded against the caller's own
credential on the way past. The engine is graded against nothing, so permitting this would
hand back the self-escalation that has no switch: a caller that may not write `UserLocal`
could otherwise stage a source pointed at it (iTop fills the mapping in for you, `password`
and `profile_list` included, `update:true` by default) and wait for an administrator account
of its choosing to appear.

A definition pointed at any other class is allowed **only if the caller could have written
that class itself** — create, modify and delete, and the bulk forms, all five, because a data
source does all of them and its own `delete_policy` decides the last. Staging a write you
could have performed is a scheduling decision; staging one you were refused is the bypass.
Rights that cannot be established are a refusal.

A definition that does not say where it lands is refused, because the target is the entire
basis on which the other two rules grade it.

The family is `SynchroDataSource` and anything descending from it, plus any class carrying an
external key to one — which is what the attribute mappings, the staged replicas and the run
logs all are. It is not matched by name prefix: a customer class called `SynchroWidget` stages
nothing and is nobody's business here. Reading is unaffected throughout, and
`core_class_schema` grades these classes `depends` with a `restricted` line saying what would
settle it. Found by a red-team pass against a live instance, not by this document.

**And it does not write standing instructions that make the instance act on its own.** A third
family, refused wholesale rather than graded — and the difference from the synchronisation rule
above is the whole argument for it. A synchro delegates a capability this endpoint *does* grant
and can therefore grade: writing objects of a class. A `Trigger` with an `Action` behind it
delegates capabilities this endpoint grants **nobody** — no tool here sends mail, none makes an
outbound HTTP request, none invokes a static method by name. "Could the caller have done this
itself" has one answer for every caller, so there is nothing to grade against.

What one burst of write access buys without the barrier: a `RemoteApplicationConnection` whose
`url` is a plain text attribute with no scheme or host validation — an attacker's collector, or
an internal address the web server can reach and the caller cannot — an `ActioniTopWebhook`
pointed at it carrying whatever payload it likes, and a `Trigger` linked to that action by
`lnkTriggerAction`, firing on every matching change made by anybody. The session ends; the
channel does not, and nothing else on this endpoint outlives the call that made it.
`ActioniTopWebhook`'s `prepare_payload_callback` and `process_response_callback` add a second
thing worth knowing: they take a `Class::method` string and invoke it as a public static
callback. That is not code injection — the method must already exist — but it is "call any
loaded public static method by name", and what is loaded depends on the instance's extensions.

So `Trigger`, `Action`, everything descending from either, any class carrying an external key to
one of them (which is what `lnkTriggerAction` is), and `RemoteApplicationConnection` are refused
by every write tool unless `mcp_allow_automation_administration` is on. That is **its own
setting**, not a share of `mcp_allow_access_administration`: "may an assistant administer other
people's access" and "may an assistant leave a standing instruction that makes this instance call
out on its own" are decisions an operator can reasonably take separately. Reading is unaffected.

**And it does not write the record of what it did.** This module writes an
`AltiooEventMCPService` row per inbound request — method, tool, status, error — and then left
that class writable by the very session it was recording: a caller could delete the evidence of
what it had just done, and then the row recording that deletion, in reverse order, leaving no
MCP-side trace. Every other control here rests on "we can review what the agent did", and this
is the one whose absence makes that sentence false.

The rule is on the parent, not on this module's class. `AltiooEventMCPService` declares
`<parent>Event</parent>`, and so do iTop's own `EventNotification`, `EventIssue`,
`EventWebService`, `EventRestService` and `EventLoginUsage` — all records of something that
happened. Gating `Event` covers this module's class, iTop's, and whatever a pack adds, with none
of them listed. **No setting lifts it**, unlike every other barrier here: an audit trail the
audited party may edit with the operator's permission is an audit trail the audited party may
edit. Reading stays open — an assistant that can answer "what did I call, and what failed" is
useful, and reading a record does not alter it. iTop's change log is the same idea one layer
earlier: `CMDBChange` and `CMDBChangeOp` are not merely unwritable but unreadable through the
object tools, because `core_object_history` serves them instead.

**And it does not queue work for the cron to carry out.** `AsyncSendEmail` extends `AsyncTask` and
*is* the outbound mail queue iTop's cron drains, with free-text `to`, `subject` and `message` and
a status of `planned`. One `core_object_create` puts a real email into it, sent from the
instance's own configured identity to any address — no trigger, no action, no connection object,
none of the scaffolding the automation barrier was written for. The gate is on `AsyncTask`, not
on `AsyncSendEmail`, because the queue is the primitive: anything else landing in it is executed
the same way by the same cron. `ActionEmail` is a second route to the same place and was already
covered by `Action`. Its `to`/`cc`/`bcc` are `AttributeOQL`, and what the mailer does with them is
worth stating exactly, because the grading follows from it: `FindRecipients()` takes the raw OQL,
builds a search, calls **`AllowAllData()`** on it — deliberately, so a notification can reach
people the acting user cannot see — then walks the selected class for its *first*
`AttributeEmailAddress` and collects that one attribute from every matching row. So one field is
an arbitrary query over an arbitrary class with rights and silo switched off, returning one
column: `SELECT Person` is every contact address in the CMDB.

Each recipient field is therefore graded as what it is, a read: the caller must be able to read
the class the query selects, in bulk, and to read the one attribute the address is taken from.
Not every attribute — only one is ever read out, and refusing on the rest would refuse something
the mailer does not do. The message **body** is not the same problem and is deliberately not
graded this way: it goes through `MetaModel::ApplyParams()` against the trigger's context, so it
reaches the object that fired and the acting contact, which grading the trigger's `target_class`
already covers.

**And it does not write the credentials it authenticates outward with.** `Oauth2Client` and its
five subclasses carry `client_secret`, `refresh_token` and `access_token` as
`AttributeEncryptedPassword`; `OAuthClient` is the mailbox side of the same idea. These were
outside the barrier on the reasoning above — that a recoverable secret is the object's own data,
which is why a device or mailbox password is deliberately not matched. That reasoning covers a
device password and does not cover a token this instance authenticates to a third party with,
which a caller can replace with its own or read back through something else it wrote. They sit
behind `mcp_allow_automation_administration` with the rest of the outbound machinery. The wider
question — every class holding any recoverable secret — is deliberately still open, and the
recoverable-type exclusion above still stands for the cases it was written for.

That barrier is about writing them. **Reading them turned out to be half-open**, which is worth
stating plainly because it was checked in response to this review rather than known. Masking on
this endpoint keys off `iAttributeNoGroupBy`, the interface iTop's own code tests for, and the
two OAuth datamodels do not agree on which type to use: `Oauth2Client` puts `client_secret`,
`refresh_token` and `access_token` in `AttributeEncryptedPassword`, which implements it and was
already masked — while `OAuthClient`, the mailbox side, puts `client_secret` in
`AttributePassword` (also masked) but `refresh_token` and `token` in **`AttributeText`**, which
is not sensitive by type. A live refresh token the instance authenticates to a mail provider
with came back in clear. The type remains the rule everywhere else; those four attribute codes
on those two class hierarchies are now masked regardless of it, matched exactly so that
`refresh_token_expiration` stays readable — the expiry is the useful half and not the secret.
The mask applies to reads, to the change history, and to the `isSensible` flag the schema
reports, since a secret leaked through the history or advertised as ordinary is leaked just the
same.

**And it does not switch off what a person would be shown.** `AuditRule`, `AuditCategory` and
`AuditDomain` are iTop's data-quality audit, separate from the change log. Nothing there grants
access to anything; it is the other half of covering your tracks, since an agent that has made a
mess of the CMDB can delete the rule that would have put that mess on somebody's dashboard, and
the mess then looks like the data. Behind the automation setting rather than refused outright,
because managing data-quality rules is ordinary work an operator may delegate — unlike the record
of what already happened.

**How these are meant to be found in future.** The honest description of the barrier is that it
is a set of named roots matched by descent, plus two questions asked of the datamodel (what
points at a synchronisation source; what points at a trigger or an action) — not a derived
security property. A reviewer put that plainly: a class here "is writable because nobody added it
to the blocklist, not because it was evaluated and judged safe". Two things narrow that gap
rather than closing it. The roots are chosen as high in each hierarchy as the meaning holds
(`Event`, not `AltiooEventMCPService`; `AsyncTask`, not `AsyncSendEmail`; `Action`, not
`ActionEmail`), so a class added below one is covered before anyone here has heard of it. And a
unit test walks every class **this module's own datamodel declares**, resolves its declared
parent chain, and fails unless each is either behind a rule or named in that test with a reason —
so a class added here without that decision being made fails at the moment it is added. That test
is exactly what did not exist when `AltiooEventMCPService` was written.

**And it does not write another account's personal rows.** `appUserPreferences` carries a
`userid`, and iTop's own API for it — `GetPref()`/`SetPref()` — only ever touches the account it
is called by; the console offers no way to edit someone else's. The object tools did, because a
preference row is an ordinary `DBObject` with an ordinary id and `UserRights` has nothing to say
about it. This is the mirror of the self-guard above, and the only refusal here that is about
*somebody else's* row rather than your own. Low blast radius — what a console shows by default —
except that one of those preferences is whether obsolete objects are visible, so rewriting an
administrator's row changes what they see without changing anything they would look at to find
out why. A write naming neither an id nor a `userid` is your own row and goes through, which is
what `core_set_obsolete_data` does.

**What this does not cover.** Per-attribute rights. iTop populates a new source's mapping
itself, every attribute at `update:true`, without any call reaching this endpoint — so there is
no write here to refuse. A caller who may modify a class but not one of its attributes can have
that attribute overwritten by the engine. The rule is honestly a class-level one.

**No tool writes on a first call.** Create, update, delete, attach, apply-stimulus and the three
bulk tools all default to `simulate: true` and return what the call *would* change, having run
iTop's `CheckToWrite()`. Writing requires an explicit `simulate=false`. This is the mitigation
that matters most against prompt injection: a model acting on text that came from outside the
organisation cannot silently commit a change on the strength of that text alone.

**Calls are audited** as `AltiooEventMCPService` objects, with the method, the element invoked,
the outcome, the duration and the calling user. What earns a row depends on `log_mcp_level`:
at the default `error` that is every failure and every client connection, and successful calls
are left out deliberately, since a busy instance would otherwise write a row per read. Set it
to `info` to record those too — the section below explains why `error` is nonetheless the
recommended operating level.

**Errors do not leak internals.** Only `ToolCallException` and `ResourceReadException` messages
reach the client; anything else is answered generically and correlated to `log/error.log` by a
reference.

## Threat model

| Threat | What answers it |
|---|---|
| Prompt injection reaching a write tool — a ticket description, an email, a web page tells the model to delete something | Dry run by default on every write; `mcp_capabilities` / `mcp_read_only` instance-wide; `MCP-read` / `MCP-write` token scopes; `UserRights` on every object and attribute |
| A leaked token used against another iTop API | `MCP*` scopes are distinct from `REST`/`Export` scopes: a token minted for REST cannot call this endpoint, and the reverse holds too |
| A credential stronger than the assistant needs | Scope the token (`MCP-read`, `MCP-toolset-<name>`) rather than creating a second user account |
| An assistant widening the credential it was handed — editing its token's scope, minting a wider one, granting itself a profile | `PersonalToken`, `UserToken`, `User` and `URP_*`, with their subclasses, are read-only through this endpoint, as is any class declaring an `MCP*` scope or filed under iTop's user-rights category; the refusal does not consult `UserRights`, so it holds for an administrator too. An instance that opts into `mcp_allow_access_administration` can administer other people's access and still never its own — that half has no switch |
| An assistant staging a privileged write for something else to carry out — a synchronisation source pointed at the user classes, applied later by the synchro engine, which checks no rights on what it writes and can be triggered by the account the source names as its owner | A definition pointed at a class behind the barrier above is refused and no setting lifts it; one pointed at any other class is allowed only where the caller holds create, modify, delete and the bulk rights on it themselves; one that names no target is refused. Per-attribute rights are not covered — see above |
| An assistant leaving a standing instruction behind it — a trigger wired to a webhook action, firing on everyone's changes long after the session ends, pointed at an attacker's collector or an internal address (SSRF) | `Trigger`, `Action`, their descendants, anything carrying an external key to one, and `RemoteApplicationConnection` are read-only unless `mcp_allow_automation_administration` is on — its own setting, since no tool here can send mail or call a URL directly and so there is no rights answer that makes staging one equivalent |
| One account rewriting another's stored UI preferences | `appUserPreferences` writes are allowed on your own row and refused on anyone else's, whatever the profile says |
| Tampering with the audit log | `CMDBChangeOp` and `CMDBChange` are refused by every tool, reads included — `core_object_history` is the only way in, and `core_class_schema` now reports that refusal instead of grading them `yes` |
| An agent deleting the evidence of what it did — the MCP endpoint's own audit rows, or iTop's event log | `Event` and everything descending from it, `AltiooEventMCPService` included, are read-only through this endpoint with **no setting to change that**; `CMDBChange`/`CMDBChangeOp` are refused outright, reads included |
| Sending mail from the instance's own identity — phishing internal staff, or spoofing outward at scale | `AsyncTask` (and so `AsyncSendEmail`, the queue the cron drains) and `Action` (and so `ActionEmail`, whose `to`/`cc`/`bcc` are OQL queries) are behind `mcp_allow_automation_administration` |
| Stealing or replacing the tokens the instance uses against third parties | `Oauth2Client`, its subclasses and `OAuthClient` are behind the same setting |
| Disabling the checks that would flag a mess to a human | `AuditRule`, `AuditCategory`, `AuditDomain`, same setting |
| Data exfiltration through a wide read | Reads go through per-attribute read rights; attributes whose type implements `iAttributeNoGroupBy` are masked; `mcp_disabled_tools` removes an element outright |
| A malicious or careless third-party tool pack | Packs run with the caller's rights and no more; `mcp_enabled_toolsets` serves only what you list, so a tool added by an update is off until you say otherwise; `mcp_disabled_tools` accepts a class name |
| Browser-based attack on the endpoint | No `Access-Control-Allow-Origin` is sent unless `mcp_allowed_origins` names an origin; the session is reset per request, so a cookie cannot be used |
| Enumeration of the datamodel by an unauthorised caller | The profile gate runs before anything is advertised; `tools/list` is filtered per caller, and the `initialize` guidance is assembled from the same policy, so it names no tool the caller is not served |

**What is out of scope.** This extension cannot control what the MCP *client* does with data it
has legitimately read, which model that client sends it to, or what that vendor retains. That is
a property of the client and its provider, and it is the thing to review alongside the profiles
you grant.

## Hardening the deployment

Follow [iTop's security guidance](https://www.itophub.io/wiki/page?id=3_2_0:install:security)
— the version-pinned page for the current LTS, deliberately rather than the wiki's `latest:`
namespace, which tracks the development branch and has been observed recommending parameters
and files that do not exist on 3.2 ([doc/itop-branch-notes.md](doc/itop-branch-notes.md) §1
records two). If you run 3.3, read
[the 3.3 page](https://www.itophub.io/wiki/page?id=3_3_0:install:security) instead. In
particular, and specifically relevant here:

- Serve iTop over **HTTPS with HSTS**. A bearer token on a plaintext connection is a shared secret.
- Set `session.cookie_secure` and `session.cookie_httponly` in PHP.
- Set **`zend.exception_ignore_args=On`** in `php.ini`. With it off, a stack trace records the
  arguments of every frame — and the frame that authenticates a request was handed the raw
  token. That is how a credential ends up in a log file nobody thinks of as sensitive.
- Disable the console configuration editor wherever this endpoint is enabled:
  `'itop-config' => array('config_editor' => 'disabled')`. It executes the PHP saved into it by
  design, and an Administrator-scoped token reaches it — which is the difference between a
  prompt injection that closes a ticket and one that runs code.
- Keep `mcp_allowed_origins` empty unless a browser-based client you control needs it. Never `*`.
- Leave `mcp_allowed_hosts` derived unless iTop answers under a name `app_root_url` does not
  carry. `array('*')` disables the `Host` check and belongs only behind a proxy that performs
  it itself.
- Start with `mcp_capabilities => array('read')` and issue `MCP-read` tokens. Widen one grade
  at a time, against what the audit trail shows the assistant actually doing.
- Scope `CGIPassAuth On` to `extensions/altioo-mcp/index.php`, never to the whole server.
- Behind an OIDC proxy: map identity to an iTop credential the proxy holds. Never forward the
  upstream access token as the iTop credential — the MCP specification forbids that
  passthrough with a MUST NOT, and it turns a token stolen from any other service into a
  working iTop credential.
- Grant `MCP Services User` alongside a functional profile chosen for this purpose — the paired
  profile is the real blast radius, not the MCP profile itself.
- Review **`personal_tokens_allowed_profiles`** (authent-token, default `array('Administrator')`)
  before assuming every token holder is an administrator. It is checked when a token *logs in*,
  so adding a profile to it widens who can hold a working credential for every API on this
  instance, this endpoint included — and it is the reason a token minted against the wrong
  account is worth something rather than nothing.
- Leave `log_mcp_level` at `error` in normal operation: `debug` stores raw request parameters,
  which may contain data your users would not expect to find in an audit log.
- Review `AltiooEventMCPService` retention against your own data-retention policy.

## Dependencies

Runtime dependencies are vendored in the archive and listed in `composer.json` /
`composer.lock`. The notable one is **`mcp/sdk`**, pinned `^0.7.1` — a pre-1.0 package, so its
API can change between minors. The extension pins the minor it was tested against and treats an
SDK upgrade as a release of its own, with the test suite as the gate; nothing on an installed
instance changes until you install a new version of this extension. `composer audit` runs in CI
against the lock file.

The other one worth knowing about is **`nyholm/psr7`**, the PSR-17 implementation every request
and response is built through. No line of this module names it: it is located at runtime by
`php-http/discovery`, which makes it look unused to a reader and to anything that prunes
dependencies. `Psr17AvailabilityTest` fails if it goes missing. Nyholm rather than Guzzle
deliberately — iTop ships `guzzlehttp/psr7` itself, and a Composer autoloader prepends itself
when it registers, so of two in one process the one registered *last* answers first. That is
this module's: iTop registers its own on the first line of `index.php`, while this module's
`vendor/autoload.php` is a datamodel file and is loaded later, during `MetaModel::Startup()`.
So a second copy of that namespace vendored here would be the copy that *wins* — shadowing
iTop's own for every request to the environment, not only for the ones this endpoint serves. A
namespace iTop does not ship displaces nothing. `index.php` explains the ordering.

Seven packages nonetheless appear in both trees, and on that reasoning it is this module's
copies of them that answer. `VendoredDependencyResolutionTest` lists the seven and checks each
against what `composer.json` declares, with the one accepted divergence named in the file and
any eighth failing the test. The reverse question — whether the copies that actually answer
satisfy what *iTop's* own `installed.json` declares — is recorded there as a known gap rather
than answered; the versions cannot simply be pinned to iTop's, because the branches this module
supports do not ship the same ones.
