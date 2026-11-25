<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A stimulus whose target state makes an external key mandatory is refused while that key is empty.
 *
 * Review of 2026-11-23, B1 and T2: ev_assign on a stock UserRequest passed, in the dry run and for
 * real, with no team and no agent. The mandatory check compared the attribute with '' softly; an
 * empty external key is 0, and on PHP 8 `0 != ''` is true, so every empty key counted as filled.
 * The console's form would not have let it through.
 *
 * @group integration
 */
class ApplyStimulusMandatoryKeyTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();

		if (!MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the ITIL request management module.');
		}
		$aTransitions = MetaModel::EnumTransitions('UserRequest', 'new');
		$aStates = MetaModel::EnumStates('UserRequest');
		$sTarget = $aTransitions['ev_assign']['target_state'] ?? '';
		$aExpected = $aStates[$sTarget]['attribute_list'] ?? [];
		foreach (['team_id', 'agent_id'] as $sCode) {
			if (!(($aExpected[$sCode] ?? 0) & OPT_ATT_MANDATORY)) {
				$this->markTestSkipped("This UserRequest lifecycle does not make $sCode mandatory on ev_assign.");
			}
		}

		$this->iOrg = (int)$this->CreateOrganization('ApplyStimulusMandatoryKeyTest '.bin2hex(random_bytes(4)))->GetKey();
	}

	private function newTicket(): int
	{
		$oCaller = $this->CreatePerson(1, $this->iOrg);

		return (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $oCaller->GetKey()])->GetKey();
	}

	/**
	 * @dataProvider rehearsalOrReal
	 */
	public function testEv_assignWithNoTeamAndNoAgentIsRefusedNamingBoth(bool $bSimulate): void
	{
		$iId = $this->newTicket();

		$oResult = $this->call('core_object_apply_stimulus', ['class' => 'UserRequest', 'id' => $iId, 'stimulus' => 'ev_assign', 'simulate' => $bSimulate]);

		$this->assertTrue($oResult->isError ?? false, 'ev_assign went through with no team and no agent');
		$sText = (string)($oResult->content[0]->text ?? '');
		$this->assertStringContainsString('team_id', $sText);
		$this->assertStringContainsString('agent_id', $sText);
		$this->assertSame('new', (string)MetaModel::GetObject('UserRequest', $iId)->Get('status'), 'the ticket moved anyway');
	}

	/** @return array<string, array{0: bool}> */
	public static function rehearsalOrReal(): array
	{
		return ['dry run' => [true], 'real' => [false]];
	}
}
