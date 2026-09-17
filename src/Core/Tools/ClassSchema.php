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
		return 'Describe one iTop class: its attributes (code, label, type, whether required, read-only or sensitive, and allowed values), the relations it takes part in, and its lifecycle states and stimuli. It also reports what the current user may do with the class, so you can pick a call that will succeed instead of discovering the refusal by making it: "rights" grades read, bulkRead, create, bulkCreate, modify, bulkModify, delete and bulkDelete as "yes", "no" or "depends", and each attribute carries the same grade under "modify". "no" means the call is refused outright, so do not attempt it and say so instead. "yes" means only that the class-level check passes - the individual object may still refuse the write - and "depends" means the answer is decided per object. bulkCreate is the one iTop has no right of its own for - creating in bulk needs create and bulkModify together - so it is reported ready-made rather than left for you to combine. Attributes also carry a JSON Schema "format" and "pattern" where one applies; date and date-time values must match the pattern exactly, as iTop rejects the RFC 3339 form. Read this before creating or updating an object of the class, or before applying a stimulus. Use core_class_list to find the class name first. An external key reports its target class rather than its candidate objects: search that class with core_object_search_by_class and pass the id you find.';
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
	 * @return array An array containing the class summary, the caller's rights on the class, its attributes, its relations and its lifecycle
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
