<?php

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
 */
class ClassSchema extends AbstractMCPTool
{

	public function getNamespace(): string
	{
		return 'core';
	}

	public function getTitle(): ?string
	{
		return 'Get Class Schema';
	}

	public function getDescription(): ?string
	{
		return 'Describe one iTop class: its attributes (code, label, type, whether required, read-only or sensitive, and allowed values), the relations it takes part in, and its lifecycle states and stimuli. Attributes also carry a JSON Schema "format" and "pattern" where one applies; date and date-time values must match the pattern exactly, as iTop rejects the RFC 3339 form. Read this before creating or updating an object of the class, or before applying a stimulus. Use core_ClassList to find the class name first. An external key reports its target class rather than its candidate objects: search that class with core_ObjectSearchByClass and pass the id you find.';
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
					'description' => 'iTop class name (e.g. UserRequest, Server). Use the core_ClassList tool to list available classes.',
				],
			],
			'required' => ['class'],
		];
	}

	/**
	 * @param string $class The class to describe, e.g. 'UserRequest'
	 * @return array An array containing the class summary, its attributes, its relations and its lifecycle
	 * @throws ToolCallException if the class is unknown or access is denied.
	 */
	public static function execute(
		string $class,
	): mixed {
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
