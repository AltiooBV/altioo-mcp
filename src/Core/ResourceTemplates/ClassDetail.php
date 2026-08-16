<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\ResourceTemplates;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

/**
 * @since 1.0.0
 */
class ClassDetail extends AbstractMCPResourceTemplate
{

	protected function defaultTitle(): string
	{
		return 'iTop Class Detail';
	}

	public function getDescription(): ?string
	{
		return 'Get details of a specific iTop class: attributes, relations and lifecycle. URI: itop://core/class/{class}. The core_class_schema tool returns the same thing for clients that do not read resource templates.';
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
		return 'class/{class}';
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::User, Role::Assistant],
			0.7,
		);
	}

	/**
	 * @param string $uri The full URI that was called, e.g. 'itop://core/class/UserRequest'
	 * @param string $class The {class} variable extracted from the URI, e.g. 'UserRequest'
	 * @return string A JSON-encoded array containing class details
	 * @throws ResourceReadException if the class is missing, unknown, or access is denied.
	 */
	public function read(string $uri, string $class): mixed
	{
		$sClass = $class;

		if ($sClass === '') {
			throw new ResourceReadException("Missing class argument.");
		}

		// Unknown and forbidden answer alike, so reading this template cannot
		// map out the classes the caller is not allowed to see.
		if (!DatamodelReader::IsReadable($sClass)) {
			throw new ResourceReadException("Unknown class '{$sClass}'.");
		}

		return json_encode(DatamodelReader::Describe($sClass));
	}
}
