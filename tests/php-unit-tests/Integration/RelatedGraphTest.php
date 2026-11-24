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
	 * Review of 2026-11-23, B6 and T3: an "up" walk answered an empty graph. iTop marks the nodes of a walk "reached"
	 * only on the way down (ComputeRelatedObjectsUp() marks none), and the tool kept only the nodes marked.
	 */
	public function testABusinessProcessDependsOnItsSolutionAndItsServer(): void
	{
		[$oProcess, $oSolution, $oServer] = $this->aProcessOnASolutionOnAServer();

		$oResult = $this->call('core_object_get_related', ['class' => 'BusinessProcess', 'id' => (int)$oProcess->GetKey(), 'relation' => 'impacts', 'direction' => 'up']);

		$this->assertFalse($oResult->isError ?? false, json_encode($oResult));
		$oGraph = json_decode((string)$oResult->content[0]->text);
		$aKeys = array_keys((array)$oGraph->objects);
		sort($aKeys);
		$this->assertSame(['ApplicationSolution::'.$oSolution->GetKey(), 'Server::'.$oServer->GetKey()], $aKeys, 'the walk up came back with something else than what the process depends on');
		$this->assertNotEmpty($oGraph->relations, 'objects with no edge between them are a list, not a graph');
	}

	/**
	 * Review of 2026-11-23, B10 and T3: the answer counted the object the walk started from, though the description
	 * says it is not in the result.
	 *
	 * @dataProvider directionProvider
	 */
	public function testTheStartingObjectIsNeitherListedNorCounted(string $sDirection): void
	{
		[$oProcess, $oSolution, $oServer] = $this->aProcessOnASolutionOnAServer();
		$oStart = $sDirection === 'up' ? $oProcess : $oServer;

		$oResult = $this->call('core_object_get_related', ['class' => get_class($oStart), 'id' => (int)$oStart->GetKey(), 'relation' => 'impacts', 'direction' => $sDirection]);

		$this->assertFalse($oResult->isError ?? false, json_encode($oResult));
		$oGraph = json_decode((string)$oResult->content[0]->text);
		$this->assertArrayNotHasKey(get_class($oStart).'::'.$oStart->GetKey(), (array)$oGraph->objects);
		$this->assertStringNotContainsString(get_class($oStart).'=', (string)$oGraph->summary, 'the starting object is counted');
		$this->assertNotSame('', (string)$oGraph->summary, 'nothing came back, which proves nothing about the start');
	}

	/** @return array<string, array{0: string}> */
	public static function directionProvider(): array
	{
		return ['down' => ['down'], 'up' => ['up']];
	}

	/**
	 * Review of 2026-11-23, B6 and ADR 0012: what the operator excluded is not in a walk up either, and neither is
	 * what is reachable only through it. The walk up from the process reaches the server only through the solution.
	 */
	public function testAnExcludedClassIsNotInAWalkUpAndNeitherIsWhatLiesBeyondIt(): void
	{
		[$oProcess, $oSolution, $oServer] = $this->aProcessOnASolutionOnAServer();
		$aArguments = ['class' => 'BusinessProcess', 'id' => (int)$oProcess->GetKey(), 'relation' => 'impacts', 'direction' => 'up'];

		$this->exclude(['ApplicationSolution'], []);
		try {
			$oResult = $this->call('core_object_get_related', $aArguments);
		} finally {
			$this->exclude([], []);
		}

		$sText = (string)json_encode($oResult);
		$this->assertStringNotContainsString('ApplicationSolution', $sText);
		$this->assertStringNotContainsString('Server::'.$oServer->GetKey(), $sText);
	}

	/**
	 * @return array{0: \DBObject, 1: \DBObject, 2: \DBObject} A business process that uses a solution that is hosted on a server.
	 */
	private function aProcessOnASolutionOnAServer(): array
	{
		foreach (['BusinessProcess', 'ApplicationSolution', 'Server', 'lnkApplicationSolutionToBusinessProcess', 'lnkApplicationSolutionToFunctionalCI'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}

		$iOrg = (int)$this->CreateOrganization('RelatedGraph '.bin2hex(random_bytes(4)))->GetKey();
		$oServer = $this->createObject('Server', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$oSolution = $this->createObject('ApplicationSolution', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$oProcess = $this->createObject('BusinessProcess', ['name' => 'RelatedGraph-'.bin2hex(random_bytes(3)), 'org_id' => $iOrg]);
		$this->createObject('lnkApplicationSolutionToFunctionalCI', ['applicationsolution_id' => $oSolution->GetKey(), 'functionalci_id' => $oServer->GetKey()]);
		$this->createObject('lnkApplicationSolutionToBusinessProcess', ['applicationsolution_id' => $oSolution->GetKey(), 'businessprocess_id' => $oProcess->GetKey()]);

		return [$oProcess, $oSolution, $oServer];
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
