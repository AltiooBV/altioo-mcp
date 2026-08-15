<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResourceTemplate;

class FixtureResourceTemplate extends AbstractMCPResourceTemplate
{
	public function getTitle(): ?string
	{
		return 'Fixture Resource Template';
	}

	public function getDescription(): ?string
	{
		return 'A resource template that exists only for tests.';
	}

	protected function getResourceNamespace(): string
	{
		return 'test';
	}

	protected function getResourcePath(): string
	{
		return 'fixture/{id}';
	}

	public function read(string $uri, string $id): mixed
	{
		return $id;
	}
}
