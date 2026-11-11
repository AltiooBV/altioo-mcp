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
use Altioo\iTop\Extension\MCP\Service\Read\OutputFields;
use Altioo\iTop\Extension\MCP\Service\Write\ExternalKeyPolicy;
use Altioo\iTop\Extension\MCP\Service\Write\WriteOutcome;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

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
			$oSource = SourceCalls::OfClass($sTool);
			$this->assertTrue($oSource->HasCall('->Disclosed', 'execute'), $sTool);
			$this->assertTrue(
				$oSource->CallsInOrder(['->Disclosed', WriteOutcome::class.'::Defaulted'], 'execute'),
				"{$sTool} must filter before `defaulted` is derived from the changes"
			);
		}
	}

	public function testAnExternalKeyIsAnIdOnlyInPortalMode(): void
	{
		$oSource = SourceCalls::OfClass(ExternalKeyPolicy::class);

		$this->assertStringContainsString('PortalMode::Current()!==null', $oSource->Code('RefusalFor'));
		$this->assertTrue(
			$oSource->CallsInSequence(['->GetTargetClass', PortalMode::class.'::Current', ExternalKeyPolicy::class.'::RefusalForExternalKeyQuery'], 'RefusalFor'),
			'the portal refusal must come before the OQL lookup'
		);
	}

	/** Hidden and unknown read alike, and a value that is no code is not repeated. */
	public function testAHiddenAttributeIsRefusedInTheWordsOfAnUnknownOne(): void
	{
		$this->assertSame(
			"Unknown attribute 'org_id' on class 'UserRequest'. Call core_class_schema for the attribute codes of the class.",
			OutputFields::UnknownAttributes(['org_id'], 'UserRequest')
		);
		$this->assertStringNotContainsString('OR 1=1', OutputFields::UnknownAttributes(["x' OR 1=1 --"], 'UserRequest'));
	}

	public function testThePortalTextNamesNoToolItDoesNotServe(): void
	{
		$oRefusal = SourceCalls::OfClass(ClassRefusal::class);
		$sBody = $oRefusal->Code('Unreadable');
		$this->assertTrue($oRefusal->HasCall(PortalMode::class.'::Current', 'Unreadable'));
		$this->assertLessThan(strpos($sBody, 'rights model'), strpos($sBody, 'PortalMode::Current()'));
		$this->assertTrue(SourceCalls::OfClass(ErrorReport::class)->HasCall(PortalMode::class.'::Current', 'auditLookup'));
	}
}
