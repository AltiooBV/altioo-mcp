<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * One class in detail, as a tool.
 *
 * Same content as itop://core/class/{class} - see
 * {@see \Altioo\iTop\Extension\MCP\Helper\DatamodelReader} for why the schema
 * is exposed on both surfaces. This is the one every other core tool depends
 * on: attribute codes, allowed values and stimuli are what an ObjectCreate or
 * an ObjectApplyStimulus call has to get right, and a model that cannot read
 * them invents them.
 *
 * @since 1.0.0
 */
class ClassSchema extends AbstractMCPTool
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
		return 'Describe one iTop class: attributes, relations, lifecycle states and stimuli, and what this user may do with it. '
			.'Attributes arrive in two blocks: "attributes" can be written - that is what a create or an update needs - and "derived" cannot - computed or structural, including the _friendlyname each external key carries - which on a class with many of them is most of the payload. '
			.'"rights" grades read, bulkRead, create, bulkCreate, modify, bulkModify, delete and bulkDelete as "yes", "no" or "depends", and each attribute carries the same grade under "modify". '
			.'"no" is refused outright: do not attempt it. "yes" means the class check passes and the object may still refuse. "depends" is answered per object. '
			.'Attributes carry a JSON Schema "format" and "pattern" where one applies; a date or date-time must match the pattern, since iTop rejects the RFC 3339 form. '
			.'An external key reports its target class, not its candidate objects: search that class with core_object_search_by_class and pass the id you find. '
			.'Read this before creating or updating an object of the class, or before applying a stimulus; core_class_list finds the class name.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get iTop class schema',
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
					'description' => 'iTop class name (e.g. UserRequest, Server). Use the core_class_list tool to list available classes.',
				],
			],
			'required' => ['class'],
		];
	}

	/**
	 * @param string $class The class to describe, e.g. 'UserRequest'
	 * @return array An array containing the class summary, the caller's rights on the class, its writable attributes, its derived ones, its relations and its lifecycle
	 * @throws ToolCallException if the class is unknown or access is denied.
	 */
	public static function execute(
		string $class,
	): mixed
	{
		if ($class === '') {
			throw new ToolCallException("Missing class argument.");
		}

		// Unknown and forbidden answer alike, so calling this tool cannot map
		// out the classes the caller is not allowed to see.
		if (!DatamodelReader::IsReadable($class)) {
			throw new ToolCallException("Unknown class '{$class}'.");
		}

		return ToolOutput::Json(DatamodelReader::Describe($class));
	}
}
