<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use DBObjectSearch;
use DBObjectSet;
use iAttributeNoGroupBy;


/**
 * Retrieve a single iTop object by class and ID.
 */
class ObjectGet extends AbstractMCPTool
{

	public function getTitle(): ?string
	{
		return 'Get Object';
	}

	public function getDescription(): ?string
	{
		return 'Retrieve a single iTop object by its class and ID. Returns all readable attributes.';
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
					'description' => 'iTop class name (e.g. UserRequest, Server). Use the itop://core/classes resource to list available classes.',
				],
				'id'  => [
					'type'        => 'integer',
					'description' => 'The ID of the object to get.',
					'minimum'     => 1,
				],
			],
			'required' => ['class', 'id'],
		];
	}

	/**
	 * @param string $class The class of the object to retrieve, e.g. 'UserRequest'
	 * @param int $id The ID of the object to retrieve, e.g. 123
	 * @return array An array containing the class and all readable attributes of the object
	 * @throws ToolCallException if the class is unknown, if access is denied, or if the object is not found.
	 */
	public static function execute(
		string $class,
		int    $id,
	): mixed {
		if ($id < 1) {
			throw new ToolCallException("Invalid ID '{$id}'.");
		}

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		// Check access rights on the specific object before retrieving it, to avoid information leaks about the existence of the object
		$sKey = MetaModel::DBGetKey($class);
		$oSearch = DBObjectSearch::FromOQL("SELECT {$class} WHERE {$sKey} = {$id}");
		$oSet = new DBObjectSet($oSearch);
		if (!UserRights::IsActionAllowed($class,  UR_ACTION_READ, $oSet)) {
			throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
		}

		$oObject = MetaModel::GetObject($class, $id, false);
		if ($oObject === null) {
			throw new ToolCallException("Object {$class}::{$id} not found.");
		}
		// Check the final class of the object
		$sFinalClass = MetaModel::GetFinalClassName($class, $id);
		if ($sFinalClass !== $class) {
			if (!UserRights::IsActionAllowed($sFinalClass, UR_ACTION_READ)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			$sKeyFinal = MetaModel::DBGetKey($sFinalClass);
			$oSearchFinal = DBObjectSearch::FromOQL("SELECT {$sFinalClass} WHERE {$sKeyFinal} = {$id}");
			$oSetFinal = new DBObjectSet($oSearchFinal);
			if (!UserRights::IsActionAllowed($sFinalClass,  UR_ACTION_READ, $oSetFinal)) {
				throw new ToolCallException("Object {$class}::{$id} not found."); // hide that the object exists
			}
			//Get it again with the correct class
			$oObject = MetaModel::GetObject($sFinalClass, $id, false);
		}

		$sKey = MetaModel::DBGetKey($sFinalClass);
		$aData = [$sKey => $oObject->GetKey()];

		foreach (MetaModel::ListAttributeDefs($sFinalClass) as $sAttCode => $oAttDef) {
			if (!UserRights::IsActionAllowedOnAttribute($sFinalClass, $sAttCode, UR_ACTION_READ)) {
				continue;
			}
			$aData[$sAttCode] = $oObject->Get($sAttCode);

			if ($oAttDef instanceof iAttributeNoGroupBy && $aData[$sAttCode] !== null) { // iAttributeNoGroupBy is equivalent to sensitive attribute
				$aData[$sAttCode] = '***';
			}
		}

		return [
			'requested_class'  => $class,
			'class' => $sFinalClass,
			'object' => $aData,
		];
	}
}
