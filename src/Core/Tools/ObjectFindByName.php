<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use utils;

/**
 * The console's global search, as a tool.
 *
 * Every other reading tool needs the class before it can do anything, and a
 * question rarely arrives with one. "The Paris datacenter", "Durand's laptop",
 * "the printer on the third floor" - a model that cannot start from the words
 * has to guess a class, guess an attribute, and search for a name in a field
 * that may not hold it. This is the tool that turns words into objects, and
 * the class it finds is what every other tool then takes as its argument.
 *
 * It is iTop's own global search, not a new one: the same needle splitting, the
 * same minimum length, the same class list, the same
 * DBObjectSearch::AddCondition_FullText() over every searchable scalar
 * attribute, and the same leaf rule that keeps one object from being reported
 * once per class in its ancestry. Results a user finds in the console are the
 * results they find here, which is the only sane contract for a tool that
 * answers "what is this called".
 *
 * The rights are core_object_get's, applied per object rather than per class,
 * because this reaches across the whole datamodel rather than into one class
 * the caller named. A class the caller cannot read is not searched; an object
 * they cannot read is not reported; and neither is distinguishable in the
 * answer from one that does not exist.
 *
 * @since 1.0.0
 */
class ObjectFindByName extends AbstractMCPTool
{
	const MIN_LIMIT = 1;
	const DEFAULT_LIMIT = 20;
	const MAX_LIMIT = 100;


	const NEEDLE_MIN_DEFAULT = 3;
	const CHUNK_DURATION_DEFAULT = 2.0;

	/**
	 * How long the scan may run before it reports what it has.
	 *
	 * iTop's own setting, and its own default of 2 seconds. A stock datamodel
	 * declares several hundred searchable classes and each one is a query; the
	 * console pages through them across requests, which an MCP call cannot do,
	 * so it stops and says it stopped.
	 */
	const CHUNK_DURATION_SETTING = 'full_text_chunk_duration';

	/** iTop's floor on a needle, below which a search matches half the database. */
	const NEEDLE_MIN_SETTING = 'full_text_needle_min';

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves. */
	public function getToolset(): string
	{
		return 'objects';
	}

	protected function defaultTitle(): string
	{
		return 'Find Objects by Name';
	}

