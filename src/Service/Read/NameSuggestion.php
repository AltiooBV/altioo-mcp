<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use MetaModel;
use UserRights;

/**
 * "Did you mean": what to say beside a refusal of a class or an attribute name that does not exist, among the names this
 * caller is served (review 2026-11-23, C12).
 *
 * **Only names the caller is served, chosen from them alone.** The candidates are filtered by what the account may read
 * and by the operator's exclusions (doc/adr/0012) *before* any is ranked or counted, and the cut to three comes after.
 * So what is suggested for a string is a function of that string and of the served names, never of a name that is not
 * served: an excluded class, an attribute the account may not read, and a name that never existed leave the same
 * answer for the same string, and nothing is said to tell them apart. (Choosing the nearest names first and filtering
 * after would let an excluded neighbour crowd a slot out, and the missing suggestion would say it is there.)
 *
 * **A suggestion, never an acceptance.** Names stay case-sensitive; `userrequest` is refused, and the refusal says
 * `UserRequest`. Nothing here decides whether a call goes on.
 *
 * The portal's served names are not something this layer may ask (it sits below the portal's); the server hands them
 * over per request, with {@see Remember()}.
 *
 * @api
 * @since 2.2.0
 */
final class NameSuggestion
{
	/** At most this many are named. */
	public const MAX = 3;

	/** Longer than any class or attribute code, and past what levenshtein() takes. */
	private const MAX_LENGTH = 64;

	/** @var (\Closure(): array<int, string>)|null */
	private static ?\Closure $fPortalClasses = null;

	/** @var (\Closure(string): array<int, string>)|null */
	private static ?\Closure $fPortalAttributes = null;

	/**
	 * @param \Closure(): array<int, string>       $fPortalClasses    The classes the portal offers this user.
	 * @param \Closure(string): array<int, string> $fPortalAttributes The attribute codes its view form shows of a class.
	 *
	 * @internal Request state, set by Service\Server\ServedElements; a pack never calls it.
	 */
	public static function Remember(\Closure $fPortalClasses, \Closure $fPortalAttributes): void
	{
		self::$fPortalClasses = $fPortalClasses;
		self::$fPortalAttributes = $fPortalAttributes;
	}

	/** For tests, and for a runner that serves more than one request. @internal */
	public static function Forget(): void
	{
		self::$fPortalClasses = null;
		self::$fPortalAttributes = null;
	}

	/**
	 * The sentence to add to the refusal of a class name, or '' when there is nothing to suggest.
	 */
	public static function ForClass(string $sName): string
	{
		return self::Sentence(self::NearClasses($sName), $sName);
	}

	/**
	 * The served classes nearest to a name that is none of them, at most {@see MAX}.
	 *
	 * @return array<int, string>
	 */
	public static function NearClasses(string $sName): array
	{
		if (!self::askable($sName)) {
			return [];
		}

		try {
			if (PortalMode::Current() !== null) {
				$aServed = self::$fPortalClasses === null ? [] : (self::$fPortalClasses)();

				return self::Closest($sName, $aServed);
			}

			// The cheap test first, on every class name, and the served test on what survives it: the cut by
			// distance depends on the string alone, so what is dropped afterwards cannot show.
			$aNear = self::Closest($sName, MetaModel::GetClasses(), PHP_INT_MAX);
			$aServed = array_values(array_filter(
				$aNear,
				static fn (string $sClass): bool => !ExclusionPolicy::ExcludesClass($sClass)
					&& !ChangeLogReservation::IsReserved($sClass)
					&& UserRights::IsActionAllowed($sClass, UR_ACTION_READ)
			));

			return array_slice($aServed, 0, self::MAX);
		} catch (\Throwable $e) {
			ErrorReport::Log('Could not suggest a class name: '.get_class($e));

			return [];
		}
	}

	/**
	 * The sentence to add to the refusal of an attribute code of a class, or ''.
	 *
	 * Nothing for a class that is not itself served: the refusal of the class comes first and says what it says.
	 */
	public static function ForAttribute(string $sClass, string $sName, bool $bForSearch = false): string
	{
		return self::Sentence(self::NearAttributes($sClass, $sName, $bForSearch), $sName);
	}

