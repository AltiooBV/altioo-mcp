<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\EditPolicy;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Service\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * Create a new iTop object.
 *
 * The `fields` parameter is a key/value map of attribute codes to values.
 * Use the itop://core/class/{classname} resource to discover mandatory attributes
 * and their types before calling this tool.
 *
 * @since 1.0.0
 */
class ObjectCreate extends AbstractMCPTool implements PortalAware
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
		if (PortalMode::Current() !== null) {
			return self::PORTAL_DESCRIPTION;
		}

		return 'Create a new iTop object of the given class. Call core_class_schema first: it reports the attribute codes, their types and which ones are mandatory. '
			.'The dry run validates the object and reports what would be written. A new object starts in its initial state: the state, and fields that state locks, are set afterwards with core_object_apply_stimulus. '
			.'Read `overridden` on the answer as carefully as `changes`: a derived attribute is recomputed from the ones it derives from on every write, so supplying it directly changes nothing and is reported there rather than in `changes`.';
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
		// In portal mode the answer also names the create action that ran.
		if (PortalMode::Current() !== null) {
			return WritePlan::OutcomeSchema([
				'action'     => [
					'type'        => 'string',
					'description' => 'The create action used.',
				],
				'after'      => WritePlan::AfterSchemaProperty(),
				'changes'    => WritePlan::ChangesSchemaProperty('Every attribute the new object is given, the ones the portal\'s rules preset included.'),
				'overridden' => WritePlan::OverriddenSchemaProperty(),
				'defaulted'  => WritePlan::DefaultedSchemaProperty(),
			]);
		}

		return WritePlan::OutcomeSchema([
			'after' => WritePlan::AfterSchemaProperty(),
			'changes'    => WritePlan::ChangesSchemaProperty('Every attribute of the object being created.'),
			'overridden' => WritePlan::OverriddenSchemaProperty(),
			'defaulted'  => WritePlan::DefaultedSchemaProperty(),
		]);
	}

	/** What this tool is to a portal-mode connection, which creates through the portal's create actions. */
	private const PORTAL_DESCRIPTION = 'Create an object through one of the portal\'s create actions, as its create form would. Name the action from core_class_list; when it starts from an object (in iTop\'s stock portal, a service subcategory), pass that object\'s id as from_id, a row of the action\'s from_list in core_portal_list. The action decides the class, and its rules preset what the portal presets - the caller, the organisation, the service - so send only the fields the create form leaves settable. Dry run by default (simulate): it shows what the rules set and what would be created.';

	public function getInputSchema(): ?array
	{
		if (PortalMode::Current() !== null) {
			return [
				'type'       => 'object',
				'properties' => [
					'action'   => [
						'type'        => 'string',
						'description' => 'A create action id from core_class_list.',
					],
					'from_id'  => [
						'type'        => 'integer',
						'description' => 'For an action that starts from an object: its id, a row of the action\'s from_list. 0 (the default) for one that starts from nothing.',
						'default'     => 0,
					],
					'fields'   => [
						'type'                 => MCPHelper::MAP_TYPE,
						'description'          => 'Attribute code => value for the settable fields of the portal\'s create form for the class.',
						'additionalProperties' => true,
					],
					'simulate' => WritePlan::SimulateSchemaProperty('create the object'),
					'comment'  => ChangeTracking::CommentSchemaProperty('the object is being created'),
				],
				'required'   => ['action', 'fields'],
			];
		}

		return [
			'type'       => 'object',
			'properties' => [
				'class'   => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Call core_class_list to find the class name.',
				],
				'fields' => [
					'type'                 => MCPHelper::MAP_TYPE,
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
	 * @param string|null $comment Why the object is being created, recorded on the audit row
	 * @param string $action In portal mode, the portal's create action that decides the class and presets the object
	 * @param int $from_id In portal mode, the object the create action starts from
	 * @return mixed The class and ID of the newly created object, or what creating it would write
	 * @throws ToolCallException if the class is unknown, if it's abstract, if access is denied, or if any provided attribute is invalid or not writable.
	 */
	public static function execute(
		string  $class = '',
		array   $fields = [],
		bool    $simulate = WritePlan::SIMULATE_BY_DEFAULT,
		?string $comment = null,
		string  $action = '',
		int     $from_id = 0,
	): mixed
	{
		// The advisory scope, before anything reads it: a token pinned to dry
		// runs rehearses whatever the caller passed, and normalising here means
		// every branch and every reported `simulated` below is already right.
		$simulate = WritePlan::Simulated($simulate);

		// In portal mode the portal's create action decides the class and
		// presets the object; on the console the class named does
		// (doc/adr/0006). Every check below applies in both.
		try {
			$oAccess = ObjectAccess::Current();
			$oPrepared = $oAccess->Prepared(trim($action), $from_id);
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}
		if ($oPrepared !== null) {
			$class = get_class($oPrepared);
		}
		$aAction = $oPrepared === null ? [] : ['action' => trim($action)];

		if (!MetaModel::IsValidClass($class)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class));
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}
		$sAccessRefusal = AccessGrants::RefusalFor($class, null, $fields);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
		}
		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
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

		$oObject = $oPrepared ?? MetaModel::NewObject($class);

		// The form once the rules are in: a portal's layout may read what
		// they set.
		try {
			$sModeRefusal = $oAccess->RefusalToSet($oObject, $fields);
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}
		if ($sModeRefusal !== null) {
			throw new ToolCallException($sModeRefusal);
		}

		// Validate fields before applying any changes. Every rule a value has
		// to pass is in one place, shared with the update, the stimulus and
		// the portal; no instance set, since nothing exists yet.
		[$aValidatedValues, $aIssues] = WritePlan::ValidatedValues($oObject, $class, $fields, null);
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

		// Once everything is Set(), because a datamodel filter reads the
		// object and this call may have moved what it reads. See EditPolicy.
		$aIssues = EditPolicy::RefusalsForExternalKeys($oObject, array_keys($aValidatedValues))
			+ $oAccess->RefusalsForReach($oObject, array_keys($aValidatedValues));
		if (!empty($aIssues)) {
			throw new ToolCallException("Failed to create due to fields : ".implode(', ', $aIssues));
		}

		// Before the check, because the check is what moves them: CheckToWrite()
		// runs DoComputeValues(), and a class that derives an attribute from
		// others overwrites whatever was just Set() into it.
		$aRequested = WritePlan::Requested($oObject, $class, array_keys($aValidatedValues));

		// iTop's own pre-write check: mandatory attributes, DoCheckToWrite() on
		// the class and on every extension hooked into it. Without it the
		// first thing that fails is DBInsert(), from inside the ORM.
		WritePlan::Check($oObject, "A {$class}");

		// What the check threw away. On a creation every attribute is in
		// `changes`, so an overridden one is reported there too - with the
		// computed value, which is exactly what makes it unreadable as a
		// refusal of what the caller sent.
		$aOverridden = WritePlan::Overridden($oObject, $class, $aRequested);
		WritePlan::CheckRequested($oObject, $aRequested, $aOverridden, "A {$class}");

		$aChanges = WritePlan::Changes($oObject, $class);

		// The portal shows what its view form shows; a write that fills in
		// more reports no more than a read would (doc/adr/0005).
		try {
			$aChanges = $oAccess->Disclosed($oObject, $aChanges, array_keys($aValidatedValues));
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}

		if ($simulate) {
			// id is null rather than absent: a dry run has created nothing, and
			// saying so is not the same as answering with a different shape.
			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, null)
				+ $aAction
				+ self::credentialNote($class)
				+ [
					'simulated'  => true,
					'valid'      => true,
					'after'        => WritePlan::After($oObject, $class, array_keys($aValidatedValues), $simulate),
					'changes'    => WritePlan::Map($aChanges),
					'untrusted_text' => WritePlan::Untrusted($class, $aChanges)['untrusted_text'],
					'overridden' => WritePlan::Map($aOverridden),
					'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValidatedValues)),
				]);
		}

		// Said before the write, because the change record is built by the
		// write itself and reads what was last said.
		ChangeTracking::Explain($comment);

		try {
			$iId = $oObject->DBInsert();

			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $iId)
				+ $aAction
				+ [
					'simulated'  => false,
					'valid'      => true,
					'after'        => WritePlan::After($oObject, $class, array_keys($aValidatedValues), $simulate),
					'changes'    => WritePlan::Map($aChanges),
					'untrusted_text' => WritePlan::Untrusted($class, $aChanges)['untrusted_text'],
					'overridden' => WritePlan::Map($aOverridden),
					'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValidatedValues)),
				]
				+ self::credentialNote($class));
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
			$iCommittedId = WritePlan::CommittedId($oObject);
			if ($iCommittedId === null) {
				// WritePlan::Check() ran first and refused everything the
				// caller could have corrected, so what reaches here is the
				// instance's problem, described in the instance's vocabulary.
				// MCPHelper explains the split.
				throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to create the object', $e));
			}

			return ToolOutput::Structured(['class' => $class]
				+ WritePlan::Identity($class, $iCommittedId)
				+ $aAction
				+ [
					'simulated'  => false,
					'valid'      => true,
					'after'        => WritePlan::After($oObject, $class, array_keys($aValidatedValues), $simulate),
					'changes'    => WritePlan::Map($aChanges),
					'untrusted_text' => WritePlan::Untrusted($class, $aChanges)['untrusted_text'],
					'overridden' => WritePlan::Map($aOverridden),
					'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValidatedValues)),
					'warning'    => MCPHelper::OpaqueFailure(
						"The {$class} was created and has id {$iCommittedId}, but the call failed after the write",
						$e
					),
				]);
		}
	}

	/**
	 * The `note` key, on the classes that need one.
	 *
	 * Added as an array so it disappears entirely for every other class
	 * rather than appearing as an empty string nobody can act on.
	 *
	 * @return array<string, string>
	 */
	private static function credentialNote(string $sClass): array
	{
		$sNote = WritePlan::CredentialNote($sClass);

		return $sNote === null ? [] : ['note' => $sNote];
	}
}
