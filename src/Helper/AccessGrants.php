<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use DBObject;
use DBObjectSearch;
use MetaModel;
use Throwable;
use UserRights;

/**
 * The classes that decide what this endpoint may do, which it may therefore
 * not write.
 *
 * Four gates decide a request: the profile gate, the token scope, the instance
 * capability grading, and iTop's own UserRights. Three of them hang off the
 * user or off the instance configuration, and neither is an object a tool can
 * reach. The token scope is the exception - it is an ordinary attribute on an
 * ordinary DBObject - and it is the only one of the four that grades a single
 * credential rather than everyone holding it.
 *
 * That is what makes it worth a barrier of its own. AccessPolicy promises that
 * a scope can only ever make a credential narrower than its owner's profiles,
 * and the promise is what an operator buys when they issue an MCP-write token
 * to an administrator instead of maintaining a second user account. A caller
 * that can write PersonalToken keeps the promise only by choosing to: it can
 * widen its own scope to MCP, or - easier, and without touching the row it
 * authenticated with - mint a fresh token that holds it. The same goes one
 * layer up, where writing User or a URP_ link grants the profiles that the
 * scope was narrowing.
 *
 * So the rule is structural rather than a matter of rights: the endpoint never
 * writes the things that decide what the endpoint may write. An administrator
 * reaching this through the console is out of scope and always was - they can
 * already edit the datamodel - but an administrator reaching it through a
 * credential minted to be narrow is exactly the escalation the scopes exist to
 * prevent, and "they were an administrator anyway" does not cover it.
 *
 * Reading is not refused. A caller listing its own tokens learns nothing it
 * did not bring with it, the secret is not readable after it is minted, and
 * an assistant that can report "this token expires on Friday" is useful.
 * Every read stays gated by UserRights as before.
 *
 * Every class named below is matched with its descendants, through is_a()
 * rather than by name: UserLocal and UserLDAP are Users, an authentication
 * pack adds more, and a datamodel extension can subclass any of them. A
 * barrier that matched only the exact names would be one <parent> away from
 * open, and nothing would report that it had been stepped around.
 *
 * The prefix is a second match on top of that, not a substitute for it. iTop's
 * rights model is a flat family - URP_Profiles, URP_UserProfile, URP_UserOrg,
 * URP_ActionGrant, URP_StimulusGrant, URP_AttributeGrant and URP_Dimensions
 * are siblings sharing a naming convention, not a parent - so a version that
 * adds an eighth one is covered the day it ships, before anyone here notices
 * it exists. The listed names catch subclasses; the prefix catches siblings.
 *
 * Names alone would still be a list that ages, though, and this is a part of
 * iTop that moves: personal tokens arrived in 3.1, application tokens after
 * them, and whatever grades a credential in 4.x has no name anyone can write
 * down today. So the datamodel is asked as well, about the class in front of
 * it rather than about a list:
 *
 *   - a class declaring a scope attribute that can hold an MCP scope grades
 *     this endpoint, so this endpoint must not write it - which is the
 *     definition the token classes satisfy, arrived at without naming them;
 *   - a class carrying an AttributeOneWayPassword holds a credential, which is
 *     how iTop stores one and the only thing every credential class has in
 *     common;
 *   - a class iTop files under its user-rights category is part of the rights
 *     model by iTop's own reckoning rather than by its spelling.
 *
 * The two layers are a union and the names are the floor. Nothing in the
 * datamodel can make a listed class writable; the datamodel is only ever asked
 * whether something else should be refused too. That ordering is what lets the
 * dynamic half fail quietly - no MetaModel, a category iTop renames, an
 * attribute definition that raises - without opening anything that was closed
 * before, and it is why the unit suite, which runs without an iTop at all,
 * still holds the barrier to the same classes.
 *
 * @api
 * @since 1.0.0
 */
final class AccessGrants
{
	/**
	 * Each of them, and everything descending from it.
	 *
	 * PersonalToken and UserToken carry the scope attribute this endpoint
	 * reads. User carries the login, the password and - through profile_list -
	 * the profiles behind every other gate. The URP_ classes are the rights
	 * model itself: what a profile grants, and who holds it.
	 *
	 * The last two are the parents the URP_ classes actually declare, in
	 * addons/userrights - so the family is matched by descent and not only by
	 * the convention it is named by. User is listed because it is abstract and
	 * carries no credential attribute of its own: UserInternal declares the
	 * password and UserExternal declares nothing, so a rule made of attributes
	 * would cover the second of those and not the first.
	 *
	 * The list is iTop's as of 3.2 and is not load-bearing on its own - the
	 * prefix and the datamodel below cover the family whether or not this
	 * stays current.
	 */
	private const GRANTING_ROOTS = [
		'PersonalToken',
		'UserToken',
		'User',
		'URP_Profiles',
		'URP_UserProfile',
		'URP_UserOrg',
		'URP_ActionGrant',
		'URP_StimulusGrant',
		'URP_AttributeGrant',
		'URP_Dimensions',
		'UserRightsBaseClass',
		'UserRightsBaseClassGUI',
	];

	/**
	 * "User Rights Profile": the convention every class of the rights family
	 * is named by, which covers one this module has never heard of.
	 */
	private const GRANTING_PREFIX = 'URP_';

	/**
	 * The classes that write on this endpoint's behalf, with rights it does
	 * not have.
	 *
	 * The barrier above is built on one sentence: the endpoint never writes
	 * the things that decide what the endpoint may write. These classes are
	 * the other half of it, and the half that was missing. A
	 * SynchroDataSource decides nothing about this endpoint's rights - it is
	 * not a credential and carries none, which is why the census by attribute
	 * type passes it by and why the note on CREDENTIAL_ATTRIBUTE says as much.
	 * What it is, is a standing instruction to iTop's synchronisation engine:
	 * scope_class names any class in the datamodel, attribute_list names the
	 * fields to overwrite, and the engine applies it later, from cron or from
	 * a console button, as a trusted internal process. It does not pass
	 * through this endpoint, so it never meets the barrier above.
	 *
	 * A caller that may not write UserLocal can therefore write a
	 * SynchroDataSource whose scope_class is UserLocal - iTop fills the
	 * mapping in for you, password and profile_list included, update:true by
	 * default - and wait. The account it creates is an administrator with a
	 * password of the caller's choosing, and nothing in the write that staged
	 * it touched a class this module refuses.
	 *
	 * The refusal is therefore not about credentials. It is that a definition
	 * executed later by something else is a write this endpoint cannot grade:
	 * not the class it lands on, not the values, not whether it reaches the
	 * caller's own access. Staging one is indistinguishable from performing
	 * one, except in when it happens and in who is blamed.
	 *
	 * Matched by descent and by prefix, like the family above and for the same
	 * reason. SynchroAttribute is abstract and its subclasses carry the field
	 * mapping; SynchroReplica is the staged data itself; SynchroLog records a
	 * run. A datamodel is one <parent> away from adding another.
	 *
	 * Reading is not refused, exactly as above: a caller that can report "this
	 * source last ran on Tuesday and touched 40 rows" is useful, and the
	 * refusal is about what executes, not about what is visible.
	 */
	private const DELEGATING_ROOTS = [
		'SynchroDataSource',
		'SynchroAttribute',
		'SynchroReplica',
		'SynchroLog',
	];

	/**
	 * The external key every dependent row of the family points back with.
	 *
	 * This is the datamodel half, and it replaces the name prefix that stood
	 * here first. A prefix is the wrong instrument twice over: it refuses a
	 * customer class called SynchroWidget that stages nothing, and it misses a
	 * class that stages something without being named for it. What actually
	 * makes a row part of the family is that it hangs off a data source - the
	 * attribute mappings, the staged replicas, the run logs all do - and that
	 * is a question the datamodel answers about the class in front of it.
	 */
	private const SOURCE_KEY_TARGET = 'SynchroDataSource';

