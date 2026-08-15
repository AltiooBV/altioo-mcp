<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Abstract;

abstract class AbstractMCPPrompt
{
	public function getName(): ?string //a short identifier for this prompt - defaults to the class name
	{
		$ref = new \ReflectionClass($this);
		return $ref->getShortName();
	}

	abstract public function getTitle(): ?string; //Human-readable title for display in UI

	abstract public function getDescription(): ?string; // Description of the prompt

	public function getIcons(): ?array //list of icon URLs representing the prompt
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
