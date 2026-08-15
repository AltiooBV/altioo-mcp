<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;

/**
 * Named (not anonymous) on purpose: AbstractMCPTool::getName() derives the name
 * from the class short name via reflection, which is meaningless for an
 * anonymous class.
 */
class FixtureTool extends AbstractMCPTool
{
	public function getNamespace(): string
	{
		return 'test';
	}

	public function getDescription(): ?string
	{
		return 'A tool that exists only for tests.';
	}

	public function getInputSchema(): ?array
	{
		return ['type' => 'object', 'properties' => []];
	}

	public function execute(): mixed
	{
		return 'executed';
	}
}
