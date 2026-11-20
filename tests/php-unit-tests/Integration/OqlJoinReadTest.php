<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Write\ExternalKeyPolicy;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, RT-6 and ADR 0023: rights were asked of the class an OQL query selects and of no other, so a
 * join or a sub-query reached classes the account may not read - `SELECT Person AS P JOIN UserLocal AS U ON U.contactid
 * = P.id WHERE U.login LIKE 'p%'` answered whether such a login exists. Every class the parsed query names is now asked
 * like the selected one, in the same words, on every route that takes OQL: the search, the count, and an OQL given as an
 * external key's value.
 */
class OqlJoinReadTest extends ItopDataTestCaseAlias
{
	private string $sSuffix;
	private string $sLogin;

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['Person', 'UserLocal', 'URP_UserProfile', 'PersonalToken', 'UserRequest', 'Organization'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}

		$this->sSuffix = bin2hex(random_bytes(4));
		$iOrg = (int)$this->CreateOrganization('Join '.$this->sSuffix)->GetKey();
		$iPerson = (int)$this->CreatePerson(1, $iOrg)->GetKey();
		$this->sLogin = 'join-agent-'.$this->sSuffix;
		$iUser = (int)$this->CreateUser($this->sLogin, self::profileId('Support Agent'), 'Join-Agent-'.$this->sSuffix.'-1!', $iPerson)->GetKey();
		$oLink = MetaModel::NewObject('URP_UserOrg');
		$oLink->Set('userid', $iUser);
		$oLink->Set('allowed_org_id', $iOrg);
		$oLink->DBInsert();

		UserRights::Login($this->sLogin);
	}

	protected function tearDown(): void
	{
		RequestScope::Close();
		parent::tearDown();
	}

	/** @return array<string, array{0: string, 1: string}> The query, and the class it reaches beside the selected one. */
	public static function refusedProvider(): array
	{
		return [
			'a login behind a person' => ['SELECT Person AS P JOIN UserLocal AS U ON U.contactid = P.id WHERE U.login LIKE "p%"', 'UserLocal'],
			'a profile in a sub-query' => ['SELECT Person AS P WHERE P.id IN (SELECT URP_UserProfile AS UP WHERE UP.profile LIKE "Admin%")', 'URP_UserProfile'],
			'a token scope in a sub-query' => ['SELECT Person AS P WHERE P.id IN (SELECT PersonalToken AS T WHERE T.scope LIKE "%MCP-delete%")', 'PersonalToken'],
			'a login in a sub-query' => ['SELECT Person AS P WHERE P.id IN (SELECT UserLocal AS U WHERE U.login LIKE "a%")', 'UserLocal'],
		];
	}

	private function directRefusal(string $sClass): string
	{
		try {
			ObjectSearchByOQL::execute("SELECT {$sClass}");
		} catch (ToolCallException $e) {
			return $e->getMessage();
		}
		$this->fail("The account reads {$sClass}: it cannot stand for a low-privilege account here.");
	}

	public function testTheWalkNamesEveryClassOfAQuery(): void
	{
		$this->assertEqualsCanonicalizing(
			['Person', 'UserLocal', 'Organization', 'Team'],
			ExclusionSearches::ClassesNamed('SELECT Person AS P JOIN UserLocal AS U ON U.contactid = P.id WHERE P.org_id IN (SELECT Organization AS O WHERE O.id IN (SELECT Team AS T))')
		);
		$this->assertEqualsCanonicalizing(['Person', 'Team'], ExclusionSearches::ClassesNamed('SELECT Person WHERE id = 0 UNION SELECT Team WHERE id = 0'));
	}

	/** @dataProvider refusedProvider */
	public function testAJoinOrASubQueryOnAClassTheAccountMayNotReadIsRefusedLikeSelectingIt(string $sOql, string $sClass): void
	{
		$sDirect = $this->directRefusal($sClass);

		try {
			ObjectSearchByOQL::execute($sOql);
			$this->fail('the search was served');
		} catch (ToolCallException $e) {
			$this->assertSame($sDirect, $e->getMessage());
		}
		try {
			ObjectCount::execute('', [], $sOql);
			$this->fail('the count was served');
		} catch (ToolCallException $e) {
			$this->assertSame($sDirect, $e->getMessage());
		}
	}

	public function testTheSameOqlAsAnExternalKeysValueIsRefused(): void
	{
		$sOql = 'SELECT Person AS P JOIN UserLocal AS U ON U.contactid = P.id WHERE U.login LIKE "p%"';

		$sRefusal = ExternalKeyPolicy::RefusalFor('UserRequest', 'caller_id', $sOql);

		$this->assertNotNull($sRefusal);
		$this->assertStringStartsWith($this->directRefusal('UserLocal'), $sRefusal);
	}

	/** @return array<string, array{0: string}> */
	public static function servedProvider(): array
	{
		return [
			'a person and their organisation' => ['SELECT Person AS P JOIN Organization AS O ON P.org_id = O.id WHERE O.name LIKE "zzz%"'],
			'a sub-query on a readable class' => ['SELECT Person AS P WHERE P.org_id IN (SELECT Organization AS O WHERE O.name LIKE "zzz%")'],
			'a ticket and its caller' => ['SELECT UserRequest AS R JOIN Person AS P ON R.caller_id = P.id WHERE P.first_name LIKE "zzz%"'],
		];
	}

	/** @dataProvider servedProvider */
	public function testAJoinOverReadableClassesIsStillAnswered(string $sOql): void
	{
		$this->assertSame(0, ToolOutput::Decode(ObjectSearchByOQL::execute($sOql))['total']);
		$this->assertSame(0, ToolOutput::Decode(ObjectCount::execute('', [], $sOql))['total']);
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