	/**
	 * The classes that make iTop act outside this endpoint on its own.
	 *
	 * A third family, and the one that needs the least grading, because there
	 * is nothing to grade it against.
	 *
	 * A synchronisation definition delegates a capability this endpoint does
	 * grant - writing objects of a class - so it can be graded: may this
	 * caller write that class itself? A trigger with an action behind it
	 * delegates capabilities this endpoint grants **nobody**. No tool here
	 * sends mail. No tool here makes an outbound HTTP request. No tool here
	 * calls a static method by name. So there is no answer to "could the
	 * caller have done this itself" other than no, for every caller, always -
	 * and a rule whose answer never varies is a refusal.
	 *
	 * What one burst of write access buys without this: a
	 * RemoteApplicationConnection whose url is a plain text attribute with no
	 * scheme or host validation - an attacker's collector, or an internal
	 * address the web server can reach and the caller cannot - an
	 * ActioniTopWebhook pointed at it carrying whatever payload it likes, and
	 * a Trigger linked to that action by lnkTriggerAction, firing on every
	 * matching change made by anybody, for as long as nobody notices. The
	 * session ends; the channel does not. That is the part that makes this
	 * worth a barrier rather than a warning: nothing else on this endpoint
	 * outlives the call that made it.
	 *
	 * ActioniTopWebhook's prepare_payload_callback and
	 * process_response_callback take a Class::method string and invoke it as a
	 * public static callback. That is not code injection - the method has to
	 * exist already - but it is "call any loaded public static method by name",
	 * and what is loaded depends on which extensions an instance has.
	 *
	 * Trigger and Action are iTop root classes, matched by descent - and
	 * ActionWebhook is listed beside them for a reason worth writing down,
	 * because it is not obvious and it was checked rather than assumed. In
	 * combodo-webhook-integration's datamodel, ActionWebhook declares
	 * <parent>cmdbAbstractObject</parent>: in iTop's own class hierarchy it is
	 * not an Action at all. What makes is_a() answer yes is its <php_parent>,
	 * _ActionWebhook, which extends ActionNotification, which extends Action -
	 * and the compiler takes php_parent over parent when it emits the class
	 * (compiler.class.inc.php, GetUniqueElement('php_parent')). So the descent
	 * match works, through a declaration in a module this one does not own.
	 * Listing the name as well costs nothing and removes the dependence.
	 *
	 * RemoteApplicationConnection is a named floor and is load-bearing:
	 * verified to declare <parent>Typology</parent>, so no Action or Trigger
	 * descent reaches it. Its three subclasses - RemoteiTopConnection,
	 * RemoteiTopConnectionToken, RemoteOauthConnection - come with it by
	 * descent. Deriving it instead from "a class an Action points at" would
	 * refuse whatever else an action happens to reference, and a barrier that
	 * makes Contact read-only by inference is worse than one that misses a
	 * connection class a future branch adds.
	 *
	 * Not listed, and a deliberate gap: Oauth2Client, which
	 * RemoteOauthConnection points at and which holds an outbound client
	 * secret. Outbound secrets are stored in the recoverable attribute types
	 * this module deliberately does not treat as credentials - see
	 * CREDENTIAL_ATTRIBUTE - and widening that here would be a different
	 * restriction wearing this one's name.
	 */
	private const AUTOMATION_ROOTS = [
		'Trigger',
		'Action',
		'ActionWebhook',
		'RemoteApplicationConnection',
		// iTop's own deferred-work queue, and the reason it belongs here
		// rather than beside the synchronisation family: AsyncSendEmail
		// extends it and *is* the outbound mail queue the cron drains, with
		// free-text to, subject and message and a status of 'planned'. One
		// create puts a real email into it, sent from the instance's own
		// configured identity to any address - no trigger, no action, no
		// connection. Gated at AsyncTask rather than at AsyncSendEmail
		// because the queue is the primitive: anything else that lands in it
		// is executed the same way, by the same cron, outside this endpoint.
		'AsyncTask',
		// The credentials the instance acts outward with. Oauth2Client and
		// its five subclasses carry client_secret, refresh_token and
		// access_token as AttributeEncryptedPassword - recoverable by
		// design, because an outbound secret has to be replayable to be
		// used, which is exactly what makes writing one worth refusing.
		// OAuthClient is the mailbox side of the same idea. Left out of the
		// barrier once before, on the reasoning at CREDENTIAL_ATTRIBUTE that
		// a recoverable secret is the object's own data; that reasoning
		// covers a device password and does not cover a token this instance
		// authenticates to a third party with, which a caller can steal by
		// reading it back out through a webhook or replace with its own.
	];

	/**
	 * The subset of the above that stores a secret rather than performing an
	 * action, kept apart because the read side needs to name it.
	 *
	 * Gated with the automation family - they are the credentials it acts
	 * with - but also asked about by {@see ObjectSerializer::IsSensitive()},
	 * which is a question about reading and not about writing.
	 */
	private const OUTBOUND_CREDENTIAL_ROOTS = [
		'Oauth2Client',
		'OAuthClient',
	];

	/**
	 * The attributes on those classes that hold the secret itself.
	 *
	 * A list, and it is a list on purpose. iTop's two OAuth datamodels store
	 * the same four things in four different types: Oauth2Client puts
	 * client_secret, refresh_token and access_token in
	 * AttributeEncryptedPassword, which implements iAttributeNoGroupBy and is
	 * therefore already masked - while OAuthClient puts client_secret in
	 * AttributePassword (masked) but refresh_token and token in **AttributeText**,
	 * which is not sensitive by type and came back in clear. The type is the
	 * rule everywhere else on this endpoint and stays the rule; this covers
	 * the case where iTop's own datamodel does not use one.
	 *
	 * Matched exactly rather than by substring, so that
	 * refresh_token_expiration stays readable: "this token expires on Friday"
	 * is the kind of thing an assistant is useful for, and masking a date
	 * would be masking the answer rather than the secret.
	 */
	private const OUTBOUND_SECRET_ATTRIBUTES = [
		'client_secret',
		'refresh_token',
		'access_token',
		'token',
	];

	/**
	 * The rules that decide what a person is shown as wrong.
	 *
	 * iTop's data-quality audit - AuditRule, AuditCategory, AuditDomain, all
	 * of them plain cmdbAbstractObject children with no common root, so this
	 * is a list and there is no datamodel question that would derive it.
	 *
	 * Not an escalation: nothing here grants access to anything. It is the
	 * other half of covering your tracks. An agent that has made a mess of the
	 * CMDB can also delete the rule that would have put that mess on somebody's
	 * dashboard, and the mess then looks like the data.
	 *
	 * Behind the same setting as the automation family rather than refused
	 * outright, because managing data-quality rules is ordinary work an
	 * operator may reasonably delegate - unlike the record of what already
	 * happened, which is below and has no switch.
	 */
	/**
	 * The attributes an email action names its recipients with.
	 *
	 * Each is an AttributeOQL, and what the mailer does with it is worth
	 * stating exactly, because the grade below follows from it and not from
	 * the field's name. ActionEmail::FindRecipients() takes the raw OQL,
	 * builds a DBObjectSearch from it, calls **AllowAllData()** on that search
	 * - deliberately, so a notification reaches people the acting user cannot
	 * see - then walks the class for its *first* AttributeEmailAddress and
	 * collects that attribute's value from every matching row.
	 *
	 * So one field is an arbitrary query over an arbitrary class with the
	 * rights and the silo switched off, returning one column. `SELECT Person`
	 * is every contact's address in the CMDB. The body is not the same
	 * problem: it goes through MetaModel::ApplyParams() against the trigger's
	 * context, so it reaches the object that fired and the acting contact,
	 * which grading the trigger's target_class already covers.
	 *
	 * What that makes the right grade: read on the class the query selects,
	 * and read on the one attribute it would extract. Not every attribute -
	 * only one is ever read out, and refusing on the rest would be refusing
	 * something this does not do.
	 */
	private const RECIPIENT_ATTRIBUTES = ['to', 'cc', 'bcc'];

	/** The OQL a category is defined by, and the key a rule names its category with. */
	private const AUDIT_QUERY_ATTRIBUTE = 'definition_set';

	private const AUDIT_CATEGORY_ATTRIBUTE = 'category_id';

	private const DETECTION_ROOTS = [
		'AuditRule',
		'AuditCategory',
		'AuditDomain',
	];

	/**
	 * The record of what happened, which the thing being recorded may not
	 * edit.
	 *
	 * This module writes an AltiooEventMCPService row per inbound request -
	 * method, tool, status, error - and then left that class writable by the
	 * very session it was recording. A caller could delete the evidence of
	 * what it had just done, and then the row recording that deletion, in
	 * reverse order, leaving no MCP-side trace at all. Every other control on
	 * this endpoint rests on "we can review what the agent did"; this is the
	 * one whose absence makes that sentence false.
	 *
	 * Found by a red-team pass. It was never evaluated and judged safe - it
	 * was added as an audit feature and never cross-checked against the
	 * barrier protecting everything else, which is the general failure this
	 * whole set of findings describes.
	 *
	 * The root is Event, not the class this module declares: AltiooEventMCPService
	 * declares <parent>Event</parent>, and so do iTop's own EventNotification,
	 * EventIssue, EventWebService, EventRestService and EventLoginUsage - all
	 * of them records of something that happened, none of them things a caller
	 * should be rewriting. Gating the parent covers this module's class, iTop's,
	 * and whatever a pack adds, without any of them being listed.
	 *
	 * iTop's change log is the same idea and is refused a layer earlier, by
	 * ObjectHistory: CMDBChange and CMDBChangeOp are not merely unwritable but
	 * unreadable through the object tools, because core_object_history serves
	 * them instead. Here, reading is deliberately open - an assistant that can
	 * answer "what did I call, and what failed" is useful, and reading a record
	 * does not alter it.
	 *
	 * **No setting lifts this one.** An audit trail the audited party can edit
	 * with the operator's permission is an audit trail the audited party can
	 * edit.
	 */
	private const RECORDING_ROOTS = [
		'Event',
		// Not an Event, and that is the point of listing it. The webhook
		// module declares <parent>DBObject</parent> for EventWebhook while
		// _ActionWebhook writes one per call - url, headers, payload and the
		// response body - so it is a record of something that happened by
		// every test except the one the Event root applies. A class named
		// Event* that is not an Event is exactly the case a root match
		// silently misses, which is why this was found by a reviewer and not
		// by the rule.
		'EventWebhook',
	];

