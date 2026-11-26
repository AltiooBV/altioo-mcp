<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * A description or a refusal that names a tool names one this caller is served (review 2026-11-23, C7).
 *
 * A token without the history toolset was told by core_object_get and the searches to call core_object_history, which
 * it does not have, and a refusal of the change log said that tool alone serves it.
 */
class ServedToolNamesTest extends TestCase
{
	protected function tearDown(): void
	{
		ServedAccess::Forget();
		parent::tearDown();
	}

	/** @param array<int, string> $aTools */
	private function serve(array $aTools): void
	{
		ServedAccess::Remember(
			static fn (): array => ['toolsets' => [], 'tools' => $aTools, 'capabilities' => [], 'prompts' => []],
			static fn (): array => []
		);
	}

	/** @return array<string, array{0: string}> */
	public static function surfaces(): array
	{
		return [
			'core_object_get description'            => [ObjectGet::class],
			'the search tools\' audit argument'      => [ObjectSearchByClass::class],
		];
	}

	/** @dataProvider surfaces */
	public function testHistoryIsNamedOnlyWhenItIsServed(string $sTool): void
	{
		$fText = static fn (): string => (string)(new $sTool())->getDescription().json_encode((new $sTool())->getInputSchema());

		$this->serve(['core_object_get', 'core_object_search_by_class']);
		$this->assertStringNotContainsString('core_object_history', $fText(), 'the token is not served it');

		$this->serve(['core_object_get', 'core_object_history']);
		$this->assertStringContainsString('core_object_history', $fText(), 'the control: served, it is the pointer it was');
	}

	public function testTheChangeLogRefusalNamesTheWayInOnlyWhenItIsServed(): void
	{
		$this->serve(['core_object_get']);
		$sRefused = ChangeLogReservation::Refusal('CMDBChangeOp');
		$this->assertStringNotContainsString('core_object_history', $sRefused);
		$this->assertStringContainsString("'CMDBChangeOp'", $sRefused);

		$this->serve(['core_object_history']);
		$this->assertStringContainsString('core_object_history', ChangeLogReservation::Refusal('CMDBChangeOp'));
	}

	public function testWithNoServerAskingAToolIsNamedAsItAlwaysWas(): void
	{
		ServedAccess::Forget();

		$this->assertTrue(ServedAccess::Serves('core_object_history'));
		$this->assertStringContainsString('core_object_history', ChangeLogReservation::Refusal('CMDBChange'));
	}

	/** The portal count sent a model to core_class_list for columns that list carries none of. */
	public function testThePortalCountDoesNotSayTheClassListGivesColumns(): void
	{
		$oSource = file_get_contents(dirname(__DIR__, 3).'/src/Core/Tools/ObjectCount.php');

		$this->assertStringNotContainsString('for the lists and their columns', $oSource);
		$this->assertStringContainsString('core_portal_list rows show their columns', $oSource);
	}

	public function testTheInstructionsNameHistoryOnlyWhenItIsServed(): void
	{
		$oPolicy = \Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy::Unrestricted();
		$fText = static fn (): string => \Altioo\iTop\Extension\MCP\Core\ServerInstructions::Text($oPolicy, 'Y-m-d H:i:s', 'Y-m-d');

		$this->serve(['core_object_get']);
		$this->assertStringNotContainsString('core_object_history', $fText());

		$this->serve(['core_object_history']);
		$this->assertStringContainsString('core_object_history', $fText());
	}
}
