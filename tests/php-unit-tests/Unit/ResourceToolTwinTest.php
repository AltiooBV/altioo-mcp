<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Api\MCPRegistry;
use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAware;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * Every core resource and resource template has a tool twin (ADR 0025).
 *
 * Plenty of clients never fetch a resource, and support for templates is
 * thinner still, while every client reads tools/list. A resource without a
 * twin is content those clients cannot reach - which is how itop://core/version
 * went without one from 1.0 to 2.1.
 *
 * The pairs are written out rather than derived, like the surface list and the
 * context budgets, so adding a resource is a reviewed decision about its tool.
 * What is checked about each pair is that the tool is served wherever the
 * resource is: the same toolset, no profile the resource does not require,
 * portal mode if the resource has it, and the read grade. A tool may be served
 * in more places than its resource (core_class_list in portal mode), never in
 * fewer - except under a delete-only token, which is served every resource and
 * no read tool, because resources are not graded (ADR 0025). That both are built on one handler is said in each class's docblock
 * and held by the integration suite's comparison of their answers.
 */
class ResourceToolTwinTest extends TestCase
{
	/** Resource or template URI => the tool that returns the same thing. */
	public const TWINS = [
		'itop://core/instructions'                     => 'core_instructions',
		'itop://core/version'                          => 'core_version',
		'itop://core/current-user'                     => 'core_current_user',
		'itop://core/classes'                          => 'core_class_list',
		'itop://core/class/{class}'                    => 'core_class_schema',
		'itop://core/document/{class}/{id}/{att_code}' => 'core_object_get_document',
	];

	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	/** @return array<string, object> URI => resource or template. */
	private static function resources(): array
	{
		return MCPRegistry::GetResources() + MCPRegistry::GetResourceTemplates();
	}

	public function testEveryResourceHasATwin(): void
	{
		$aUris = array_keys(self::resources());
		sort($aUris);
		$aTwinned = array_keys(self::TWINS);
		sort($aTwinned);

		$this->assertSame(
			$aTwinned,
			$aUris,
			'a resource was added without a tool twin, or one was removed: give it a tool over the same handler and add the pair to TWINS (ADR 0025)'
		);
	}

	public function testEveryTwinIsARegisteredTool(): void
	{
		$aTools = MCPRegistry::GetTools();
		foreach (self::TWINS as $sUri => $sTool) {
			$this->assertArrayHasKey($sTool, $aTools, "{$sUri} names {$sTool} as its twin, which is not registered");
		}
	}

	public function testATwinIsServedWhereverItsResourceIs(): void
	{
		$aTools = MCPRegistry::GetTools();
		foreach (self::resources() as $sUri => $oResource) {
			$oTool = $aTools[self::TWINS[$sUri] ?? ''] ?? null;
			if ($oTool === null) {
				continue; // reported by the two tests above
			}
			$sTool = self::TWINS[$sUri];

			$this->assertSame($oResource->getToolset(), $oTool->getToolset(), "{$sTool} is not in the toolset of {$sUri}, so a token narrowed to one serves only the other");
			$this->assertSame(
				[],
				array_values(array_diff($oTool->requiredProfiles(), $oResource->requiredProfiles())),
				"{$sTool} requires a profile {$sUri} does not"
			);
			if ($oResource instanceof PortalAware) {
				$this->assertInstanceOf(PortalAware::class, $oTool, "{$sUri} is served in portal mode and {$sTool} is not");
			}

			$aHints = $oTool->getAnnotations()?->jsonSerialize() ?? [];
			$this->assertSame(
				AccessPolicy::CAPABILITY_READ,
				AccessPolicy::CapabilityOf(
					array_key_exists('readOnlyHint', $aHints) ? (bool)$aHints['readOnlyHint'] : null,
					array_key_exists('destructiveHint', $aHints) ? (bool)$aHints['destructiveHint'] : null
				),
				"{$sTool} is not graded read, so a read-only token is served {$sUri} and not its twin"
			);
		}
	}
}
