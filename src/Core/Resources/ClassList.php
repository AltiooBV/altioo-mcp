<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\ResourceOutput;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

/**
 * @since 1.0.0
 */
class ClassList extends AbstractMCPResource
{

	protected function defaultTitle(): string
	{
		return 'iTop Classes';
	}

	public function getDescription(): ?string
	{
		return 'List all available iTop classes. Use the itop://core/class/{class} resource template to read details about a specific class, or the core_class_list tool for the same list narrowed by category or name.';
	}

	/** The datamodel itself: what classes exist and what they look like. */
	public function getToolset(): string
	{
		return 'datamodel';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	protected function getResourcePath(): string
	{
		return 'classes';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::Assistant],
			1,
		);
	}

	/**
	 * @return string The same envelope core_class_list returns, with neither
	 *                narrowing applied: a static URI takes no arguments.
	 * @throws ResourceReadException if the class list cannot be encoded.
	 */
	public function read(): mixed
	{
		return ResourceOutput::Json(DatamodelReader::ClassListPayload());
	}
}
