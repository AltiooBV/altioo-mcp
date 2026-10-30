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
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;

/**
 * Moves an object along its lifecycle the way the portal's buttons do:
 * close a resolved request, reopen one.
 *
 * Only a stimulus the portal offers on the object as it is: one its lifecycle
 * configuration allows this user, that UserRights grants on the object, and
 * that leaves the object's current state - so "close" is refused on a request
 * that is still new, as the portal shows no such button there. The object
 * must be in the portal's write scope.
 *
 * The fields a call may set are those the portal's transition form would ask
 * for - what the target state requires or prompts for - and those its edit
 * form leaves writable, each held to the same rules as any write here.
 *
 * @since 1.2.0
 */
class PortalApplyStimulus extends AbstractPortalTool
{
	protected function defaultTitle(): string
	{
		return 'Apply a Stimulus through the Portal';
	}

	public function getDescription(): ?string
	{
		return 'Apply a state change the portal offers this user on an object now, such as closing a resolved request or reopening it. '
			.'core_portal_get lists the stimuli the object\'s current state accepts; any other is refused. '
			.'fields may set what the transition asks for (the target state\'s mandatory or prompted fields, such as a satisfaction rating) and what the edit form leaves writable. '
			.'The dry run reports the state the object would move to and anything mandatory still missing.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Apply a stimulus through the portal',
			false,  // readOnlyHint
			false,  // destructiveHint
			false,  // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'stimulus'      => [
				'type'        => 'string',
				'description' => 'The stimulus applied.',
			],
			'state'         => [
				'type'        => 'string',
				'description' => 'The state the object is in now: the one it reached on a real call, the one it is still in on a dry run.',
			],
			'would_move_to' => [
				'type'        => 'string',
				'description' => 'The state this transition targets. On a real call the object is already there, so it equals state.',
			],
			'after'         => WritePlan::AfterSchemaProperty(),
			'changes'       => WritePlan::ChangesSchemaProperty('The attributes the transition set, including the ones the lifecycle filled in by itself.'),
			'overridden'    => WritePlan::OverriddenSchemaProperty(),
			'defaulted'     => WritePlan::DefaultedSchemaProperty(),
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
				'stimulus' => [
					'type'        => 'string',
					'description' => 'A stimulus code from the object\'s stimuli in core_portal_get, e.g. ev_close.',
				],
				'fields'   => [
					'type'                 => MCPHelper::MAP_TYPE,
					'description'          => 'Optional attribute values the transition asks for, e.g. a satisfaction rating when closing.',
					'additionalProperties' => true,
					'default'              => new \stdClass(),
				],
				'simulate' => WritePlan::SimulateSchemaProperty('apply the transition'),
				'comment'  => ChangeTracking::CommentSchemaProperty('the transition is being applied'),
			],
			'required'   => ['class', 'id', 'stimulus'],
		];
	}

	/**
	 * @param string      $class    The class of the object
	 * @param int         $id       Its id
	 * @param string      $stimulus A stimulus the portal offers on the object now
	 * @param array       $fields   Values the transition asks for
	 * @param bool        $simulate When true (default), the transition is validated and described but not applied
	 * @param string|null $comment  Why, recorded in the object's history
	 *
	 * @return mixed The object's class and id, its state and the one it moves to, and what changes
	 * @throws ToolCallException if the portal does not offer the stimulus on the object now, or the transition is invalid
	 */
	public static function execute(string $class, int $id, string $stimulus, array $fields = [], bool $simulate = WritePlan::SIMULATE_BY_DEFAULT, ?string $comment = null): mixed
	{
		$simulate = WritePlan::Simulated($simulate);

		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException('The database is in read-only mode, cannot apply stimulus on objects.');
		}

		$oPolicy = self::policy();
		$oObject = self::writable($oPolicy, trim($class), $id);
		$sClass = get_class($oObject);

		$sAccessRefusal = AccessGrants::RefusalFor($sClass, $id, $fields);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
		}
		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$sClass}::{$id} is in read-only mode, cannot apply stimulus on object.");
		}

		$sState = (string)$oObject->GetState();
		$aOffered = $oPolicy->StimuliOn($oObject);
		if (!isset($aOffered[$stimulus])) {
			// The code is the client's text: quoted only when it is shaped like one.
			throw new ToolCallException(
				(MCPHelper::LooksLikeClassName($stimulus) ? "The portal does not offer '{$stimulus}'" : 'The portal does not offer that stimulus')
				." on {$sClass} {$id} in state '{$sState}'. "
				.($aOffered === [] ? 'It offers none in this state.' : 'It offers: '.implode(', ', array_keys($aOffered)).'.')
			);
		}

		$aTransitions = MetaModel::EnumTransitions($sClass, $sState);
		$sTarget = (string)$aTransitions[$stimulus]['target_state'];

		self::refuseUnsettable(
			$fields,
			self::settable($sClass, self::portalStep(static fn (): array => $oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject))),
			self::prompted($oObject, $sTarget),
			'transition or edit'
		);

		$oInstanceSet = DBObjectSet::FromObject($oObject);
		[$aValues, $aIssues] = WritePlan::ValidatedValues($oObject, $sClass, $fields, $oInstanceSet, $stimulus);
		if ($aIssues !== []) {
			throw new ToolCallException('Failed to apply stimulus due to fields : '.implode(', ', $aIssues));
		}

		[, $aOverridden, $aChanges] = self::plan($oPolicy, $oObject, $aValues, "{$sClass}::{$id}");

		$aMissing = self::missingMandatory($oObject, $sTarget);
		if ($aMissing !== []) {
			throw new ToolCallException("Missing mandatory attribute(s) for applying stimulus '{$stimulus}': ".implode(', ', $aMissing).'.');
		}

		// A dry run does not move the state, so the change the transition is
		// guaranteed to make is added from the lifecycle. A real call has it
		// already, put there by ApplyStimulus().
		$sStateAttCode = MetaModel::GetStateAttributeCode($sClass);
		if ($simulate && $sStateAttCode !== '' && !array_key_exists($sStateAttCode, $aChanges)) {
			$aChanges[$sStateAttCode] = $sTarget;
		}

		$aAnswer = static fn (bool $bSimulated, string $sNow): mixed => ToolOutput::Structured(['class' => $sClass]
			+ WritePlan::Identity($sClass, $id)
			+ [
				'stimulus'      => $stimulus,
				'simulated'     => $bSimulated,
				'valid'         => true,
				'state'         => $sNow,
				'would_move_to' => $sTarget,
				'after'         => WritePlan::After($oObject, $sClass, array_keys($aValues), $bSimulated),
				'changes'       => WritePlan::Map($aChanges),
				'overridden'    => WritePlan::Map($aOverridden),
				'defaulted'     => WritePlan::Defaulted($aChanges, array_keys($aValues)),
			]);

		if ($simulate) {
			return $aAnswer(true, $sState);
		}

		ChangeTracking::Explain($comment);

		try {
			$bApplied = $oObject->ApplyStimulus($stimulus);
		} catch (\Throwable $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to apply stimulus '{$stimulus}' on {$sClass}::{$id}", $e));
		}
		// False when a condition on the transition was not met; nothing is thrown.
		if (!$bApplied) {
			throw new ToolCallException("Failed to apply stimulus '{$stimulus}' on {$sClass}::{$id}.");
		}

		return $aAnswer(false, (string)$oObject->GetState());
	}

	/**
	 * What the portal's transition form asks for: the target state's
	 * must-change and must-prompt attributes, and the mandatory ones still
	 * empty.
	 *
	 * @return array<int, string>
	 */
	private static function prompted(DBObject $oObject, string $sTarget): array
	{
		$aPrompted = [];
		foreach (self::targetFlags($oObject, $sTarget) as $sAttCode => $iFlags) {
			if (($iFlags & (OPT_ATT_MUSTCHANGE | OPT_ATT_MUSTPROMPT)) !== 0
				|| (($iFlags & OPT_ATT_MANDATORY) !== 0 && self::isEmpty($oObject, $sAttCode))) {
				$aPrompted[] = $sAttCode;
			}
		}

		return $aPrompted;
	}

	/**
	 * The target state's mandatory attributes still empty once the values
	 * are set.
	 *
	 * @return array<int, string>
	 */
	private static function missingMandatory(DBObject $oObject, string $sTarget): array
	{
		$aMissing = [];
		foreach (self::targetFlags($oObject, $sTarget) as $sAttCode => $iFlags) {
			if (($iFlags & OPT_ATT_MANDATORY) !== 0 && self::isEmpty($oObject, $sAttCode)) {
				$aMissing[] = $sAttCode;
			}
		}

		return $aMissing;
	}

	/**
	 * @return array<string, int>
	 */
	private static function targetFlags(DBObject $oObject, string $sTarget): array
	{
		$aStates = MetaModel::EnumStates(get_class($oObject));

		return array_map('intval', $aStates[$sTarget]['attribute_list'] ?? []);
	}

	/**
	 * Soft comparison on purpose: Get() returns a string, an int, an
	 * ormLinkSet or a date depending on the attribute, as ObjectApplyStimulus
	 * compares it.
	 */
	private static function isEmpty(DBObject $oObject, string $sAttCode): bool
	{
		return $oObject->Get($sAttCode) == '';
	}
}
