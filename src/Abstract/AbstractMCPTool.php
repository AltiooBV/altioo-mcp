<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\Identifier;

use \Mcp\Schema\ToolAnnotations;

abstract class AbstractMCPTool
{
	/**
	 * Namespace owning this tool, e.g. 'core' or your own vendor or module
	 * name. It qualifies the name the client sees, so two extensions that have
	 * never heard of each other cannot end up claiming one identifier.
	 *
	 * 'core' belongs to this module; pick your own.
	 */
	abstract public function getNamespace(): string;

	public function getName(): ?string
	{
		return Identifier::SnakeCase($this->shortClassName());
	}

	/** The class name without its namespace, e.g. ObjectSearchByOQL. */
	final protected function shortClassName(): string
	{
		return (new \ReflectionClass($this))->getShortName();
	}

	/**
	 * What the client sees and calls: namespace and name joined by '_'.
	 *
	 * Final on purpose - an extension cannot accidentally un-qualify itself
	 * back into the shared space. To deliberately take over another element's
	 * identifier, say so through {@see overrides()}.
	 */
	final public function getQualifiedName(): string
	{
		return $this->getNamespace().'_'.$this->getName();
	}

	/**
	 * Qualified name of an element this one deliberately replaces, or null.
	 *
	 * This is the only way to claim an identifier that is not yours. Two
	 * extensions colliding by accident never declare it, which is exactly what
	 * lets the registry tell an intended override from a name clash instead of
	 * guessing from load order.
	 */
	public function overrides(): ?string
	{
		return null;
	}

	abstract public function getDescription(): ?string; //A human-readable description of the tool This can be used by clients to improve the LLM's understanding of available tools. It can be thought of like a "hint" to the model.

	abstract public function getInputSchema(): ?array; // JSON Schema object (as a PHP array) defining the expected input structure for the tool. This allows clients to validate inputs before calling the tool and can also be used by LLMs to better understand how to use the tool.

	public function getAnnotations(): ?ToolAnnotations
	{
		return null; // By default, not open to the world
	}

	/**
	 * Human-readable title for display in a UI.
	 *
	 * The class name, not the identifier: getName() is snake_case because
	 * that is what a model expects to call, which is not what a person wants
	 * to read in a list. Override it with a real title.
	 */
	public function getTitle(): ?string
	{
		return $this->shortClassName();
	}

	public function getIcons(): ?array //list of icon URLs representing the tool
	{
		return null;
	}

	public function getOutputSchema(): ?array // JSON Schema object (as a PHP array) defining the expected output structure
	{
		return null;
	}

	public function getMeta(): ?array //metadata
	{
		return null;
	}

	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * Profiles the caller must hold for this element to be advertised and
	 * served. All of them, not any of them: an empty list (the default) offers
	 * it to everyone who cleared the endpoint gates.
	 *
	 * A visibility filter on top of UserRights, never a replacement for it.
	 *
	 * @return array<int, string>
	 */
	public function requiredProfiles(): array
	{
		return [];
	}
}
