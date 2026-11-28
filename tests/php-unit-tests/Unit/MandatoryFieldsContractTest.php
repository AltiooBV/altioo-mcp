<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Write\EditPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * doc/adr/0004, Amendment 1: each tool that creates asks for what the form makes mandatory, and each that updates keeps
 * it from being emptied, in its own source (doc/adr/0014). What they answer needs a datamodel: MandatoryFieldsWriteTest.
 */
class MandatoryFieldsContractTest extends TestCase
{
	/** @return array<string, array{0: class-string}> */
	public static function creating(): array
	{
		return ['create' => [ObjectCreate::class], 'bulk create' => [ObjectBulkCreate::class]];
	}

	/** @dataProvider creating */
	public function testAToolThatCreatesFillsTheOnlyValueAndAsksForTheRest(string $sTool): void
	{
		$oSource = SourceCalls::OfClass($sTool);

		$this->assertTrue($oSource->HasCallTo('MandatoryOn'));
		$this->assertTrue($oSource->HasCall(EditPolicy::class.'::FillOnlyValues'));
		$this->assertTrue($oSource->HasCall(EditPolicy::class.'::RefusalForMissingMandatory'));
	}

	/** @return array<string, array{0: class-string}> */
	public static function updating(): array
	{
		return ['update' => [ObjectUpdate::class], 'bulk update' => [ObjectBulkUpdate::class]];
	}

	/** @dataProvider updating */
	public function testAToolThatUpdatesEmptiesNothingTheFormHolds(string $sTool): void
	{
		$oSource = SourceCalls::OfClass($sTool);

		$this->assertTrue($oSource->HasCallTo('MandatoryOn'));
		$this->assertTrue($oSource->HasCall(EditPolicy::class.'::MandatoryHeld'));
		$this->assertTrue($oSource->HasCall(EditPolicy::class.'::RefusalForEmptied'));
		$this->assertFalse($oSource->HasCall(EditPolicy::class.'::FillOnlyValues'), 'an update fills nothing in');
	}
}
