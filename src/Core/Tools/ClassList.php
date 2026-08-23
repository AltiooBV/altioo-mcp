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
 * The readable classes of the datamodel, as a tool.
 *
 * Same list as itop://core/classes, narrowable - see
 * {@see \Altioo\iTop\Extension\MCP\Helper\DatamodelReader} for why the schema
 * is exposed on both surfaces. Narrowing is what the resource cannot offer: a
 * stock iTop declares several hundred classes, and the handful a model needs
 * are almost always one category ('bizmodel') or one word ('ticket') away.
 *
 * @since 1.0.0
 */
class ClassList extends AbstractMCPTool
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
		return 'List Classes';
	}

	public function getDescription(): ?string
	{
		return 'List the iTop classes the current user can read, with their label, description and place in the class hierarchy. A full datamodel holds several hundred classes, so narrow it: category "bizmodel" keeps the business objects (tickets, CIs, contacts) and drops the technical ones, and filter keeps only classes whose name, label or description contains the given text. Call core_class_schema next for the attributes of one class.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'List iTop classes',
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
				'category' => [
					'type'        => 'string',
					'description' => 'Restrict to one datamodel category, e.g. "bizmodel" for business objects or "searchable" for the ones exposed to search. Empty (the default) means every class. An unknown category is rejected with the list of valid ones.',
					'default'     => '',
				],
				'filter'   => [
					'type'        => 'string',
					'description' => 'Case-insensitive text kept only if it appears in the class name, its label or its description, e.g. "ticket" or "server".',
					'default'     => '',
				],
			],
			'required' => [],
		];
	}

	/**
	 * @param string $category Optional datamodel category to restrict the list to, e.g. 'bizmodel'
	 * @param string $filter Optional case-insensitive text matched against class name, label and description
	 * @return array An array containing the category, the filter, the number of classes returned, and the classes themselves
	 * @throws ToolCallException if the category is not one the datamodel declares.
	 */
	public static function execute(
		string $category = '',
		string $filter = '',
	): mixed
	{
		$sCategory = trim($category);
		$sFilter = trim($filter);

		if ($sCategory !== '' && !in_array($sCategory, DatamodelReader::Categories(), true)) {
			// Naming the valid ones costs one line and saves the model a round of guessing.
			throw new ToolCallException(sprintf(
				"Unknown category '%s'. Valid categories: %s.",
				$sCategory,
				implode(', ', DatamodelReader::Categories())
			));
		}

		// The envelope itself is DatamodelReader's, so that itop://core/classes
		// answers with the same shape for the same data.
		return ToolOutput::Json(DatamodelReader::ClassListPayload($sCategory, $sFilter));
	}
}
