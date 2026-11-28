<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Service\Read\NameSuggestion;
use Altioo\iTop\Extension\MCP\Api\AbstractObjectSearch;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\FilterDate;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\JsonPayload;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Read\AttributeDescription;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Read\UntrustedMarker;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use AttributeBoolean;
use AttributeDate;
use AttributeDateTime;
use AttributeEnum;
use AttributeExternalKey;
use AttributeFinalClass;
use AttributeInteger;
use AttributeString;
use CMDBSource;
use DBObjectSearch;
use DBObjectSet;
use DBSearch;
use FieldExpression;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;
use Throwable;
use UserRights;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;

/**
 * How many objects match, and how many per value of one or two attributes.
 *
 * "Open tickets per team" used to mean paging every ticket. Here one call
 * answers it, with a count per value and nothing else about the objects.
 *
 * **One tool in both modes** (doc/adr/0006): the console names a class and
 * filters or an OQL query, the portal names one of its lists, and each is
 * answered by the rules of its own side. A count is not a lesser read. A
 * count by a value an account may not read is an oracle for it, so a group
 * is only offered by an attribute that the account may read, in the same
 * words as an attribute that does not exist; the label of an external key is
 * given only where its target is readable, and the id alone otherwise.
 *
 * Totals are counted by the database before object-level rights remove rows
 * from a page, as {@see AbstractObjectSearch} explains for `total`, so a count
 * can exceed what the account could list. The answer says so.
 *
 * @internal
 * @since 1.3.0
 */
class ObjectCount extends AbstractObjectSearch implements PortalAware
{
	/** Groups an answer carries; one more is asked for, to know there were more. */
	public const MAX_GROUPS = 100;

	/** Attributes one count may be grouped by. */
	public const MAX_GROUP_BY = 2;

	/**
	 * What a group may be made of. Exactly these types, and not what extends
	 * them: a value of free text, a document or a computed field from another
	 * object is either an unbounded set of groups or a way to read a column
	 * the caller may not.
	 *
	 * @var array<int, class-string>
	 */
	private const GROUPABLE = [
		AttributeEnum::class,
		AttributeString::class,
		AttributeInteger::class,
		AttributeBoolean::class,
		AttributeExternalKey::class,
		AttributeDate::class,
		AttributeDateTime::class,
		AttributeFinalClass::class,
	];

	#[\Override]
	public function getNamespace(): string
	{
		return 'core';
	}

	#[\Override]
	public function getToolset(): string
	{
		return 'objects';
	}

	#[\Override]
	protected function defaultTitle(): string
	{
		return 'Count Objects';
	}

	#[\Override]
	public function getDescription(): ?string
	{
		if (PortalMode::Current() !== null) {
			return 'Count the rows of one of the portal\'s lists, and optionally per value of one or two of the columns it shows. A number without paging every row: list "ongoing-tickets-for-portal-user" with group_by ["status"] is open requests per status. A column only some kinds of row have (a ticket list\'s status or priority) is counted kind by kind: each group names its class, and the total is of the kinds counted. Call core_class_list for the lists; core_portal_list rows show their columns.';
		}

		return 'Count the objects of a class that match, and optionally per value of one or two attributes: "open tickets per team" without paging every ticket. Give class and filters, or an OQL query. Only attributes you may read, of simple types, can be grouped by.';
	}

