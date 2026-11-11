<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * core_object_get_related against a real graph.
 *
 * The tool walked the graph with RelationGraph::GetNodes() and GetEdges(),
 * which iTop does not have (it has _GetNodes() and _GetEdges()), so every call
 * answered "Failed to walk". Nothing called the tool on a live datamodel, so
 * nothing noticed.
 */
class RelatedGraphTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	public function testAServerImpactsTheSolutionThatUsesIt(): void
	{
		$iOrg = (int)$this->CreateOrganization('RelatedGraph '.bin2hex(random_bytes(4)))->GetKey();
		$oServer = $this->createObject('Server', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$oSolution = $this->createObject('ApplicationSolution', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$this->createObject('lnkApplicationSolutionToFunctionalCI', ['applicationsolution_id' => $oSolution->GetKey(), 'functionalci_id' => $oServer->GetKey()]);

		$oResult = $this->call('core_object_get_related', ['class' => 'Server', 'id' => (int)$oServer->GetKey(), 'relation' => 'impacts', 'direction' => 'down']);

		$this->assertFalse($oResult->isError ?? false, json_encode($oResult));
		$sText = json_encode($oResult);
		$this->assertStringContainsString('ApplicationSolution', $sText);
		$this->assertStringContainsString((string)$oSolution->GetKey(), $sText);
	}

	/**
	 * Whatever an operator excludes, a walk from an object that is not excluded still starts, and still reaches what
	 * is not excluded (doc/adr/0012). The filter looked for the starting object among the nodes iTop marks as not
	 * reached, and iTop marks every node of a real graph as reached, the starting one included: it found no start and
	 * kept nothing, so any exclusion at all emptied every walk.
	 *
	 * @return array<string, array{0: array<int, string>, 1: array<string, array<int, string>>}>
	 */
	public static function exclusionProvider(): array
	{
		return [
			'a class, one the walk does not touch'     => [['Team'], []],
			'an attribute, one the walk does not read' => [[], ['Person' => ['phone', 'mobile_phone']]],
		];
	}

	/**
	 * @param array<int, string>               $aClasses
	 * @param array<string, array<int, string>> $aAttributes
	 *
	 * @dataProvider exclusionProvider
	 */
	public function testAServerStillImpactsItsSolutionWhileSomethingElseIsExcluded(array $aClasses, array $aAttributes): void
	{
		foreach (['Team', 'Person'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}

		$iOrg = (int)$this->CreateOrganization('RelatedGraph '.bin2hex(random_bytes(4)))->GetKey();
		$oServer = $this->createObject('Server', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$oSolution = $this->createObject('ApplicationSolution', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$this->createObject('lnkApplicationSolutionToFunctionalCI', ['applicationsolution_id' => $oSolution->GetKey(), 'functionalci_id' => $oServer->GetKey()]);

		$this->exclude($aClasses, $aAttributes);

		try {
			$oResult = $this->call('core_object_get_related', ['class' => 'Server', 'id' => (int)$oServer->GetKey(), 'relation' => 'impacts', 'direction' => 'down']);
		} finally {
			$this->exclude([], []);
		}

		$this->assertFalse($oResult->isError ?? false, json_encode($oResult));
		$sText = json_encode($oResult);
		$this->assertStringContainsString('ApplicationSolution', $sText, 'the walk came back empty');
		$this->assertStringContainsString((string)$oSolution->GetKey(), $sText);
	}

	public function testAnExcludedTeamIsNotInTheWalkAndTheRestStillIs(): void
	{
		if (!MetaModel::IsValidClass('Team') || !MetaModel::IsValidClass('lnkContactToFunctionalCI')) {
			$this->markTestSkipped('Needs Team and lnkContactToFunctionalCI.');
		}

		$iOrg = (int)$this->CreateOrganization('RelatedGraph '.bin2hex(random_bytes(4)))->GetKey();
		$oServer = $this->createObject('Server', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$oSolution = $this->createObject('ApplicationSolution', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$this->createObject('lnkApplicationSolutionToFunctionalCI', ['applicationsolution_id' => $oSolution->GetKey(), 'functionalci_id' => $oServer->GetKey()]);
		$sTeam = 'Excluded-team-'.bin2hex(random_bytes(3));
		$oTeam = $this->createObject('Team', ['name' => $sTeam, 'org_id' => $iOrg]);
		$this->createObject('lnkContactToFunctionalCI', ['contact_id' => $oTeam->GetKey(), 'functionalci_id' => $oServer->GetKey()]);
		$aArguments = ['class' => 'Server', 'id' => (int)$oServer->GetKey(), 'relation' => 'impacts', 'direction' => 'down'];

		$sBefore = json_encode($this->call('core_object_get_related', $aArguments));
		$this->assertStringContainsString($sTeam, (string)$sBefore, 'the team is in the walk when nothing is excluded, or this proves nothing');

		$this->exclude(['Team'], []);
		try {
			$sAfter = json_encode($this->call('core_object_get_related', $aArguments));
		} finally {
			$this->exclude([], []);
		}

		$this->assertStringNotContainsString($sTeam, (string)$sAfter);
		$this->assertStringContainsString((string)$oSolution->GetKey(), (string)$sAfter);
	}

	/**
	 * @param array<int, string>               $aClasses
	 * @param array<string, array<int, string>> $aAttributes
	 */
	private function exclude(array $aClasses, array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		ExclusionPolicy::Forget();
	}
}
