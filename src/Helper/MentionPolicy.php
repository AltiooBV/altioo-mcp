<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeCaseLog;
use MetaModel;
use Throwable;
use utils;

/**
 * Which @mentions a case-log entry written through this endpoint may carry.
 *
 * A mention is markup, not a relation: iTop finds
 * `<a data-object-class="…" data-object-key="…">` in a new case-log entry
 * (utils::GetMentionedObjectsFromText()) and fires TriggerOnObjectMention for
 * every object it names - typically a mail to each one. The console only
 * ever writes that markup through its editor's autocomplete, which offers the
 * classes in `mentions.allowed_classes` and the objects the user can see, one
 * at a time. This endpoint takes the value as the caller sends it, so the
 * markup is whatever the caller types: any class, any id, as many as fit. A
 * red-team pass fired 27 notification mails from one update.
 *
 * The parser is iTop's, and so is the bug. What this module can do is refuse
 * to be the easy way to reach it: hold a mention written here to what the
 * console would have let the same user write, and cap how many objects one
 * call may mention (mcp_max_mentions). It asks iTop's own parser, so it sees
 * exactly what the trigger will see - including markup sent as plain text,
 * which the trigger decodes back into markup.
 *
 * @api
 * @since 1.1.0
 */
final class MentionPolicy
{
	/**
	 * Distinct Class::id pairs mentioned so far in this request.
	 *
	 * One request is one tool call, and the cap is per call: a bulk update of
	 * a hundred tickets with five mentions each would otherwise be five
	 * hundred mails under a limit of five.
	 *
	 * @var array<string, true>
	 */
	private static array $aSeen = [];

	/**
	 * Why this value may not be written, or null when it may.
	 *
	 * @param mixed $value As the caller sent it: a string, or {"add_item": {"message": "…"}}.
	 */
	public static function RefusalFor(string $sClass, string $sAttCode, mixed $value): ?string
	{
		$sText = self::entryText($value);
		if ($sText === null) {
			return null;
		}

		try {
			if (!MetaModel::GetAttributeDef($sClass, $sAttCode) instanceof AttributeCaseLog) {
				return null;
			}
			$aMentioned = utils::GetMentionedObjectsFromText($sText);
		} catch (Throwable $e) {
			return null;
		}

		if ($aMentioned === []) {
			return null;
		}

		return self::Evaluate(
			$sAttCode,
			$aMentioned,
			self::AllowedClasses(),
			MCPHelper::GetMaxMentions(),
			// IsParentClass() throws on an unknown class, and a typo in
			// mentions.allowed_classes is an operator's, not the caller's.
			static fn (string $sParent, string $sChild): bool => MetaModel::IsValidClass($sParent)
				&& MetaModel::IsValidClass($sChild)
				&& MetaModel::IsParentClass($sParent, $sChild),
			// GetObject() without AllowAllData answers what the caller can see,
			// which is what the console's autocomplete would have offered.
			static function (string $sMentionedClass, string $sId): bool {
				try {
					return MetaModel::GetObject($sMentionedClass, $sId, false) !== null;
				} catch (Throwable $e) {
					return false;
				}
			}
		);
	}

	/**
	 * The classes this instance lets a user mention: mentions.allowed_classes,
	 * whose keys are the autocomplete markers and do not matter here.
	 *
	 * @return array<int, string>
	 */
	public static function AllowedClasses(): array
	{
		try {
			$aAllowed = MetaModel::GetConfig()->Get('mentions.allowed_classes');
		} catch (Throwable $e) {
			return [];
		}

		return is_array($aAllowed) ? array_values(array_filter($aAllowed, 'is_string')) : [];
	}

	/**
	 * The decision, with iTop's lookups passed in.
	 *
	 * Records what it accepts against the per-call count; a refused value
	 * counts for nothing.
	 *
	 * @param array<string, array<int, string>> $aMentioned      As utils::GetMentionedObjectsFromText() returns it.
	 * @param array<int, string>                $aAllowedClasses mentions.allowed_classes, values only.
	 * @param callable(string, string): bool    $fIsA            (parent, child) - child is parent or below it.
	 * @param callable(string, string): bool    $fCanSee         (class, id) - the caller can read that object.
	 */
	public static function Evaluate(string $sAttCode, array $aMentioned, array $aAllowedClasses, int $iMax, callable $fIsA, callable $fCanSee): ?string
	{
		$aNew = [];
		foreach ($aMentioned as $sMentionedClass => $aIds) {
			$bAllowedClass = false;
			foreach ($aAllowedClasses as $sAllowedClass) {
				if ($fIsA($sAllowedClass, (string) $sMentionedClass)) {
					$bAllowedClass = true;
					break;
				}
			}
			if (!$bAllowedClass) {
				return sprintf(
					"The entry for '%s' mentions a %s, and mentions here are limited to %s - the classes the console lets a user mention (mentions.allowed_classes). Remove the mention markup, or name the object in plain words.",
					$sAttCode,
					$sMentionedClass,
					$aAllowedClasses === [] ? 'nothing on this instance' : implode(', ', $aAllowedClasses)
				);
			}

			foreach ($aIds as $sId) {
				if (!$fCanSee((string) $sMentionedClass, (string) $sId)) {
					return sprintf(
						"The entry for '%s' mentions %s::%s, which you cannot see. A mention notifies the object it names, so it is limited to the ones you could have picked in the console.",
						$sAttCode,
						$sMentionedClass,
						$sId
					);
				}
				$sKey = $sMentionedClass.'::'.$sId;
				if (!isset(self::$aSeen[$sKey])) {
					$aNew[$sKey] = true;
				}
			}
		}

		$iTotal = count(self::$aSeen) + count($aNew);
		if ($iTotal > $iMax) {
			return $iMax === 0
				? sprintf("The entry for '%s' mentions someone, and mentions are turned off for this endpoint (mcp_max_mentions is 0). Name them in plain words instead.", $sAttCode)
				: sprintf(
					"The entry for '%s' would bring this call to %d mentioned objects, over this endpoint's limit of %d (mcp_max_mentions). Each mention can send a notification; mention fewer, or split the work across calls a person can follow.",
					$sAttCode,
					$iTotal,
					$iMax
				);
		}

		self::$aSeen += $aNew;

		return null;
	}

	/**
	 * Starts the per-call count again. Tests only: a real request starts a
	 * fresh process state.
	 *
	 * @internal
	 */
	public static function ForgetCount(): void
	{
		self::$aSeen = [];
	}

	/**
	 * The text of the entry being added, in either shape a case log accepts.
	 */
	private static function entryText(mixed $value): ?string
	{
		if (is_string($value)) {
			return $value;
		}
		if (is_array($value) && is_array($value['add_item'] ?? null) && is_string($value['add_item']['message'] ?? null)) {
			return $value['add_item']['message'];
		}

		return null;
	}
}