	#[\Override]
	public function getInputSchema(): ?array
	{
		$aGroupBy = [
			'group_by' => [
				'type'        => 'array',
				'description' => 'Attribute codes to count per value of, at most '.self::MAX_GROUP_BY.'. Empty for one total.',
				'items'       => ['type' => 'string'],
				'maxItems'    => self::MAX_GROUP_BY,
				'default'     => [],
			],
		];

		if (PortalMode::Current() !== null) {
			return [
				'type'       => 'object',
				'properties' => [
					'list'      => [
						'type'        => 'string',
						'description' => 'A list id from core_class_list.',
					],
					'tab'       => [
						'type'        => 'string',
						'description' => 'A tab of the list, as in core_portal_list. Empty counts every tab.',
						'default'     => '',
					],
					'parent_id' => [
						'type'        => 'integer',
						'description' => 'For a browse level: the row of the level above whose children to count, as in core_portal_list. 0 for none.',
						'default'     => 0,
					],
				] + $aGroupBy,
				'required'   => ['list'],
			];
		}

		return [
			'type'       => 'object',
			'properties' => [
				'class'   => [
					'type'        => 'string',
					'description' => 'iTop class name, from core_class_list. A parent class counts its children too. Not with oql.',
				],
				'filters' => [
					'type'                 => JsonPayload::MAP_TYPE,
					'description'          => 'Attribute code => value, combined with AND, each an equality. Omit for the whole class; an empty one is {}, never [].',
					'additionalProperties' => true,
					'default'              => new \stdClass(),
				],
				'oql'     => [
					'type'        => 'string',
					'description' => 'An OQL query, instead of class and filters.',
					'default'     => '',
				],
			] + $aGroupBy + self::archivedSchemaProperty(),
		];
	}

	/**
	 * @param string $class     Console: the class to count.
	 * @param array  $filters   Console: attribute code => value.
	 * @param string $oql       Console: an OQL query, instead of class and filters.
	 * @param array  $group_by  Attribute codes to count per value of, at most two.
	 * @param string $archived  Console: 'exclude' (default), 'include' or 'only'.
	 * @param string $list      Portal: the list to count.
	 * @param string $tab       Portal: a tab of the list.
	 * @param int    $parent_id Portal: a browse level's parent row.
	 *
	 * @return mixed The class, the total, the groups, whether there were more, and a note
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When what is asked cannot be answered, in the words of an attribute or class that does not exist.
	 */
	public static function execute(
		string $class = '',
		array  $filters = [],
		string $oql = '',
		array  $group_by = [],
		string $archived = self::DEFAULT_ARCHIVED,
		string $list = '',
		string $tab = '',
		int    $parent_id = 0,
	): mixed
	{
		$aGroupBy = self::groupBy($group_by);

		try {
			if (PortalMode::Current() !== null) {
				return self::inPortal($list, $tab, $parent_id, $aGroupBy);
			}

			return self::inConsole($class, $filters, trim($oql), $aGroupBy, $archived);
		} catch (ToolCallException $oException) {
			throw $oException;
		} catch (\OQLException $oException) {
			throw ToolRefusal::Because('Invalid filter condition. '.$oException->getMessage());
		} catch (Throwable $oException) {
			throw ToolRefusal::Because(ErrorReport::OpaqueFailure('Failed to count', $oException));
		}
	}

	/**
	 * @param array<int, mixed> $aGroupBy
	 *
	 * @return array<int, string>
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal)
	 */
	private static function groupBy(array $aGroupBy): array
	{
		if (count($aGroupBy) > self::MAX_GROUP_BY) {
			throw ToolRefusal::Because('At most '.self::MAX_GROUP_BY.' attributes can be counted per value of.');
		}
		$aCodes = [];
		foreach ($aGroupBy as $mCode) {
			if (!is_string($mCode)) {
				throw ToolRefusal::Because('group_by is a list of attribute codes.');
			}
			$aCodes[] = trim($mCode);
		}

		return array_values(array_unique($aCodes));
	}

