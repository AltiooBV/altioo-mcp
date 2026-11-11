<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Audit;

/**
 * Which objects a request handed back, written onto its own audit row.
 *
 * The counterpart of {@see ChangeSummary}, for the half of the trail it cannot
 * see: a write leaves `CMDBChangeOp` rows and a summary of them, a read left
 * the tool and the size of the answer and nothing about who was in it. "Was
 * this person's ticket sent to a model?" could not be answered afterwards.
 *
 * **Class and id only, never a value and never a friendly name.** A name is
 * data about the person, and the README's promise is that the audit row does
 * not record what the objects contain. The identity of an object is what a
 * data owner asks about; what it said is theirs to look up while it exists.
 *
 * Filled by whatever turns an object into an answer - the serializer, the
 * related graph, a document fetch, the history reader - and emptied by the
 * controller once the row is written. Static for the request, like
 * {@see \Altioo\iTop\Extension\MCP\Helper\ErrorReport::MintedReferences()},
 * because the places that read are many and the row is written once.
 *
 * @internal
 * @since 1.3.0
 */
class ReadSummary
{
	/** Objects named per class before the tail is counted instead. */
	public const MAX_PER_CLASS = 50;

	/** Classes listed before the rest are counted instead. */
	public const MAX_CLASSES = 20;

	/** @var array<string, array<int, true>> Class => ids, in the order they were read. */
	private static array $aRead = [];

	/**
	 * Records that an object was returned.
	 *
	 * A key below 1 is a dry run's new object, which has no identity yet and
	 * was read by nobody.
	 *
	 * @since 1.3.0
	 */
	public static function Note(string $sClass, int $iId): void
	{
		if ($sClass === '' || $iId < 1) {
			return;
		}
		self::$aRead[$sClass][$iId] = true;
	}

	/**
	 * The line for the row, empty when nothing was read.
	 *
	 * `UserRequest: 12, 15, 99 (+41 more); Person: 7`. Ids are listed in the
	 * order they were read, which is the order they were returned.
	 *
	 * @since 1.3.0
	 */
	public static function Compose(): string
	{
		return self::ComposeFrom(self::$aRead);
	}

	/**
	 * {@see Compose()} for a given set, so the format can be asserted without
	 * the request's state.
	 *
	 * @param array<string, array<int, mixed>> $aRead Class => ids as keys.
	 *
	 * @since 1.3.0
	 */
	public static function ComposeFrom(array $aRead): string
	{
		$aParts = [];
		$iClasses = 0;
		$iOtherObjects = 0;
		foreach ($aRead as $sClass => $aIds) {
			$aIds = array_keys($aIds);
			if ($aIds === []) {
				continue;
			}
			if ($iClasses === self::MAX_CLASSES) {
				$iOtherObjects += count($aIds);
				continue;
			}
			$iClasses++;
			$sIds = implode(', ', array_slice($aIds, 0, self::MAX_PER_CLASS));
			$iMore = count($aIds) - self::MAX_PER_CLASS;
			if ($iMore > 0) {
				$sIds .= ' (+'.$iMore.' more)';
			}
			$aParts[] = $sClass.': '.$sIds;
		}
		if ($iOtherObjects > 0) {
			$aParts[] = '+'.$iOtherObjects.' more in other classes';
		}

		return implode('; ', $aParts);
	}

	/** For tests, and for a runner that serves more than one request. @since 1.3.0 */
	public static function Forget(): void
	{
		self::$aRead = [];
	}
}
