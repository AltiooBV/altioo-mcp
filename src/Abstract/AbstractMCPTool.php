<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\Identifier;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;

use \Mcp\Schema\ToolAnnotations;

/**
 * @since 1.0.0
 */
abstract class AbstractMCPTool
{
	/**
	 * Namespace owning this tool, e.g. 'core' or your own vendor or module
	 * name. It qualifies the name the client sees, so two extensions that have
	 * never heard of each other cannot end up claiming one identifier.
	 *
	 * 'core' belongs to this module; pick your own.
	 *
	 * @since 1.0.0
	 */
	abstract public function getNamespace(): string;

	/**
	 * @since 1.0.0
	 */
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
	 * @since 1.0.0
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
	 *
	 * @since 1.0.0
	 */
	public function overrides(): ?string
	{
		return null;
	}

	/**
	 * @since 1.0.0
	 */
	abstract public function getDescription(): ?string; //A human-readable description of the tool This can be used by clients to improve the LLM's understanding of available tools. It can be thought of like a "hint" to the model.

	/**
	 * @since 1.0.0
	 */
	abstract public function getInputSchema(): ?array; // JSON Schema object (as a PHP array) defining the expected input structure for the tool. This allows clients to validate inputs before calling the tool and can also be used by LLMs to better understand how to use the tool.

	/**
	 * @since 1.0.0
	 */
	public function getAnnotations(): ?ToolAnnotations
	{
		return null; // By default, not open to the world
	}

	/**
	 * Human-readable title for display in a UI, localised.
	 *
	 * Titles are the one thing here a person reads rather than a model, so
	 * they are the one thing translated. Descriptions deliberately are not: a
	 * description is written for the model deciding whether to call the tool,
	 * English is what those models have seen thousands of examples of, and a
	 * description that changes with the caller's language changes what the
	 * model does. See the README, Extending.
	 *
	 * Override {@see defaultTitle()} rather than this method to keep the
	 * lookup; overriding this one is still supported and simply opts out.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Resolved through the dictionary.
	 */
	public function getTitle(): ?string
	{
		return MCPHelper::Translate($this->titleDictionaryKey(), $this->defaultTitle());
	}

	/**
	 * The dictionary entry a pack writes to translate this title.
	 *
	 * Qualified, because a pack and this module can both have a ClassList.
	 *
	 * @since 1.0.0
	 */
	final public function titleDictionaryKey(): string
	{
		return 'MCP:tool:'.$this->getQualifiedName().':title';
	}

	/**
	 * The title as written in code, used when nothing translates it.
	 *
	 * The class name is a poor title - getName() is snake_case because that
	 * is what a model expects to call, which is not what a person wants to
	 * read in a list - so override this with a real one.
	 *
	 * @since 1.0.0
	 */
	protected function defaultTitle(): string
	{
		return $this->shortClassName();
	}

	/**
	 * @since 1.0.0
	 */
	public function getIcons(): ?array //list of icon URLs representing the tool
	{
		return null;
	}

	/**
	 * @since 1.0.0
	 */
	public function getOutputSchema(): ?array // JSON Schema object (as a PHP array) defining the expected output structure
	{
		return null;
	}

	/**
	 * @since 1.0.0
	 */
	public function getMeta(): ?array //metadata
	{
		return null;
	}

	/**
	 * The functional group this element belongs to, e.g. 'datamodel' or
	 * 'tickets'. Defaults to the namespace, which is the vendor.
	 *
	 * The two answer different questions. A namespace says who owns an
	 * identifier, and exists so that two vendors cannot claim one name. A
	 * toolset says what a thing is for, and exists so that an operator can
	 * turn on the half of a pack an instance actually uses - through
	 * mcp_enabled_toolsets, or through a token scope. A pack that does not
	 * care gets one toolset named after itself, which is the right answer for
	 * a pack with four tools in it.
	 *
	 * @since 1.0.0
	 */
	public function getToolset(): string
	{
		return $this->getNamespace();
	}

	/**
	 * @since 1.0.0
	 */
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
	 * @since 1.0.0
	 */
	public function requiredProfiles(): array
	{
		return [];
	}
}
