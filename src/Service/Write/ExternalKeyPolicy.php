<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Write;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;

use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use Throwable;

/**
 * Whether an external key, or the items of a link set, may point where the caller aimed them: said plainly, before the ORM
 * turns a mistake into an exception this module may not repeat.
 *
 * @internal
 * @since 2.0.0
 */
final class ExternalKeyPolicy
{
	/**
	 * Why an external key cannot point where the caller aimed it, said plainly.
	 *
	 * The case: org_id = 999999 on an instance with no such organisation. iTop
	 * refuses it from inside RestUtils::MakeValue(), and what comes back here
	 * is a CoreException whose message routinely carries SQL, table names and
	 * the context array folded in - so this module answers it opaquely, with a
	 * log reference, as it does for every ORM failure it cannot vouch for.
	 *
	 * That was the wrong answer to this particular question, twice over. The
	 * reason is knowable and harmless - no object of that class has that id -
	 * and the advice was a dead end: the reference points into iTop's log, and
	 * nothing in this server reads it. An agent was told to quote an
	 * identifier at a system it has no path to, for a mistake it could have
	 * corrected itself.
	 *
	 * Asked before the value reaches the ORM, so the refusal replaces the
	 * opaque one rather than decorating it. Missing and unreadable answer the
	 * same sentence: telling them apart would let a caller enumerate the
	 * objects it may not see, which is the oracle SECURITY.md closes
	 * everywhere else.
	 *
	 * A string is the other half, and it was the half that stayed a dead end.
	 * FindObjectFromKey() runs a non-numeric string as OQL
	 * (applicationextension.inc.php), so `org_id: "abc"` is not a malformed id
	 * - it is a malformed query, and what came back was the opaque refusal
	 * above with no way for the caller to learn which. The three accepted
	 * forms are an id, an OQL query selecting the target class, and a criteria
	 * object; a string that is none of them is named as such here, before the
	 * ORM turns it into an exception this module may not repeat.
	 *
	 * An array is search criteria and stays the ORM's business: it has its own
	 * shape and its own failures, and guessing at them here would be inventing
	 * refusals rather than replacing one.
	 *
	 * @param mixed $value As the caller sent it.
	 *
	 * @return string|null The refusal, or null when there is nothing to object to.
	 * @since 1.0.0
	 */
	public static function RefusalFor(string $sClass, string $sAttCode, mixed $value): ?string
	{
		$sExcluded = self::RefusalForExcludedKeys($sClass, $sAttCode, $value);
		if ($sExcluded !== null) {
			return $sExcluded;
		}

		$bNumeric = is_int($value) || (is_string($value) && ctype_digit($value));

		if (!$bNumeric && !is_string($value) && !(is_array($value) && PortalMode::Current() !== null)) {
			return null;
		}

		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
			if ($oAttDef->IsLinkSet() && is_array($value) && PortalMode::Current() !== null) {
				return self::refusalInPortalLinks($oAttDef->GetLinkedClass(), $sAttCode, $value);
			}
			if (!$oAttDef->IsExternalKey()) {
				return null;
			}

			$sTarget = $oAttDef->GetTargetClass();

			// An OQL string or a criteria object is searched under the account's
			// rights, which in portal mode are wider than the portal's scope:
			// whether it found one, none or several would tell the caller about
			// objects the portal does not show, a letter at a time. So an id is
			// all the portal accepts, and an id it does not show is answered
			// alike whether or not the object exists.
			if (PortalMode::Current() !== null) {
				if (!$bNumeric) {
					return sprintf("Give '%s' the id of a %s, taken from a list the portal shows.", $sAttCode, $sTarget);
				}

				return ((int) $value === 0 && $oAttDef->IsNullAllowed()) || ObjectAccess::Current()->Readable($sTarget, (int) $value) !== null
					? null
					: sprintf("'%s' points at a %s the portal does not show you.", $sAttCode, $sTarget);
			}

			if (!$bNumeric) {
				return self::RefusalForExternalKeyQuery($sTarget, $sAttCode, (string) $value);
			}

			if ((int) $value === 0 && $oAttDef->IsNullAllowed()) {
				// 0 is how iTop spells "no target" on a key that allows one.
				return null;
			}

			$oFound = MetaModel::GetObject($sTarget, (int) $value, false);
			if ($oFound !== null && !ExclusionPolicy::ExcludesClass(get_class($oFound)) && !ExclusionPolicy::IsFiledUnderExcluded($oFound)) {
				return null;
			}

			return sprintf(
				"No %s has id %s, or it is not one you may read. Search %s for the object you want and pass its id: "
				."core_object_find_by_name finds it by name, core_object_search_by_class by attribute.",
				$sTarget,
				(string) $value,
				$sTarget
			);
		} catch (Throwable $e) {
			// In portal mode the ORM's say is what this keeps from running.
			if (PortalMode::Current() !== null) {
				ErrorReport::Log("Could not check '{$sAttCode}' against the portal: ".$e->getMessage());

				return sprintf("'%s' could not be checked against what the portal shows, so it was not accepted.", $sAttCode);
			}

			// A question about the datamodel must not become the refusal. The
			// ORM still gets its say, which is where this started.
			return null;
		}
	}

	/**
	 * The same rule for a link set's items, which RestUtils::MakeValue() turns
	 * into objects through the same search. An item's own external keys take an
	 * id the portal shows, as the portal's pickers would have offered, and
	 * nothing else. A code the link class lacks is left to the ORM, which stops
	 * at it before searching for anything after it.
	 *
	 * @param array<mixed> $aItems As the caller sent them.
	 */
	private static function refusalInPortalLinks(string $sLinkClass, string $sAttCode, array $aItems): ?string
	{
		foreach ($aItems as $aItem) {
			if (!is_array($aItem)) {
				continue;
			}
			foreach ($aItem as $sItemAttCode => $mValue) {
				if (!MetaModel::IsValidAttCode($sLinkClass, (string) $sItemAttCode)) {
					continue;
				}
				$oItemDef = MetaModel::GetAttributeDef($sLinkClass, (string) $sItemAttCode);
				if ($oItemDef->IsLinkSet() && is_array($mValue)) {
					$sNested = self::refusalInPortalLinks($oItemDef->GetLinkedClass(), $sAttCode, $mValue);
					if ($sNested !== null) {
						return $sNested;
					}
					continue;
				}
				if (!$oItemDef->IsExternalKey()) {
					continue;
				}
				$sTarget = $oItemDef->GetTargetClass();
				if (!is_int($mValue) && !(is_string($mValue) && ctype_digit($mValue))) {
					return sprintf("Give the items of '%s' the id of a %s, taken from a list the portal shows.", $sAttCode, $sTarget);
				}
				if ((int) $mValue !== 0 && ObjectAccess::Current()->Readable($sTarget, (int) $mValue) === null) {
					return sprintf("'%s' links a %s the portal does not show you.", $sAttCode, $sTarget);
				}
			}
		}

		return null;
	}

	/**
	 * The string form of an external key, which iTop reads as OQL.
	 *
	 * Three things can be wrong with it and all three are the caller's to fix,
	 * so all three are said rather than referred to a log: it is not OQL at
	 * all, it selects a class that is not the one this key points at, or it
	 * matches no object - or several, where the key needs exactly one.
	 *
	 * The count is asked here even though MakeValue() will ask it again. One
	 * extra query on a call that was going to make it anyway buys a message
	 * the caller can act on, and the alternative is the dead end this exists
	 * to remove. Rights and silo apply exactly as they do to the ORM's own
	 * attempt, so "no object" here means the same thing it would mean there.
	 *
	 * The caller's own string is quoted back; iTop's exception never is. That
	 * is the line RejectedValue() draws, and it is drawn for the reason given
	 * there - a MySQLException on this path carries the SQL it issued.
	 */
	/**
	 * A criteria object or the items of a link set naming an attribute the
	 * operator excluded (doc/adr/0012): searched on, or set, it would be an
	 * oracle for the value or a way to write it. Refused as an unknown attribute.
	 */
	private static function RefusalForExcludedKeys(string $sClass, string $sAttCode, mixed $value): ?string
	{
		if (!is_array($value) || !ExclusionPolicy::IsActive()) {
			return null;
		}

		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
			if ($oAttDef->IsExternalKey()) {
				$sOn = $oAttDef->GetTargetClass();
				$aRows = [$value];
			} elseif ($oAttDef->IsLinkSet()) {
				$sOn = $oAttDef->GetLinkedClass();
				$aRows = array_filter($value, 'is_array');
			} else {
				return null;
			}

			foreach ($aRows as $aRow) {
				foreach (array_keys($aRow) as $sKey) {
					// An unknown name is refused in the same words as an excluded one: the two must not be told apart.
					if (is_string($sKey) && (!MetaModel::IsValidAttCode($sOn, $sKey) || ExclusionPolicy::ExcludesAttribute($sOn, $sKey))) {
						return "Unknown attribute '{$sKey}' on class '{$sOn}'.";
					}
				}
			}
		} catch (Throwable $e) {
			return null;
		}

		return null;
	}

	private static function RefusalForExternalKeyQuery(string $sTarget, string $sAttCode, string $sValue): ?string
	{
		$sHow = sprintf(
			"Give '%s' the id of a %s, an OQL query selecting exactly one (\"SELECT %s WHERE ...\"), or a criteria object. "
			."core_object_find_by_name finds an object by name, core_object_search_by_class by attribute.",
			$sAttCode,
			$sTarget,
			$sTarget
		);

		try {
			$oSearch = DBObjectSearch::FromOQL($sValue);
		} catch (Throwable $e) {
			return sprintf('%s is not an id and not valid OQL. %s', json_encode($sValue), $sHow);
		}

		// Held to what this channel serves, as a query is anywhere else (doc/adr/0012).
		try {
			ObjectQuery::CheckQuery($sValue);
		} catch (\Mcp\Exception\ToolCallException $e) {
			return $e->getMessage();
		}
		$sUnproved = ExclusionPolicy::OqlRefusal($sValue);
		if ($sUnproved !== null) {
			return $sUnproved;
		}

		$sSelected = (string) $oSearch->GetClass();
		if ($sSelected !== '' && strcasecmp($sSelected, $sTarget) !== 0 && !is_a($sSelected, $sTarget, true)) {
			return sprintf(
				"That query selects %s, but '%s' points at %s. %s",
				$sSelected,
				$sAttCode,
				$sTarget,
				$sHow
			);
		}

		try {
			$iCount = (new DBObjectSet(ExclusionPolicy::Narrowed($oSearch, $sSelected)))->Count();
		} catch (Throwable $e) {
			// The query parses and names the right class; whatever running it
			// hit is the ORM's to report, on its own attempt.
			return null;
		}

		if ($iCount === 1) {
			return null;
		}

		return $iCount === 0
			? sprintf("That query matches no %s you may read. %s", $sTarget, $sHow)
			: sprintf("That query matches %d objects and an external key needs exactly one. %s", $iCount, $sHow);
	}
}