	/**
	 * The classes whose rows belong to one person.
	 *
	 * The mirror of the self guard, and the only place on this endpoint where
	 * the refusal is for *somebody else's* row rather than for your own.
	 * appUserPreferences carries a userid and iTop's own API for it -
	 * appUserPreferences::GetPref()/SetPref() - only ever reads and writes the
	 * current user's; the console offers no way to edit another person's. The
	 * object tools do, because a preference row is an ordinary DBObject with an
	 * ordinary id, and UserRights has nothing to say about it.
	 *
	 * Small on its own: what somebody's console shows by default. Not nothing,
	 * though, since one of those preferences is whether obsolete objects are
	 * visible - so rewriting an administrator's row changes what they see
	 * without changing anything they would look at to find out why.
	 */
	private const PERSONAL_ROOTS = [
		'appUserPreferences',
		// An edit lock, and forging one has no use this endpoint serves. A row
		// names an arbitrary obj_class and obj_key and attributes the lock to
		// an arbitrary user_id, so writing one freely is claiming "somebody
		// else is editing this" about any object, in anybody's name - or
		// clearing a lock a person is actually relying on. No tool here takes
		// a lock or needs one; the console's concurrent-edit flow does.
		//
		// Scoped to the caller rather than hard-blocked, which is the lighter
		// of the two the review offered: a row of your own is harmless and a
		// row in someone else's name is the whole abuse, so the rule that
		// already says "your own only" is the one that fits.
		'iTopOwnershipToken',
	];

	/**
	 * Whose row it is, on the classes above.
	 *
	 * Two spellings because iTop uses two: appUserPreferences declares
	 * `userid` and iTopOwnershipToken declares `user_id`. Tried in order, and
	 * the first the class actually declares is the one that decides.
	 */
	private const OWNER_ATTRIBUTES = ['userid', 'user_id'];

	/**
	 * The attribute a data source names its target class in, and the one its
	 * dependent rows name their data source in.
	 *
	 * Asked of the datamodel before they are read, never assumed: a branch
	 * that renames either costs the scope_class half of the rule and nothing
	 * else, because the outright refusal below it does not depend on them.
	 */
	private const SCOPE_ATTRIBUTE = 'scope_class';

	/**
	 * The attribute a trigger names the class it watches in.
	 *
	 * AttributeClass, and its more_values explicitly adds User, UserExternal,
	 * UserInternal, UserLDAP and UserLocal to the bizmodel category - so a
	 * trigger can be pointed at the account classes by design.
	 */
	private const TARGET_ATTRIBUTE = 'target_class';

	private const SOURCE_ATTRIBUTE = 'sync_source_id';

	/**
	 * What every write tool says when the instance refuses these classes
	 * outright, spelled once so they all say the same thing and all name the
	 * setting that decides it - a model told only "no" retries a variation,
	 * and a model told which switch is off reports it.
	 */
	public const GRANT_REFUSAL = 'Class \'%s\' decides who may reach this endpoint and what they may do with it, so it cannot be written here unless mcp_allow_access_administration is on. Change it in the iTop console.';

	/**
	 * What they say when the instance allows these classes and the call
	 * reaches the caller's own access anyway.
	 *
	 * A different sentence from the one above on purpose: the first is an
	 * instance that has not opted in and can, the second is a rule no
	 * configuration lifts. Telling them apart is what stops a model - or the
	 * person reading over its shoulder - from asking an operator to turn on a
	 * setting that would not have helped.
	 */
	public const SELF_REFUSAL = 'Class \'%s\' can be administered here, but not for yourself: this call reaches the access you are connected with. Change your own token, account or profiles in the iTop console.';

	/**
	 * What every write tool says when the instance refuses a synchronisation
	 * definition.
	 *
	 * Names what is actually being refused - a write performed later by
	 * something else - rather than "access denied", because a model told the
	 * latter tries a neighbouring class and a model told the former reports
	 * it.
	 */
	public const DELEGATION_REFUSAL = 'Class \'%s\' defines work that iTop\'s synchronisation engine carries out later, and this call does not settle which class it would be carried out on. Name the target - scope_class on the data source, sync_source_id on a row that hangs off one - so the write can be graded against it.';

	/**
	 * What they say when the instance allows it and the definition targets a
	 * class this endpoint may not write anyway.
	 *
	 * The setting buys administration of other people's access through *this*
	 * endpoint, where every write is graded against the caller's own
	 * credential. A definition handed to the engine is graded against nothing,
	 * so the one rule no setting lifts - never your own access - would be
	 * lifted by staging it instead of performing it.
	 */
	public const DELEGATED_TARGET_REFUSAL = 'Class \'%s\' would be synchronised into \'%s\', which this endpoint does not write directly - so it does not write it through something else either. Whatever refuses \'%2$s\' to a tool refuses it here, and no setting changes that, because the engine performs the write without any of the checks a tool applies. Do it in the iTop console.';

	/**
	 * What they say when the definition targets an ordinary class the caller
	 * could not have written itself.
	 *
	 * The rule the whole barrier reduces to, once the escalation above is
	 * shut: staging a write must not be a way to perform one you were refused.
	 * The engine runs as a trusted internal process and consults no
	 * UserRights, so the rights are asked here, once, about the class the
	 * definition points at - and all five of them, because a data source can
	 * create, can modify, and depending on its own delete_policy can delete,
	 * in bulk.
	 */
	public const DELEGATED_RIGHTS_REFUSAL = 'Class \'%s\' would be synchronised into \'%s\', and the synchronisation engine writes without consulting anyone\'s rights - so a definition may only be written here for a class you could write yourself. %s Ask an administrator for the right on \'%s\', or define the source in the iTop console.';

	/**
	 * What every write tool says when the instance refuses a trigger, an
	 * action or the connection behind one.
	 *
	 * Names the capability rather than the class, because that is what an
	 * operator is being asked to decide about: not "may an assistant write
	 * Trigger rows" but "may an assistant leave standing instructions that
	 * make this instance call out on its own".
	 */
	public const AUTOMATION_REFUSAL = 'Class \'%s\' arranges something no tool here can do at all - sending mail, calling a URL, invoking a method by name, or authenticating outward as this instance - so it is not refused for want of a right you might be granted: no caller of this endpoint has one, administrators included. Writing it is refused unless mcp_allow_privilege_escalation is on, which is an operator allowing this endpoint to grant more than the credential it was called with, rather than a switch for managing automation. Do it in the iTop console. Reading is unaffected.';

	/**
	 * What every write tool says about the record of what happened.
	 *
	 * Names no setting, because there is not one. A caller told "unless X is
	 * on" goes and asks for X.
	 */
	public const RECORDING_REFUSAL = 'Class \'%s\' is a record of something that happened - this endpoint writes one for every request it serves - so it cannot be written or deleted here at all, by anyone, with no setting to change that. An audit trail the audited party can edit is not one. Reading is unaffected: ask for the rows rather than changing them.';

	/**
	 * What they say about the rules that decide what gets flagged.
	 */
	/**
	 * What they say about editing a check that is watching the caller.
	 */
	/** What they say when a recipient query reaches something the caller may not read. */
	public const RECIPIENT_REFUSAL = 'The \'%2$s\' of this \'%1$s\' is a query over \'%3$s\', and the mailer runs it with rights and silos switched off - so it would collect addresses from every matching row. Refused because %4$s. Narrow the query to a class you can read here, or set the recipients up in the iTop console.';

	public const DETECTION_TAMPER_REFUSAL = 'Class \'%s\' is a check that already exists, and a check is not something the account it watches gets to edit here: turning one off, making the change it would have flagged and turning it back on leaves nothing for anyone to notice. Creating a new one is allowed; changing or deleting this one is not, whatever mcp_allow_privilege_escalation says. Use the iTop console.';

	public const DETECTION_REFUSAL = 'Class \'%s\' is part of iTop\'s data-quality audit, which decides what gets flagged to a person as wrong - not something this endpoint changes on its own initiative. It is refused unless mcp_allow_privilege_escalation is on, which is an operator allowing this endpoint to grant more than the credential it was called with rather than a switch for managing automation. Change it in the iTop console. Reading is unaffected.';

