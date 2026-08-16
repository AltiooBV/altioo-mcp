<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\Identifier;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;

/**
 * @since 1.0.0
 */
abstract class AbstractMCPPrompt
{
	/**
	 * Namespace owning this prompt, e.g. 'core' or your own vendor or module
	 * name. It qualifies the name the client sees. 'core' belongs to this
	 * module; pick your own.
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

	/** What the client sees and calls: namespace and name joined by '_'. */
	/**
	 * @since 1.0.0
	 */
	final public function getQualifiedName(): string
	{
		return $this->getNamespace().'_'.$this->getName();
	}

	/**
	 * Qualified name of an element this one deliberately replaces, or null.
	 * The only way to claim an identifier that is not yours.
	 *
	 * @since 1.0.0
	 */
	public function overrides(): ?string
	{
		return null;
	}

	/**
	 * Human-readable title for display in a UI, localised.
	 *
	 * Titles are translated; descriptions are not. See the README, Extending,
	 * and {@see \Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool::getTitle()}
	 * for why the two are treated differently.
	 *
	 * Override {@see defaultTitle()} rather than this method to keep the
	 * lookup; overriding this one is still supported and simply opts out.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Resolved through the dictionary; was abstract.
	 */
	public function getTitle(): ?string
	{
		return MCPHelper::Translate($this->titleDictionaryKey(), $this->defaultTitle());
	}

	/**
	 * The dictionary entry a pack writes to translate this title.
	 *
	 * @since 1.0.0
	 */
	final public function titleDictionaryKey(): string
	{
		return 'MCP:prompt:'.$this->getQualifiedName().':title';
	}

	/**
	 * The title as written in code, used when nothing translates it.
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
	abstract public function getDescription(): ?string; // Description of the prompt

	/**
	 * @since 1.0.0
	 */
	public function getIcons(): ?array //list of icon URLs representing the prompt
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
