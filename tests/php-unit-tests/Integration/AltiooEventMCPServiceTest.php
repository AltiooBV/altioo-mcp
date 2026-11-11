<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The AltiooEventMCPService audit class, against a live datamodel.
 *
 * Uses core MetaModel/DBObject APIs rather than the helpers on iTop's
 * ItopDataTestCase, so the suite is not tied to one iTop minor's test harness.
 */
class AltiooEventMCPServiceTest extends ItopDataTestCaseAlias
{
	private const CLASS_NAME = 'AltiooEventMCPService';

	public function testClassIsDeclared(): void
	{
		$this->assertTrue(MetaModel::IsValidClass(self::CLASS_NAME), 'run the iTop setup so the module datamodel is compiled');
	}

	public function testExtendsEvent(): void
	{
		$this->assertContains('Event', MetaModel::EnumParentClasses(self::CLASS_NAME));
	}

	public function testIsNotAbstract(): void
	{
		$this->assertFalse(MetaModel::IsAbstract(self::CLASS_NAME));
	}

	public function testUsesItsOwnTable(): void
	{
		$this->assertSame('priv_altioo_event_mcp_service', MetaModel::DBGetTable(self::CLASS_NAME));
	}

	/**
	 * @dataProvider ownAttributeProvider
	 */
	public function testDeclaresItsOwnAttributes(string $sAttCode): void
	{
		$this->assertTrue(MetaModel::IsValidAttCode(self::CLASS_NAME, $sAttCode));
	}

	/** @return array<string, array{0: string}> */
	public static function ownAttributeProvider(): array
	{
		return [
			'mcp_method' => ['mcp_method'],
			'mcp_name' => ['mcp_name'],
			'status' => ['status'],
			'request_params' => ['request_params'],
			'client' => ['client'],
			'read_objects' => ['read_objects'],
			'user_id' => ['user_id'],
		];
	}

	/**
	 * @dataProvider inheritedAttributeProvider
	 */
	public function testInheritsEventAttributes(string $sAttCode): void
	{
		$this->assertTrue(MetaModel::IsValidAttCode(self::CLASS_NAME, $sAttCode));
	}

	/** @return array<string, array{0: string}> */
	public static function inheritedAttributeProvider(): array
	{
		return [
			'date' => ['date'],
			'userinfo' => ['userinfo'],
			'message' => ['message'],
		];
	}

	public function testStatusEnumOffersSuccessAndError(): void
	{
		$oAttDef = MetaModel::GetAttributeDef(self::CLASS_NAME, 'status');
		$aValues = array_keys($oAttDef->GetAllowedValues());

		sort($aValues);
		$this->assertSame(['error', 'success'], $aValues);
	}

	public function testMcpMethodIsMandatory(): void
	{
		$this->assertFalse(MetaModel::GetAttributeDef(self::CLASS_NAME, 'mcp_method')->IsNullAllowed());
	}

	public function testMcpNameIsOptional(): void
	{
		$this->assertTrue(MetaModel::GetAttributeDef(self::CLASS_NAME, 'mcp_name')->IsNullAllowed());
	}

	/**
	 * Nullable, so that an upgrade needs no installer and a row from before
	 * the column holds nothing.
	 *
	 * @dataProvider additiveColumnProvider
	 */
	public function testTheAuditColumnsAreNullableSoAnUpgradeIsAdditive(string $sAttCode): void
	{
		$this->assertTrue(MetaModel::GetAttributeDef(self::CLASS_NAME, $sAttCode)->IsNullAllowed());
	}

	/** @return array<string, array{0: string}> */
	public static function additiveColumnProvider(): array
	{
		return ['client' => ['client'], 'read_objects' => ['read_objects'], 'user_id' => ['user_id']];
	}

	public function testReadObjectsIsATextSoTheControllersCutIsTheRightOne(): void
	{
		$this->assertInstanceOf(\AttributeText::class, MetaModel::GetAttributeDef(self::CLASS_NAME, 'read_objects'));
	}

	public function testUserIdPointsAtAUser(): void
	{
		$oAttDef = MetaModel::GetAttributeDef(self::CLASS_NAME, 'user_id');

		$this->assertInstanceOf(\AttributeExternalKey::class, $oAttDef);
		$this->assertSame('User', $oAttDef->GetTargetClass());
	}

