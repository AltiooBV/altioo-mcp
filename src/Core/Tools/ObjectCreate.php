<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\RestValue;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
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
 *
 * @since 1.0.0
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


	protected function defaultTitle(): string
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


	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'changes' => WritePlan::ChangesSchemaProperty('Every attribute of the object being created.'),
		]);
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
				'comment'  => ChangeTracking::CommentSchemaProperty('the object is being created'),
			],
			'required' => ['class'],
		];
	}

	/**
	 * @param string $class The class of the object to create, e.g. 'UserRequest'
	 * @param array $fields A key/value map of attribute codes to values, e.g. ['title' => 'My request', 'description' => 'Details about my request']
	 * @param bool $simulate When true (default), the object is validated and described but not created
	 * @param string|null $comment Why the object is being created, recorded in its history
	 * @return array The class and ID of the newly created object, or what creating it would write
	 * @throws ToolCallException if the class is unknown, if it's abstract, if access is denied, or if any provided attribute is invalid or not writable.
	 */
	public static function execute(
		string  $class,
		array   $fields = [],
		bool    $simulate = WritePlan::SIMULATE_BY_DEFAULT,
		?string $comment = null,
	): mixed
	{
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
			// No object exists yet, so "depends on the object" cannot be
			// answered; only an outright refusal counts, and DBInsert() checks
			// again once there is something to check against.
			if (UserRights::IsActionAllowedOnAttribute($class, $sAttCode, UR_ACTION_MODIFY) === UR_ALLOWED_NO) {
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
			} catch (\Throwable $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Invalid value for attribute '{$sAttCode}'", $e);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to create due to fields : ".implode(', ', $aIssues));
		}

		// Most validations passed, apply the changes
		foreach ($aValidatedValues as $sAttCode => $realValue) {
			try {
				$oObject->Set($sAttCode, $realValue);
			} catch (\Throwable $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Failed to set attribute '{$sAttCode}'", $e);
			}
		}
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to create due to setting fields : ".implode(', ', $aIssues));
		}

		// iTop's own pre-write check: mandatory attributes, DoCheckToWrite() on
		// the class and on every extension hooked into it. Without it the
		// first thing that fails is DBInsert(), from inside the ORM.
		WritePlan::Check($oObject, "A {$class}");
		$aChanges = WritePlan::Changes($oObject, $class);

		if ($simulate) {
			// id is null rather than absent: a dry run has created nothing, and
			// saying so is not the same as answering with a different shape.
			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, null)
				+ [
					'simulated' => true,
					'valid'     => true,
					'changes'   => $aChanges,
				]);
		}

		// Said before the write, because the change record is built by the
		// write itself and reads what was last said.
		ChangeTracking::Explain($comment);

		try {
			$iId = $oObject->DBInsert();

			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $iId)
				+ [
					'simulated' => false,
					'valid'     => true,
					'changes'   => $aChanges,
				]);
		} catch (\Throwable $e) {
			// A throw here does not mean nothing was written. DBInsert()
			// commits in DBInsertNoReload() and only then walks the loaded
			// attributes calling ReadExternalValues(), so the row can exist by
			// the time this runs - and the object carries its key from the
			// moment it does.
			//
			// Answering "failed" with the row committed is the worst thing
			// this tool can do: creating is not idempotent, nothing in the
			// protocol tells a model that a failed write may have written, and
			// the reasonable next move on an error is to try again. That is a
			// second ticket. So a committed id is reported as the success it
			// is, with the failure attached rather than substituted for it.
			$iCommittedId = self::committedId($oObject);
			if ($iCommittedId === null) {
				// WritePlan::Check() ran first and refused everything the
				// caller could have corrected, so what reaches here is the
				// instance's problem, described in the instance's vocabulary.
				// MCPHelper explains the split.
				throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to create the object', $e));
			}

			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $iCommittedId)
				+ [
					'simulated' => false,
					'valid'     => true,
					'changes'   => $aChanges,
					'warning'   => MCPHelper::OpaqueFailure(
						"The {$class} was created and has id {$iCommittedId}, but the call failed after the write",
						$e
					),
				]);
		}
	}

	/**
	 * The id of an object that reached the database, or null if it did not.
	 *
	 * iTop gives an unsaved object a temporary key, and makes it negative
	 * precisely so it cannot be mistaken for a real one (DBObject::
	 * GetNextTempId()). The insert overwrites it with the autonumber - as a
	 * string, which is why this casts rather than compares types.
	 *
	 * Guarded, because it runs on the failure path: an object left in a state
	 * where even reading its key throws must not replace the failure being
	 * reported with one from the reporting.
	 */
	private static function committedId(\DBObject $oObject): ?int
	{
		try {
			$mKey = $oObject->GetKey();
		} catch (\Throwable $e) {
			return null;
		}

		if (!is_numeric($mKey) || (int) $mKey <= 0) {
			return null;
		}

		return (int) $mKey;
	}
}
