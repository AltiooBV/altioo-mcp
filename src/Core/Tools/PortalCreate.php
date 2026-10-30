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
use DBObject;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;

/**
 * Creates an object through one of the portal's create actions, as the
 * portal would: "New request" on a service subcategory, or a CreateBrick.
 *
 * Everything the client does not type comes from the portal, on the server.
 * The object the action starts from must be a row of the browse level the
 * action is on, and the levels above it the rows they are listed under. The
 * class is the one the action names, or the one its factory method returns
 * for that object - the method the brick's configuration names, run here as
 * the portal runs it (doc/adr/0002). The action's rules then preset the new
 * object with the portal's own helper: in the stock portal, the caller and
 * organisation from the user's contact, the service from the subcategory, and
 * the origin. What the client sends is limited to the fields of the portal's
 * create form left writable once those are in, and held to the rules of any
 * write here.
 *
 * @since 1.2.0
 */
class PortalCreate extends AbstractPortalTool
{
	protected function defaultTitle(): string
	{
		return 'Create through the Portal';
	}

	public function getDescription(): ?string
	{
		return 'Create an object through one of the create actions this user\'s portal offers, as the portal would: open a request on a service subcategory, for example. '
			.'action is a create action id from core_portal_describe. When the action starts from an object (from names its class), from_id is that object\'s id, a row of the browse list the action is on: browse the services with core_portal_list to find it. '
			.'The portal fills in what its rules say - the caller, their organisation, the service - so fields is only what its create form asks for, such as title and description; core_portal_describe with the class shows that form. '
			.'Ask the user to confirm what the dry run shows before creating.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Create through the portal',
			false,  // readOnlyHint
			false,  // destructiveHint
			false,  // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
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

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'action'   => [
					'type'        => 'string',
					'description' => 'A create action id from core_portal_describe, e.g. services/1/1/1/create_from_this.',
				],
				'from_id'  => [
					'type'        => 'integer',
					'description' => 'For an action that starts from an object: its id, a row of the browse list the action is on. 0 (the default) for one that starts from nothing.',
					'default'     => 0,
				],
				'fields'   => [
					'type'                 => MCPHelper::MAP_TYPE,
					'description'          => 'Attribute code => value for the fields of the portal\'s create form, e.g. title and description.',
					'additionalProperties' => true,
				],
				'simulate' => WritePlan::SimulateSchemaProperty('create the object'),
				'comment'  => ChangeTracking::CommentSchemaProperty('the object is being created'),
			],
			'required'   => ['action', 'fields'],
		];
	}

	/**
	 * @param string      $action   A create action id from core_portal_describe
	 * @param int         $from_id  The id of the object it starts from, a row of its browse list; 0 for none
	 * @param array       $fields   Values for the fields of the portal's create form
	 * @param bool        $simulate When true (default), the creation is validated and described but not written
	 * @param string|null $comment  Why, recorded in the object's history
	 *
	 * @return mixed The new object's class and id (null on a dry run), and every attribute it is given
	 * @throws ToolCallException if the portal does not offer the action or the object it starts from, or the creation is invalid
	 */
	public static function execute(string $action, array $fields, int $from_id = 0, bool $simulate = WritePlan::SIMULATE_BY_DEFAULT, ?string $comment = null): mixed
	{
		$simulate = WritePlan::Simulated($simulate);

		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException('The database is in read-only mode, cannot create objects.');
		}

		$oPolicy = self::policy();
		$aAction = self::actionOf($oPolicy, trim($action));

		[$oFrom, $aSources] = self::startingPoint($oPolicy, $aAction, $from_id);

		$sClass = $aAction['class'] ?? self::portalStep(
			static fn (): string => $oPolicy->ChosenClass((string)$aAction['factory_method'], $oFrom)
		);
		self::refuseReserved($sClass);
		if (!MetaModel::IsValidClass($sClass) || MetaModel::IsAbstract($sClass) || !$oPolicy->MayCreate($sClass)) {
			throw new ToolCallException('The portal does not let you create what this action creates.');
		}

		$sAccessRefusal = AccessGrants::RefusalFor($sClass, null, $fields);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
		}

		$oObject = MetaModel::NewObject($sClass);
		self::portalStep(static fn () => $oPolicy->ApplyRules($aAction['rules'], $aSources, $oObject));

		// The form once the rules are in: a layout may read what they set.
		self::refuseUnsettable($fields, self::settable($sClass, self::portalStep(
			static fn (): array => $oPolicy->Fields($sClass, PortalPolicy::MODE_CREATE, $oObject)
		)), [], 'create');

		[$aValues, $aIssues] = WritePlan::ValidatedValues($oObject, $sClass, $fields, null);
		if ($aIssues !== []) {
			throw new ToolCallException('Failed to create due to fields : '.implode(', ', $aIssues));
		}

		[, $aOverridden, $aChanges] = self::plan($oPolicy, $oObject, $aValues, "A {$sClass}");

		$aAnswer = static fn (bool $bSimulated, ?int $iId, array $aExtra = []): mixed => ToolOutput::Structured(['class' => $sClass]
			+ WritePlan::Identity($sClass, $iId)
			+ [
				'action'     => (string)$aAction['action'],
				'simulated'  => $bSimulated,
				'valid'      => true,
				'after'      => WritePlan::After($oObject, $sClass, array_keys($aValues), $bSimulated),
				'changes'    => WritePlan::Map($aChanges),
				'overridden' => WritePlan::Map($aOverridden),
				'defaulted'  => WritePlan::Defaulted($aChanges, array_keys($aValues)),
			]
			+ $aExtra);

		if ($simulate) {
			return $aAnswer(true, null);
		}

		ChangeTracking::Explain($comment);

		try {
			return $aAnswer(false, (int)$oObject->DBInsert());
		} catch (\Throwable $e) {
			// A throw here does not mean nothing was written: see ObjectCreate.
			// A committed id is the success it is, with the failure attached.
			$iCommittedId = WritePlan::CommittedId($oObject);
			if ($iCommittedId === null) {
				throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to create the object', $e));
			}

			return $aAnswer(false, $iCommittedId, ['warning' => MCPHelper::OpaqueFailure(
				"The {$sClass} was created and has id {$iCommittedId}, but the call failed after the write",
				$e
			)]);
		}
	}

	/**
	 * The create action of that id, among those the granted bricks offer.
	 *
	 * @return array<string, mixed>
	 */
	private static function actionOf(PortalPolicy $oPolicy, string $sAction): array
	{
		foreach ($oPolicy->CreateActions() as $aAction) {
			if ($aAction['action'] === $sAction) {
				return $aAction;
			}
		}

		// Not quoted back: the id is the client's text.
		throw new ToolCallException('The portal offers no such create action. Call core_portal_describe for the ones it offers.');
	}

	/**
	 * The object an action starts from, and the objects its rules may read:
	 * that one, and the row above it on each level of its browse brick.
	 *
	 * @param array<string, mixed> $aAction
	 *
	 * @return array{0: DBObject|null, 1: array<string, int>}
	 */
	private static function startingPoint(PortalPolicy $oPolicy, array $aAction, int $iFromId): array
	{
		$sList = $aAction['from_list'] ?? null;
		if (!is_string($sList)) {
			if ($iFromId > 0) {
				throw new ToolCallException('This create action starts from nothing: leave from_id out.');
			}

			return [null, []];
		}

		$oFrom = $iFromId > 0 ? PortalList::RowOf($oPolicy, $sList, $iFromId) : null;
		if ($oFrom === null) {
			throw new ToolCallException(sprintf(
				'This create action starts from a %s: from_id must be one listed by core_portal_list on %s.',
				(string)$aAction['from'],
				$sList
			));
		}

		// The portal hands its rules every object of the row the user
		// clicked: the subcategory and, above it, its service and family.
		$aSources = [get_class($oFrom) => (int)$oFrom->GetKey()];
		$oRow = $oFrom;
		$aLevel = $oPolicy->ListDefinition($sList);
		while (is_array($aLevel) && is_string($aLevel['parent']) && is_string($aLevel['parent_att']) && $aLevel['parent_att'] !== '') {
			$oRow = PortalList::RowOf($oPolicy, $aLevel['parent'], (int)$oRow->Get($aLevel['parent_att']));
			if ($oRow === null) {
				break;
			}
			$aSources[get_class($oRow)] = (int)$oRow->GetKey();
			$aLevel = $oPolicy->ListDefinition($aLevel['parent']);
		}

		return [$oFrom, $aSources];
	}
}
