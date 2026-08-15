<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
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


	public function getTitle(): ?string
	{
		return 'Get Related Objects';
	}

	public function getDescription(): ?string
	{
		return 'Find objects related to a given iTop object through a named relation (e.g. impacts, depends on). Useful for impact analysis and dependency mapping on CIs.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get related iTop objects',
			true,  // readOnlyHint
			false,  // destructiveHint — additive create
			true,  // idempotentHint — each call can create another row
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
					'description' => 'Relation code to follow. Built-in values: "impacts", "depends on". Call core_class_schema for the relations a class takes part in.',
				],
				'direction' => [
					'type'        => 'string',
					'description' => '"down" follows the relation forward (e.g. what does this CI impact). "up" follows it backward (e.g. what does this CI depend on). Defaults to "down".',
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
	): mixed {
		if($id < 1) {
			throw new ToolCallException("Invalid ID. Please specify a valid object ID.");
		}

		if (!in_array($direction, [self::DIRECTION_DOWN, self::DIRECTION_UP], true)) {
			throw new ToolCallException("Invalid direction '{$direction}'. Use 'down' or 'up'.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if ($depth < self::MIN_DEPTH || $depth > self::MAX_DEPTH) {
			throw new ToolCallException("Invalid depth. Please specify a depth between " . self::MIN_DEPTH . " and " . self::MAX_DEPTH . ".");
		}
		$iMaxRecursionDepth = MetaModel::GetConfig()->Get('relations_max_depth');
		if ($depth > $iMaxRecursionDepth) {
			throw new ToolCallException("Requested depth of {$depth} exceeds the maximum allowed depth of {$iMaxRecursionDepth}. Please specify a lower depth.");
		}

		// Validate relation
		$aValidRelations = MetaModel::EnumRelationsEx();
		if (!in_array($relation, $aValidRelations, true)) {
			throw new ToolCallException(
				"Invalid relation '{$relation}'. Available: ".implode(', ', $aValidRelations).'.'
			);
		}

		// Check access rights on the specific object before retrieving it, to avoid information leaks about the existence of the object
		$oSearch = ObjectQuery::ById($class, $id);
		$oSet = new DBObjectSet($oSearch);
		// Based on GetRelated - object search manages read access
		if ($oSet->Count() === 0) {
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		// Check the final class of the object
		$sFinalClass = MetaModel::GetFinalClassName($class, $id);
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$oSearchFinal = ObjectQuery::ById($sFinalClass, $id);
			$oSetFinal = new DBObjectSet($oSearchFinal);
			// Based on GetRelated - object search manages read access
			if ($oSetFinal->Count() === 0) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			//Update the Set witht the correct class
			$oSet = $oSetFinal;
		}
		$oObject = $oSet->Fetch();


		// Build a single-object set as the source for the relation graph
		if ($direction === self::DIRECTION_DOWN) {
			$oGraph = $oSet->GetRelatedObjectsDown($relation, $depth, $redundancy);
		} else {
			$oGraph = $oSet->GetRelatedObjectsUp($relation, $depth, $redundancy);
		}

		return ToolOutput::Json(self::serializeGraph($oGraph));
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
				MetaModel::DBGetKey($sClass)           => $oObject->GetKey(),
				'friendlyname' => $oObject->GetName(),
			];

			// Stats per class
			$aStats[$sClass] = ($aStats[$sClass] ?? 0) + 1;
		}

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

			$aRelations[] = [
				'from' => get_class($oSourceObj).'::'.$oSourceObj->GetKey(),
				'to'   => get_class($oSinkObj).'::'.$oSinkObj->GetKey(),
			];
		}

		$aSummaryParts = [];
		foreach ($aStats as $sClass => $iCount) {
			$aSummaryParts[] = "{$sClass}={$iCount}";
		}

		return [
			'objects'   => $aObjects,
			'relations' => $aRelations,
			'summary'   => implode(', ', $aSummaryParts),
		];
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
				foreach($aListId as $aItem ) {
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
