<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__.'/../bootstrap.php';

/**
 * The settings moved to Settings in 1.3.0 and a tool pack may still name them
 * on MCPHelper: nothing is removed before a documented deprecation has run for
 * a minor (guide, "Changelog & deprecation").
 */
class MCPHelperSettingsStubsTest extends TestCase
{
	public function testEveryConstantOfSettingsStillResolvesOnMCPHelper(): void
	{
		$aMissing = [];
		foreach ((new ReflectionClass(Settings::class))->getReflectionConstants() as $oConstant) {
			$sName = $oConstant->getName();
			if (!defined(MCPHelper::class.'::'.$sName) || constant(MCPHelper::class.'::'.$sName) !== $oConstant->getValue()) {
				$aMissing[] = $sName;
			}
		}

		$this->assertSame([], $aMissing, 'a constant moved to Settings must stay an alias on MCPHelper');
	}

	public function testEveryPublicMethodOfSettingsStillExistsOnMCPHelperAsADeprecatedStub(): void
	{
		$aMissing = [];
		foreach ((new ReflectionClass(Settings::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $oMethod) {
			$sName = $oMethod->getName();
			if (!method_exists(MCPHelper::class, $sName)) {
				$aMissing[] = $sName;
				continue;
			}
			$sDoc = (string)(new \ReflectionMethod(MCPHelper::class, $sName))->getDocComment();
			if (!str_contains($sDoc, '@deprecated 1.3.0 use Settings::'.$sName.'()')) {
				$aMissing[] = $sName.' (not marked deprecated)';
			}
		}

		$this->assertSame([], $aMissing);
	}

	public function testAStubAnswersAsItsReplacementDoes(): void
	{
		$this->assertSame(Settings::SwitchFrom('a_setting', 'yes', true), MCPHelper::SwitchFrom('a_setting', 'yes', true));
		$this->assertSame(Settings::RetentionDaysFrom(30), MCPHelper::RetentionDaysFrom(30));
		$this->assertSame(Settings::EscalationClassesFrom(['A']), MCPHelper::EscalationClassesFrom(['A']));
	}
}
