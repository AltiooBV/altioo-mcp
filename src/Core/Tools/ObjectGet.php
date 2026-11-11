<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;


/**
 * Retrieve a single iTop object by class and ID.
 *
 * Served in portal mode too (doc/adr/0006-one-tool-surface-the-portal-is-a-policy.md):
 * {@see ObjectAccess} adds the portal's limits to the console's, so a portal
 * user reads the objects its scopes hold, the fields its view form shows,
 * and the gates and stimuli it offers.
 *
 * @since 1.0.0
 */
class ObjectGet extends AbstractMCPTool implements PortalAware
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
		if (PortalMode::Current() !== null) {
			return self::PORTAL_DESCRIPTION;
		}

		return 'Retrieve a single iTop object by its class and ID. Returns all readable attributes, plus an audit block saying when the object was created and when it was last changed, with the user behind each: iTop keeps no such field on the object itself. It also reports what may be done to this object now - the update and delete gates answered for this object rather than for its class, and the stimuli its current state accepts, which is what core_object_apply_stimulus will take. Call core_object_history for the full record of what changed. '
			.'Files attached to an object are not attributes of it: they are Attachment objects pointing back at it, so core_object_search_by_class on Attachment with item_class and item_id lists them.';
	}

	/** What this tool is to a portal-mode connection, which has no class schema yet and no attachment search. */
	private const PORTAL_DESCRIPTION = 'Read one object this user\'s portal shows them: the fields of its view form for the object as it is now (output_fields narrows them), the attachments listed beside it, and what they may do to it now - its rights, the stimuli its current state accepts (for core_object_apply_stimulus), and the fields its edit form lets them set (settable_fields, for core_object_update). class and id come from a row of core_portal_list; a parent class such as Ticket is fine, and the answer names the class the object is. core_object_history gives what changed, where the portal shows history.';

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'Get iTop object',
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
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

		if (!ExclusionPolicy::Serves($class)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class));
		}

		if (ObjectHistory::IsReserved($class)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $class));
		}

		if (!UserRights::IsActionAllowed($class, UR_ACTION_READ)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class)); // exists, but not for this account to read
		}

		$oAccess = self::portalRefusal(static fn () => ObjectAccess::Current());
		[$sFinalClass, $aObject] = self::portalRefusal(static function () use ($oAccess, $class, $id, $output_fields): array {
			// The object gate before the object is read, so that a refusal
			// cannot tell that it exists; the leaf's own rights are asked too.
			$oObject = $oAccess->Readable($class, $id) ?? throw new ToolCallException("Object {$class}::{$id} not found.");
			$sFinalClass = get_class($oObject);

			$aFields = self::fieldsOf($oAccess, $oObject, $output_fields);
			$aObject = ObjectSerializer::Serialize($oObject, $sFinalClass, $aFields);

			// Unconditional where it is given, being two indexed single-row
			// reads for one object. The search tools ask for it, because there
			// the count of those reads is the page size.
			$aAudit = $oAccess->Attribution($oObject);
			if ($aAudit !== null) {
				$aObject['audit'] = $aAudit;
			}

			// What may be done to this object now, rather than to its class:
			// the write gates with the 'depends' ones resolved, and the
			// stimuli its current state accepts.
			$oInstanceSet = null;
			$aObject['rights'] = $oAccess->RightsOn($oObject, $oInstanceSet);
			$aObject['stimuli'] = $oAccess->StimuliOn($oObject, $aObject['rights'], $oInstanceSet);
			$aObject += $oAccess->Extras($oObject);
			return [$sFinalClass, $aObject];
		});

		$aResult = [
			'requested_class'  => $class,
			'class' => $sFinalClass,
			'object' => $aObject,
		];
		$aUntrusted = ObjectSerializer::UntrustedText($sFinalClass, $aObject);
		if ($aUntrusted !== null) {
			$aResult['untrusted_text'] = $aUntrusted;
		}

		return ToolOutput::Json($aResult);
	}

	/**
	 * The attributes to report: those output_fields names, within those this
	 * mode lets a read report. One it does not - a field the portal's view
	 * form hides - is answered as a code the class lacks, so a read cannot map
	 * out what the portal withholds.
	 *
	 * @return array<int, string>|null Null for every attribute the rights let through.
	 */
	private static function fieldsOf(ObjectAccess $oAccess, \DBObject $oObject, string $sOutputFields): ?array
	{
		$sClass = get_class($oObject);
		$aVisible = $oAccess->VisibleFields($oObject);
		if ($aVisible === null) {
			return ObjectSerializer::ParseFieldList($sClass, $sOutputFields);
		}

		$sOutputFields = trim($sOutputFields);
		if ($sOutputFields === '' || $sOutputFields === ObjectSerializer::ALL_FIELDS) {
			return $aVisible;
		}

		// Against the form alone, the class never asked: a list naming a hidden
		// code beside a code the class lacks must not name only the second.
		$aRequested = array_values(array_diff(array_filter(array_map('trim', explode(',', $sOutputFields)), 'strlen'), ['id', 'class']));
		$aHidden = array_values(array_diff($aRequested, $aVisible));
		if ($aHidden !== []) {
			throw new ToolCallException(ObjectSerializer::UnknownAttributes($aHidden, $sClass));
		}

		return $aRequested;
	}
}