	/**
	 * @param array<string, mixed> $aFilters
	 * @param array<int, string>   $aGroupBy
	 *
	 * @return mixed
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal)
	 */
	private static function inConsole(string $sClass, array $aFilters, string $sOql, array $aGroupBy, string $sArchived): mixed
	{
		if ($sOql !== '') {
			if ($sClass !== '' || $aFilters !== []) {
				throw ToolRefusal::Because('Give class and filters, or oql, not both.');
			}
			ObjectQuery::CheckQuery($sOql);
			$sUnproved = ExclusionSearches::OqlRefusal($sOql);
			if ($sUnproved !== null) {
				throw ToolRefusal::Because($sUnproved);
			}
			$oSearch = DBObjectSearch::FromOQL($sOql);
			$sClass = $oSearch->GetClass();
		} else {
			if (!ExclusionPolicy::Serves($sClass)) {
				throw ToolRefusal::Because(ClassRefusal::Unreadable($sClass));
			}
			$oSearch = DBObjectSearch::FromOQL("SELECT {$sClass}");
		}

		// One answer for every reason a class may not be counted: reserved, not
		// readable, no bulk read. A refusal that said which would say that the
		// class exists, and what is hidden about it.
		if (ChangeLogReservation::IsReserved($sClass)
			|| !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)
			|| !UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_READ)) {
			throw ToolRefusal::Because(ClassRefusal::Unreadable($sClass));
		}

		// A join or a sub-query is asked like the selected class, in the same words (ADR 0023).
		if ($sOql !== '') {
			foreach (ExclusionSearches::ClassesNamed($sOql) as $sNamed) {
				if (ChangeLogReservation::IsReserved($sNamed)
					|| !UserRights::IsActionAllowed($sNamed, UR_ACTION_READ)
					|| !UserRights::IsActionAllowed($sNamed, UR_ACTION_BULK_READ)) {
					throw ToolRefusal::Because(ClassRefusal::Unreadable($sNamed));
				}
			}
		}

		self::applyArchived($oSearch, $sClass, $sArchived);

		if ($sOql === '') {
			foreach ($aFilters as $sAttCode => $value) {
				// A count under a condition on a value the account may not read is
				// that value, read: the same gate as a group, minus the type.
				if (!self::mayRead($sClass, (string)$sAttCode) || !is_scalar($value)) {
					throw ToolRefusal::Because("Unknown attribute '{$sAttCode}' on class '{$sClass}'.".NameSuggestion::ForAttribute($sClass, (string)$sAttCode, true));
				}
				$oAttDef = MetaModel::GetAttributeDef($sClass, (string)$sAttCode);
				$sRefusal = AttributeDescription::FilterRefusal($oAttDef, (string)$sAttCode, $value)
					?? FilterDate::Refusal($oAttDef, (string)$sAttCode, $value);
				if ($sRefusal !== null) {
					throw ToolRefusal::Because($sRefusal);
				}
				try {
					$oSearch->AddCondition((string)$sAttCode, FilterDate::ForFilter($oAttDef, AttributeDescription::ForFilter($oAttDef, $value)), '=');
				} catch (\CoreException) {
					// iTop's own refusal of a code its search cannot use (an image, say): the caller's mistake.
					throw ToolRefusal::Because("Attribute '{$sAttCode}' on class '{$sClass}' cannot be used as a filter.");
				}
			}
		}

		foreach ($aGroupBy as $sAttCode) {
			if (!self::mayGroupBy($sClass, $sAttCode)) {
				throw ToolRefusal::Because("Unknown attribute '{$sAttCode}' on class '{$sClass}'.".NameSuggestion::ForAttribute($sClass, $sAttCode, true));
			}
		}

		return self::answer(
			$sClass,
			ExclusionSearches::Narrowed($oSearch, $sClass),
			$aGroupBy,
			static fn (string $sTarget, int $iId): ?string => self::consoleLabel($sTarget, $iId),
			null,
			['class' => $sClass]
		);
	}

	/**
	 * @param array<int, string> $aGroupBy
	 *
	 * @return mixed
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal)
	 */
	private static function inPortal(string $sList, string $sTab, int $iParent, array $aGroupBy): mixed
	{
		$aResolved = PortalList::Resolve($sList, $sTab, $iParent);
		$oPolicy = $aResolved['policy'];
		$sClass = $aResolved['class'];
		/** @var array<int, string> $aColumns */
		$aColumns = $aResolved['list']['columns'];

		/** @var array<int, string> $aBelow Columns the list shows that its class has not, and some of its kinds of row have. */
		$aBelow = [];
		foreach ($aGroupBy as $sAttCode) {
			// Only what the list shows: a column the portal hides is a column
			// a count must not split by, and it is refused as one that is not
			// there. The attribute rules apply on top.
			if (!in_array($sAttCode, $aColumns, true)) {
				throw ToolRefusal::Because("Unknown attribute '{$sAttCode}' on list '".trim($sList)."'.");
			}
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				// Shown by the list, so no secret: a column of a subclass, counted class by class below.
				$aBelow[] = $sAttCode;
				continue;
			}
			if (!self::mayGroupBy($sClass, $sAttCode)) {
				throw ToolRefusal::Because("Unknown attribute '{$sAttCode}' on list '".trim($sList)."'.");
			}
		}

		// Decided before the query is looked at, so that a column no kind of row can be counted by is refused
		// in the same words whether or not the list holds anything for this user.
		$aKinds = $aBelow === [] ? [] : self::kindsToCount($oPolicy, trim($sList), $sClass, $aGroupBy, $aBelow);

		$oQuery = $aResolved['query'];
		if ($oQuery === null) {
			return ToolOutput::Json(self::emptyAnswer($sClass, ['list' => trim($sList)]));
		}

		if ($aBelow !== []) {
			return self::answerPerClass($oPolicy, trim($sList), $sClass, $oQuery, $aGroupBy, $aKinds);
		}

		return self::answer(
			$sClass,
			$oQuery,
			$aGroupBy,
			static fn (string $sTarget, int $iId): ?string => self::portalLabel($oPolicy, $sTarget, $iId),
			trim($sList),
			['list' => trim($sList), 'class' => $sClass]
		);
	}

	/**
	 * The classes above under the names the running iTop gives its objects.
	 *
	 * iTop 3.3 namespaced its attribute classes and kept the old names as
	 * aliases, so get_class() answers the real name and a comparison with the
	 * alias never matches; reflection resolves one to the other, on every
	 * version.
	 *
	 * @return array<int, string>
	 */
	private static function groupableClasses(): array
	{
		static $aResolved = null;
		if ($aResolved === null) {
			$aResolved = [];
			foreach (self::GROUPABLE as $sClass) {
				$aResolved[] = (new \ReflectionClass($sClass))->getName();
			}
		}

		return $aResolved;
	}

	/**
	 * Whether an attribute may be counted per value of: it exists, it is a
	 * simple type, it is not a secret, and the account may read it whatever
	 * the object (a grade of "depends" cannot be answered for a group).
	 */
	private static function mayGroupBy(string $sClass, string $sAttCode): bool
	{
		return self::mayRead($sClass, $sAttCode)
			&& in_array(get_class(MetaModel::GetAttributeDef($sClass, $sAttCode)), self::groupableClasses(), true);
	}

	/**
	 * Whether a count may look at an attribute at all: it exists, it is not a
	 * secret, and the account may read it whatever the object.
	 */
	private static function mayRead(string $sClass, string $sAttCode): bool
	{
		if ($sAttCode === '' || !MetaModel::IsValidAttCode($sClass, $sAttCode) || ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode)) {
			return false;
		}
		if (ObjectSerializer::IsSensitive(MetaModel::GetAttributeDef($sClass, $sAttCode), $sClass)) {
			return false;
		}

		return UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_YES;
	}

	/**
	 * @param array<int, string>                   $aGroupBy
	 * @param callable(string, int): ?string       $fLabelOfKey The label of an external key's target, or null when it may not be shown.
	 * @param array<string, mixed>                 $aWho        What the answer says it counted.
	 *
	 * @return mixed
	 */
	private static function answer(string $sClass, DBSearch|DBObjectSearch $oSearch, array $aGroupBy, callable $fLabelOfKey, ?string $sList, array $aWho): mixed
	{
		$oSet = new DBObjectSet($oSearch);
		$iTotal = $oSet->Count();
		$aGroups = [];
		$bTruncated = false;

		if ($aGroupBy !== []) {
			$aLabels = [];
			$aGroups = self::groupsOf($sClass, $oSearch, $aGroupBy, $fLabelOfKey, $aLabels);
			if (count($aGroups) > self::MAX_GROUPS) {
				$bTruncated = true;
				$aGroups = array_slice($aGroups, 0, self::MAX_GROUPS);
			}
		}

		$aAnswer = $aWho + [
			'total'     => $iTotal,
			'groups'    => $aGroups,
			'truncated' => $bTruncated,
			'note'      => 'Counted by the database before object-level rights remove rows, so a count can exceed what you could list; it never includes an object outside your organisations.'
				.($bTruncated ? ' Only the '.self::MAX_GROUPS.' largest groups are listed.' : ''),
		];

		// Labels are the objects' own text.
		if ($aGroupBy !== []) {
			$aAnswer['untrusted_text'] = ['fields' => ['groups[].label'], 'note' => UntrustedMarker::UNTRUSTED_NOTICE];
		}

		return ToolOutput::Json($aAnswer);
	}

	/**
	 * The groups of one search of one class, largest first, at most one more than an answer carries so
	 * that the caller can tell there were more.
	 *
	 * @param array<int, string>             $aGroupBy
	 * @param callable(string, int): ?string $fLabelOfKey
	 * @param array<string, string|null>     $aLabels    Labels of external keys' targets already asked for.
	 *
	 * @return array<int, array{value: array<string, mixed>, label: array<string, ?string>, count: int}>
	 */
	private static function groupsOf(string $sClass, DBSearch|DBObjectSearch $oSearch, array $aGroupBy, callable $fLabelOfKey, array &$aLabels): array
	{
		$sAlias = $oSearch->GetClassAlias();
		$aExpr = [];
		foreach ($aGroupBy as $i => $sAttCode) {
			$aExpr['g'.$i] = new FieldExpression($sAttCode, $sAlias);
		}
		$sSql = $oSearch->MakeGroupByQuery([], $aExpr, false, [], ['_itop_count_' => false], self::MAX_GROUPS + 1);
		$oResult = CMDBSource::Query($sSql);
		$aGroups = [];
		while ($aRow = $oResult->fetch_assoc()) {
			$aValue = [];
			$aLabel = [];
			foreach ($aGroupBy as $i => $sAttCode) {
				$mValue = $aRow['g'.$i];
				$aValue[$sAttCode] = self::plain(MetaModel::GetAttributeDef($sClass, $sAttCode), $mValue);
				$aLabel[$sAttCode] = self::labelOf($sClass, $sAttCode, $mValue, $fLabelOfKey, $aLabels);
			}
			$aGroups[] = ['value' => $aValue, 'label' => $aLabel, 'count' => (int)$aRow['_itop_count_']];
		}

		return $aGroups;
	}

	/**
	 * The kinds of row (the concrete classes under the list's class) a list is counted by, when it is
	 * counted per a column that its class has not: those the account is served, may read and the portal's
	 * scopes can hold, whose attributes are all served and readable.
	 *
	 * A kind that fails any of that is left out and nothing says so; the answer's note says only that the
	 * total is of the kinds counted. A refusal names the columns, which the list shows, and never a class
	 * that was left out: it is decided from the kinds that pass the checks, by the schema.
	 *
	 * @param array<int, string> $aGroupBy All the columns asked for.
	 * @param array<int, string> $aBelow   Those the list's class has not.
	 *
	 * @return array<int, string> Class names, in name order.
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal)
	 */
	private static function kindsToCount(PortalPolicy $oPolicy, string $sList, string $sClass, array $aGroupBy, array $aBelow): array
	{
		$aServed = [];
		foreach (MetaModel::EnumChildClasses($sClass, ENUM_CHILD_CLASSES_ALL) as $sKind) {
			$sKind = (string)$sKind;
			if (MetaModel::IsAbstract($sKind)
				|| !ExclusionPolicy::Serves($sKind)
				|| ChangeLogReservation::IsReserved($sKind)
				|| UserRights::IsActionAllowed($sKind, UR_ACTION_READ) === UR_ALLOWED_NO
				|| $oPolicy->ReadSet($sKind) === null) {
				continue;
			}
			$aServed[] = $sKind;
		}
		sort($aServed);

		// The kinds that have a column, by the schema: the same for every account.
		$aHaving = [];
		foreach ($aBelow as $sAttCode) {
			$aHaving[$sAttCode] = array_values(array_filter($aServed, static fn (string $sKind): bool => MetaModel::IsValidAttCode($sKind, $sAttCode)));
			if ($aHaving[$sAttCode] === []) {
				throw ToolRefusal::Because("Unknown attribute '{$sAttCode}' on list '{$sList}'.");
			}
		}
		foreach ($aBelow as $sAttCode) {
			if ($aHaving[$sAttCode] !== $aHaving[$aBelow[0]]) {
				throw ToolRefusal::Because("Cannot count list '{$sList}' per ".implode(' and ', array_map(static fn (string $s): string => "'{$s}'", $aBelow)).' together: not every kind of row in it has all of them. Count per one of them.');
			}
		}

		// Then what this account may count by, kind by kind.
		$aKinds = array_values(array_filter($aHaving[$aBelow[0]], static function (string $sKind) use ($aGroupBy): bool {
			foreach ($aGroupBy as $sAttCode) {
				if (!self::mayGroupBy($sKind, $sAttCode)) {
					return false;
				}
			}

			return true;
		}));
		if ($aKinds === []) {
			throw ToolRefusal::Because("Unknown attribute '{$aBelow[0]}' on list '{$sList}'.");
		}

		return $aKinds;
	}

	/**
	 * A list counted kind of row by kind of row, for a column that only some kinds have (the `status` of
	 * a list of tickets).
	 *
	 * Each kind is the list's own query, scopes and tab and parent included, narrowed to that class: never
	 * a wider one. The groups carry their class, because the same code means a different state in a
	 * different class and is never merged across them. The total is the sum of the kinds counted; the
	 * list's own count is not given, so that what a left-out kind holds cannot be told from it.
	 *
	 * @param array<int, string> $aGroupBy
	 * @param array<int, string> $aKinds   As {@see kindsToCount()} answers.
	 *
	 * @return mixed
	 */
	private static function answerPerClass(PortalPolicy $oPolicy, string $sList, string $sClass, DBSearch|DBObjectSearch $oQuery, array $aGroupBy, array $aKinds): mixed
	{
		$fLabelOfKey = static fn (string $sTarget, int $iId): ?string => self::portalLabel($oPolicy, $sTarget, $iId);
		$iTotal = 0;
		$aGroups = [];
		$aLabels = [];
		foreach ($aKinds as $sKind) {
			$oSearch = $oQuery->DeepClone();
			$oSearch->ChangeClass($sKind);
			if (MetaModel::EnumChildClasses($sKind, ENUM_CHILD_CLASSES_EXCLUDETOP) !== []) {
				// Its own rows, not those of its subclasses, which are counted as their own kind.
				$oSearch = $oSearch->Intersect(DBObjectSearch::FromOQL("SELECT {$sKind} WHERE finalclass = '{$sKind}'"));
			}
			$iTotal += (new DBObjectSet($oSearch))->Count();
			foreach (self::groupsOf($sKind, $oSearch, $aGroupBy, $fLabelOfKey, $aLabels) as $aGroup) {
				$aGroups[] = ['class' => $sKind] + $aGroup;
			}
		}

		// Largest first across kinds, and the limit is the answer's, not each kind's.
		usort($aGroups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
		$bTruncated = count($aGroups) > self::MAX_GROUPS;
		$aGroups = array_slice($aGroups, 0, self::MAX_GROUPS);

		return ToolOutput::Json([
			'list'      => $sList,
			'class'     => $sClass,
			'total'     => $iTotal,
			'groups'    => $aGroups,
			'truncated' => $bTruncated,
			'note'      => 'Counted kind of row by kind of row, for a column that only some kinds have: each group says its class, because the same code means a different state in a different class. The total is of the kinds counted and can be less than the list\'s own count. Counted by the database before object-level rights remove rows; it never includes an object outside your organisations.'
				.($bTruncated ? ' Only the '.self::MAX_GROUPS.' largest groups are listed.' : ''),
			'untrusted_text' => ['fields' => ['groups[].label'], 'note' => UntrustedMarker::UNTRUSTED_NOTICE],
		]);
	}

	/**
	 * @param array<string, mixed> $aWho
	 *
	 * @return array<string, mixed>
	 */
	private static function emptyAnswer(string $sClass, array $aWho): array
	{
		return $aWho + ['class' => $sClass, 'total' => 0, 'groups' => [], 'truncated' => false, 'note' => 'Nothing to count.'];
	}

	/**
	 * The value as a client reads it: an id as an integer, a flag as a boolean, null as null.
	 *
	 * Psalm runs with no iTop checkout to say what extends what (psalm.xml).
	 *
	 * @psalm-suppress TypeDoesNotContainType
	 * @psalm-suppress RedundantCondition
	 */
	private static function plain(\AttributeDefinition $oAttDef, mixed $mValue): mixed
	{
		if ($mValue === null) {
			return null;
		}
		if ($oAttDef instanceof AttributeExternalKey || $oAttDef instanceof AttributeInteger) {
			return (int)$mValue;
		}
		if ($oAttDef instanceof AttributeBoolean) {
			return (bool)$mValue;
		}

		return (string)$mValue;
	}

	/**
	 * What to call a value: the label iTop gives an enum value or a class, the
	 * name of an external key's target where the caller may see it, and
	 * otherwise nothing - the id says it, and the label would be a read.
	 *
	 * @param callable(string, int): ?string $fLabelOfKey
	 * @param array<string, string|null>     $aCache
	 */
	private static function labelOf(string $sClass, string $sAttCode, mixed $mValue, callable $fLabelOfKey, array &$aCache): ?string
	{
		if ($mValue === null || $mValue === '') {
			return null;
		}
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if ($oAttDef instanceof AttributeExternalKey) {
			$iId = (int)$mValue;
			if ($iId < 1) {
				return null;
			}
			$sTarget = $oAttDef->GetTargetClass();
			$sKey = $sTarget.'::'.$iId;
			if (!array_key_exists($sKey, $aCache)) {
				$aCache[$sKey] = $fLabelOfKey($sTarget, $iId);
			}

			return $aCache[$sKey];
		}
		if ($oAttDef instanceof AttributeEnum) {
			return (string)$oAttDef->GetValueLabel((string)$mValue);
		}
		if ($oAttDef instanceof AttributeFinalClass) {
			return MetaModel::IsValidClass((string)$mValue) ? MetaModel::GetName((string)$mValue) : null;
		}

		return null;
	}

	/** The console's: a target the account may read, through the same query a read by id runs. */
	private static function consoleLabel(string $sTarget, int $iId): ?string
	{
		if (!ExclusionPolicy::Serves($sTarget)
			|| ExclusionPolicy::ExcludesAttribute($sTarget, 'friendlyname')
			|| ChangeLogReservation::IsReserved($sTarget)
			|| !UserRights::IsActionAllowed($sTarget, UR_ACTION_READ)) {
			return null;
		}
		$oObject = (new DBObjectSet(ObjectQuery::ById($sTarget, $iId)))->Fetch();
		if ($oObject === null) {
			return null;
		}

		// The name is the object's text, handed to the model: a read, recorded as every other read is (DO-1).
		ReadSummary::Note(get_class($oObject), (int)$oObject->GetKey());

		return (string)$oObject->GetName();
	}

	/** The portal's: a target its scopes show this user, which UserRights does not decide. */
	private static function portalLabel(PortalPolicy $oPolicy, string $sTarget, int $iId): ?string
	{
		if (!ExclusionPolicy::Serves($sTarget) || ExclusionPolicy::ExcludesAttribute($sTarget, 'friendlyname') || ChangeLogReservation::IsReserved($sTarget)) {
			return null;
		}
		$oQuery = $oPolicy->ReadSet($sTarget);
		if ($oQuery === null) {
			return null;
		}
		$oQuery = $oPolicy->Bounded($oQuery, $sTarget);
		if ($oQuery === null) {
			return null;
		}
		$oQuery->AddCondition('id', $iId, '=');
		$oObject = (new DBObjectSet($oQuery))->Fetch();
		if ($oObject === null) {
			return null;
		}

		ReadSummary::Note(get_class($oObject), (int)$oObject->GetKey());

		return (string)$oObject->GetName();
	}
}
