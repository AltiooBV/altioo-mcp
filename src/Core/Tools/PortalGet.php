<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * One object as the portal shows it to this user, and what they may do to it
 * now.
 *
 * Read by the scopes, as the portal's object page reads it: a ticket's caller
 * or service resolves whether or not a brick lists it
 * (doc/adr/0001-portal-mode-bricks-bound-discovery.md). The fields are those
 * of the portal's view form for the object as it is - its state, and any
 * condition in the layout - which is the answer core_portal_describe cannot
 * give, since it reads no object. Beside them, when the user may change it,
 * the fields its edit form lets them set and the stimuli its state accepts:
 * "close" is offered on a resolved request and not on a new one.
 *
 * @since 1.2.0
 */
class PortalGet extends AbstractPortalTool
{
	protected function defaultTitle(): string
	{
		return 'Get from the Portal';
	}

	public function getDescription(): ?string
	{
		return 'Read one object as this user\'s portal shows it: the fields of its view form, for the object in its current state, and the attachments listed beside it. '
			.'When the portal lets the user change it, also the fields its edit form lets them set now (for core_portal_update) and the stimuli its current state accepts (for core_portal_apply_stimulus). '
			.'class and id come from a row of core_portal_list; a parent class such as Ticket is fine, and the answer names the class the object is.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get from the portal',
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
				'class' => [
					'type'        => 'string',
					'description' => 'The class of the object, as a row of core_portal_list gives it.',
				],
				'id'    => [
					'type'        => 'integer',
					'description' => 'The id of the object.',
				],
			],
			'required'   => ['class', 'id'],
		];
	}

	/**
	 * @param string $class The class of the object
	 * @param int    $id    Its id
	 *
	 * @return mixed The object's view fields, attachments, and what may be done to it now
	 * @throws ToolCallException if the portal does not show the user this object, or cannot be read
	 */
	public static function execute(string $class, int $id): mixed
	{
		$oPolicy = self::policy();
		$oObject = self::readable($oPolicy, trim($class), $id);
		$sClass = get_class($oObject);

		try {
			$aObject = self::serialized($oObject, self::shown($sClass, self::portalStep(
				static fn (): array => $oPolicy->Fields($sClass, PortalPolicy::MODE_VIEW, $oObject)
			)));
			$aObject['attachments'] = self::attachmentsOf($oObject);

			$aActions = ['editable' => false, 'settable_fields' => [], 'stimuli' => []];
			if ($oPolicy->Writable($sClass, $id) !== null) {
				$aActions['editable'] = true;
				$aActions['settable_fields'] = self::settable($sClass, self::portalStep(
					static fn (): array => $oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject)
				));
				foreach ($oPolicy->StimuliOn($oObject) as $sCode => $sLabel) {
					$aActions['stimuli'][] = ['code' => $sCode, 'label' => $sLabel];
				}
			}

			return ToolOutput::Json([
				'requested_class' => trim($class),
				'class'           => $sClass,
				'object'          => $aObject,
				'actions'         => $aActions,
			]);
		} catch (ToolCallException $oException) {
			throw $oException;
		} catch (\Throwable $oException) {
			throw new ToolCallException(MCPHelper::OpaqueFailure("Failed to read {$sClass}::{$id}", $oException));
		}
	}
}
