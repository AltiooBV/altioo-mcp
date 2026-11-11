<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use Altioo\iTop\Extension\MCP\Helper\Settings;
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
			Settings::AllowsAccessAdministration(),
			$sClass,
			$iId,
			$aFields,
			Settings::AllowsPrivilegeEscalation(),
			Settings::GetEscalationClasses()
		);
	}

	/**
	 * The same decision with the instance's answer handed in rather than read.
	 *
	 * Split for the reason AccessPolicy is a value object: the rule is worth
	 * testing without an iTop, a configuration or a request, and the half that
	 * needs all three is one line long and lives above.
	 *
	 * @param bool                    $bAdministrationAllowed What mcp_allow_access_administration says.
	 * @param array<string, mixed>    $aFields
	 * @param array<int, string>|null $aEscalationClasses     What mcp_privilege_escalation_classes says; null for every class.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Takes the classes the operator added to the escalation barrier.
	 */
	public static function RefusalGiven(
		bool    $bAdministrationAllowed,
		string  $sClass,
		?int    $iId = null,
		array   $aFields = [],
		bool    $bEscalationAllowed = false,
		?array  $aEscalationClasses = [],
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
		if (AccessGrantFamilies::IsPersonal($sClass)
			&& !$bAdministrationAllowed
			&& !self::IsTheCallersOwnRow($sClass, $iId, $aFields)) {
			return sprintf(AccessGrantRefusals::NOT_YOURS_REFUSAL, $sClass);
		}

		// The record of what happened, before anything else and with no
		// setting consulted: every other rule here is worth having only while
		// somebody can still check whether it held.
		if (AccessGrantFamilies::IsRecording($sClass)) {
			return sprintf(AccessGrantRefusals::RECORDING_REFUSAL, $sClass);
		}

		// What the operator added, before the built-in families: an operator
		// listing a class has said it stages work this endpoint cannot grade,
		// and a built-in rule that would grade it - a synchronisation source
		// is allowed over a class the caller may write - does not get to
		// overrule that. With the setting on, the class is graded below as if
		// it had never been listed, which for a class nothing else here knows
		// is an ordinary write.
		if (!$bEscalationAllowed && AccessGrantFamilies::IsOperatorListed($sClass, $aEscalationClasses)) {
			return sprintf(AccessGrantRefusals::OPERATOR_ESCALATION_REFUSAL, $sClass);
		}

		// A standing instruction that makes the instance act by itself, or the
		// credentials it acts with. The effect it arranges - mail out, a URL
		// called, a static method invoked - is one no tool here performs at
		// all, so the rule below yields "refused" for every caller and the
		// setting is an operator overriding that, not a grade.
		if (AccessGrantFamilies::IsAutomation($sClass)) {
			if (!$bEscalationAllowed) {
				return sprintf(AccessGrantRefusals::AUTOMATION_REFUSAL, $sClass);
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

		if (AccessGrantFamilies::IsDetection($sClass)) {
			if (!$bEscalationAllowed) {
				return sprintf(AccessGrantRefusals::DETECTION_REFUSAL, $sClass);
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

		if (AccessGrantFamilies::IsDelegating($sClass)) {
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
				return sprintf(AccessGrantRefusals::DELEGATION_REFUSAL, $sClass);
			}

			// The rule, stated once: whatever this would end up doing, the
			// caller has to be able to do it here, now, by calling a tool.
			// Anything this endpoint refuses directly it refuses through a
			// mechanism that would arrange it - including, first of all, a
			// target behind one of the barriers above, which no setting
			// reaches.
			return self::CouldNotDoItDirectly($sClass, $sTarget, true);
		}

		if (!AccessGrantFamilies::IsGranting($sClass)) {
			return null;
		}

		if (!$bAdministrationAllowed) {
			return sprintf(AccessGrantRefusals::GRANT_REFUSAL, $sClass);
		}

		if (self::ReachesTheCaller($sClass, $iId, $aFields)) {
			return sprintf(AccessGrantRefusals::SELF_REFUSAL, $sClass);
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
		foreach ([AccessGrantRoots::SCOPE_ATTRIBUTE => null, AccessGrantRoots::TARGET_ATTRIBUTE => null, AccessGrantRoots::SOURCE_ATTRIBUTE => 'source'] as $sAttCode => $sKind) {
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

			if (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::TARGET_ATTRIBUTE)) {
				$sTarget = trim((string) $oRow->Get(AccessGrantRoots::TARGET_ATTRIBUTE));

				return $sTarget === '' ? null : $sTarget;
			}

			if (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::SCOPE_ATTRIBUTE)) {
				$sScope = trim((string) $oRow->Get(AccessGrantRoots::SCOPE_ATTRIBUTE));

				return $sScope === '' ? null : $sScope;
			}

			// A mapping or a replica: the target is whatever its data source
			// says, so ask that row the question instead.
			if (!MetaModel::IsValidAttCode($sClass, AccessGrantRoots::SOURCE_ATTRIBUTE)) {
				return null;
			}

			return self::ScopeOfSource((int) $oRow->Get(AccessGrantRoots::SOURCE_ATTRIBUTE));
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

			foreach (AccessGrantRoots::RECIPIENT_ATTRIBUTES as $sAttCode) {
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
		if (AccessGrantFamilies::IsBarred($sSelected)) {
			return sprintf(AccessGrantRefusals::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'this endpoint does not read it');
		}

		if (!class_exists('UserRights') || !defined('UR_ACTION_READ') || !defined('UR_ACTION_BULK_READ')) {
			return sprintf(AccessGrantRefusals::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'your rights on it could not be established here');
		}

		if (!UserRights::IsActionAllowed($sSelected, UR_ACTION_READ)
			|| !UserRights::IsActionAllowed($sSelected, UR_ACTION_BULK_READ)) {
			return sprintf(AccessGrantRefusals::RECIPIENT_REFUSAL, $sClass, $sAttCode, $sSelected, 'you may not read it here');
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
				AccessGrantRefusals::RECIPIENT_REFUSAL,
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
		$sRefusal = sprintf(AccessGrantRefusals::DETECTION_TAMPER_REFUSAL, $sClass);

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
					return sprintf(AccessGrantRefusals::DETECTION_TAMPER_REFUSAL, $sClass).sprintf(' It watches \'%s\', which you may change.', $sAudited);
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

		if (array_key_exists(AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE, $aFields)) {
			$sOql = trim((string) $aFields[AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE]);
		}

		if ($sOql === null || $sOql === '') {
			$oRow = MetaModel::GetObject($sClass, $iId, false, true);
			if ($oRow === null) {
				return null;
			}

			if (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE)) {
				$sOql = trim((string) $oRow->Get(AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE));
			} elseif (MetaModel::IsValidAttCode($sClass, AccessGrantRoots::AUDIT_CATEGORY_ATTRIBUTE)) {
				$oCategory = MetaModel::GetObject('AuditCategory', (int) $oRow->Get(AccessGrantRoots::AUDIT_CATEGORY_ATTRIBUTE), false, true);
				$sOql = $oCategory === null ? '' : trim((string) $oCategory->Get(AccessGrantRoots::AUDIT_QUERY_ATTRIBUTE));
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
		foreach (AccessGrantRoots::OWNER_ATTRIBUTES as $sCandidate) {
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
			foreach (AccessGrantRoots::OWNER_ATTRIBUTES as $sCandidate) {
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
		if (AccessGrantFamilies::IsBarred($sTarget)) {
			return sprintf(AccessGrantRefusals::DELEGATED_TARGET_REFUSAL, $sClass, $sTarget);
		}

		if (!class_exists('UserRights') || !class_exists('MetaModel')) {
			return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
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
					AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
					$sClass,
					$sTarget,
					'This instance is in read-only mode (access_mode), so nothing may be written here at all just now - which has nothing to do with your rights on it.',
					$sTarget
				);
			}

			if (!MetaModel::IsValidClass($sTarget)) {
				return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, "'{$sTarget}' is not a class this instance knows.", $sTarget);
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
					return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
				}
				if (!UserRights::IsActionAllowed($sTarget, constant($sConstant))) {
					$aRefused[] = $sWhat;
				}
			}

			if ($aRefused !== []) {
				return sprintf(
					AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
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
					AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
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
						AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL,
						$sClass,
						$sTarget,
						'A source fills its own mapping in with every attribute of the class, and it both creates and updates - you may not set '.self::few($aUnwritable).' here.',
						$sTarget
					);
				}
			}
		} catch (Throwable) {
			return sprintf(AccessGrantRefusals::DELEGATED_RIGHTS_REFUSAL, $sClass, $sTarget, 'Your rights on it could not be established here.', $sTarget);
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

			$sScope = trim((string) $oSource->Get(AccessGrantRoots::SCOPE_ATTRIBUTE));

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

}
