<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, HA-5: an OQL string given as an external key's value was parsed by iTop against the real datamodel
 * before the channel's checker ran, so `manager_id: "SELECT Person WHERE phone = 'x'"` answered "Unknown filter code ...
 * expecting {...}" when `phone` was excluded and "is not an id and not valid OQL" when the name did not exist at all:
 * any account allowed to create or update could enumerate which names exist but are excluded.
 */
class ExclusionKeyQueryTest extends ItopDataTestCaseAlias
{
	private int $iPerson;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidAttCode('Person', 'manager_id')) {
			$this->markTestSkipped('Needs Person.manager_id.');
		}
		$iOrg = (int)$this->CreateOrganization('KeyQuery '.bin2hex(random_bytes(4)))->GetKey();
		$this->iPerson = (int)$this->CreatePerson(1, $iOrg)->GetKey();
		$this->exclude([], ['Person' => ['phone']]);
	}

	protected function tearDown(): void
	{
		$this->exclude([], []);
		parent::tearDown();
	}

	/**
	 * @param array<int, string> $aClasses
	 * @param array<string, array<int, string>> $aAttributes
	 */
	private function exclude(array $aClasses, array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	private function answerTo(string $sQuery, string $sName): string
	{
		try {
			ObjectUpdate::execute('Person', $this->iPerson, ['manager_id' => $sQuery]);
			$sOut = 'ok';
		} catch (ToolCallException $e) {
			$sOut = $e->getMessage();
		}

		// The name the caller typed, the position iTop reports for it and the class it suggests instead are not part of the comparison.
		$sOut = preg_replace('/\.? You may try "[^"]*" instead\./', '', $sOut);
		$sOut = preg_replace('/\b'.preg_quote($sName, '/').'\b/', '#', $sOut);

		return preg_replace('/ at \d+ in /', ' at N in ', (string)$sOut);
	}

	public function testAnExcludedAttributeInAKeyQueryIsAnsweredAsAnUnknownOne(): void
	{
		$sUnknown = 'zzphone';
		$sExcluded = 'phone';

		$sAbsent = $this->answerTo("SELECT Person WHERE {$sUnknown} = 'x'", $sUnknown);
		$sHidden = $this->answerTo("SELECT Person WHERE {$sExcluded} = 'x'", $sExcluded);

		$this->assertNotSame('ok', $sHidden);
		$this->assertSame($sAbsent, $sHidden, 'an excluded attribute was told apart from one that does not exist');
		$this->assertStringContainsString('Unknown filter code', $sHidden);
	}

	public function testAnExcludedClassInAKeyQueryIsAnsweredAsAnUnknownOne(): void
	{
		$this->exclude(['Team'], []);

		$sAbsent = $this->answerTo('SELECT Zzteam', 'Zzteam');
		$sHidden = $this->answerTo('SELECT Team', 'Team');

		$this->assertSame($sAbsent, $sHidden, 'an excluded class was told apart from one that does not exist');
		$this->assertStringContainsString('Unknown class', $sHidden);
	}

	public function testTextThatIsNotOqlStillGetsTheExplanationOfWhatAKeyTakes(): void
	{
		$sAnswer = $this->answerTo('not a query', 'x');

		$this->assertStringContainsString("Give 'manager_id' the id of a", $sAnswer);
	}
}
