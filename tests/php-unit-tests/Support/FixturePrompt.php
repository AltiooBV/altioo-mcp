<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPPrompt;

class FixturePrompt extends AbstractMCPPrompt
{
	public function getNamespace(): string
	{
		return 'test';
	}

	public function getTitle(): ?string
	{
		return 'Fixture Prompt';
	}

	public function getDescription(): ?string
	{
		return 'A prompt that exists only for tests.';
	}

	/** @return array<int, array<string, mixed>> */
	public function get(): array
	{
		return [
			[
				'role' => 'user',
				'content' => ['type' => 'text', 'text' => 'Fixture prompt.'],
			],
		];
	}
}
