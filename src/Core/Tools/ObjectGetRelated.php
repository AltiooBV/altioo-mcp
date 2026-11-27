<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\ObjectId;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Read\UntrustedMarker;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use DBObjectSet;
use MetaModel;
use UserRights;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;

/**
 * Get objects related to a given iTop object via a named relation.
 *
 * Equivalent to the REST core/get_related operation.
 *
 * Any relation code the class has in the datamodel, in the directions it has it; core_class_schema lists them.
 *
 * Example:
 *   class:     "Server"
 *   id:        1
 *   relation:  "impacts"
 *   direction: "down"
 *   depth:     4
 *
 * @internal
 * @since 1.0.0
 */
class ObjectGetRelated extends AbstractMCPTool
{
	const DEFAULT_DEPTH = 5;
	const MIN_DEPTH = 1;
	const MAX_DEPTH = 99;

	const DIRECTION_DOWN = 'down';
	const DIRECTION_UP = 'up';

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Walking the relation graph, which is a different question from reading an object and a much heavier one. */
	public function getToolset(): string
	{
		return 'relations';
	}


	protected function defaultTitle(): string
	{
		return 'Get Related Objects';
	}

	public function getDescription(): ?string
	{
		return 'Walk a relation from one iTop object, e.g. impact analysis on CIs. The codes depend on the datamodel: core_class_schema lists the class\'s codes, their directions ("down", "up") and what each means; '
			.'others are refused with the valid ones. '
			.'The answer is a graph: "objects" maps keys like Server::12 to class, id and friendlyname, "relations" is a list of {from, to} over those keys and the source, "summary" counts per class ("Server=2"). '
			.'Each hop is one level of depth. The source is not in objects or summary, an object reached twice appears once, and an edge back to the source is a loop. '
			.'"truncated" is true when a class was held back for want of bulk read on it, and "withheld" then names it: what came back is what you may see, not what exists. Do not report an impact analysis as complete while truncated is true.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'Get related iTop objects',
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'     => [
					'type'        => 'string',
					'description' => 'iTop class of the source object (e.g. Server, ApplicationSolution).',
				],
				'id'        => [
					'type'        => 'integer',
					'description' => 'ID of the source object.',
					'minimum'     => 1,
				],
				'relation'  => [
					'type'        => 'string',
					'description' => 'Relation code to follow, one core_class_schema lists for this class: whatever the datamodel declares. A code the class does not have in the direction asked is refused with the list of the ones it does.',
				],
				'direction' => [
					'type'        => 'string',
					'description' => '"down" follows the relation forward (e.g. what does this CI impact). "up" follows it backward (e.g. what does this CI depend on). Defaults to "down". Direction is part of what a class participates in, not a free choice on top of it: a class may declare a relation in one direction only, and asking for the other is refused even though the relation code is right.',
					'enum'        => [self::DIRECTION_DOWN, self::DIRECTION_UP],
					'default'     => self::DIRECTION_DOWN,
				],
				'depth'     => [
					'type'        => 'integer',
					'description' => 'Maximum recursion depth. Defaults to 5.',
					'minimum'     => self::MIN_DEPTH,
					'maximum'     => self::MAX_DEPTH,
					'default'     => self::DEFAULT_DEPTH,
				],
				'redundancy' => [
					'type'        => 'boolean',
					'description' => 'Whether to take redundancy into account during impact analysis. Defaults to true.',
					'default'     => true,
				],
			],
			'required' => ['class', 'id', 'relation'],
		];
	}

	public static function execute(
		string $class,
		int    $id,
		string $relation,
		string $direction = self::DIRECTION_DOWN,
		int    $depth = self::DEFAULT_DEPTH,
		bool   $redundancy = true,
	): mixed
	{
		if ($id < 1) {
			throw ToolRefusal::Because("Invalid ID. Please specify a valid object ID.");
		}

		if (!in_array($direction, [self::DIRECTION_DOWN, self::DIRECTION_UP], true)) {
			throw ToolRefusal::Because("Invalid direction '{$direction}'. Use 'down' or 'up'.");
		}

		if (!ExclusionPolicy::Serves($class)) {
			throw ToolRefusal::Because(ClassRefusal::Unreadable($class));
		}

		if (ChangeLogReservation::IsReserved($class)) {
			throw ToolRefusal::Because(ChangeLogReservation::Refusal($class));
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw ToolRefusal::Because(ClassRefusal::Unreadable($class)); // exists, but not for this account to read
		}

		if ($depth < self::MIN_DEPTH || $depth > self::MAX_DEPTH) {
			throw ToolRefusal::Because("Invalid depth. Please specify a depth between ".self::MIN_DEPTH." and ".self::MAX_DEPTH.".");
		}
		$iMaxRecursionDepth = MetaModel::GetConfig()->Get('relations_max_depth');
		if ($depth > $iMaxRecursionDepth) {
			throw ToolRefusal::Because("Requested depth of {$depth} exceeds the maximum allowed depth of {$iMaxRecursionDepth}. Please specify a lower depth.");
		}

		// Validate the relation against this class, and in the direction asked
		// for.
		//
		// EnumRelationsEx() takes the class - a relation is declared per class,
		// and one this class has no query for is not a relation it has - and it
		// answers a map keyed by relation code whose value says which
		// directions exist: ['impacts' => ['down' => 'Impacts', 'up' => ...]].
		// Called without the class it raises ArgumentCountError, and read as a
		// list of codes it never matches, because the values are those inner
		// arrays rather than the codes.
		$aValidRelations = MetaModel::EnumRelationsEx($class);
		// A relation that exists here only to reach an excluded class is not one this class has (doc/adr/0012): the
		// schema does not list it, and a walk does not accept it. Nothing changes while nothing is excluded.
		if (ExclusionPolicy::IsActive()) {
			foreach ($aValidRelations as $sRelationCode => $aDirections) {
				foreach (['down' => true, 'up' => false] as $sWay => $bDown) {
					if (isset($aDirections[$sWay]) && ExclusionSearches::RelationQueries($class, (string)$sRelationCode, $bDown) === []) {
						unset($aValidRelations[$sRelationCode][$sWay]);
					}
				}
				if ($aValidRelations[$sRelationCode] === []) {
					unset($aValidRelations[$sRelationCode]);
				}
			}
		}
		if (!isset($aValidRelations[$relation][$direction])) {
			$aAvailable = array_keys(array_filter(
				$aValidRelations,
				static fn(array $aDirections): bool => isset($aDirections[$direction])
			));

			throw ToolRefusal::Because(sprintf(
				"Invalid relation '%s' for %s in direction '%s'. Available: %s.",
				$relation,
				$class,
				$direction,
				$aAvailable === [] ? 'none' : implode(', ', $aAvailable)
			));
		}

		// Check access rights on the specific object before retrieving it, to avoid information leaks about the existence of the object
		$oSearch = ObjectQuery::ById($class, $id);
		$oSet = new DBObjectSet($oSearch);
		// Based on GetRelated - object search manages read access
		if ($oSet->Count() === 0) {
			throw ToolRefusal::Because("Object {$class}::{$id} not found."); // hide that the object exists
		}

		// Fetch() instantiates the leaf from the finalclass column it has
		// already read, so the object in hand names its own class.
		// GetFinalClassName() was a query asking for something that had already
		// arrived. Rewound because the set itself, not the object, is what the
		// relation graph below is built from.
		$oObject = $oSet->Fetch();
		$oSet->Rewind();

		$sFinalClass = get_class($oObject);
		if (ExclusionPolicy::ExcludesClass($sFinalClass)) {
			throw ToolRefusal::Because("Object {$class}::{$id} not found."); // as an object that is not there
		}
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw ToolRefusal::Because("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$oSetFinal = new DBObjectSet(ObjectQuery::ById($sFinalClass, $id));
			// Based on GetRelated - object search manages read access
			if ($oSetFinal->Count() === 0) {
				throw ToolRefusal::Because("Object {$class}::{$id} not found."); // hide that the object exists
			}
			// Walk the relation from the class the object actually is: a
			// relation is declared per class, and the parent's may not be the
			// one that matters here.
			$oSet = $oSetFinal;
		}

		// Build a single-object set as the source for the relation graph.
		//
		// Wrapped because everything below is the ORM's: a relation query that
		// does not match the datamodel it was declared against fails inside
		// iTop, and unwrapped that reaches the caller as the SDK's fixed
		// string - no class, no message, and no reference to find it by. This
		// tool had no handler at all, which is how a call that could never
		// succeed looked the same as one that broke today.
		try {
			if ($direction === self::DIRECTION_DOWN) {
				$oGraph = $oSet->GetRelatedObjectsDown($relation, $depth, $redundancy);
			} else {
				$oGraph = $oSet->GetRelatedObjectsUp($relation, $depth, $redundancy);
			}

			return ToolOutput::Json(self::serializeGraph($oGraph, $sFinalClass.'::'.$id, $direction === self::DIRECTION_UP));
		} catch (ToolCallException $e) {
			// The withheld-class refusal from serializeGraph is this module's
			// own answer and keeps its wording.
			throw $e;
		} catch (\Throwable $e) {
			throw ToolRefusal::Because(ErrorReport::OpaqueFailure(
				"Failed to walk '{$relation}' from {$class}::{$id}",
				$e
			));
		}
	}

	/**
	 * @param string $sRoot The object the walk started from, as `Class::id`, by the class it is.
	 * @param bool   $bUp   Whether the walk went up. iTop marks nodes reached only on the way down
	 *                      (RelationGraph::ComputeRelatedObjectsUp() marks none, and its own display treats
	 *                      every node of an up graph as reached), so on the way up what the graph holds is
	 *                      what the walk reached (review 2026-11-23, B6).
	 */
	private static function serializeGraph(\RelationGraph $oGraph, string $sRoot = '', bool $bUp = false): array
	{
		$aObjects   = [];
		$aRelations = [];
		$aStats     = [];

		// If user rights not applied on graph, force it
		if (!MetaModel::GetConfig()->Get('relations.complete_analysis')) {
			$oGraph = self::ApplyUserRightsOnGraph($oGraph);
		}

		/** @var \RelationObjectNode $oNode */
		foreach ($oGraph->_GetNodes() as $sNodeId => $oNode) {
			if (!$oNode instanceof \RelationObjectNode) {
				continue;
			}

			$oObject = $oNode->GetProperty('object');
			if ($oObject === null) {
				continue;
			}

			$sClass = get_class($oObject);
			$sKey = $sClass.'::'.$oObject->GetKey();

			// The source is not in the answer or in its counts, whichever way the walk went: iTop marks it
			// reached on the way down, and builds an up walk out of it (review 2026-11-23, B10).
			if ($sKey === $sRoot) {
				continue;
			}

			if (!$bUp && !$oNode->GetProperty('is_reached', false)) {
				continue;
			}

			$aObjects[$sKey] = [
				'class'        => $sClass,
				MetaModel::DBGetKey($sClass)           => ObjectId::Normalise($oObject->GetKey()),
				// Not served where a part of the name is excluded (doc/adr/0012, Amendment 3).
				'friendlyname' => ExclusionPolicy::ExcludesAttribute($sClass, 'friendlyname') ? null : $oObject->GetName(),
			];

			// Stats per class
			$aStats[$sClass] = ($aStats[$sClass] ?? 0) + 1;
		}

		// What the operator excluded is not in the graph, and neither is what is reachable only through it
		// (doc/adr/0012): a path through an excluded object is a statement about that object.
		if (ExclusionPolicy::IsActive()) {
			[$aObjects, $aStats] = self::withoutExcluded($oGraph, $aObjects, $sRoot, $bUp);
		}

		$aWithheld = self::classesReadInBulkWithoutTheRight($aStats);
		foreach ($aWithheld as $sWithheldClass) {
			unset($aStats[$sWithheldClass]);
		}
		$aObjects = array_filter(
			$aObjects,
			static fn(array $aObject): bool => !in_array($aObject['class'], $aWithheld, true)
		);

		// This graph has its own node builder, so the serializer never sees
		// these objects. What is returned is what is noted: a withheld class
		// has already gone.
		foreach ($aObjects as $aObject) {
			ReadSummary::Note($aObject['class'], (int)$aObject[MetaModel::DBGetKey($aObject['class'])]);
		}

		/** @var \RelationEdge $oEdge */
		foreach ($oGraph->_GetEdges() as $oEdge) {
			$oSource = $oEdge->GetSourceNode();
			$oSink   = $oEdge->GetSinkNode();

			if (!$oSource instanceof \RelationObjectNode || !$oSink instanceof \RelationObjectNode) {
				continue;
			}

			if (!$bUp && (!$oSource->GetProperty('is_reached', false) || !$oSink->GetProperty('is_reached', false))) {
				continue;
			}

			$oSourceObj = $oSource->GetProperty('object');
			$oSinkObj   = $oSink->GetProperty('object');

			if ($oSourceObj === null || $oSinkObj === null) {
				continue;
			}

			$sFrom = get_class($oSourceObj).'::'.$oSourceObj->GetKey();
			$sTo   = get_class($oSinkObj).'::'.$oSinkObj->GetKey();

			// An edge to an object that was withheld would dangle, and a
			// dangling edge is how a partial graph reads as a complete one.
			// The source is not among the objects and is still named by the edges that leave or reach it:
			// without them the first level of the graph hangs from nothing. The caller named it.
			if ((!isset($aObjects[$sFrom]) && $sFrom !== $sRoot) || (!isset($aObjects[$sTo]) && $sTo !== $sRoot)) {
				continue;
			}

			$aRelations[] = ['from' => $sFrom, 'to' => $sTo];
		}

		$aSummaryParts = [];
		foreach ($aStats as $sClass => $iCount) {
			$aSummaryParts[] = "{$sClass}={$iCount}";
		}

		$aPayload = [
			// An object whether or not anything was reached: an empty PHP array would be sent as [] (review 2026-11-23, S6).
			'objects'   => $aObjects === [] ? new \stdClass() : $aObjects,
			'relations' => $aRelations,
			'summary'   => implode(', ', $aSummaryParts),
			// Always present, and false on the ordinary answer. `withheld` says
			// what was held back and why, but a caller has to look for it to
			// find out there is anything to look for - and a graph quietly
			// missing a class reads as a whole one, which is how "nothing
			// related" reaches a user as fact. A flag beside summary is read by
			// anyone parsing the answer at all.
			'truncated' => $aWithheld !== [],
		];

		// The names are what people typed into the objects.
		$aPayload = UntrustedMarker::MarkRows($aPayload, array_values($aObjects));

		if ($aWithheld !== []) {
			$aPayload['withheld'] = [
				'classes' => $aWithheld,
				'note'    => 'More than one related object of '.implode(' and ', $aWithheld)
					.' was found, which is a bulk read of that class, and this account does not hold'
					.' UR_ACTION_BULK_READ on it. Those objects and the relations touching them are'
					.' not in this answer; objects of every other class are. This is a statement about'
					.' the rights of this account, not about what exists - do not report it as "there'
					.' is nothing related", and do not look for another route to the same objects.',
			];
		}

		return $aPayload;
	}

	/**
	 * The objects reachable from the source without passing through an excluded
	 * one, and the per-class counts of those.
	 *
	 * The walk starts from the object it was asked about, which is named rather than looked for among the nodes:
	 * iTop marks every node of a real graph as reached on the way down, the starting one included, so no node says
	 * "I am the start". On the way up it marks none, so the start is the only seed (ADR 0012 holds on both ways).
	 * A redundancy node holds no data and is walked through; an excluded object is not.
	 *
	 * @param array<string, array<string, mixed>> $aObjects
	 * @param string                              $sRoot    The object the walk started from, as `Class::id`.
	 * @param bool                                $bUp      Whether the walk went up.
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<string, int>}
	 */
	private static function withoutExcluded(\RelationGraph $oGraph, array $aObjects, string $sRoot = '', bool $bUp = false): array
	{
		$fKeyOf = static function ($oNode): ?string {
			if ($oNode instanceof \RelationObjectNode) {
				$oObject = $oNode->GetProperty('object');

				return $oObject === null ? null : get_class($oObject).'::'.$oObject->GetKey();
			}

			return $oNode instanceof \GraphNode ? 'node:'.$oNode->GetId() : null;
		};

		$aClassOf = [];
		$aRoots   = [];
		foreach ($oGraph->_GetNodes() as $oNode) {
			if (!$oNode instanceof \RelationObjectNode || $oNode->GetProperty('object') === null) {
				continue;
			}
			$oObject = $oNode->GetProperty('object');
			$sKey = $fKeyOf($oNode);
			$aClassOf[$sKey] = get_class($oObject);
			if ($sKey === $sRoot || (!$bUp && !$oNode->GetProperty('is_reached', false))) {
				$aRoots[] = $sKey;
			}
		}

		$aNeighbours = [];
		foreach ($oGraph->_GetEdges() as $oEdge) {
			$sFrom = $fKeyOf($oEdge->GetSourceNode());
			$sTo   = $fKeyOf($oEdge->GetSinkNode());
			if ($sFrom === null || $sTo === null) {
				continue;
			}
			$aNeighbours[$sFrom][] = $sTo;
			$aNeighbours[$sTo][]   = $sFrom;
		}

		$aRoots = array_values(array_filter($aRoots, static fn (?string $sKey): bool => $sKey !== null));
		$aSeen  = array_fill_keys($aRoots, true);
		$aQueue = $aRoots;
		while ($aQueue !== []) {
			$sAt = array_shift($aQueue);
			foreach ($aNeighbours[$sAt] ?? [] as $sNext) {
				if (isset($aSeen[$sNext]) || ExclusionPolicy::ExcludesClass($aClassOf[$sNext] ?? '')) {
					continue;
				}
				$aSeen[$sNext] = true;
				$aQueue[] = $sNext;
			}
		}

		$aKept  = [];
		$aStats = [];
		foreach ($aObjects as $sKey => $aObject) {
			if (isset($aSeen[$sKey]) && !ExclusionPolicy::ExcludesClass((string)$aObject['class'])) {
				$aKept[$sKey] = $aObject;
				$aStats[$aObject['class']] = ($aStats[$aObject['class']] ?? 0) + 1;
			}
		}

		return [$aKept, $aStats];
	}

	/**
	 * The classes this walk read in bulk without holding the right to.
	 *
	 * A walk that returns two or more objects of a class has read that class in
	 * bulk, whatever the tool is called. The search tools ask for
	 * UR_ACTION_BULK_READ before they hand back a set, and a relation walk that
	 * did not would be the way around a credential deliberately issued without
	 * it: one reachable object, a relation and a depth, and the caller has what
	 * search refused. iTop's own console gates impact analysis on
	 * UR_ACTION_READ alone, so this is stricter than the console on purpose -
	 * the console is a person clicking one screen, and this is a credential
	 * handed to something that can walk every relation on every object it can
	 * reach.
	 *
	 * Per class, and only past the first object. One related object of a class
	 * is a single read and stays one, which is what keeps the ordinary "what
	 * does this depend on" answer working for a caller holding nothing but
	 * UR_ACTION_READ.
	 *
	 * The class is withheld, not the call. A walk spans classes the caller is
	 * graded differently on, and refusing the whole answer because one of them
	 * needs a right the others do not throws away everything the caller is
	 * entitled to. What must not happen is the silent version: a graph quietly
	 * missing a class reads as a complete one, and an agent reports "nothing
	 * related" as fact - which is what FindByNameRightsTest exists to keep out
	 * of the search tools. So the caller is told, and the edges touching a
	 * withheld object go with it rather than dangling.
	 *
	 * Named, never counted. The names are a statement about this account's
	 * rights on classes it already holds UR_ACTION_READ on - the graph only
	 * ever contained objects the ORM let it see. A count would be the datum the
	 * bulk right withholds, which is the oracle SECURITY.md closes.
	 *
	 * @param array<string, int> $aStats Objects found, counted per class.
	 *
	 * @return array<int, string> Class names, sorted, empty when nothing is withheld.
	 */
	private static function classesReadInBulkWithoutTheRight(array $aStats): array
	{
		$aWithheld = [];

		foreach ($aStats as $sRelatedClass => $iCount) {
			if ($iCount > 1 && !UserRights::IsActionAllowed($sRelatedClass, UR_ACTION_BULK_READ)) {
				$aWithheld[] = $sRelatedClass;
			}
		}

		sort($aWithheld);

		return $aWithheld;
	}

	/**
	 * @return void
	 * @throws \CoreException
	 * @throws \CoreUnexpectedValue
	 * @throws \MySQLException
	 * @throws \OQLException
	 * @throws \SimpleGraphException
	 */
	private static function ApplyUserRightsOnGraph(\RelationGraph $oGraph)
	{
		//The chart is complete. Now we need to control which objects are allowed to the current user.
		if (!UserRights::IsAdministrator()) {
			//First we get all the objects presents in chart in $aArrayTest
			$oIterator = new \RelationTypeIterator($oGraph, 'Node');
			$aArrayTest = [];
			foreach ($oIterator as $oNode) {
				$oObj = $oNode->GetProperty('object');
				if ($oObj) {
					$aArrayTest[get_class($oObj)][$oObj->GetKey()] = $oObj->GetKey();
				}
			}
			//Then for each class, we made a request to control access rights
			// visible objects are removed from $aArrayTest
			foreach ($aArrayTest as $sClass => $aKeys) {
				$oSearch = ObjectQuery::ByIds($sClass, $aKeys);
				$aListId = $oSearch->SelectAttributeToArray('id');
				foreach ($aListId as $aItem) {
					unset($aArrayTest[$sClass][$aItem['id']]);
				}
			}
			//then removes from the graph all objects still present in $aArrayTest
			foreach ($oIterator as $oNode) {
				$oObj = $oNode->GetProperty('object');
				if ($oObj && isset($aArrayTest[get_class($oObj)]) && in_array($oObj->GetKey(), $aArrayTest[get_class($oObj)])) {
					$oGraph->FilterNode($oNode);
				}
			}
		}

		return $oGraph;
	}
}