	/**
	 * The audit trail must outlive the account it records, and deleting an
	 * account must not be blocked by it. The key is reset (DEL_AUTO, like the
	 * token links), one row at a time in the deleting request, which is the
	 * cost the maintainer accepted; `userinfo` stays the record.
	 */
	public function testDeletingTheUserKeepsItsRowsClearsTheKeyAndIsNotBlockedByThem(): void
	{
		$iOrg = (int)$this->CreateOrganization('AuditUserDelete '.bin2hex(random_bytes(4)))->GetKey();
		$iContact = (int)$this->CreatePerson(1, $iOrg)->GetKey();
		$sLogin = 'audit-user-delete-'.bin2hex(random_bytes(4));
		$iUser = (int)$this->CreateUser($sLogin, 1, 'Audit-User-Delete-1!', $iContact)->GetKey();

		$oRow = new \AltiooEventMCPService();
		$oRow->Set('userinfo', $sLogin);
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('message', 'audit row of a user about to be deleted');
		$oRow->Set('user_id', $iUser);
		$iRow = (int)$oRow->DBInsertNoReload();

		$oUser = MetaModel::GetObject('User', $iUser);
		$oDeletion = new \DeletionPlan();
		$oUser->DBDelete($oDeletion);

		$this->assertNull(MetaModel::GetObject('User', $iUser, false), 'the user is deleted');
		$oSurvivor = MetaModel::GetObject(self::CLASS_NAME, $iRow, false);
		$this->assertNotNull($oSurvivor, 'the audit row survives its user');
		$this->assertSame($sLogin, $oSurvivor->Get('userinfo'));
		$this->assertSame(0, (int)$oSurvivor->Get('user_id'), 'the key is reset, not left pointing at a deleted user');
	}

	/**
	 * MCPController truncates request_params to 65535 bytes before writing,
	 * which is only correct while the column stays a TEXT.
	 */
	public function testRequestParamsIsAText(): void
	{
		$this->assertInstanceOf(
			\AttributeText::class,
			MetaModel::GetAttributeDef(self::CLASS_NAME, 'request_params')
		);
	}

	/**
	 * Mirrors what CallLog::LogIfConfigured() writes, including the
	 * DBInsertNoReload() it uses.
	 */
	public function testAnAuditEntryCanBeWrittenAndReadBack(): void
	{
		$sMarker = 'phpunit-'.bin2hex(random_bytes(8));

		$oEvent = MetaModel::NewObject(self::CLASS_NAME);
		$oEvent->Set('message', $sMarker);
		$oEvent->Set('mcp_method', 'tools/call');
		$oEvent->Set('mcp_name', 'ObjectGet');
		$oEvent->Set('status', 'success');
		$oEvent->Set('request_params', '{"name":"ObjectGet"}');
		$oEvent->SetTrim('userinfo', 'phpunit');
		$iKey = $oEvent->DBInsertNoReload();

		$this->assertGreaterThan(0, $iKey);

		try {
			$oReloaded = MetaModel::GetObject(self::CLASS_NAME, $iKey);

			$this->assertSame($sMarker, $oReloaded->Get('message'));
			$this->assertSame('tools/call', $oReloaded->Get('mcp_method'));
			$this->assertSame('ObjectGet', $oReloaded->Get('mcp_name'));
			$this->assertSame('success', $oReloaded->Get('status'));
			$this->assertSame('{"name":"ObjectGet"}', $oReloaded->Get('request_params'));
		} finally {
			MetaModel::GetObject(self::CLASS_NAME, $iKey)->DBDelete();
		}
	}

	public function testErrorEntriesAreSearchable(): void
	{
		$sMarker = 'phpunit-'.bin2hex(random_bytes(8));

		$oEvent = MetaModel::NewObject(self::CLASS_NAME);
		$oEvent->Set('message', $sMarker);
		$oEvent->Set('mcp_method', 'tools/call');
		$oEvent->Set('status', 'error');
		$oEvent->SetTrim('userinfo', 'phpunit');
		$iKey = $oEvent->DBInsertNoReload();

		try {
			$oSearch = DBObjectSearch::FromOQL('SELECT '.self::CLASS_NAME." WHERE status = 'error' AND message = :marker");
			$oSet = new DBObjectSet($oSearch, [], ['marker' => $sMarker]);

			$this->assertSame(1, $oSet->Count());
		} finally {
			MetaModel::GetObject(self::CLASS_NAME, $iKey)->DBDelete();
		}
	}

	/**
	 * mcp_method is capped at 64 characters; the longest method the controller
	 * records has to fit.
	 */
	public function testMcpMethodColumnFitsTheLongestLoggedMethod(): void
	{
		$iMaxSize = MetaModel::GetAttributeDef(self::CLASS_NAME, 'mcp_method')->GetMaxSize();
		foreach (['resources/read', 'tools/call', 'prompts/get', 'notifications/initialized'] as $sMethod) {
			$this->assertLessThanOrEqual($iMaxSize, strlen($sMethod), "'{$sMethod}' does not fit in mcp_method");
		}
	}
}
