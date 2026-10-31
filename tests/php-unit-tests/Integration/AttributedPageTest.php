<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Exception\ToolCallException;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * audit and actions are bounded by the page served, not by the limit asked.
 *
 * Both cost a question per object, so a page of more than
 * ObjectHistory::MAX_AUDIT_PAGE is refused. The guard used to read the
 * limit, whose default is above that bound: actions=true on its own was
 * refused for every query, four matches included.
 */
class AttributedPageTest extends ItopDataTestCaseAlias
{
	private int $iOrg;

	protected function setUp(): void
	{
		parent::setUp();

		$this->iOrg = (int)$this->CreateOrganization('AttributedPageTest '.bin2hex(random_bytes(4)))->GetKey();
	}

	public function testAFewMatchesAreServedUnderTheDefaultLimit(): void
	{
		$this->teams(3);

		$aResult = ToolOutput::Decode(ObjectSearchByClass::execute('Team', ['org_id' => $this->iOrg], actions: true, audit: true));

		$this->assertCount(3, $aResult['objects']);
		$this->assertArrayHasKey('rights', $aResult['objects'][0]);
		$this->assertArrayHasKey('audit', $aResult['objects'][0]);
	}

	public function testAPageLargerThanTheBoundIsStillRefused(): void
	{
		$this->teams(ObjectHistory::MAX_AUDIT_PAGE + 1);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessage('limit='.ObjectHistory::MAX_AUDIT_PAGE);
		ObjectSearchByOQL::execute("SELECT Team WHERE org_id = {$this->iOrg}", actions: true);
	}

	/** The last page is what remains after the offset, so it may be served. */
	public function testTheLastPageIsMeasuredAfterTheOffset(): void
	{
		$this->teams(ObjectHistory::MAX_AUDIT_PAGE + 1);

		$aResult = ToolOutput::Decode(ObjectSearchByOQL::execute("SELECT Team WHERE org_id = {$this->iOrg}", offset: ObjectHistory::MAX_AUDIT_PAGE, actions: true));

		$this->assertCount(1, $aResult['objects']);
	}

	private function teams(int $iCount): void
	{
		for ($i = 1; $i <= $iCount; $i++) {
			$this->createObject('Team', ['org_id' => $this->iOrg, 'name' => "AttributedPageTest team {$i}"]);
		}
	}
}
