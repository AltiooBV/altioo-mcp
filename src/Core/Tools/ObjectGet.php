<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use DBObjectSet;


/**
 * Retrieve a single iTop object by class and ID.
 *
 * @since 1.0.0
 */
class ObjectGet extends AbstractMCPTool
{

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
		return 'Get Object';
	}

	public function getDescription(): ?string
	{
		return 'Retrieve a single iTop object by its class and ID. Returns all readable attributes, and - where this user may read the change log - an audit block saying when the object was created and when it was last changed, with the user behind each: iTop keeps no such field on the object itself. It also reports what may be done to this object now - the update and delete gates answered for this object rather than for its class, and the stimuli its current state accepts, which is what core_object_apply_stimulus will take. Call core_object_history for the full record of what changed.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get iTop object',
			true,  // readOnlyHint
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
				'class'   => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Call core_class_list to find the class name.',
				],
				'id'  => [
					'type'        => 'integer',
					'description' => 'The ID of the object to get.',
					'minimum'     => 1,
				],
				'output_fields' => ObjectSerializer::FieldsSchemaProperty(ObjectSerializer::ALL_FIELDS),
			],
			'required' => ['class', 'id'],
		];
	}

	/**
	 * @param string $class The class of the object to retrieve, e.g. 'UserRequest'
	 * @param int $id The ID of the object to retrieve, e.g. 123
	 * @param string $output_fields Comma-separated attribute codes to return; '*' (the default) returns all of them
	 * @return array An array containing the class and all readable attributes of the object
	 * @throws ToolCallException if the class is unknown, if access is denied, or if the object is not found.
	 */
	public static function execute(
		string $class,
		int    $id,
		string $output_fields = ObjectSerializer::ALL_FIELDS,
	): mixed
	{
		if ($id < 1) {
			throw new ToolCallException("Invalid ID '{$id}'.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		// Check access rights on the specific object before retrieving it, to avoid information leaks about the existence of the object
		$oSearch = ObjectQuery::ById($class, $id);
		$oSet = new DBObjectSet($oSearch);
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ, $oSet)) {
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		$oObject = MetaModel::GetObject($class, $id, false);
		if ($oObject === null) {
			throw new ToolCallException("Object {$class}::{$id} not found.");
		}

		// GetObject() goes through MetaModel::GetObjectByRow(), which reads the
		// finalclass column and instantiates the leaf - so the object in hand is
		// already the right one, and its class is already known. Asking
		// GetFinalClassName() for that name, and then reading the object a
		// second time under it, was two queries spent on an answer that had
		// already arrived.
		$sFinalClass = get_class($oObject);
		if ($sFinalClass !== $class) {
			// Being allowed to read the parent says nothing about the child, at
			// class level or - a profile whose rights depend on the object - at
			// object level. Both checks stay.
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$oSetFinal = new DBObjectSet(ObjectQuery::ById($sFinalClass, $id));
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ, $oSetFinal)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
		}

		$aObject = ObjectSerializer::Serialize($oObject, $sFinalClass, ObjectSerializer::ParseFieldList($sFinalClass, $output_fields));

		// Unconditional here, where it is two indexed single-row reads for one
		// object. The search tools ask for it, because there the count of those
		// reads is the page size. Absent when this caller may not read the log.
		$aAttribution = ObjectHistory::AttributionFor($oObject);
		if ($aAttribution !== null) {
			$aObject['audit'] = $aAttribution;
		}

		// What may be done to this object now, rather than to its class: the
		// write gates with the 'depends' ones resolved, and the stimuli its
		// current state accepts. One object, so no flag - the search tools ask,
		// because there this is a question per row.
		$oInstanceSet = null;
		$aObject['rights'] = ObjectSerializer::RightsOn($oObject, $sFinalClass, null, $oInstanceSet);
		$aObject['stimuli'] = ObjectSerializer::StimuliOn($oObject, $sFinalClass, $aObject['rights'], $oInstanceSet);

		return ToolOutput::Json([
			'requested_class'  => $class,
			'class' => $sFinalClass,
			'object' => $aObject,
		]);
	}
}
