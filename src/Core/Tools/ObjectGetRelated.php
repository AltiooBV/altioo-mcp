<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use DBObjectSet;
use MetaModel;
use UserRights;

/**
 * Get objects related to a given iTop object via a named relation.
 *
 * Equivalent to the REST core/get_related operation.
 *
 * The two built-in relations are:
 *   - impacts    : objects that this object impacts (direction: down)
 *   - depends on : objects this object depends on  (direction: up)
 *
 * Example:
 *   class:     "Server"
 *   id:        1
 *   relation:  "impacts"
 *   direction: "down"
 *   depth:     4
 *
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
		return 'Walk a named relation from one iTop object (impacts, depends on, ...): impact analysis and dependency mapping on CIs. '
			.'Relations are declared per class and per direction, so read the "relations" block of core_class_schema for this class first; a code it does not have is refused with the list of the ones it does. '
			.'The answer is a graph, not a tree: "objects" maps "<Class>::<id>" to class, id and friendlyname, "relations" is a flat list of {from, to} over those keys, "summary" counts per class. '
			.'The source object is not in it and an object reached twice appears once. '
			.'"truncated" is true when a class was held back for want of bulk read on it, and "withheld" then names it: what came back is what you may see, not what exists. Do not report an impact analysis as complete while truncated is true.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get related iTop objects',
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
				'class'     => [
					'type'        => 'string',
					'description' => 'iTop class of the source object (e.g. Server, UserRequest).',
				],
				'id'        => [
					'type'        => 'integer',
					'description' => 'ID of the source object.',
					'minimum'     => 1,
				],
				'relation'  => [
					'type'        => 'string',
					'description' => 'Relation code to follow. A stock iTop declares "impacts" and "depends on", but a datamodel may declare others and no class takes part in all of them. Call core_class_schema for this class and read its "relations" block: the keys are the codes valid here. A code the class does not have is refused with the list of the ones it does, for the direction you asked for.',
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
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}

		if (!in_array($direction, [self::DIRECTION_DOWN, self::DIRECTION_UP], true)) {
			throw new ToolCallException("Invalid direction '{$direction}'. Use 'down' or 'up'.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class));
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
		}

		if ($depth < self::MIN_DEPTH || $depth > self::MAX_DEPTH) {
			throw new ToolCallException("Invalid depth. Please specify a depth between ".self::MIN_DEPTH." and ".self::MAX_DEPTH.".");
		}
		$iMaxRecursionDepth = MetaModel::GetConfig()->Get('relations_max_depth');
		if ($depth > $iMaxRecursionDepth) {
			throw new ToolCallException("Requested depth of {$depth} exceeds the maximum allowed depth of {$iMaxRecursionDepth}. Please specify a lower depth.");
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
		if (!isset($aValidRelations[$relation][$direction])) {
			$aAvailable = array_keys(array_filter(
				$aValidRelations,
				static fn(array $aDirections): bool => isset($aDirections[$direction])
			));

			throw new ToolCallException(sprintf(
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
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		// Fetch() instantiates the leaf from the finalclass column it has
		// already read, so the object in hand names its own class.
		// GetFinalClassName() was a query asking for something that had already
		// arrived. Rewound because the set itself, not the object, is what the
		// relation graph below is built from.
		$oObject = $oSet->Fetch();
		$oSet->Rewind();

		$sFinalClass = get_class($oObject);
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$oSetFinal = new DBObjectSet(ObjectQuery::ById($sFinalClass, $id));
			// Based on GetRelated - object search manages read access
			if ($oSetFinal->Count() === 0) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
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

			return ToolOutput::Json(self::serializeGraph($oGraph));
		} catch (ToolCallException $e) {
			// The withheld-class refusal from serializeGraph is this module's
			// own answer and keeps its wording.
			throw $e;
		} catch (\Throwable $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure(
				"Failed to walk '{$relation}' from {$class}::{$id}",
				$e
			));
		}
	}

	private static function serializeGraph(\RelationGraph $oGraph): array
	{
		$aObjects   = [];
		$aRelations = [];
		$aStats     = [];

		// If user rights not applied on graph, force it
		if (!MetaModel::GetConfig()->Get('relations.complete_analysis')) {
			$oGraph = self::ApplyUserRightsOnGraph($oGraph);
		}

		/** @var \RelationObjectNode $oNode */
		foreach ($oGraph->GetNodes() as $sNodeId => $oNode) {
			if (!$oNode instanceof \RelationObjectNode) {
				continue;
			}

			$oObject = $oNode->GetObject();
			if ($oObject === null) {
				continue;
			}

			// Skip source node and unreached nodes
			if (!$oNode->GetProperty('is_reached', false)) {
				continue;
			}

			$sClass = get_class($oObject);

			$sKey = $sClass.'::'.$oObject->GetKey();
			$aObjects[$sKey] = [
				'class'        => $sClass,
				MetaModel::DBGetKey($sClass)           => WritePlan::AsId($oObject->GetKey()),
				'friendlyname' => $oObject->GetName(),
			];

			// Stats per class
			$aStats[$sClass] = ($aStats[$sClass] ?? 0) + 1;
		}

		$aWithheld = self::classesReadInBulkWithoutTheRight($aStats);
		foreach ($aWithheld as $sWithheldClass) {
			unset($aStats[$sWithheldClass]);
		}
		$aObjects = array_filter(
			$aObjects,
			static fn(array $aObject): bool => !in_array($aObject['class'], $aWithheld, true)
		);

		/** @var \RelationEdge $oEdge */
		foreach ($oGraph->GetEdges() as $oEdge) {
			$oSource = $oEdge->GetSourceNode();
			$oSink   = $oEdge->GetSinkNode();

			if (!$oSource instanceof \RelationObjectNode || !$oSink instanceof \RelationObjectNode) {
				continue;
			}

			if (!$oSource->GetProperty('is_reached', false) || !$oSink->GetProperty('is_reached', false)) {
				continue;
			}

			$oSourceObj = $oSource->GetObject();
			$oSinkObj   = $oSink->GetObject();

			if ($oSourceObj === null || $oSinkObj === null) {
				continue;
			}

			$sFrom = get_class($oSourceObj).'::'.$oSourceObj->GetKey();
			$sTo   = get_class($oSinkObj).'::'.$oSinkObj->GetKey();

			// An edge to an object that was withheld would dangle, and a
			// dangling edge is how a partial graph reads as a complete one.
			if (!isset($aObjects[$sFrom]) || !isset($aObjects[$sTo])) {
				continue;
			}

			$aRelations[] = ['from' => $sFrom, 'to' => $sTo];
		}

		$aSummaryParts = [];
		foreach ($aStats as $sClass => $iCount) {
			$aSummaryParts[] = "{$sClass}={$iCount}";
		}

		$aPayload = [
			'objects'   => $aObjects,
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
