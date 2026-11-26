<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, B3 (the wording): the portal's core_object_count offered "open requests per status" as its example, and the
 * stock ticket lists are on Ticket, which has no status. The example it gives now is run here, so it fails when it stops working.
 */
class PortalCountExampleTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest') || !class_exists('Combodo\iTop\Portal\Brick\BrickCollection') || !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs the stock portal on iTop 3.2.2 or later, and the request management module.');
		}
		$sSuffix = bin2hex(random_bytes(4));
		$iOrg = (int)$this->CreateOrganization('PortalCount '.$sSuffix)->GetKey();
		$iContact = (int)$this->CreatePerson(1, $iOrg)->GetKey();
		$oProfile = (new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :n'), [], ['n' => 'Portal user']))->Fetch();
		$this->CreateUser('portal-count-'.$sSuffix, (int)$oProfile->GetKey(), 'Portal-Count-'.$sSuffix.'-1!', $iContact);
		UserRights::Login('portal-count-'.$sSuffix);
		RequestScope::EnterPortal('itop-portal');
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	public function testTheExampleInTheDescriptionWorksOnTheStockPortal(): void
	{
		$sDescription = (string)(new ObjectCount())->getDescription();

		$this->assertSame(1, preg_match('/list "([^"]+)" with group_by \["([^"]+)"\]/', $sDescription, $aMatch), 'the description carries an example: '.$sDescription);
		$aCount = ToolOutput::Decode(ObjectCount::execute(group_by: [$aMatch[2]], list: $aMatch[1]));

		$this->assertArrayHasKey('total', $aCount);
		$this->assertStringNotContainsString('status', $sDescription, 'it names no column the stock lists\' class lacks');
	}

	public function testAColumnOfASubclassIsRefusedInWordsThatSayWhy(): void
	{
		try {
			ObjectCount::execute(group_by: ['status'], list: 'closed-tickets-for-portal-user');
			$this->fail('status is not an attribute of Ticket.');
		} catch (ToolCallException $oException) {
			$this->assertStringContainsString('a column of a subclass of Ticket', $oException->getMessage());
			$this->assertStringNotContainsString('Unknown attribute', $oException->getMessage());
		}
	}
}