	/**
	 * What they say when a write reaches somebody else's personal row.
	 *
	 * The mirror of SELF_REFUSAL and deliberately its own sentence: that one
	 * refuses your own access, this one refuses everyone else's preferences.
	 */
	public const NOT_YOURS_REFUSAL = 'Class \'%s\' holds one person\'s own settings, and this row is not yours. iTop\'s own API for it only ever reads and writes the account it is called by, and so does this endpoint unless mcp_allow_access_administration is on. Act on your own row, ask an operator to turn that setting on, or use the iTop console.';

	/**
	 * The category iTop files its rights model under.
	 *
	 * Every URP_ class carries it, in the XML for the three the core declares
	 * and in Init_Params for the ones the addon declares in PHP. Read through
	 * MetaModel::GetClasses(), which answers an unknown category with an empty
	 * list rather than an error - so a category renamed in a later version
	 * costs the dynamic half of the URP_ match and nothing else, the names and
	 * the prefix still standing.
	 */
	private const RIGHTS_CATEGORY = 'addon/userrights';

	/**
	 * The attribute type iTop verifies a credential with, and the sign that a
	 * class authenticates somebody.
	 *
	 * The distinction that matters is one-way, not "password". This type
	 * stores a salted hash and nothing else - two columns, _hash and _salt -
	 * so the secret cannot be read back out of it. A mailbox password, an
	 * OAuth client secret, a device login: every outbound credential has to be
	 * recoverable to be used, so none of them can be kept in this type, and
	 * iTop's own are not. The OAuth client's secret and the webhook's
	 * auth_pwd are AttributePassword; there is a whole module for
	 * AttributeEncryptedPassword. What is left, once a value can only ever be
	 * compared against and never replayed, is something iTop checks a caller
	 * against - which is the definition of the thing this barrier is for.
	 *
	 * The census agrees, for what it is worth: five classes in 3.2 declare
	 * one - UserInternal's password, UserLocal's, PersonalToken's and
	 * UserToken's auth_token, and the change log's record of a previous
	 * password, which ObjectHistory reserves already - and no CMDB class does.
	 * But the argument is the storage, not the count, because the count is
	 * what an extension can change.
	 *
	 * So the recoverable types are deliberately not matched. A datamodel
	 * extension may legitimately keep a mailbox or device password on an
	 * object, and refusing to write those would be an unrelated restriction
	 * wearing this one's name. iTop's own addon/authentication category is
	 * left alone for the same reason: SynchroDataSource carries it, and a
	 * synchro source is not a credential.
	 *
	 * An extension that stored a mailbox password in this type anyway would be
	 * storing one it can never use - and the cost here is that its class
	 * becomes read-only through this endpoint, which is the direction a
	 * barrier should fail in.
	 */
	private const CREDENTIAL_ATTRIBUTE = 'AttributeOneWayPassword';

	/** Answers already worked out, keyed by lowercase class name. */
	private static array $aDecided = [];

	/**
	 * Whether $sClass is one of them.
	 *
	 * Case-insensitive on the exact name for the same reason the rest of the
	 * surface is: iTop resolves class names case-insensitively in OQL, so a
	 * barrier that did not would be one lowercase letter away from open.
	 *
	 * @since 1.0.0
	 */
	public static function IsGranting(string $sClass): bool
	{
		$sKey = strtolower($sClass);
		if (array_key_exists($sKey, self::$aDecided)) {
			return self::$aDecided[$sKey];
		}

		return self::$aDecided[$sKey] = self::IsNamedGranting($sClass) || self::IsDeclaredGranting($sClass);
	}

	/**
	 * Whether $sClass stages work for the synchronisation engine.
	 *
	 * Names, descendants and prefix, with no datamodel half: the question is
	 * not what the class holds but what iTop does with it, and that is not
	 * something an attribute definition says. Cached with the rest, and
	 * case-insensitive for the reason {@see IsGranting()} gives.
	 *
	 * @since 1.0.0
	 */
	public static function IsDelegating(string $sClass): bool
	{
		$sKey = 'delegating:'.strtolower($sClass);
		if (array_key_exists($sKey, self::$aDecided)) {
			return self::$aDecided[$sKey];
		}

		foreach (self::DELEGATING_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return self::$aDecided[$sKey] = true;
			}
		}