	public function getDescription(): ?string
	{
		return 'Find iTop objects by free text, the way the console\'s global search does: the words are matched against every searchable attribute of every class the user may read, and the matching objects are returned with the class they belong to. This is the tool to start from when you know what something is called but not which class holds it - take the class from the result and pass it to core_object_get or core_class_schema. Several words are matched as AND. Wrap the text in double quotes to match it as one phrase. Narrow with class when you already know it, which is faster and searches that class and its subclasses only.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Find iTop objects by name',
			true,   // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'name'          => [
					'type'        => 'string',
					'description' => 'The text to look for, e.g. "Paris" or "laptop Durand". Words are matched as AND; a double-quoted string is matched as one phrase. Each word must be at least as long as the instance\'s minimum, 3 characters by default.',
				],
				'class'         => [
					'type'        => 'string',
					'description' => 'Restrict the search to this class and its subclasses, e.g. "Server". Empty (the default) searches every searchable class, which is slower. Call core_class_list to find a class name.',
					'default'     => '',
				],
				'limit'         => [
					'type'        => 'integer',
					'description' => 'Maximum number of objects to return, across all classes.',
					'default'     => self::DEFAULT_LIMIT,
					'minimum'     => self::MIN_LIMIT,
					'maximum'     => self::MAX_LIMIT,
				],
				'output_fields' => ObjectSerializer::FieldsSchemaProperty(ObjectSerializer::DEFAULT_LIST_FIELDS),
			],
			'required'   => ['name'],
		];
	}

	/**
	 * @param string $name          Text to search for
	 * @param string $class         Class to restrict the search to, with its subclasses; '' for every searchable class
	 * @param int    $limit         Maximum number of objects to return across all classes
	 * @param string $output_fields Comma-separated attribute codes to return, or '*' for all of them
	 *
	 * @return mixed The matching objects, each with the class it belongs to
	 *
	 * @throws ToolCallException When the text is too short to search on, or the class is unknown.
	 */
	public static function execute(
		string $name,
		string $class = '',
		int    $limit = self::DEFAULT_LIMIT,
		string $output_fields = ObjectSerializer::DEFAULT_LIST_FIELDS,
	): mixed
	{
		if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
			throw new ToolCallException('Invalid limit. Please specify a limit between '.self::MIN_LIMIT.' and '.self::MAX_LIMIT.'.');
		}

		$aNeedles = self::needles($name);
		$aClasses = self::searchableClasses(trim($class));

		$aResults   = [];
		$iScanned   = 0;
		$bTruncated = false;
		$fDeadline  = microtime(true) + self::chunkDuration();

		foreach ($aClasses as $sClass) {
			if (count($aResults) >= $limit) {
				$bTruncated = true;
				break;
			}

			// Checked after the first class rather than before it, so that a
			// search always reports on at least one - a deadline that has
			// already passed would otherwise return nothing and say nothing.
			if ($iScanned > 0 && microtime(true) >= $fDeadline) {
				$bTruncated = true;
				break;
			}

			$iScanned++;
			$aResults = array_merge(
				$aResults,
				self::matchesIn($sClass, $aNeedles, $limit - count($aResults), $output_fields)
			);
		}

		return ToolOutput::Json([
			'search'           => $name,
			'needles'          => $aNeedles,
			'class'            => $class,
			'total'            => count($aResults),
			'limit'            => $limit,
			// Both of these mean "there may be more": the cap was reached, or
			// the scan ran out of time with classes left. A caller told neither
			// reads an empty tail as an answer.
			'truncated'        => $bTruncated,
			'classes_searched' => $iScanned,
			'classes_total'    => count($aClasses),
			'objects'          => $aResults,
		]);
	}

	/**
	 * The words to match, iTop's way.
	 *
	 * A double-quoted string is one needle, anything else is split on blanks
	 * and matched as AND, and a word below the instance's minimum is dropped -
	 * the same three rules the console applies, so that the same text typed in
	 * both places searches for the same thing.
	 *
	 * @return array<int, string>
	 *
	 * @throws ToolCallException When nothing long enough is left to search on.
	 */
	private static function needles(string $sText): array
	{
		$sText = trim($sText);
		if ($sText === '') {
			throw new ToolCallException('Nothing to search for: name is empty.');
		}

		if (preg_match('/^"(.*)"$/', $sText, $aMatches) === 1) {
			$aNeedles = [trim($aMatches[1])];
		} else {
			$aNeedles = array_values(array_filter(preg_split('/\s+/', $sText) ?: [], static fn (string $s): bool => $s !== ''));
		}

		$iMin     = self::needleMin();
		$aTooShort = [];
		$aKept     = [];

		foreach ($aNeedles as $sNeedle) {
			if (mb_strlen($sNeedle) < $iMin) {
				$aTooShort[] = $sNeedle;
				continue;
			}
			$aKept[] = $sNeedle;
		}

		if (empty($aKept)) {
			throw new ToolCallException(sprintf(
				'Nothing to search for: %s shorter than %d characters, which is this instance\'s minimum. Search for a longer word.',
				empty($aTooShort) ? 'the text is' : 'every word given is',
				$iMin
			));
		}

		return $aKept;
	}

	/**
	 * The classes to scan, in the order they are scanned.
	 *
	 * No class the caller cannot read, and abstract classes dropped because
	 * every one of their instances is an instance of a leaf that is in the list
	 * anyway.
	 *
	 * @return array<int, string>
	 *
	 * @throws ToolCallException When the named class is unknown, or not this caller's to see.
	 */
	private static function searchableClasses(string $sClass): array
	{
		if ($sClass === '') {
			$aCandidates = MetaModel::GetClasses('searchable');
		} else {
			if (!MetaModel::IsValidClass($sClass)) {
				throw new ToolCallException("Unknown class '{$sClass}'.");
			}
			if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				throw new ToolCallException("Unknown class '{$sClass}'."); // hide that the class exists
			}

			$aCandidates = MetaModel::EnumChildClasses($sClass, ENUM_CHILD_CLASSES_ALL);
		}

		$aClasses = [];
		foreach ($aCandidates as $sCandidate) {
			if (MetaModel::IsAbstract($sCandidate)) {
				continue;
			}
			if (!UserRights::IsActionAllowed($sCandidate, UR_ACTION_READ)) {
				continue;
			}
			// This reads many objects of the class at once, which is the right
			// iTop has a separate action for.
			if (!UserRights::IsActionAllowed($sCandidate, UR_ACTION_BULK_READ)) {
				continue;
			}

			$aClasses[] = $sCandidate;
		}

		sort($aClasses);

		return $aClasses;
	}

	/**
	 * A needle that matches itself, rather than acting as a pattern.
	 *
	 * AddCondition_FullText() wraps the needle in %...% and binds it as a
	 * parameter, so nothing here reaches the query text and this is not a
	 * escaping-for-safety measure. iTop escapes _ inside that method, for the
	 * stated reason that it is a single-character wildcard; % is the
	 * many-character one and is left alone, so a search for "100%" or "50% off"
	 * currently matches every object of every readable class.
	 *
	 * For the console that is a curiosity a human notices immediately. Here the
	 * caller is a model, which cannot see that its needle was a pattern: it gets
	 * a full page of unrelated objects and no signal that they are unrelated, so
	 * it reports them as matches. A wrong answer delivered confidently is worse
	 * than an empty one.
	 *
	 * A deliberate divergence from the console, then, and the only one this tool
	 * makes. Anyone wanting pattern matching has core_object_search_by_oql,
	 * where writing LIKE is an explicit act.
	 *
	 * Escaped before the call rather than after, because iTop escapes _ on the
	 * needle it is handed; a backslash introduced here carries no _ of its own,
	 * so the two compose.
	 */
	private static function literal(string $sNeedle): string
	{
		return str_replace('%', '\\%', $sNeedle);
	}

	/**
	 * The objects of one class that match, checked one by one.
	 *
	 * @param array<int, string> $aNeedles
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function matchesIn(string $sClass, array $aNeedles, int $iRoom, string $sOutputFields): array
	{
		try {
			$oFilter = new DBObjectSearch($sClass);
			foreach ($aNeedles as $sNeedle) {
				$oFilter->AddCondition_FullText(self::literal($sNeedle));
			}
			$oFilter->SetShowObsoleteData(utils::ShowObsoleteData());

			// Asking for more than there is room for would be paid in rows read
			// and thrown away, once per class.
			$oSet = new DBObjectSet($oFilter, [], [], null, $iRoom, 0);

			if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ, $oSet)) {
				return []; // hides objects that the caller should not see
			}

			$aFields  = ObjectSerializer::ParseFieldList($sClass, $sOutputFields);
			$aMatches = [];

			while ($oObject = $oSet->Fetch()) {
				// One object is an instance of every class in its ancestry, and
				// every one of those classes is scanned in turn. Keeping only
				// the leaves is what stops a Server being reported again as a
				// FunctionalCI - iTop's global search draws the same line.
				if (get_class($oObject) !== $sClass) {
					continue;
				}

				// core_object_get's check, per object: object-level rights
				// answer "depends" for a set and yes or no for one object, and
				// a search that skipped it would report objects that the tool
				// reading them refuses to open.
				$oOneRow = new DBObjectSet(ObjectQuery::ById($sClass, (int)$oObject->GetKey()));
				if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ, $oOneRow)) {
					continue; // hide that the object exists
				}

				$aMatches[] = ['class' => $sClass] + ObjectSerializer::Serialize($oObject, $sClass, $aFields);
			}

			return $aMatches;
		} catch (\Exception $e) {
			// One class that cannot be searched - a broken attribute, a table
			// out of step with the datamodel - is not a reason to answer
			// nothing for the other three hundred.
			return [];
		}
	}

	private static function needleMin(): int
	{
		$iMin = (int)MetaModel::GetConfig()->Get(self::NEEDLE_MIN_SETTING);

		return $iMin > 0 ? $iMin : self::NEEDLE_MIN_DEFAULT;
	}

	private static function chunkDuration(): float
	{
		$fDuration = (float)MetaModel::GetConfig()->Get(self::CHUNK_DURATION_SETTING);

		return $fDuration > 0 ? $fDuration : self::CHUNK_DURATION_DEFAULT;
	}
}
