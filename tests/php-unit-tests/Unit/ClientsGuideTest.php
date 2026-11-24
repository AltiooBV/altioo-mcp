<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, L4: the Claude Code section of doc/clients.md was one line. It now says what a Claude Code user meets, and
 * links to the README and the ADR for the rest rather than restating them.
 */
class ClientsGuideTest extends TestCase
{
	private string $sGuide;

	private string $sReadme;

	protected function setUp(): void
	{
		parent::setUp();
		$sRoot = dirname(__DIR__, 3);
		$this->sGuide = (string)file_get_contents($sRoot.'/doc/clients.md');
		$this->sReadme = (string)file_get_contents($sRoot.'/README.md');
	}

	public function testItCoversOAuthPortalCutPromptsAndResources(): void
	{
		foreach (['OAuth instead of a token', 'portal_id', 'mcp_portal_token_max_days', '4,096', '2,050', '/mcp__<server>__<prompt>', '@<server>:<uri>', 'core_instructions'] as $sNeedle) {
			$this->assertStringContainsString($sNeedle, $this->sGuide, $sNeedle);
		}
	}

	public function testItAttributesEachCutToItsClient(): void
	{
		$this->assertMatchesRegularExpression('/Claude Code keeps the first\s+4,096/', $this->sGuide);
		$this->assertMatchesRegularExpression('/desktop app.{0,40}\s*Code tab.{0,80}about 2,050/s', $this->sGuide);
	}

	public function testItsLinksResolve(): void
	{
		$sRoot = dirname(__DIR__, 3);
		$this->assertFileExists($sRoot.'/doc/adr/0007-the-connection-names-its-portal.md');
		foreach (['#granting-access' => '## Granting access', '#endpoint' => '## Endpoint'] as $sAnchor => $sHeading) {
			$this->assertStringContainsString($sAnchor, $this->sGuide);
			$this->assertStringContainsString("\n".$sHeading."\n", $this->sReadme);
		}
	}
}
