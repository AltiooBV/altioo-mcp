<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Exception\MCPRegistrationException;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * The guard a pack calls when it needs a base newer than the one installed.
 */
class VersionGuardTest extends TestCase
{
	public function testAnOlderRequirementIsSatisfied(): void
	{
		$this->assertTrue(MCPHelper::AtLeast('1.0.0'));
		$this->assertTrue(MCPHelper::AtLeast(MCPHelper::VERSION), 'The running version satisfies itself.');
	}

	public function testANewerRequirementIsNot(): void
	{
		$this->assertFalse(MCPHelper::AtLeast('99.0.0'));
	}

	public function testRequireVersionPassesQuietlyWhenSatisfied(): void
	{
		MCPHelper::RequireVersion('1.0.0', 'acme-servicedesk');

		$this->expectNotToPerformAssertions();
	}

	public function testRequireVersionNamesThePackTheVersionsAndTheWayOut(): void
	{
		$this->expectException(MCPRegistrationException::class);
		$this->expectExceptionMessage('acme-servicedesk requires '.MCPHelper::MODULE_NAME.' 99.0.0 or later');

		MCPHelper::RequireVersion('99.0.0', 'acme-servicedesk');
	}

	public function testRequireVersionReadsSensiblyWithoutAName(): void
	{
		try {
			MCPHelper::RequireVersion('99.0.0');
			$this->fail('Expected a refusal.');
		} catch (MCPRegistrationException $e) {
			$this->assertStringStartsWith('This tool pack requires', $e->getMessage());
			$this->assertStringContainsString('this instance runs '.MCPHelper::VERSION, $e->getMessage());
		}
	}

	/**
	 * The unit suite runs without iTop, which is also the state an early
	 * failure leaves a request in: no dictionary, so the literal has to come
	 * back rather than the key.
	 */
	public function testTranslateFallsBackToTheLiteralWhenThereIsNoDictionary(): void
	{
		$this->assertSame(
			'Add a log entry to a ticket',
			MCPHelper::Translate('MCP:tool:acme_ticket_add_log_entry:title', 'Add a log entry to a ticket')
		);
	}
}
