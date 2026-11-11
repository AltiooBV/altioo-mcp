<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Write\ExternalKeyPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * What a write reports in portal mode is what the portal's view form shows.
 *
 * The values need a portal and a datamodel, so this pins the wiring: every
 * tool that reports `changes` passes them through the access policy, and an
 * external key takes an id only when a portal is the caller's scope.
 */
class PortalWriteOutcomeContractTest extends TestCase
{
	public function testEveryWriteToolFiltersItsChangesThroughTheAccessPolicy(): void
	{
		foreach ([ObjectCreate::class, ObjectUpdate::class, ObjectApplyStimulus::class] as $sTool) {
			$sBody = $this->bodyOf($sTool, 'execute');
			$this->assertStringContainsString('$oAccess->Disclosed(', $sBody, $sTool);
			$this->assertLessThan(
				strpos($sBody, 'WriteOutcome::Defaulted('),
				strpos($sBody, '$oAccess->Disclosed('),
				"{$sTool} must filter before `defaulted` is derived from the changes"
			);
		}
	}

	public function testAnExternalKeyIsAnIdOnlyInPortalMode(): void
	{
		$sBody = $this->bodyOf(ExternalKeyPolicy::class, 'RefusalFor');

		$this->assertStringContainsString('PortalMode::Current() !== null', $sBody);
		$this->assertLessThan(
			strpos($sBody, 'RefusalForExternalKeyQuery('),
			strpos($sBody, 'PortalMode::Current()', strpos($sBody, 'GetTargetClass')),
			'the portal refusal must come before the OQL lookup'
		);
	}

	/** Hidden and unknown read alike, and a value that is no code is not repeated. */
	public function testAHiddenAttributeIsRefusedInTheWordsOfAnUnknownOne(): void
	{
		$this->assertSame(
			"Unknown attribute 'org_id' on class 'UserRequest'. Call core_class_schema for the attribute codes of the class.",
			ObjectSerializer::UnknownAttributes(['org_id'], 'UserRequest')
		);
		$this->assertStringNotContainsString('OR 1=1', ObjectSerializer::UnknownAttributes(["x' OR 1=1 --"], 'UserRequest'));
	}

	public function testThePortalTextNamesNoToolItDoesNotServe(): void
	{
		$sBody = $this->bodyOf(ClassRefusal::class, 'Unreadable');
		$this->assertLessThan(strpos($sBody, 'rights model'), strpos($sBody, 'PortalMode::Current()'));
		$this->assertStringContainsString('PortalMode::Current()', $this->bodyOf(ErrorReport::class, 'auditLookup'));
	}

	private function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file($oMethod->getFileName());

		return implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));
	}
}
