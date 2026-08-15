<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\Identifier;

use Mcp\Schema\Annotations;

abstract class AbstractMCPResource
{
	protected const URI_SCHEME = 'itop';

	public function getName(): ?string
	{
		return Identifier::SnakeCase($this->shortClassName());
	}

	/** The class name without its namespace, e.g. ObjectSearchByOQL. */
	final protected function shortClassName(): string
	{
		return (new \ReflectionClass($this))->getShortName();
	}

	abstract public function getTitle(): ?string; //human-readable title for display in UI

	abstract public function getDescription(): ?string;

	abstract protected function getResourceNamespace(): string;

	/**
	 * Namespace owning this element, the same one that qualifies its URI.
	 * Named alike on all four kinds so the registry can read it off any of
	 * them. 'core' belongs to this module; pick your own.
	 */
	final public function getNamespace(): string
	{
		return $this->getResourceNamespace();
	}

	abstract protected function getResourcePath(): string; // no {variables}

	abstract public function read(): mixed;

	final public function getUri(): string
	{
		return self::URI_SCHEME . '://'
			. $this->getResourceNamespace() . '/'
			. $this->getResourcePath();
	}

	public function getMimeType(): ?string //the MIME type, if known and constant for this resource
	{
		return 'application/json';
	}

	public function getSize(): ?int //the size in bytes, if known and constant
	{
		return null;
	}

	public function getAnnotations(): ?Annotations
	{
		return null;
	}

	public function getIcons(): ?array //list of icon URLs representing the resource
	{
		return null;
	}

	public function getMeta(): ?array
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
	 */
	public function getToolset(): string
	{
		return $this->getNamespace();
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

	/** Namespace and name joined by '_', for clients that list by name. */
	final public function getQualifiedName(): string
	{
		return $this->getNamespace().'_'.$this->getName();
	}

	/**
	 * URI of an element this one deliberately replaces, or null. The only way
	 * to claim an identifier that is not yours.
	 */
	public function overrides(): ?string
	{
		return null;
	}
}
