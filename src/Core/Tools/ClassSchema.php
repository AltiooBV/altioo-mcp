<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalDescription;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * One class in detail, as a tool.
 *
 * Same content as itop://core/class/{class} - see
 * {@see \Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader} for why the schema
 * is exposed on both surfaces. This is the one every other core tool depends
 * on: attribute codes, allowed values and stimuli are what an ObjectCreate or
 * an ObjectApplyStimulus call has to get right, and a model that cannot read
 * them invents them.
 *
 * @since 1.0.0
 */
class ClassSchema extends AbstractMCPTool implements PortalAware
{

	public function getNamespace(): string
	{
		return 'core';
	}

	/** The datamodel itself: what classes exist and what they look like. */
	public function getToolset(): string
	{
		return 'datamodel';
	}


	protected function defaultTitle(): string
	{
		return 'Get Class Schema';
	}

	public function getDescription(): ?string
	{
		if (PortalMode::Current() !== null) {
			return self::PORTAL_DESCRIPTION;
		}

		return 'Describe one iTop class: attributes, relations, lifecycle states and stimuli, and what this user may do with it. '
			.'Attributes arrive in two blocks: "attributes" can be written - that is what a create or an update needs - and "derived" cannot - computed or structural, including the _friendlyname each external key carries - which on a class with many of them is most of the payload. '
			.'"required" is the datamodel forbidding null; on a derived attribute that is a column iTop computes and not a value you supply. '
			.'An attribute with "dependsOn" is declared as deriving from those attributes - a ticket\'s priority from its urgency and impact - so a value you set for it may be recomputed and discarded on the write; set what it derives from instead, and the write reports what it kept under "overridden". '
			.'"rights" grades read, bulkRead, create, bulkCreate, modify, bulkModify, delete and bulkDelete as "yes", "no" or "depends", and each attribute carries the same grade under "modify". '
			.'"no" is refused outright: do not attempt it. "yes" means the class check passes and the object may still refuse. "depends" is answered per object. '
			.'The grades answer for this endpoint and not only for iTop: a class that decides what this endpoint may do - a token, an account, a rights link - is graded "no" for every write whatever your profile says, and "restricted" explains which restriction applies. Reading is never narrowed by it. '
			.'Attributes carry a JSON Schema "format" and "pattern" where one applies; a date or date-time must match the pattern, since iTop rejects the RFC 3339 form. '
			.'An external key reports its target class, not its candidate objects: search that class with core_object_search_by_class and pass the id you find. '
			.'Read this before creating or updating an object of the class, or before applying a stimulus; core_class_list finds the class name.';
	}

	/** What this tool is to a portal-mode connection: one class as the portal offers it. */
	private const PORTAL_DESCRIPTION = 'Describe one class as this user\'s portal offers it: whether they may read and change objects of it, how objects of it are created, the fields shown when viewing, editing and creating one, and the stimuli they may apply. Pass a class from core_class_list. '.PortalDescription::READING;

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			title: $this->getTitle() ?? 'Get iTop class schema',
			readOnlyHint: true,
			destructiveHint: false,
			idempotentHint: true,
			openWorldHint: false,
		);
	}

	public function getInputSchema(): ?array
	{
		if (PortalMode::Current() !== null) {
			return [
				'type'       => 'object',
				'properties' => [
					'class' => [
						'type'        => 'string',
						'description' => 'A class from core_class_list, e.g. UserRequest.',
					],
				],
				'required'   => ['class'],
			];
		}

		return [
			'type'       => 'object',
			'properties' => [
				'class' => [
					'type'        => 'string',
					'description' => 'iTop class name (e.g. UserRequest, Server). Use the core_class_list tool to list available classes.',
				],
				'include' => [
					'type'        => 'string',
					'description' => 'Blocks to return, comma-separated: '.implode(', ', DatamodelReader::BLOCKS).'. Defaults to all of them. A stock ticket class answers with about seventy attributes, so include="attributes" is the cheap call before a create.',
					'default'     => DatamodelReader::BLOCKS_ALL,
				],
				'attributes' => [
					'type'        => 'string',
					'description' => 'Attribute codes to describe, comma-separated, or "*" for every one. Narrows the attributes and derived blocks only.',
					'default'     => DatamodelReader::ATTRIBUTES_ALL,
				],
				'required_only' => [
					'type'        => 'boolean',
					'description' => 'Keep only the attributes a write must set, and drop the derived ones - nothing can set those. Answers "what is mandatory here" without the rest of the class.',
					'default'     => false,
				],
			],
			'required' => ['class'],
		];
	}

	/**
	 * @param string $class         The class to describe, e.g. 'UserRequest'
	 * @param string $include       Blocks to return, comma-separated; '*' for all of them
	 * @param string $attributes    Attribute codes to describe, comma-separated; '*' for all of them
	 * @param bool   $required_only Keep only the attributes a write must set
	 *
	 * @return mixed The class summary and whichever of rights, attributes, derived, relations and lifecycle were asked for, plus a `reported` block echoing the narrowing
	 * @throws ToolCallException if the class is unknown or access is denied.
	 */
	public static function execute(
		string $class,
		string $include = DatamodelReader::BLOCKS_ALL,
		string $attributes = DatamodelReader::ATTRIBUTES_ALL,
		bool   $required_only = false,
	): mixed
	{
		if ($class === '') {
			throw new ToolCallException("Missing class argument.");
		}

		// In portal mode, the class as the portal offers it: its forms,
		// lifecycle and create actions, not the datamodel (doc/adr/0006).
		if (PortalMode::Current() !== null) {
			return self::portalRefusal(static fn () => ToolOutput::Json(PortalDescription::Of(PortalPolicy::ForCurrentRequest(), trim($class))));
		}

		// Unknown and forbidden answer alike, so calling this tool cannot map
		// out the classes the caller is not allowed to see.
		if (!DatamodelReader::IsReadable($class)) {
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($class));
		}

		return ToolOutput::Json(DatamodelReader::Describe($class, $include, $attributes, $required_only));
	}
}
