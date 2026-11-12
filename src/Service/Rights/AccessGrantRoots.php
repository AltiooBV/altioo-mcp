<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

/**
 * The data behind the barrier on classes that grant access: which classes it
 * names, and which attributes it asks about.
 *
 * Only lists and names live here. What is done with them - matching a class
 * against a family, grading a write - is {@see AccessGrantFamilies} and
 * {@see AccessGrants}, so a class added to a family is a change to this file
 * and to nothing that decides.
 *
 * Every class named below is matched with its descendants, through is_a()
 * rather than by name, for the reason {@see AccessGrants} gives at length.
 *
 * @internal
 * @since 2.0.0
 */
final class AccessGrantRoots
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
	public const GRANTING_ROOTS = [
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
	public const GRANTING_PREFIX = 'URP_';

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
	public const DELEGATING_ROOTS = [
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
	public const SOURCE_KEY_TARGET = 'SynchroDataSource';

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
	 * Oauth2Client and OAuthClient, which RemoteOauthConnection and the
	 * mailbox side point at, are listed below by name. A secret on any other
	 * class - one an extension adds - is not a reason to refuse the class:
	 * SecretPolicy refuses the attribute holding it, and leaves the rest of
	 * the object writable.
	 */
	public const AUTOMATION_ROOTS = [
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
	 *
	 * RemoteApplicationConnection is here for its read side alone - it is
	 * already in AUTOMATION_ROOTS, so the write barrier covered it before this
	 * line did. What the write barrier does not do is mask a read, and
	 * RemoteiTopConnectionToken (combodo-webhook-integration) keeps the token
	 * this instance authenticates to a remote iTop with in an **AttributeString**
	 * - the same shape of gap OAuthClient had, in a sibling hierarchy the
	 * OAuth-only list did not reach, so the value came back in clear through
	 * every read tool, the change history and the schema's isSensible flag. Its
	 * siblings carry no clear secret of their own - RemoteiTopConnection's is an
	 * AttributePassword, masked by type; RemoteOauthConnection's lives in the
	 * Oauth2Client it points at - so naming the floor costs nothing and covers
	 * whatever a future connection subclass adds. Found by a red-team read, not
	 * by this list having been derived.
	 */
	public const OUTBOUND_CREDENTIAL_ROOTS = [
		'Oauth2Client',
		'OAuthClient',
		'RemoteApplicationConnection',
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
	 * would be masking the answer rather than the secret. The exact match is
	 * also why `token` covers RemoteiTopConnectionToken's secret without
	 * catching combodo-oauth2-client's `token_type`, which is "Bearer".
	 */
	public const OUTBOUND_SECRET_ATTRIBUTES = [
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
	public const RECIPIENT_ATTRIBUTES = ['to', 'cc', 'bcc'];

	/** The OQL a category is defined by, and the key a rule names its category with. */
	public const AUDIT_QUERY_ATTRIBUTE = 'definition_set';

	public const AUDIT_CATEGORY_ATTRIBUTE = 'category_id';

	public const DETECTION_ROOTS = [
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
	public const RECORDING_ROOTS = [
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
	public const PERSONAL_ROOTS = [
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
	public const OWNER_ATTRIBUTES = ['userid', 'user_id'];

	/**
	 * The attribute a data source names its target class in, and the one its
	 * dependent rows name their data source in.
	 *
	 * Asked of the datamodel before they are read, never assumed: a branch
	 * that renames either costs the scope_class half of the rule and nothing
	 * else, because the outright refusal below it does not depend on them.
	 */
	public const SCOPE_ATTRIBUTE = 'scope_class';

	/**
	 * The attribute a trigger names the class it watches in.
	 *
	 * AttributeClass, and its more_values explicitly adds User, UserExternal,
	 * UserInternal, UserLDAP and UserLocal to the bizmodel category - so a
	 * trigger can be pointed at the account classes by design.
	 */
	public const TARGET_ATTRIBUTE = 'target_class';

	public const SOURCE_ATTRIBUTE = 'sync_source_id';

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
	public const RIGHTS_CATEGORY = 'addon/userrights';

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
	 * object, and refusing to write the whole object for it would be an
	 * unrelated restriction wearing this one's name. The secret itself is
	 * refused by SecretPolicy, one attribute at a time. iTop's own addon/authentication category is
	 * left alone for the same reason: SynchroDataSource carries it, and a
	 * synchro source is not a credential.
	 *
	 * An extension that stored a mailbox password in this type anyway would be
	 * storing one it can never use - and the cost here is that its class
	 * becomes read-only through this endpoint, which is the direction a
	 * barrier should fail in.
	 */
	public const CREDENTIAL_ATTRIBUTE = 'AttributeOneWayPassword';
}
