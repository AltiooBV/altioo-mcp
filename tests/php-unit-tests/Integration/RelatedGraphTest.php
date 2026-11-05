<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;

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
}
