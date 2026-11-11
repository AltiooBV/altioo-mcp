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
			&& !CallersOwnAccess::IsTheCallersOwnRow($sClass, $iId, $aFields)) {
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
			$sWatched = DelegatedWrites::DelegatedTarget($sClass, $iId, $aFields);
			if ($sWatched !== null) {
				$sRefusal = DelegatedWrites::CouldNotDoItDirectly($sClass, $sWatched, false);
				if ($sRefusal !== null) {
					return $sRefusal;
				}
			}

			// And the recipients, where the class names any. An email action's
			// to/cc/bcc are queries run with AllowAllData(), so each is a read
			// of a class this caller may or may not be allowed to read - see
			// RECIPIENT_ATTRIBUTES.
			return RecipientQueries::RecipientQueryRefusal($sClass, $iId, $aFields);
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
			$sTarget = DelegatedWrites::DelegatedTarget($sClass, $iId, $aFields);
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
			return DelegatedWrites::CouldNotDoItDirectly($sClass, $sTarget, true);
		}

		if (!AccessGrantFamilies::IsGranting($sClass)) {
			return null;
		}

		if (!$bAdministrationAllowed) {
			return sprintf(AccessGrantRefusals::GRANT_REFUSAL, $sClass);
		}

		if (CallersOwnAccess::ReachesTheCaller($sClass, $iId, $aFields)) {
			return sprintf(AccessGrantRefusals::SELF_REFUSAL, $sClass);
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

}
