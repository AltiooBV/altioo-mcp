<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * Changes an object the way the portal's edit form would: a reply added to
 * the public log, a contact added to the request.
 *
 * Two forms decide what may be set, and a field has to pass both. The
 * portal's edit form for the object as it is, which is what the user is
 * shown; and the console's rules every write here is held to
 * (doc/adr/0004-writes-follow-the-console-form.md), which is what keeps a case
 * log to new entries and the state to its transitions. The portal's form is
 * never the wider of the two on a stock instance, but an operator's layout
 * could make it so, and the narrower reading is the one taken.
 *
 * The object must be in the portal's write scope, and anything a value points
 * at - an external key, a link's far end - in the user's read scope, which is
 * all the portal's pickers would have offered.
 *
 * @since 1.2.0
 */
class PortalUpdate extends AbstractPortalTool
{
	protected function defaultTitle(): string
	{
		return 'Update through the Portal';
	}

	public function getDescription(): ?string
	{
		return 'Change an object as this user\'s portal edit form would let them: add a reply to a request\'s public log, or change another field the form leaves writable. '
			.'core_portal_get lists, for an object, the fields that may be set now (settable_fields); anything else is refused, and so is a state change, which is core_portal_apply_stimulus. '
			.'A case log such as public_log takes the text of one new entry, and adds it; earlier entries are never replaced. '
			.'Read `overridden` on the answer as carefully as `changes`.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Update through the portal',
			false,  // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'after'      => WritePlan::AfterSchemaProperty(),
			'changes'    => WritePlan::ChangesSchemaProperty('Only the attributes this update actually modifies.'),
			'overridden' => WritePlan::OverriddenSchemaProperty(),
			'defaulted'  => WritePlan::DefaultedSchemaProperty(),
		]);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'    => [
					'type'        => 'string',
					'description' => 'The class of the object, as core_portal_get names it.',
				],
				'id'       => [
					'type'        => 'integer',
					'description' => 'The id of the object.',
				],
				'fields'   => [
					'type'                 => MCPHelper::MAP_TYPE,
					'description'          => 'Attribute code => value, among the object\'s settable_fields from core_portal_get. For public_log, the text of the reply.',
					'additionalProperties' => true,
				],
				'simulate' => WritePlan::SimulateSchemaProperty('apply the change'),
				'comment'  => ChangeTracking::CommentSchemaProperty('the change is being made'),
			],
			'required'   => ['class', 'id', 'fields'],
		];
	}

	/**
	 * @param string      $class    The class of the object
	 * @param int         $id       Its id
	 * @param array       $fields   Attribute => value, among those the portal's edit form lets the user set
	 * @param bool        $simulate When true (default), the change is validated and described but not written
	 * @param string|null $comment  Why the change is being made, recorded in the object's history
	 *
	 * @return mixed The object's class and id, and what the call changes or would change
	 * @throws ToolCallException if the portal does not let the user change the object or a field, or the change is invalid
	 */
	public static function execute(string $class, int $id, array $fields, bool $simulate = WritePlan::SIMULATE_BY_DEFAULT, ?string $comment = null): mixed
	{
		$simulate = WritePlan::Simulated($simulate);

		if ($fields === []) {
			throw new ToolCallException('No fields provided for update.');
		}
		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException('The database is in read-only mode, cannot update objects.');
		}

		$oPolicy = self::policy();
		$oObject = self::writable($oPolicy, trim($class), $id);
		$sClass = get_class($oObject);

		$sAccessRefusal = AccessGrants::RefusalFor($sClass, $id, $fields);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
		}
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$sClass}::{$id} is in read-only mode, cannot update object.");
		}

		$oInstanceSet = DBObjectSet::FromObject($oObject);
		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY, $oInstanceSet)) {
			throw new ToolCallException("The portal lets you read {$sClass} {$id} but not change it.");
		}

		self::refuseUnsettable($fields, self::settable($sClass, self::portalStep(
			static fn (): array => $oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject)
		)), [], 'edit');

		[$aValues, $aIssues] = WritePlan::ValidatedValues($oObject, $sClass, $fields, $oInstanceSet);
		if ($aIssues !== []) {
			throw new ToolCallException('Failed to update due to fields : '.implode(', ', $aIssues));
		}

		[, $aOverridden, $aChanges] = self::plan($oPolicy, $oObject, $aValues, "{$sClass}::{$id}");

		$aAnswer = static fn (bool $bSimulated): mixed => ToolOutput::Structured(['class' => $sClass]
			+ WritePlan::Identity($sClass, $id)
			+ [
				'simulated'  => $bSimulated,
				'valid'      => true,
				'after'      => WritePlan::After($oObject, $sClass, array_keys($aValues), $bSimulated),
				'changes'    => WritePlan::Map($aChanges),
				'overridden' => WritePlan::Map($aOverridden),
				'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValues)),
			]);

		if ($simulate) {
			return $aAnswer(true);
		}

		// Said before the write, because the change record is built by the
		// write itself and reads what was last said.
		ChangeTracking::Explain($comment);

		try {
			$oObject->DBUpdate();
		} catch (\Throwable $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to update {$sClass}::{$id}", $e));
		}

		return $aAnswer(false);
	}
}
