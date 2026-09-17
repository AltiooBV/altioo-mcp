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
		return 'List the iTop classes this user can read, with their label, description and place in the hierarchy. '
			.'A full datamodel holds several hundred, so narrow it: category "bizmodel" keeps the business objects and drops the technical ones, filter matches name, label or description, and may keeps the classes this user may act on - may="create" answers "what can I create here" in one call. '
			.'A class returned under may carries all eight rights grades, not only the one asked for; may="*" reports that block on every class and drops none. '
			.'Call core_class_schema next for the attributes of one class.';
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
				'may'      => [
					'type'        => 'string',
					'description' => 'Keep only the classes this caller may act on through the named gate, and report every gate on each one. Empty (the default) means no such narrowing and no rights reported. "*" reports the gates without narrowing on any of them, which is the one call that answers "what may I do across all of these". A class the named gate refuses outright is dropped; one graded "depends" is kept, because that is the datamodel asking for the object rather than refusing the class. The gates are the ones the tools check: '
						.implode(', ', DatamodelReader::RightsKeys()).'.',
					'enum'        => DatamodelReader::MayValues(),
					'default'     => '',
				],
			],
			'required' => [],
		];
	}

	/**
	 * @param string $category Optional datamodel category to restrict the list to, e.g. 'bizmodel'
	 * @param string $filter Optional case-insensitive text matched against class name, label and description
	 * @param string $may Optional rights gate to narrow on, e.g. 'create', or '*' to attach the rights block without narrowing; either way each class returned carries all eight grades
	 * @return array An array containing the category, the filter, the gate narrowed on, the number of classes returned, and the classes themselves
	 * @throws ToolCallException if the category is not one the datamodel declares, or the gate is not one the tools check.
	 */
	public static function execute(
		string $category = '',
		string $filter = '',
		string $may = '',
	): mixed
	{
		$sCategory = trim($category);
		$sFilter = trim($filter);
		$sMay = trim($may);

		if ($sCategory !== '' && !in_array($sCategory, DatamodelReader::Categories(), true)) {
			// Naming the valid ones costs one line and saves the model a round of guessing.
			throw new ToolCallException(sprintf(
				"Unknown category '%s'. Valid categories: %s.",
				$sCategory,
				implode(', ', DatamodelReader::Categories())
			));
		}

		if ($sMay !== '' && !in_array($sMay, DatamodelReader::MayValues(), true)) {
			// Same courtesy as the category above: the valid ones cost one line.
			throw new ToolCallException(sprintf(
				"Unknown gate '%s'. Valid gates: %s. Pass '%s' to report them all without narrowing.",
				$sMay,
				implode(', ', DatamodelReader::RightsKeys()),
				DatamodelReader::RIGHTS_ALL
			));
		}

		// The envelope itself is DatamodelReader's, so that itop://core/classes
		// answers with the same shape for the same data.
		return ToolOutput::Json(DatamodelReader::ClassListPayload($sCategory, $sFilter, $sMay));
	}
}