	/**
	 * The attribute codes of a class, served to this caller, nearest to a code that is none of them: none when the code is
	 * itself served (the call was refused for another reason, and a neighbour would be a wrong turn).
	 *
	 * @param bool $bForSearch For a filter, an order or a group: an attribute the endpoint masks on output is unknown there, and answered
	 *                         as one that does not exist, so it is neither suggested nor held to be served - otherwise a near
	 *                         miss on its name would name it.
	 *
	 * @return array<int, string>
	 */
	public static function NearAttributes(string $sClass, string $sName, bool $bForSearch = false): array
	{
		if (!self::askable($sName) || !ClassRefusal::LooksLikeClassName($sClass)) {
			return [];
		}

		try {
			if (PortalMode::Current() !== null) {
				$aServed = self::$fPortalAttributes === null ? [] : (self::$fPortalAttributes)($sClass);

				return in_array($sName, $aServed, true) ? [] : self::Closest($sName, $aServed);
			}

			if (!MetaModel::IsValidClass($sClass) || ExclusionPolicy::ExcludesClass($sClass) || !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				return [];
			}
			$fServed = static fn (string $sCode): bool => MetaModel::IsValidAttCode($sClass, $sCode)
				&& !ExclusionPolicy::ExcludesAttribute($sClass, $sCode)
				&& UserRights::IsActionAllowedOnAttribute($sClass, $sCode, UR_ACTION_READ) !== UR_ALLOWED_NO
				&& (!$bForSearch || !ObjectSerializer::IsSensitive(MetaModel::GetAttributeDef($sClass, $sCode), $sClass));
			if ($fServed($sName)) {
				return [];
			}

			/** @var array<int, string> $aCodes */
			$aCodes = array_map('strval', array_keys((array)MetaModel::ListAttributeDefs($sClass)));
			$aNear = self::Closest($sName, $aCodes, PHP_INT_MAX);

			return array_slice(array_values(array_filter($aNear, $fServed)), 0, self::MAX);
		} catch (\Throwable $e) {
			ErrorReport::Log('Could not suggest an attribute code: '.get_class($e));

			return [];
		}
	}

	/**
	 * The names nearest to $sName, closest first, then alphabetically: the same name in another case is the closest
	 * there is. Pure.
	 *
	 * "Near" is a Levenshtein distance, case ignored, of at most a third of the name's length and at least two: `state` is
	 * near `status`, `Incdent` is near `Incident`, and `ticket` is near nothing.
	 *
	 * @param array<int, string> $aNames
	 *
	 * @return array<int, string>
	 */
	public static function Closest(string $sName, array $aNames, int $iMax = self::MAX): array
	{
		if (!self::askable($sName)) {
			return [];
		}

		$sLower = strtolower($sName);
		$iThreshold = max(2, (int)ceil(strlen($sName) / 3));
		$aScored = [];
		foreach ($aNames as $sCandidate) {
			if (!is_string($sCandidate) || $sCandidate === $sName || strlen($sCandidate) > self::MAX_LENGTH) {
				continue;
			}
			$iDistance = levenshtein($sLower, strtolower($sCandidate));
			if ($iDistance <= $iThreshold) {
				$aScored[$sCandidate] = $iDistance;
			}
		}
		uksort($aScored, static fn (string $a, string $b): int => [$aScored[$a], $a] <=> [$aScored[$b], $b]);

		return array_slice(array_keys($aScored), 0, $iMax);
	}

	/**
	 * " Did you mean 'A', 'B' or 'C'?" - and that names are case-sensitive, only when the first differs from the name by its case alone.
	 *
	 * @param array<int, string> $aNames
	 */
	public static function Sentence(array $aNames, string $sName = ''): string
	{
		if ($aNames === []) {
			return '';
		}

		$aQuoted = array_map(static fn (string $s): string => "'{$s}'", $aNames);
		$sList = count($aQuoted) === 1 ? $aQuoted[0] : implode(', ', array_slice($aQuoted, 0, -1)).' or '.end($aQuoted);

		return ' Did you mean '.$sList.'?'.($sName !== '' && strcasecmp($sName, $aNames[0]) === 0 ? ' Names are case-sensitive.' : '');
	}

	/** Only a value shaped like a name is compared, and only that one is ever quoted back. */
	private static function askable(string $sName): bool
	{
		return $sName !== '' && strlen($sName) <= self::MAX_LENGTH && ClassRefusal::LooksLikeClassName($sName);
	}
}
