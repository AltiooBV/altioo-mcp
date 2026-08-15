<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;
use RestUtils;

/**
 * Create a new iTop object.
 *
 * The `fields` parameter is a key/value map of attribute codes to values.
 * Use the itop://core/class/{classname} resource to discover mandatory attributes
 * and their types before calling this tool.
 */
class ObjectCreate extends AbstractMCPTool
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


	public function getTitle(): ?string
	{
		return 'Create Object';
	}

	public function getDescription(): ?string
	{
		return 'Create a new iTop object of the given class. Call core_class_schema first: it reports the attribute codes, their types and which ones are mandatory. '
			.'Runs as a dry run by default: call it with simulate=true to have iTop validate the object and report what would be written, show that to the user, then call again with simulate=false to create it.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Create iTop object',
			false,  // readOnlyHint
			false,  // destructiveHint — additive create
			false,  // idempotentHint — each call can create another row
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
				'fields' => [
					'type'                 => 'object',
					'description'          => 'Key/value pairs to set. Keys are attribute codes. Call core_class_schema for the attribute codes of the class, their types and which ones are mandatory.',
					'additionalProperties' => true,
				],
				'simulate' => WritePlan::SimulateSchemaProperty('create the object'),
			],
			'required' => ['class'],
		];
	}

	/**
	 * @param string $class The class of the object to create, e.g. 'UserRequest'
	 * @param array $fields A key/value map of attribute codes to values, e.g. ['title' => 'My request', 'description' => 'Details about my request']
	 * @param bool $simulate When true (default), the object is validated and described but not created
	 * @return array The class and ID of the newly created object, or what creating it would write
	 * @throws ToolCallException if the class is unknown, if it's abstract, if access is denied, or if any provided attribute is invalid or not writable.
	 */
	public static function execute(
		string $class,
		array  $fields = [],
		bool   $simulate = WritePlan::SIMULATE_BY_DEFAULT,
	): mixed {
		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$class}'."); // hide that the class exists
		}

		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException("The database is in read-only mode, cannot create objects.");
		}

		if (MetaModel::IsAbstract($class)) {
			throw new ToolCallException("Cannot create an instance of abstract class '{$class}'.");
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_CREATE)) {
			throw new ToolCallException("Access denied: cannot create objects of class '{$class}'.");
		}

		$oObject = MetaModel::NewObject($class);

		// Validate fields before applying any changes
		$aIssues = [];
		$aValidatedValues = [];
		foreach ($fields as $sAttCode => $value) {
			if (!MetaModel::IsValidAttCode($class, $sAttCode)) {
				$aIssues[$sAttCode] = "Unknown attribute '{$sAttCode}' on class '{$class}'.";
				continue;
			}
			if (!UserRights::IsActionAllowedOnAttribute($class, $sAttCode, UR_ACTION_MODIFY)) {
				$aIssues[$sAttCode] = "Write access denied on attribute '{$sAttCode}'.";
				continue;
			}
			$oAttDef = MetaModel::GetAttributeDef($class, $sAttCode);
			if (!$oAttDef->IsWritable()) {
				$aIssues[$sAttCode] = "Attribute '{$sAttCode}' is not writable.";
				continue;
			}
			try {
				// The SDK hands us arrays for nested JSON objects; RestUtils
				// branches on stdClass. See RestValue.
				$aValidatedValues[$sAttCode] = RestUtils::MakeValue($class, $sAttCode, RestValue::FromDecodedJson($value));
			} catch (\Exception $e) {
				$aIssues[$sAttCode] = "Invalid value for attribute '{$sAttCode}': " . $e->getMessage();
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to create due to fields : " . implode(', ', $aIssues));
		}

		// Most validations passed, apply the changes
		foreach ($aValidatedValues as $sAttCode => $realValue) {
			try
			{
				$oObject->Set($sAttCode, $realValue);
			}
			catch (\Exception $e)
			{
				$aIssues[$sAttCode] = "Failed to set attribute '{$sAttCode}': " . $e->getMessage();
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to create due to setting fields : " . implode(', ', $aIssues));
		}

		// iTop's own pre-write check: mandatory attributes, DoCheckToWrite() on
		// the class and on every extension hooked into it. Without it the
		// first thing that fails is DBInsert(), from inside the ORM.
		WritePlan::Check($oObject, "A {$class}");
		$aChanges = WritePlan::Changes($oObject, $class);

		if ($simulate) {
			return ToolOutput::Json([
				'class'     => $class,
				'simulated' => true,
				'valid'     => true,
				'changes'   => $aChanges,
			]);
		}

		try {
			$iId = $oObject->DBInsert();

			return ToolOutput::Json([
				'class' => $class,
				MetaModel::DBGetKey($class) => $iId,
				'simulated' => false,
				'changes'   => $aChanges,
			]);
		} catch (\Exception $e) {
			throw new ToolCallException("Failed to create object: " . $e->getMessage());
		}
	}
}
