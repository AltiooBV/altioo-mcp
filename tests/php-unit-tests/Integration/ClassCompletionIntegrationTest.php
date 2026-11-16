<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * completion/complete for the `class` variable of itop://core/class/{class}, through the SDK as a client asks.
 * An excluded class is absent from it, as it is from every other route (doc/adr/0012).
 */
class ClassCompletionIntegrationTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	protected function tearDown(): void
	{
		$this->exclude([]);
		parent::tearDown();
	}

	/** @param array<int, string> $aClasses */
	private function exclude(array $aClasses): void
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, []);
		ExclusionPolicy::Forget();
	}

	/** @return array<int, string> */
	private function complete(string $sPrefix): array
	{
		$oInit = $this->ask([
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
			'params'  => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'tests', 'version' => '1']],
		]);
		$oAnswer = $this->ask([
			'jsonrpc' => '2.0', 'id' => 2, 'method' => 'completion/complete',
			'params'  => [
				'ref'      => ['type' => 'ref/resource', 'uri' => 'itop://core/class/{class}'],
				'argument' => ['name' => 'class', 'value' => $sPrefix],
			],
		], $oInit['session']);

		$this->assertObjectHasProperty('result', $oAnswer['body'], json_encode($oAnswer['body']));

		return $oAnswer['body']->result->completion->values;
	}

	public function testAPrefixCompletesToTheClassesTheCallerMayRead(): void
	{
		$aValues = $this->complete('Pers');

		$this->assertContains('Person', $aValues);
		foreach ($aValues as $sValue) {
			$this->assertStringStartsWith('Pers', $sValue);
		}
	}

	public function testAnExcludedClassNeverCompletes(): void
	{
		$this->assertContains('Person', $this->complete('Person'));

		$this->exclude(['Person']);

		$this->assertNotContains('Person', $this->complete('Person'));
		$this->assertNotContains('Person', $this->complete(''));
	}
}
