<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

use Altioo\iTop\Extension\MCP\Helper\Identifier;

use Mcp\Schema\Annotations;

abstract class AbstractMCPResourceTemplate
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

	abstract protected function getResourcePath(): string; // contains {variables}

	final public function getUriTemplate(): string
	{
		return self::URI_SCHEME . '://'
			. $this->getResourceNamespace() . '/'
			. $this->getResourcePath();
	}

	public function getMimeType(): ?string //the MIME type, if known and constant for this resource
	{
		return 'application/json';
	}

	public function getAnnotations(): ?Annotations
	{
		return null;
	}

	public function getMeta(): ?array
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
