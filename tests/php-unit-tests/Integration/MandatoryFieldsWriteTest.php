<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Retest of 2026-11-28, B13 (doc/adr/0004, Amendment 1): a create ignored what the first state makes mandatory - a
 * UserRequest without a caller was answered `valid: true` - and an update could empty it again.
 *
 * Stock: `new` makes org_id and caller_id mandatory, and a caller is one of the organisation's people.
 *
 * @group integration
 */
class MandatoryFieldsWriteTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest')
			|| (MetaModel::GetInitialStateAttributeFlags('UserRequest', MetaModel::GetDefaultState('UserRequest'), 'caller_id') & OPT_ATT_MANDATORY) === 0) {
			$this->markTestSkipped('Needs a UserRequest whose first state makes the caller mandatory.');
		}
		$this->exclude([]);
	}

	protected function tearDown(): void
	{
		$this->exclude([]);
		parent::tearDown();
	}

	public function testACreateWithoutACallerToChooseIsRefusedOnADryRunAndForReal(): void
	{
		$iOrg = $this->orgWithPeople(2);

		foreach ([true, false] as $bSimulate) {
			$sRefusal = $this->refusal(fn () => ObjectCreate::execute('UserRequest', $this->aRequest($iOrg), $bSimulate));
			$this->assertStringContainsString('Mandatory on a new one and holding no value: caller_id.', $sRefusal);
		}
		$this->assertSame(0, $this->requestsOf($iOrg));
	}

	public function testTheOnlyAllowedCallerIsFilledInAndListedAsDefaulted(): void
	{
		$iOrg = $this->orgWithPeople(1);
		$iPerson = (int)(new DBObjectSet(DBObjectSearch::FromOQL('SELECT Person WHERE org_id = '.$iOrg)))->Fetch()->GetKey();

		foreach ([true, false] as $bSimulate) {
			$aDone = ToolOutput::Decode(ObjectCreate::execute('UserRequest', $this->aRequest($iOrg), $bSimulate));
			$aDone = json_decode((string)json_encode($aDone), true);
			$this->assertContains('caller_id', $aDone['defaulted'], json_encode($aDone));
			$this->assertSame($iPerson, (int)$aDone['changes']['caller_id']);
		}
		$this->assertSame($iPerson, (int)MetaModel::GetObject('UserRequest', (int)$aDone['id'])->Get('caller_id'));
	}

	/** One sent empty is the caller's own word: it is refused, never replaced. */
	public function testACallerSentEmptyIsRefusedEvenWhereOnlyOneIsAllowed(): void
	{
		$iOrg = $this->orgWithPeople(1);

		$sRefusal = $this->refusal(fn () => ObjectCreate::execute('UserRequest', ['caller_id' => 0] + $this->aRequest($iOrg), false));

		$this->assertStringContainsString('caller_id', $sRefusal);
		$this->assertSame(0, $this->requestsOf($iOrg));
	}

	public function testABulkCreateAsksAndFillsRowByRow(): void
	{
		$iTwo = $this->orgWithPeople(2);
		$iOne = $this->orgWithPeople(1);

		foreach ([true, false] as $bSimulate) {
			$aReport = json_decode((string)json_encode(ToolOutput::Decode(ObjectBulkCreate::execute('UserRequest', [$this->aRequest($iTwo), $this->aRequest($iOne)], $bSimulate))), true);
			$this->assertSame('error', $aReport['objects'][0]['status']);
			$this->assertStringContainsString('caller_id', $aReport['objects'][0]['message']);
			$this->assertSame('ok', $aReport['objects'][1]['status'], json_encode($aReport['objects'][1]));
			$this->assertContains('caller_id', $aReport['objects'][1]['defaulted']);
		}
		$this->assertSame(0, $this->requestsOf($iTwo));
		$this->assertSame(1, $this->requestsOf($iOne));
	}

	public function testRequiredOnlyListsWhatTheFirstStateMakesMandatory(): void
	{
		$aAttributes = ToolOutput::Decode(ClassSchema::execute('UserRequest', 'attributes', '*', true))['attributes'];
		$aAttributes = json_decode((string)json_encode($aAttributes), true);

		$this->assertTrue($aAttributes['caller_id']['mandatoryOnCreate'] ?? false, 'the caller is not listed as mandatory');
		$this->assertFalse($aAttributes['caller_id']['required'], 'the premise: the column may be null, and `required` keeps meaning that');
		$this->assertArrayHasKey('org_id', $aAttributes);
		$this->assertArrayHasKey('title', $aAttributes);
		$this->assertArrayNotHasKey('team_id', $aAttributes, 'hidden in the first state');
	}

	public function testAnUpdateDoesNotEmptyAFieldItsStateMakesMandatory(): void
	{
		$iOrg = $this->orgWithPeople(1);
		$iCaller = (int)(new DBObjectSet(DBObjectSearch::FromOQL('SELECT Person WHERE org_id = '.$iOrg)))->Fetch()->GetKey();
		$iId = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $iCaller])->GetKey();

		foreach ([true, false] as $bSimulate) {
			$sRefusal = $this->refusal(fn () => ObjectUpdate::execute('UserRequest', $iId, ['caller_id' => 0], $bSimulate));
			$this->assertSame("caller_id is mandatory on this UserRequest in state 'new', so it cannot be emptied: send another value, or leave it out.", $sRefusal);

			$aReport = json_decode((string)json_encode(ToolOutput::Decode(ObjectBulkUpdate::execute('UserRequest', [$iId], ['caller_id' => 0], $bSimulate))), true);
			$this->assertSame('error', $aReport['objects'][0]['status']);
			$this->assertSame($sRefusal, $aReport['objects'][0]['message']);
		}
		$this->assertSame($iCaller, (int)MetaModel::GetObject('UserRequest', $iId, true, true)->Get('caller_id'));

		ObjectUpdate::execute('UserRequest', $iId, ['title' => 'another title'], false);
		$this->assertSame('another title', (string)$this->reloaded($iId)->Get('title'));
	}

	/** Only emptying is refused: an object already without one stays editable. */
	public function testAnObjectAlreadyWithoutOneStaysEditable(): void
	{
		$iOrg = $this->orgWithPeople(2);
		$iId = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg])->GetKey();
		$this->assertSame(0, (int)$this->reloaded($iId)->Get('caller_id'), 'the premise: no caller');

		ObjectUpdate::execute('UserRequest', $iId, ['title' => 'still editable'], false);
		ObjectUpdate::execute('UserRequest', $iId, ['caller_id' => 0, 'title' => 'and again'], false);

		$this->assertSame('and again', (string)$this->reloaded($iId)->Get('title'));
	}

	/** doc/adr/0012: the setting is said to be why, the field is not named, and the words do not depend on any object. */
	public function testAnExcludedMandatoryFieldStopsTheCreateAndIsNotNamed(): void
	{
		$iOne = $this->orgWithPeople(1);
		$iTwo = $this->orgWithPeople(2);
		$this->exclude(['UserRequest' => ['caller_id']]);

		$aWords = [];
		foreach ([$iOne, $iTwo] as $iOrg) {
			$aWords[] = $this->refusal(fn () => ObjectCreate::execute('UserRequest', $this->aRequest($iOrg), true));
			$aWords[] = $this->refusal(fn () => ObjectBulkCreate::execute('UserRequest', [$this->aRequest($iOrg)], true));
		}

		$this->assertCount(1, array_unique($aWords), json_encode($aWords));
		$this->assertStringContainsString(ExclusionSettings::SETTING_ATTRIBUTES, $aWords[0]);
		$this->assertStringNotContainsString('caller', $aWords[0]);
	}

	/** @return array<string, mixed> */
	private function aRequest(int $iOrg): array
	{
		return ['org_id' => $iOrg, 'title' => 'mandatory fields', 'description' => 'x'];
	}

	private function orgWithPeople(int $iPeople): int
	{
		$iOrg = (int)$this->CreateOrganization('Mandatory '.bin2hex(random_bytes(4)))->GetKey();
		for ($i = 1; $i <= $iPeople; $i++) {
			$this->CreatePerson($i, $iOrg);
		}

		return $iOrg;
	}

	private function requestsOf(int $iOrg): int
	{
		return (new DBObjectSet(DBObjectSearch::FromOQL('SELECT UserRequest WHERE org_id = '.$iOrg)))->Count();
	}

	private function reloaded(int $iId): \DBObject
	{
		$oSet = new DBObjectSet(DBObjectSearch::FromOQL('SELECT UserRequest WHERE id = '.$iId));
		$oObject = $oSet->Fetch();
		$this->assertNotNull($oObject);

		return $oObject;
	}

	private function refusal(callable $fCall): string
	{
		try {
			$fCall();
		} catch (ToolCallException $e) {
			return $e->getMessage();
		}
		$this->fail('the call was accepted');
	}

	/** @param array<string, array<int, string>> $aAttributes */
	private function exclude(array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, []);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}
}
