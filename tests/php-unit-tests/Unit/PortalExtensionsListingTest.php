<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Service\Server\SettingsWarnings;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwareCoreSubclassInheriting;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackPrompt;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackTool;
use Altioo\iTop\Extension\MCP\Test\Support\PortalAwarePackToolOverride;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * What an operator's mcp_portal_extensions line admits, and what the log says
 * about a line that admits nothing (ADR 0017; red-team review of 2026-11-12,
 * findings F2, F3 and F4).
 */
class PortalExtensionsListingTest extends TestCase
{
	private const NAME = 'test_portal_aware_pack_tool';

	protected function tearDown(): void
	{
		RequestScope::Close();
	}

	/** F2: the identifier was listed for the first pack's element; an override of it is not what the operator decided about. */
	public function testAnIdentifierListedForOnePackIsNotInheritedByAnOverrideOfIt(): void
	{
		$oFirst = new PortalAwarePackTool();
		$oOverride = new PortalAwarePackToolOverride();

		$this->assertTrue(PortalAccess::Serves($oFirst, self::NAME, [self::NAME]), 'the element the operator meant');
		$this->assertFalse(PortalAccess::Serves($oOverride, self::NAME, [self::NAME]), 'an override of its identifier');
		$this->assertTrue(PortalAccess::Serves($oOverride, self::NAME, [PortalAwarePackToolOverride::class]), 'listed by its class name');
		$this->assertFalse(PortalAccess::Serves($oFirst, self::NAME, [PortalAwarePackToolOverride::class]), 'the class name is the override\'s only');
	}

	public function testAnOverrideIsHiddenInPortalModeUnlessListedByClassName(): void
	{
		RequestScope::EnterPortal('itop-portal');

		$oOverride = new PortalAwarePackToolOverride();
		$this->assertTrue($this->isHidden($oOverride, self::NAME, [self::NAME]), 'by the identifier it took');
		$this->assertFalse($this->isHidden($oOverride, self::NAME, [PortalAwarePackToolOverride::class]), 'by class name');
	}

	/**
	 * F2, second half, recorded as it is: an identifier is not owned by a kind,
	 * so an entry admits a PortalAware element of any kind that holds it. The
	 * class name is the precise form.
	 */
	public function testAnIdentifierEntryAdmitsEveryKindThatHoldsIt(): void
	{
		$oTool = new PortalAwarePackTool();
		$oPrompt = new PortalAwarePackPrompt();
		$sIdentifier = self::NAME;

		$this->assertTrue(PortalAccess::Serves($oTool, $sIdentifier, [$sIdentifier]));
		$this->assertTrue(PortalAccess::Serves($oPrompt, $sIdentifier, [$sIdentifier]), 'a prompt of that name too');
		$this->assertFalse(PortalAccess::Serves($oPrompt, $sIdentifier, [PortalAwarePackTool::class]), 'the class name names one element');
		$this->assertTrue(PortalAccess::Serves($oPrompt, $sIdentifier, [PortalAwarePackPrompt::class]));
	}

	/**
	 * F3: an unlisted override of a core portal tool replaces it at its
	 * identifier, and is hidden in portal mode, so portal users lose the core
	 * tool. Console mode is unaffected. The listing that serves it is its class
	 * name, never its own identifier.
	 */
	public function testAnUnlistedOverrideOfACorePortalToolRemovesItFromPortalUsers(): void
	{
		$oOverride = new PortalAwareCoreSubclassInheriting();

		$this->assertFalse($this->isHidden($oOverride, 'core_object_get'), 'console mode');
		$this->assertFalse($this->isHidden(new ObjectGet(), 'core_object_get'), 'the core tool, console mode');

		RequestScope::EnterPortal('itop-portal');
		$this->assertTrue($this->isHidden($oOverride, 'core_object_get'), 'unlisted, portal mode');
		$this->assertTrue($this->isHidden($oOverride, 'core_object_get', ['core_object_get']), 'the core identifier does not list it');
		$this->assertTrue($this->isHidden($oOverride, 'core_object_get', [$oOverride->getQualifiedName()]), 'nor does its own');
		$this->assertFalse($this->isHidden($oOverride, 'core_object_get', [PortalAwareCoreSubclassInheriting::class]), 'its class name does');
		$this->assertFalse($this->isHidden(new ObjectGet(), 'core_object_get'), 'the core tool, when not replaced');
	}

	/** F4: each way a line serves nothing is reported under its own cause. */
	public function testALineThatServesNothingIsReportedWithItsCause(): void
	{
		$aElements = [
			[self::NAME, new PortalAwarePackToolOverride()],
			['test_fixture_tool', new FixtureTool()],
			['acme_served', new PortalAwarePackTool()],
		];

		$aProblems = $this->problems([
			'acme_missing',
			'test_fixture_tool',
			self::NAME,
			'acme_served',
			PortalAwarePackTool::class,
			'acme_missing',
		], $aElements);

		$this->assertSame(['acme_missing'], $aProblems['missing'], 'no element answers, and said once');
		$this->assertSame(['test_fixture_tool'], $aProblems['unaware']);
		$this->assertSame([self::NAME], $aProblems['overridden']);
	}

	public function testAWorkingLineIsNotReported(): void
	{
		$aElements = [
			[self::NAME, new PortalAwarePackToolOverride()],
			['acme_served', new PortalAwarePackTool()],
		];

		$this->assertSame(
			['missing' => [], 'unaware' => [], 'overridden' => []],
			$this->problems(['acme_served', PortalAwarePackToolOverride::class], $aElements)
		);
	}

	/**
	 * @param array<int, string> $aDisabled
	 * @param array<int, string> $aListed
	 */
	private function isHidden(object $oElement, string $sIdentifier, array $aListed = []): bool
	{
		return (new ReflectionMethod(ServedElements::class, 'isHidden'))
			->invoke(null, $sIdentifier, $oElement, [], AccessPolicy::Unrestricted(), $aListed);
	}

	/**
	 * @param array<int, string>                      $aListed
	 * @param array<int, array{0: string, 1: object}> $aElements
	 *
	 * @return array<string, array<int, string>>
	 */
	private function problems(array $aListed, array $aElements): array
	{
		return (new ReflectionMethod(SettingsWarnings::class, 'portalListingProblems'))->invoke(null, $aListed, $aElements);
	}
}
