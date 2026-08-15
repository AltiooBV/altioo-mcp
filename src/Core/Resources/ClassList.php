<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Resources;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

class ClassList extends AbstractMCPResource
{

	public function getTitle(): ?string
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

	public function read(): mixed
	{
		return json_encode(DatamodelReader::ListClasses());
	}
}
