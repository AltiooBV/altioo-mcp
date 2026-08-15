<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPResource;

class FixtureResource extends AbstractMCPResource
{
	public function getTitle(): ?string
	{
		return 'Fixture Resource';
	}

	public function getDescription(): ?string
	{
		return 'A resource that exists only for tests.';
	}

	protected function getResourceNamespace(): string
	{
		return 'test';
	}

	protected function getResourcePath(): string
	{
		return 'fixture';
	}

	public function read(): mixed
	{
		return '{}';
	}
}