		return self::$aDecided[$sKey] = self::PointsAtAnyOf($sClass, [self::SOURCE_KEY_TARGET]);
	}

	/**
	 * Whether this class carries an external key to any of $aTargets, or to
	 * something descending from one.
	 *
	 * The datamodel half of two families, asked of the class rather than of
	 * its spelling. A row pointing at a SynchroDataSource is part of a
	 * definition the engine will execute; a row pointing at a Trigger or an
	 * Action is part of wiring one to the other. Both are things a named list
	 * gets wrong in the same two directions - it refuses a customer class that
	 * happens to be spelled like one, and misses the one that is not.
	 *
	 * Never raises, and answers false for everything when there is no
	 * MetaModel - the named floors are what hold then, exactly as they do for
	 * the granting family.
	 *
	 * @param array<int, string> $aTargets
	 */
	private static function PointsAtAnyOf(string $sClass, array $aTargets): bool
	{
		if (!class_exists('MetaModel')) {
			return false;
		}

		try {
			if (!MetaModel::IsValidClass($sClass)) {
				return false;
			}

			foreach (MetaModel::ListAttributeDefs($sClass) as $oAttDef) {
				if (!$oAttDef->IsExternalKey()) {
					continue;
				}

				$sPointsAt = (string) $oAttDef->GetTargetClass();
				if ($sPointsAt === '') {
					continue;
				}

				foreach ($aTargets as $sTarget) {
					if (strcasecmp($sPointsAt, $sTarget) === 0 || is_a($sPointsAt, $sTarget, true)) {
						return true;
					}
				}
			}
		} catch (Throwable) {
			return false;
		}

		return false;
	}

	/**
	 * Whether $sClass is a standing instruction that makes iTop act by itself.
	 *
	 * Roots by descent - Trigger and Action are iTop's own, so every kind of
	 * each is covered - plus the datamodel question that catches the link
	 * between them: a class carrying an external key to a Trigger or an Action
	 * is part of wiring one to the other, which is what lnkTriggerAction is
	 * and what a pack's own link class would be.
	 *
	 * @since 1.0.0
	 */
	public static function IsAutomation(string $sClass): bool
	{
		$sKey = 'automation:'.strtolower($sClass);
		if (array_key_exists($sKey, self::$aDecided)) {
			return self::$aDecided[$sKey];
		}

		foreach (array_merge(self::AUTOMATION_ROOTS, self::OUTBOUND_CREDENTIAL_ROOTS) as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return self::$aDecided[$sKey] = true;
			}
		}

		return self::$aDecided[$sKey] = self::PointsAtAnyOf($sClass, ['Trigger', 'Action']);
	}

	/**
	 * Whether reading $sClass::$sAttCode would report a secret this instance
	 * authenticates to somebody else with.
	 *
	 * The read-side half of the outbound-credential rule. Everywhere else the
	 * type decides - see {@see ObjectSerializer::IsSensitive()} - and this
	 * covers the classes where iTop's own datamodel keeps a live token in a
	 * type that says nothing about it.
	 *
	 * @since 1.0.0
	 */
	public static function IsOutboundSecret(string $sClass, string $sAttCode): bool
	{
		if (!in_array(strtolower($sAttCode), self::OUTBOUND_SECRET_ATTRIBUTES, true)) {
			return false;
		}

		foreach (self::OUTBOUND_CREDENTIAL_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $sClass records something that happened.
	 *
	 * @since 1.0.0
	 */
	public static function IsRecording(string $sClass): bool
	{
		foreach (self::RECORDING_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $sClass decides what a person is shown as wrong.
	 *
	 * @since 1.0.0
	 */
	public static function IsDetection(string $sClass): bool
	{
		foreach (self::DETECTION_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether $sClass holds rows that belong to one account.
	 *
	 * @since 1.0.0
	 */
	public static function IsPersonal(string $sClass): bool
	{
		foreach (self::PERSONAL_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether either barrier has something to say about $sClass.
	 *
	 * For the callers that only need to know whether to look - the schema's
	 * rights block, and the tests that walk the surface - rather than which of
	 * the two it is.
	 *
	 * @since 1.0.0
	 */
	public static function IsBarred(string $sClass): bool
	{
		return self::IsGranting($sClass)
			|| self::IsDelegating($sClass)
			|| self::IsAutomation($sClass)
			|| self::IsRecording($sClass)
			|| self::IsDetection($sClass)
			|| self::IsPersonal($sClass);
	}

	/** The floor: the classes above, their descendants, and the prefix. */
	private static function IsNamedGranting(string $sClass): bool
	{
		if (stripos($sClass, self::GRANTING_PREFIX) === 0) {
			return true;
		}

		foreach (self::GRANTING_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What this instance's datamodel says, which is how a class nobody here
	 * has heard of is refused.
	 *
	 * Never raises: it is asked on the way into every write, about ordinary
	 * classes, and the answer for almost all of them is no. A datamodel that
	 * cannot be read is a no here and a line in the log from the reader
	 * itself, which is the right place for it - the barrier is not the thing
	 * that went wrong.
	 */
	private static function IsDeclaredGranting(string $sClass): bool
	{
		if (!class_exists('MetaModel')) {
			return false;
		}

		if (TokenScopes::GradesThisEndpoint($sClass)) {
			return true;
		}

		try {
			foreach (MetaModel::GetClasses(self::RIGHTS_CATEGORY) as $sRightsClass) {
				if (strcasecmp($sClass, $sRightsClass) === 0 || is_a($sClass, $sRightsClass, true)) {
					return true;
				}
			}

			if (!MetaModel::IsValidClass($sClass)) {
				return false;
			}

			foreach (MetaModel::ListAttributeDefs($sClass) as $oAttDef) {
				if (is_a($oAttDef, self::CREDENTIAL_ATTRIBUTE)) {
					return true;
				}
			}
		} catch (Throwable) {
			return false;
		}

		return false;
	}

	/**
	 * Why this write is refused, or null when it may go ahead.
	 *
	 * Three answers, not two. A class that grants nothing is nobody's business
	 * here and passes straight through. A granting class is refused outright
	 * unless the instance opted in, which is the default. And where the
	 * instance did opt in, one thing is still refused: a call that reaches the
	 * caller's own access.
	 *
	 * That last rule is the whole reason the setting is safe to offer. Without
	 * it, opting in would hand back exactly the escalation the barrier exists
	 * to stop - widen the scope of the token in your hand, or mint a second
	 * one that is wider, and the narrow credential an operator issued was
	 * never narrow. With it, the setting buys administration of *other*
	 * people's access and nothing whatever about your own, which is the shape
	 * an operator actually wants when they turn it on: onboard a user, retire
	 * somebody's leaked token, never promote yourself.
	 *
	 * It is also the rule iTop already follows for the console - a user may
	 * not delete themselves, may not strip their own last profile, and may not
	 * demote themselves out of being able to come back - applied here to the
	 * credential rather than to the session, because the credential is what a
	 * caller of this endpoint holds.
	 *
	 * @param string             $sClass  The class being written.
	 * @param int|null           $iId     The row, where there is one; null on a create.
	 * @param array<string, mixed> $aFields Values being written, which on a create are all there is to go on.
	 *
	 * @since 1.0.0
	 */
	public static function RefusalFor(string $sClass, ?int $iId = null, array $aFields = []): ?string
	{
		return self::RefusalGiven(
			MCPHelper::AllowsAccessAdministration(),
			$sClass,
			$iId,
			$aFields,
			MCPHelper::AllowsPrivilegeEscalation()
		);
	}

	/**
	 * The same decision with the instance's answer handed in rather than read.
	 *
	 * Split for the reason AccessPolicy is a value object: the rule is worth
	 * testing without an iTop, a configuration or a request, and the half that
	 * needs all three is one line long and lives above.
	 *
	 * @param bool                 $bAdministrationAllowed What mcp_allow_access_administration says.
	 * @param array<string, mixed> $aFields
	 *
	 * @since 1.0.0
	 */
	public static function RefusalGiven(
		bool    $bAdministrationAllowed,
		string  $sClass,
		?int    $iId = null,
		array   $aFields = [],
		bool    $bEscalationAllowed = false,
	): ?string
	{
		// Somebody else's personal row, whatever else is true of the class.
		// Asked first because it is the only rule here that is about the row
		// alone, and because a class could in principle be both.
		//
		// Behind the access setting rather than refused outright, which it was
		// at first. The reasoning that changed it: unlike the audit trail,
		// which nobody administers an instance by editing, there is a real
		// full-admin use for this - a service desk resetting a colleague's
		// broken saved view, or the obsolete-data preference that is hiding
		// half their console from them - and no other way to reach it here.
		// Every other barrier on this endpoint is closed-by-default with an
		// opt-in; this one had no hatch at all, which made it the odd one out
		// rather than the strict one.
		//
		// The setting it sits behind is the access one, because what it
		// governs is another person's account-scoped data, and an operator who
		// has said "this endpoint may administer other people's accounts" has
		// answered this question too. Note the guard runs the opposite way
		// round from the token rule above: there, the setting opens everyone
		// else's rows and never your own; here it opens everyone else's rows,
		// and your own were never in question.
		if (self::IsPersonal($sClass)
			&& !$bAdministrationAllowed
			&& !self::IsTheCallersOwnRow($sClass, $iId, $aFields)) {
			return sprintf(self::NOT_YOURS_REFUSAL, $sClass);
		}

		// The record of what happened, before anything else and with no
		// setting consulted: every other rule here is worth having only while
		// somebody can still check whether it held.
		if (self::IsRecording($sClass)) {
			return sprintf(self::RECORDING_REFUSAL, $sClass);
		}

		// A standing instruction that makes the instance act by itself, or the
		// credentials it acts with. The effect it arranges - mail out, a URL
		// called, a static method invoked - is one no tool here performs at
		// all, so the rule below yields "refused" for every caller and the
		// setting is an operator overriding that, not a grade.
		if (self::IsAutomation($sClass)) {
			if (!$bEscalationAllowed) {
				return sprintf(self::AUTOMATION_REFUSAL, $sClass);
			}

			// Overridden, but only as far as the caller could reach itself. A
			// trigger names the class it watches, and firing on a class this
			// caller may not read is arranging for data out of it to be
			// carried off - the setting says an assistant may wire up
			// notifications, not that it may wire one up over records it is
			// not allowed to see. A class naming no target - an action, a
			// connection, a token store - has nothing to grade here and the
			// setting has already decided it.
			$sWatched = self::DelegatedTarget($sClass, $iId, $aFields);
			if ($sWatched !== null) {
				$sRefusal = self::CouldNotDoItDirectly($sClass, $sWatched, false);
				if ($sRefusal !== null) {
					return $sRefusal;
				}
			}

			// And the recipients, where the class names any. An email action's
			// to/cc/bcc are queries run with AllowAllData(), so each is a read
			// of a class this caller may or may not be allowed to read - see
			// RECIPIENT_ATTRIBUTES.
			return self::RecipientQueryRefusal($sClass, $iId, $aFields);
		}

		if (self::IsDetection($sClass)) {
			if (!$bEscalationAllowed) {
				return sprintf(self::DETECTION_REFUSAL, $sClass);
			}

			// Overridden, but a rule that already exists is a control on the
			// caller and not a thing the caller configures. Writing one that
			// watches a class you can change is the loop: turn the rule off,
			// make the mess it would have flagged, turn it back on. Nobody
			// sees either edit unless they were already watching the rule
			// itself, which is the thing that was supposed to do the watching.
			//
			// Creating one is not that - a new rule flags more, not less - so
			// only an existing row is refused.
			if ($iId !== null) {
				return self::RuleWatchesSomethingTheCallerCanChange($sClass, $iId, $aFields);
			}

			return null;
		}

		if (self::IsDelegating($sClass)) {
			// Graded on where the definition points, not on the fact that it is
			// one. A synchronisation source over a CMDB class is ordinary work,
			// and refusing all of it behind a setting called
			// mcp_allow_access_administration refused the ordinary case under a
			// name that does not describe it. What the engine actually removes
			// is the rights check, so the rights are what this asks about.
			$sTarget = self::DelegatedTarget($sClass, $iId, $aFields);
			if ($sTarget === null) {
				// Not established, which is not the same as harmless: the
				// target decides whether this is a source over the CMDB or a
				// staged administrator account, and a rule that shrugs where it
				// cannot tell is a rule that is answered by not telling it. The
				// self guard below fails the same way for the same reason.
				return sprintf(self::DELEGATION_REFUSAL, $sClass);
			}

			// The rule, stated once: whatever this would end up doing, the
			// caller has to be able to do it here, now, by calling a tool.
			// Anything this endpoint refuses directly it refuses through a
			// mechanism that would arrange it - including, first of all, a
			// target behind one of the barriers above, which no setting
			// reaches.
			return self::CouldNotDoItDirectly($sClass, $sTarget, true);
		}

		if (!self::IsGranting($sClass)) {
			return null;
		}

		if (!$bAdministrationAllowed) {
			return sprintf(self::GRANT_REFUSAL, $sClass);
		}

		if (self::ReachesTheCaller($sClass, $iId, $aFields)) {
			return sprintf(self::SELF_REFUSAL, $sClass);
		}

		return null;
	}

	/**
	 * The class a synchronisation definition would write into, or null when
	 * this row does not settle one.
	 *
	 * Three shapes to cover. A data source names its target in scope_class,
	 * and on an update the caller may be changing it or may be leaving it
	 * alone - so the values being written are asked first and the stored row
	 * second, the same order {@see ReachesTheCaller()} uses and for the same
	 * reason. Everything else in the family - the attribute mappings, the
	 * staged replicas - hangs off a data source, so the question is passed up
	 * to it.
	 *
	 * Null means "this row does not say", not "nothing". The outright refusal
	 * above already covers the instance that never opted in; this only ever
	 * adds a refusal where the answer is known and is a granting class.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function DelegatedTarget(string $sClass, ?int $iId, array $aFields): ?string
	{
		// Asked of the values first, and without a datamodel: a caller naming
		// its own target has settled the question, and needing MetaModel to
		// read a key out of an array is how this rule came to not apply in the
		// one suite that runs without one.
		foreach ([self::SCOPE_ATTRIBUTE => null, self::TARGET_ATTRIBUTE => null, self::SOURCE_ATTRIBUTE => 'source'] as $sAttCode => $sKind) {
			if (!array_key_exists($sAttCode, $aFields)) {
				continue;
			}

			if ($sKind === null) {
				$sScope = trim((string) $aFields[$sAttCode]);

				return $sScope === '' ? null : $sScope;
			}

			return self::ScopeOfSource((int) $aFields[$sAttCode]);
		}

		if (!class_exists('MetaModel')) {
			return null;
		}

		try {
			if (!MetaModel::IsValidClass($sClass)) {
				return null;
			}

			$oRow = ($iId !== null && $iId > 0) ? MetaModel::GetObject($sClass, $iId, false, true) : null;
			if ($oRow === null) {
				return null;
			}

			if (MetaModel::IsValidAttCode($sClass, self::TARGET_ATTRIBUTE)) {
				$sTarget = trim((string) $oRow->Get(self::TARGET_ATTRIBUTE));

				return $sTarget === '' ? null : $sTarget;
			}

			if (MetaModel::IsValidAttCode($sClass, self::SCOPE_ATTRIBUTE)) {
				$sScope = trim((string) $oRow->Get(self::SCOPE_ATTRIBUTE));

				return $sScope === '' ? null : $sScope;
			}

			// A mapping or a replica: the target is whatever its data source
			// says, so ask that row the question instead.
			if (!MetaModel::IsValidAttCode($sClass, self::SOURCE_ATTRIBUTE)) {
				return null;
			}

			return self::ScopeOfSource((int) $oRow->Get(self::SOURCE_ATTRIBUTE));
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Whether any recipient query reaches something this caller may not read.
	 *
	 * Graded per field, on the values being written first and the stored row
	 * second - the same order the rest of this class uses, because changing
	 * one recipient field of an existing action is a write the stored row
	 * still describes the old way.
	 *
	 * A field that is empty, absent or unparseable yields nothing to grade:
	 * empty is no recipients, and OQL that does not parse is refused by
	 * CheckToWrite() before it ever runs. Only a query that resolves to a
	 * class is graded, and it is graded on the two things the mailer actually
	 * reads - the class, and the attribute it would take the address from.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function RecipientQueryRefusal(string $sClass, ?int $iId, array $aFields): ?string
	{
		if (!class_exists('MetaModel') || !class_exists('DBObjectSearch')) {
			return null;
		}

		try {
			if (!MetaModel::IsValidClass($sClass)) {
				return null;
			}

			$oRow = null;

			foreach (self::RECIPIENT_ATTRIBUTES as $sAttCode) {
				if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
					continue;
				}

				if (array_key_exists($sAttCode, $aFields)) {
					$sOql = trim((string) $aFields[$sAttCode]);
				} else {
					if ($oRow === null && $iId !== null && $iId > 0) {
						$oRow = MetaModel::GetObject($sClass, $iId, false, true);
					}
					$sOql = $oRow === null ? '' : trim((string) $oRow->Get($sAttCode));
				}

				if ($sOql === '') {
					continue;
				}

				$sSelected = trim((string) DBObjectSearch::FromOQL($sOql)->GetClass());
				if ($sSelected === '' || !MetaModel::IsValidClass($sSelected)) {
					continue;
				}

				$sRefusal = self::ReadRefusalForRecipients($sClass, $sAttCode, $sSelected);
				if ($sRefusal !== null) {
					return $sRefusal;
				}
			}
		} catch (Throwable) {
			// An unparseable query is CheckToWrite()'s refusal, not this one's,
			// and it never reaches the mailer.
			return null;
		}

		return null;
	}

	/**
	 * The read grade for one recipient query: the class, and the one address
	 * attribute the mailer would take.
	 */
	private static function ReadRefusalForRecipients(string $sClass, string $sAttCode, string $sSelected): ?string
	{
		if (self::IsBarred($sSelected)) {
			return sprintf(self::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'this endpoint does not read it');
		}

		if (!class_exists('UserRights') || !defined('UR_ACTION_READ') || !defined('UR_ACTION_BULK_READ')) {
			return sprintf(self::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'your rights on it could not be established here');
		}

		if (!UserRights::IsActionAllowed($sSelected, UR_ACTION_READ)
			|| !UserRights::IsActionAllowed($sSelected, UR_ACTION_BULK_READ)) {
			return sprintf(self::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'you may not read it here');
		}

		// The address attribute the mailer picks: the first AttributeEmailAddress
		// on the class, which is how FindRecipients() chooses it.
		$sEmailAttCode = null;
		foreach (MetaModel::ListAttributeDefs($sSelected) as $sCandidate => $oAttDef) {
			if ($oAttDef instanceof \AttributeEmailAddress) {
				$sEmailAttCode = $sCandidate;
				break;
			}
		}

		if ($sEmailAttCode === null) {
			// No address to take: the mailer reports this as a wrong target and
			// sends nothing, so there is nothing here to refuse.
			return null;
		}

		if (defined('UR_ALLOWED_NO')
			&& UserRights::IsActionAllowedOnAttribute($sSelected, $sEmailAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
			return sprintf(
				self::RECIPIENT_REFUSAL,
				$sClass,
				$sAttCode,
				$sSelected,
				"you may not read '{$sEmailAttCode}' on it here, and that is the attribute the address is taken from"
			);
		}

		return null;
	}

	/**
	 * Whether an existing audit rule watches a class this caller can change.
	 *
	 * The audited class is in the category's definition_set, an OQL: a rule
	 * points at its category, a category names the query. So the question is
	 * asked of whichever of the two is in front of it, and answered by the
	 * class that query selects.
	 *
	 * Refuses whenever it cannot establish the class, which on an update or a
	 * delete is the same stance every other rule here takes - and it means an
	 * AuditDomain, which groups categories rather than naming a query, is not
	 * editable through this endpoint at all. That is the direction to be wrong
	 * in: the cost is a refusal an administrator satisfies from the console,
	 * and the cost the other way is the edit-and-revert loop.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function RuleWatchesSomethingTheCallerCanChange(string $sClass, int $iId, array $aFields): ?string
	{
		$sRefusal = sprintf(self::DETECTION_TAMPER_REFUSAL, $sClass);

		if (!class_exists('MetaModel') || !class_exists('UserRights') || !class_exists('DBObjectSearch')) {
			return $sRefusal;
		}

		try {
			$sAudited = self::AuditedClass($sClass, $iId, $aFields);
			if ($sAudited === null) {
				return $sRefusal;
			}

			foreach (['UR_ACTION_MODIFY', 'UR_ACTION_DELETE', 'UR_ACTION_CREATE'] as $sConstant) {
				if (!defined($sConstant)) {
					return $sRefusal;
				}
				if (UserRights::IsActionAllowed($sAudited, constant($sConstant))) {
					return sprintf(self::DETECTION_TAMPER_REFUSAL, $sClass).sprintf(' It watches \'%s\', which you may change.', $sAudited);
				}
			}
		} catch (Throwable) {
			return $sRefusal;
		}

		return null;
	}

	/**
	 * The class an audit rule or category is written about.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function AuditedClass(string $sClass, int $iId, array $aFields): ?string
	{
		$sOql = null;

		if (array_key_exists(self::AUDIT_QUERY_ATTRIBUTE, $aFields)) {
			$sOql = trim((string) $aFields[self::AUDIT_QUERY_ATTRIBUTE]);
		}

		if ($sOql === null || $sOql === '') {
			$oRow = MetaModel::GetObject($sClass, $iId, false, true);
			if ($oRow === null) {
				return null;
			}

			if (MetaModel::IsValidAttCode($sClass, self::AUDIT_QUERY_ATTRIBUTE)) {
				$sOql = trim((string) $oRow->Get(self::AUDIT_QUERY_ATTRIBUTE));
			} elseif (MetaModel::IsValidAttCode($sClass, self::AUDIT_CATEGORY_ATTRIBUTE)) {
				$oCategory = MetaModel::GetObject('AuditCategory', (int) $oRow->Get(self::AUDIT_CATEGORY_ATTRIBUTE), false, true);
				$sOql = $oCategory === null ? '' : trim((string) $oCategory->Get(self::AUDIT_QUERY_ATTRIBUTE));
			}
		}

		if ($sOql === null || $sOql === '') {
			return null;
		}

		$sAudited = trim((string) DBObjectSearch::FromOQL($sOql)->GetClass());

		return $sAudited === '' ? null : $sAudited;
	}

	/**
	 * Whether a personal row is the caller's own.
	 *
	 * The mirror of {@see ReachesTheCaller()}, and it fails closed in the
	 * opposite direction for the same reason: there, an undecidable answer
	 * means "this is yours" and refuses; here it means "this is not yours" and
	 * refuses. Both land on a refusal, which is the only thing the two rules
	 * have to agree about.
	 *
	 * A write that names no owner is the caller's own. That is not a guess:
	 * iTop's own appUserPreferences::SetPref() writes the row of the account
	 * it is called by, and core_set_obsolete_data goes through exactly that -
	 * so a call with no id and no userid is the tool this module ships, not a
	 * caller reaching for somebody else.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function IsTheCallersOwnRow(string $sClass, ?int $iId, array $aFields): bool
	{
		$sOwnerAttCode = null;
		$bNamesAnOwner = false;
		foreach (self::OWNER_ATTRIBUTES as $sCandidate) {
			if (array_key_exists($sCandidate, $aFields)) {
				$sOwnerAttCode = $sCandidate;
				$bNamesAnOwner = true;
				break;
			}
		}

		if ($iId === null && !$bNamesAnOwner) {
			return true;
		}

		if (!class_exists('UserRights')) {
			return false;
		}

		try {
			$iCaller = (int) UserRights::GetUserId();
			if ($iCaller < 1) {
				return false;
			}

			// The values first, then the stored row: re-pointing your own
			// preference row at somebody else is a write the stored row still
			// describes as yours.
			if ($bNamesAnOwner) {
				return (int) $aFields[$sOwnerAttCode] === $iCaller;
			}

			if (!class_exists('MetaModel') || !MetaModel::IsValidClass($sClass)) {
				return false;
			}

			// Which spelling this class uses, asked of the datamodel rather
			// than guessed - a class declaring neither is one whose owner
			// cannot be established, which is a refusal.
			foreach (self::OWNER_ATTRIBUTES as $sCandidate) {
				if (MetaModel::IsValidAttCode($sClass, $sCandidate)) {
					$sOwnerAttCode = $sCandidate;
					break;
				}
			}

			if ($sOwnerAttCode === null) {
				return false;
			}

			$oRow = MetaModel::GetObject($sClass, (int) $iId, false, true);

			return $oRow !== null && (int) $oRow->Get($sOwnerAttCode) === $iCaller;
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * Whether the caller could have written the target class itself.
	 *
	 * This is what is left of the barrier once the escalation is shut, and it
	 * is the whole of it: the engine runs as a trusted internal process and
	 * consults no UserRights at all, so a definition is a write with the
	 * rights check removed. Removing a check the caller would have passed is
	 * a scheduling decision. Removing one it would have failed is the bypass.
	 *
	 * All five actions, because a data source does all of them. It creates the
	 * objects it finds, modifies the ones it matches, and - depending on the
	 * delete_policy it carries, which is an attribute the same caller writes -
	 * deletes the ones that have gone. In bulk, by construction. Asking only
	 * about the one the caller happens to be doing today would be asking about
	 * the wrong call.
	 *
	 * Refuses whenever it cannot ask. No UserRights, no constants, a class the
	 * rights model will not answer for: every one of those is a refusal, for
	 * the reason the self guard gives - the cost of being wrong this way is a
	 * refusal an administrator can satisfy, and the cost the other way is the
	 * bypass this exists to close.
	 *
	 * What it does not cover, and cannot: per-attribute rights. iTop fills a
	 * new source's mapping in itself, every attribute at update:true, without
	 * any call reaching this endpoint - so there is no write here to refuse.
	 * A caller who may modify a class but not one of its attributes can
	 * therefore have that attribute overwritten by the engine. The honest
	 * scope of this rule is the class, and {@see SECURITY.md} says so.
	 */
	private static function CouldNotDoItDirectly(string $sClass, string $sTarget, bool $bWouldWrite): ?string
	{
		// First, the barriers themselves. A mechanism pointed at a class this
		// endpoint will not write is the plainest form of the thing being
		// refused: the audit trail, the rights model, another mechanism. Asked
		// as IsBarred() rather than IsGranting() so that every rule here covers
		// its own indirect route without being listed again - a source pointed
		// at Event, or at AsyncTask, is refused by the rule that refuses Event
		// and AsyncTask.
		if (self::IsBarred($sTarget)) {
			return sprintf(self::DELEGATED_TARGET_REFUSAL, $sClass, $sTarget);
		}

		if (!class_exists('UserRights') || !class_exists('MetaModel')) {
			return sprintf(self::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
		}

		try {
			// Maintenance mode, named rather than described. access_mode
			// withholds writing from everyone (ACCESS_READONLY) or from
			// everyone but administrators (ACCESS_ADMIN_WRITE), and
			// UserRights::IsActionAllowed() answers UR_ALLOWED_NO for every
			// writing action while it holds. Without this the refusal below
			// would enumerate create, modify and delete as though the caller's
			// profile were the problem, and send an operator to grant rights
			// that would change nothing.
			//
			// It matters here specifically because this barrier runs before a
			// tool reaches its own read-only check - the tools ask this first,
			// so on a mechanism class this is the message the caller gets.
			if ($bWouldWrite && MetaModel::DBIsReadOnly()) {
				return sprintf(
					self::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'This instance is in read-only mode (access_mode), so nothing may be written here at all just now - which has nothing to do with your rights on it.',
					$sTarget
				);
			}

			if (!MetaModel::IsValidClass($sTarget)) {
				return sprintf(self::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, "'{$sTarget}' is not a class this instance knows.", $sTarget);
			}

			// Reading first, and for every mechanism, because every one of them
			// is a way to get data out. A trigger hands the object it fired on
			// to an action, and ActionEmail's body takes $this->attribute$
			// placeholders; a data source mirrors the rows it matches into its
			// replicas. So a class this caller may not read is a class it may
			// not arrange to have read out on its behalf - the same sentence as
			// the write rule, and the half that keeps a mechanism from becoming
			// an export of what the read tools would have masked or refused.
			$aActions = [
				'read'         => 'UR_ACTION_READ',
				'read in bulk' => 'UR_ACTION_BULK_READ',
			];

			// And the writing actions, only for a mechanism that writes: a
			// data source creates what it finds, modifies what it matches and,
			// under its own delete_policy, deletes what has gone, in bulk by
			// construction. A trigger writes nothing of its own, so requiring
			// write on the class it watches would refuse an ordinary
			// notification for no reason the rule gives.
			if ($bWouldWrite) {
				$aActions += [
					'create'         => 'UR_ACTION_CREATE',
					'modify'         => 'UR_ACTION_MODIFY',
					'delete'         => 'UR_ACTION_DELETE',
					'modify in bulk' => 'UR_ACTION_BULK_MODIFY',
					'delete in bulk' => 'UR_ACTION_BULK_DELETE',
				];
			}

			$aRefused = [];
			foreach ($aActions as $sWhat => $sConstant) {
				if (!defined($sConstant)) {
					return sprintf(self::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
				}
				if (!UserRights::IsActionAllowed($sTarget, constant($sConstant))) {
					$aRefused[] = $sWhat;
				}
			}

			if ($aRefused !== []) {
				return sprintf(
					self::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'You may not '.implode(', ', $aRefused)." objects of '{$sTarget}' through this endpoint, and the engine would.",
					$sTarget
				);
			}

			// And the attributes, which is where the rule bites hardest and
			// where it was missing. A data source is not a write of the fields
			// somebody chose: SynchroDataSource fills its own mapping in on
			// creation, one SynchroAttribute per attribute of the class, and
			// the attribute that decides whether the engine writes each one -
			// `update` - is declared with default_value true and Set(1) on
			// every row it builds (synchrodatasource.class.inc.php). Creating
			// the source therefore hands the engine every attribute of the
			// class, so the caller has to hold every attribute of the class - a
			// per-attribute grant it does not hold is one this would have got
			// round.
			//
			// Keyed on `update` and deliberately not on `update_policy`, which
			// is a three-valued enum - master_locked, master_unlocked,
			// write_if_empty - defaulting to the first. It decides what happens
			// to the attribute around the write (whether the console may still
			// edit it, whether the engine only fills a blank), not whether
			// there is one. All three write, so none of them is the question
			// this rule is asking.
			$aUnreadable = self::AttributesTheCallerMayNot($sTarget, ['UR_ACTION_READ']);
			if ($aUnreadable !== []) {
				return sprintf(
					self::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'A read here masks '.self::few($aUnreadable).", and nothing masks them once this carries the object out.",
					$sTarget
				);
			}

			if ($bWouldWrite) {
				// Both actions, because the engine does both. UR_ACTION_CREATE
				// and UR_ACTION_MODIFY are separate codes in iTop (7 and 2) and
				// an addon is free to grade them differently, so an attribute
				// the caller may not set when the object is first written is
				// one the engine would set for it - CreateObjectFromReplica()
				// is a create, and asking only about modify graded the wrong
				// half of what a data source does.
				$aUnwritable = self::AttributesTheCallerMayNot($sTarget, ['UR_ACTION_MODIFY', 'UR_ACTION_CREATE']);
				if ($aUnwritable !== []) {
					return sprintf(
						self::DELEGATED_RIGHTS_REFUSAL,
						$sClass,
						$sTarget,
						'A source fills its own mapping in with every attribute of the class, and it both creates and updates - you may not set '.self::few($aUnwritable).' here.',
						$sTarget
					);
				}
			}
		} catch (Throwable) {
			return sprintf(self::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
		}

		return null;
	}

	/**
	 * The writable attributes of $sClass this caller is refused.
	 *
	 * Only the outright refusals count. IsActionAllowedOnAttribute() is
	 * tri-state and an addon that grades per object answers DEPENDS, which is
	 * a question about a row - and a data source has no row to ask about, so
	 * treating DEPENDS as a refusal would refuse every source on any instance
	 * whose addon grades that way. UR_ALLOWED_NO is the answer that does not
	 * depend on anything.
	 *
	 * @return array<int, string>
	 */
	private static function AttributesTheCallerMayNot(string $sClass, array $aActionConstants): array
	{
		if (!defined('UR_ALLOWED_NO')) {
			return [];
		}

		$aCodes = [];
		foreach ($aActionConstants as $sConstant) {
			if (!defined($sConstant)) {
				return [];
			}
			$aCodes[] = constant($sConstant);
		}

		// Skipped only where the datamodel says the attribute can never be
		// written by anyone - a computed value, an external field. That is a
		// property of the definition and holds on both paths. Per-state flags
		// are not asked, and cannot be: GetInitialStateAttributeFlags() and
		// GetAttributeFlags() answer about an object, and a definition staged
		// for later has no object to ask about.
		$bWriting = !in_array('UR_ACTION_READ', $aActionConstants, true);
		$aRefused = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if ($bWriting && !$oAttDef->IsWritable()) {
				continue;
			}
			foreach ($aCodes as $iAction) {
				if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, $iAction) === UR_ALLOWED_NO) {
					$aRefused[] = $sAttCode;
					continue 2;
				}
			}
		}

		sort($aRefused);

		return $aRefused;
	}

	/**
	 * A few of them by name, and a count for the rest.
	 *
	 * @param array<int, string> $aAttCodes
	 */
	private static function few(array $aAttCodes): string
	{
		$aShown = array_slice($aAttCodes, 0, 5);

		return implode(', ', $aShown)
			.(count($aAttCodes) > count($aShown) ? ' and '.(count($aAttCodes) - count($aShown)).' more' : '');
	}

	/**
	 * The class a data source is pointed at, read from the source itself.
	 *
	 * Null whenever it cannot be established, which the caller refuses on: an
	 * id that names no source, a datamodel that will not answer, a source with
	 * no scope set.
	 */
	private static function ScopeOfSource(int $iSourceId): ?string
	{
		if ($iSourceId < 1 || !class_exists('MetaModel')) {
			return null;
		}

		try {
			$oSource = MetaModel::GetObject('SynchroDataSource', $iSourceId, false, true);
			if ($oSource === null) {
				return null;
			}

			$sScope = trim((string) $oSource->Get(self::SCOPE_ATTRIBUTE));

			return $sScope === '' ? null : $sScope;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Whether this write reaches the access the caller is connected with.
	 *
	 * Asked of the row, not of the tool: the same escalation is a create, an
	 * update or a delete depending on which one is cheapest, and minting a
	 * second token is easier than editing the one in hand.
	 *
	 * Refuses whenever it cannot tell. No login, no MetaModel, an object that
	 * will not load, a create that names no owner - iTop's own controller
	 * fills the owner in with the current user, so a token created naming
	 * nobody is a token for the caller. Every one of those is answered "yes,
	 * this is yours", because the cost of being wrong the other way is the
	 * escalation this exists to prevent and the cost of being wrong this way
	 * is a refusal an administrator can satisfy from the console.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function ReachesTheCaller(string $sClass, ?int $iId, array $aFields): bool
	{
		if (!class_exists('MetaModel') || !class_exists('UserRights')) {
			return true;
		}

		try {
			$iCaller = (int)UserRights::GetUserId();
			if ($iCaller < 1) {
				return true;
			}

			// The account itself. A create is never the caller: nobody makes
			// the user they are already logged in as.
			if (self::Is($sClass, 'User')) {
				return $iId !== null && $iId === $iCaller;
			}

			if (!MetaModel::IsValidClass($sClass)) {
				return true;
			}

			$oObject = ($iId !== null && $iId > 0)
				? MetaModel::GetObject($sClass, $iId, false, true)
				: null;

			// Whose row is it. user_id on the token classes, userid on the
			// rights links; both are asked of the values being written first,
			// since re-pointing someone else's token at yourself is a write
			// that the stored row still describes as theirs.
			foreach (['user_id', 'userid'] as $sAttCode) {
				if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
					continue;
				}
				if (array_key_exists($sAttCode, $aFields)) {
					if ((int)$aFields[$sAttCode] === $iCaller) {
						return true;
					}
					continue;
				}
				if ($oObject === null) {
					return true; // a create naming no owner is a create for the caller
				}
				if ((int)$oObject->Get($sAttCode) === $iCaller) {
					return true;
				}
			}

			$sProfile = self::ProfileOf($sClass, $oObject, $aFields);

			return $sProfile !== null && in_array($sProfile, UserRights::ListProfiles(), true);
		} catch (Throwable) {
			return true;
		}
	}

	/**
	 * The profile a row decides something about, by name, or null when it
	 * decides nothing about any profile.
	 *
	 * Names rather than ids, because that is the currency
	 * UserRights::ListProfiles() answers in. URP_AttributeGrant is the one
	 * that needs a hop: it names an action grant and nothing else, and the
	 * profile is on the grant.
	 *
	 * @param array<string, mixed> $aFields
	 */
	private static function ProfileOf(string $sClass, ?DBObject $oObject, array $aFields): ?string
	{
		if (self::Is($sClass, 'URP_Profiles')) {
			if (array_key_exists('name', $aFields)) {
				return (string)$aFields['name'];
			}

			return $oObject !== null ? (string)$oObject->Get('name') : null;
		}

		if (MetaModel::IsValidAttCode($sClass, 'profileid')) {
			$iProfile = array_key_exists('profileid', $aFields)
				? (int)$aFields['profileid']
				: ($oObject !== null ? (int)$oObject->Get('profileid') : 0);

			return self::ProfileName($iProfile);
		}

		if (MetaModel::IsValidAttCode($sClass, 'actiongrantid')) {
			$iGrant = array_key_exists('actiongrantid', $aFields)
				? (int)$aFields['actiongrantid']
				: ($oObject !== null ? (int)$oObject->Get('actiongrantid') : 0);

			$oGrant = $iGrant > 0 ? MetaModel::GetObject('URP_ActionGrant', $iGrant, false, true) : null;

			return $oGrant !== null ? (string)$oGrant->Get('profile') : null;
		}

		return null;
	}

	/** One profile's name, read past the caller's silo because this is a gate. */
	private static function ProfileName(int $iProfile): ?string
	{
		if ($iProfile < 1) {
			return null;
		}

		$oProfile = MetaModel::GetObject('URP_Profiles', $iProfile, false, true);

		return $oProfile !== null ? (string)$oProfile->Get('name') : null;
	}

	/** $sClass is $sRoot or descends from it, spelled once. */
	private static function Is(string $sClass, string $sRoot): bool
	{
		return strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true);
	}

	/**
	 * Forgets what the datamodel said, for a test that changes it under us.
	 *
	 * @since 1.0.0
	 */
	public static function Forget(): void
	{
		self::$aDecided = [];
	}
}
