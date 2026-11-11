<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Rights;

use MetaModel;
use Throwable;

/**
 * Which family of the barrier a class belongs to.
 *
 * Granting, delegating, automation, recording, detection and personal: each
 * answer is a match against the lists in {@see AccessGrantRoots} - by name, by
 * descent, and for two families by what the datamodel says about the class in
 * front of it. Whether a given write is then refused, and with which sentence,
 * is {@see AccessGrants}.
 *
 * Every root is matched with its descendants, through is_a() rather than by
 * name, and case-insensitively on the exact name, because iTop resolves class
 * names case-insensitively in OQL and a barrier that did not would be one
 * lowercase letter away from open. The datamodel half never raises and only
 * ever adds a class to a family: no MetaModel, a category iTop renames or an
 * attribute definition that raises leaves the named floor standing.
 *
 * @internal
 * @since 2.0.0
 */
final class AccessGrantFamilies
{
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

		foreach (AccessGrantRoots::DELEGATING_ROOTS as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return self::$aDecided[$sKey] = true;
			}
		}

		return self::$aDecided[$sKey] = self::PointsAtAnyOf($sClass, [AccessGrantRoots::SOURCE_KEY_TARGET]);
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

		foreach (array_merge(AccessGrantRoots::AUTOMATION_ROOTS, AccessGrantRoots::OUTBOUND_CREDENTIAL_ROOTS) as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return self::$aDecided[$sKey] = true;
			}
		}

		return self::$aDecided[$sKey] = self::PointsAtAnyOf($sClass, ['Trigger', 'Action']);
	}

	/**
	 * Whether the operator added $sClass to the escalation barrier, through
	 * mcp_privilege_escalation_classes.
	 *
	 * Matched like the built-in roots - the exact name, case-insensitive, or
	 * a descendant - so listing an extension's base class covers what it
	 * adds below it. Not cached with the answers above: those are facts about
	 * the datamodel, this is a question about a setting, and it is handed in.
	 *
	 * @param array<int, string>|null $aListed What the setting holds; null when it is malformed, which reads as every class.
	 *
	 * @since 1.2.0
	 */
	public static function IsOperatorListed(string $sClass, ?array $aListed): bool
	{
		if ($aListed === null) {
			return true;
		}

		foreach ($aListed as $sRoot) {
			if (strcasecmp($sClass, $sRoot) === 0 || is_a($sClass, $sRoot, true)) {
				return true;
			}
		}

		return false;
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
		if (!in_array(strtolower($sAttCode), AccessGrantRoots::OUTBOUND_SECRET_ATTRIBUTES, true)) {
			return false;
		}

		foreach (AccessGrantRoots::OUTBOUND_CREDENTIAL_ROOTS as $sRoot) {
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
		foreach (AccessGrantRoots::RECORDING_ROOTS as $sRoot) {
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
		foreach (AccessGrantRoots::DETECTION_ROOTS as $sRoot) {
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
		foreach (AccessGrantRoots::PERSONAL_ROOTS as $sRoot) {
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
		if (stripos($sClass, AccessGrantRoots::GRANTING_PREFIX) === 0) {
			return true;
		}

		foreach (AccessGrantRoots::GRANTING_ROOTS as $sRoot) {
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
			foreach (MetaModel::GetClasses(AccessGrantRoots::RIGHTS_CATEGORY) as $sRightsClass) {
				if (strcasecmp($sClass, $sRightsClass) === 0 || is_a($sClass, $sRightsClass, true)) {
					return true;
				}
			}

			if (!MetaModel::IsValidClass($sClass)) {
				return false;
			}

			foreach (MetaModel::ListAttributeDefs($sClass) as $oAttDef) {
				if (is_a($oAttDef, AccessGrantRoots::CREDENTIAL_ATTRIBUTE)) {
					return true;
				}
			}
		} catch (Throwable) {
			return false;
		}

		return false;
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
