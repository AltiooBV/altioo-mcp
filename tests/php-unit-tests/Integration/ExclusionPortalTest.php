<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * What an operator excluded (doc/adr/0012) is absent from portal mode as well: the
 * portal's lists, columns, search, forms and reads, for a user who has no other way to
 * see the datamodel than what the portal shows them.
 */
class ExclusionPortalTest extends ItopDataTestCaseAlias
{
	private const PORTAL = 'itop-portal';
	private const ONGOING = 'ongoing-tickets-for-portal-user';
	private const SENTINEL = 'Zxqportalsentinel';
	private const CALLER = 'Zxqcaller';

	private int $iOwn;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('UserRequest')
			|| !class_exists('Combodo\iTop\Portal\Brick\BrickCollection')
			|| !method_exists('Combodo\iTop\Portal\Form\ObjectFormManager', 'PrepareFields')) {
			$this->markTestSkipped('Needs request management and the stock portal on iTop 3.2.2 or later.');
		}

		$sSuffix = bin2hex(random_bytes(4));
		$iOrg = (int)$this->CreateOrganization('ExclPortal '.$sSuffix)->GetKey();
		$oContact = $this->CreatePerson(1, $iOrg);
		$oContact->Set('name', self::CALLER);
		$oContact->DBUpdate();
		$iContact = (int)$oContact->GetKey();
		$this->iOwn = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $iContact, 'description' => self::SENTINEL.' description'])->GetKey();

		$sLogin = 'excl-portal-'.$sSuffix;
		$this->CreateUser($sLogin, self::profileId('Portal user'), 'Excl-Portal-'.$sSuffix.'-1!', $iContact);
		UserRights::Login($sLogin);
		PortalMode::Remember(self::PORTAL);
		$this->exclude([], []);
	}

	protected function tearDown(): void
	{
		$this->exclude([], []);
		PortalMode::Forget();
		parent::tearDown();
	}

	private function exclude(mixed $aClasses, mixed $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_ATTRIBUTES, $aAttributes);
		ExclusionPolicy::Forget();
		// The portal's policy is read once per request, with the settings that request has.
		PortalPolicy::Forget();
	}

	public function testAnExcludedAttributeIsNotInThePortalsReadsListsOrForms(): void
	{
		$sBefore = json_encode(ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iOwn)));
		$this->assertStringContainsString(self::SENTINEL, $sBefore, 'the control: the portal user reads their own description');

		$this->exclude([], ['UserRequest' => ['description']]);

		$this->assertStringNotContainsString(self::SENTINEL, json_encode(ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iOwn))), 'a read');

		$sForms = json_encode(ToolOutput::Decode(ClassSchema::execute('UserRequest'))['forms'] ?? []);
		$this->assertStringContainsString('"attribute":"title"', $sForms, 'the control: the forms list their fields');
		$this->assertStringNotContainsString('"attribute":"description"', $sForms, 'a field of a portal form');
	}

	/** A column a list shows is excluded: no cell, no column, and a word that is only in it finds nothing. */
	public function testAnExcludedColumnIsNotShownNorSearchedByAPortalList(): void
	{
		$this->assertNotSame([], ToolOutput::Decode(PortalList::execute(self::ONGOING, '', 0, self::CALLER))['objects'] ?? [], 'the control: the caller\'s name finds the request');

		$this->exclude([], ['UserRequest' => ['caller_id']]);

		$aList = ToolOutput::Decode(PortalList::execute(self::ONGOING));
		$this->assertStringNotContainsString('caller_id', json_encode($aList), 'a column of the list');
		$this->assertStringNotContainsString(self::CALLER, json_encode($aList), 'a cell of the list');
		$this->assertSame([], ToolOutput::Decode(PortalList::execute(self::ONGOING, '', 0, self::CALLER))['objects'] ?? [], 'the list search found a row by a word that is only in an excluded column');
	}

	/** Asked for, set, filtered on or grouped by: in the same words as an attribute the datamodel does not have. */
	public function testAnExcludedAttributeAnswersAsAbsentFromThePortal(): void
	{
		$this->exclude([], ['UserRequest' => ['description']]);

		$aRoutes = [
			'a read'   => fn (string $a) => ObjectGet::execute('UserRequest', $this->iOwn, $a),
			'a write'  => fn (string $a) => ObjectUpdate::execute('UserRequest', $this->iOwn, [$a => 'x'], false),
			'a group'  => fn (string $a) => ObjectCount::execute('', [], '', [$a], 'exclude', self::ONGOING),
		];
		foreach ($aRoutes as $sRoute => $fRoute) {
			$aOut = [];
			foreach (['description', 'descriptionz'] as $sName) {
				try {
					$fRoute($sName);
					$aOut[$sName] = 'ok';
				} catch (ToolCallException $e) {
					$aOut[$sName] = str_replace(['descriptionz', 'description'], '#', $e->getMessage());
				}
			}
			$this->assertSame($aOut['descriptionz'], $aOut['description'], "{$sRoute}: told apart from an attribute that is not there");
			$this->assertNotSame('ok', $aOut['description'], "{$sRoute}: accepted");
		}
	}

	public function testRowsOfAnExcludedClassAreNotInThePortalsListOfItsParent(): void
	{
		$this->assertNotSame([], ToolOutput::Decode(PortalList::execute(self::ONGOING))['objects'] ?? [], 'the control: the list holds the request');

		$this->exclude(['UserRequest'], []);

		$this->assertSame([], ToolOutput::Decode(PortalList::execute(self::ONGOING))['objects'] ?? [], 'the list still holds a row of the excluded class');
		$this->assertNotContains('UserRequest', array_column(ToolOutput::Decode(ClassList::execute())['classes'] ?? [], 'class'));
		$this->expectException(ToolCallException::class);
		ObjectGet::execute('UserRequest', $this->iOwn);
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
