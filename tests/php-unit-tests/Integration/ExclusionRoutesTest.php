<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use AltiooEventMCPService;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\MyActivity;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkUpdate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectDelete;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetDocument;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\Server\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use ormDocument;
use UserRights;

require_once __DIR__.'/../bootstrap.php';

/**
 * What an operator excluded (doc/adr/0012) is absent on every route, not refused.
 *
 * Two things are excluded in turn: an attribute (Person.phone, with a value nobody
 * could guess) and a class (Team, a subclass of Contact, so that a search on the
 * parent has rows to leave out). Every route that reads, finds, counts, orders,
 * groups, joins, writes or describes is asked the same question twice - once about
 * the excluded name, once about a name the datamodel does not have - and the two
 * answers must be the same, word for word, once the name is taken out of them.
 * A route that answers differently is an oracle for which names exist.
 */
class ExclusionRoutesTest extends ItopDataTestCaseAlias
{
	private const SENTINEL = '+3355507731';

	private const ABSENT_ATTRIBUTE = 'phonez';

	private const ABSENT_CLASS = 'Teamz';

	private int $iOrg;
	private int $iPerson;
	private int $iPlainPerson;
	private int $iTeam;
	private int $iTeamFile;
	private string $sTeamName;

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['Person', 'Team', 'Attachment'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}

		$sSuffix = bin2hex(random_bytes(4));
		$this->iOrg = (int)$this->CreateOrganization('Exclusions '.$sSuffix)->GetKey();
		$oPerson = $this->CreatePerson(1, $this->iOrg);
		$oPerson->Set('phone', self::SENTINEL);
		$oPerson->Set('mobile_phone', '+3355507732');
		$oPerson->DBUpdate();
		$this->iPerson = (int)$oPerson->GetKey();
		$this->iPlainPerson = (int)$this->CreatePerson(2, $this->iOrg)->GetKey();

		$this->sTeamName = 'Excluded team '.$sSuffix;
		$oTeam = $this->createObject('Team', ['name' => $this->sTeamName, 'org_id' => $this->iOrg]);
		$this->iTeam = (int)$oTeam->GetKey();

		$oFile = MetaModel::NewObject('Attachment');
		$oFile->SetItem($oTeam);
		$oFile->Set('temp_id', '');
		$oFile->Set('contents', new ormDocument('Filed under a team.', 'text/plain', 'team.txt'));
		$this->iTeamFile = (int)$oFile->DBInsert();

		$this->exclude([], []);
	}

	protected function tearDown(): void
	{
		$this->exclude([], []);
		parent::tearDown();
	}

	private function exclude(mixed $aClasses, mixed $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPHelper::MODULE_NAME, ExclusionPolicy::SETTING_ATTRIBUTES, $aAttributes);
		ExclusionPolicy::Forget();
	}

	/**
	 * What a call came to, with the names it was given taken out: the words of a refusal, or the answer.
	 *
	 * @param array<int, string> $aNames
	 */
	private function outcome(callable $fCall, array $aNames): string
	{
		try {
			$mResult = $fCall();
			$sOut = 'ok '.json_encode(is_array($mResult) && isset($mResult[0]) && is_object($mResult[0]) ? ['content'] : $mResult);
		} catch (ToolCallException $e) {
			$sOut = 'refused '.$e->getMessage();
		}

		// The suggestion iTop adds to an unknown class is the closest name among those this channel serves, so it
		// cannot carry an excluded one; it differs between two names only because the names differ.
		$sOut = preg_replace('/\.? You may try "[^"]*" instead\./', '', $sOut);

		return str_replace($aNames, '#', $sOut);
	}

	// ------------------------------------------------------------------ the attribute

	/** @return array<string, callable(string): mixed> */
	private function attributeRoutes(): array
	{
		return [
			'get, named in the fields'     => fn (string $a) => ObjectGet::execute('Person', $this->iPerson, $a),
			'search, filtered on it'       => fn (string $a) => ObjectSearchByClass::execute('Person', [$a => 'x']),
			'search, asked for it'         => fn (string $a) => ObjectSearchByClass::execute('Person', [], 50, 0, '', 'asc', $a),
			'search, ordered by it'        => fn (string $a) => ObjectSearchByClass::execute('Person', [], 50, 0, $a),
			'oql, in a condition'          => fn (string $a) => ObjectSearchByOQL::execute("SELECT Person WHERE {$a} = 'x'"),
			'oql, in a like'               => fn (string $a) => ObjectSearchByOQL::execute("SELECT Person WHERE {$a} LIKE '%x%'"),
			'oql, in a sub-query'          => fn (string $a) => ObjectSearchByOQL::execute("SELECT Person AS p WHERE p.org_id IN (SELECT Organization AS o JOIN Person AS q ON q.org_id = o.id WHERE q.{$a} = 'x')"),
			'oql, ordered by it'           => fn (string $a) => ObjectSearchByOQL::execute('SELECT Person', 50, 0, $a),
			'count, filtered on it'        => fn (string $a) => ObjectCount::execute('Person', [$a => 'x']),
			'count, grouped by it'         => fn (string $a) => ObjectCount::execute('Person', [], '', [$a]),
			'count, by oql'                => fn (string $a) => ObjectCount::execute('', [], "SELECT Person WHERE {$a} = 'x'"),
			'history, of it'               => fn (string $a) => ObjectHistory::execute('Person', $this->iPerson, $a),
			'update, setting it'           => fn (string $a) => ObjectUpdate::execute('Person', $this->iPerson, [$a => 'x']),
			'bulk update, setting it'      => fn (string $a) => ObjectBulkUpdate::execute('Person', [$this->iPerson], [$a => 'x']),
			'create, setting it'           => fn (string $a) => ObjectCreate::execute('Person', [$a => 'x', 'name' => 'N', 'first_name' => 'F', 'org_id' => $this->iOrg]),
			'schema, asked for it'         => fn (string $a) => array_keys((array)(ClassSchema::execute('Person', 'attributes', $a))),
			'a key pointing by criteria'   => fn (string $a) => ObjectUpdate::execute('Person', $this->iPerson, ['manager_id' => [$a => 'x']]),
		];
	}

	/** @return array<string, array{0: string}> */
	public static function attributeRouteProvider(): array
	{
		return self::names(['get, named in the fields', 'search, filtered on it', 'search, asked for it', 'search, ordered by it', 'oql, in a condition', 'oql, in a like', 'oql, in a sub-query', 'oql, ordered by it', 'count, filtered on it', 'count, grouped by it', 'count, by oql', 'history, of it', 'update, setting it', 'bulk update, setting it', 'create, setting it', 'schema, asked for it', 'a key pointing by criteria']);
	}

	/** @return array<string, array{0: string}> */
	public static function attributeReadProvider(): array
	{
		return self::names(['get', 'get, every field', 'get, through the parent', 'search', 'oql', 'find by name', 'schema', 'update, dry run', 'history']);
	}

	/** @return array<string, array{0: string}> */
	public static function classRouteProvider(): array
	{
		return self::names(['get', 'search by class', 'search by oql', 'oql, a join', 'oql, a sub-query', 'count', 'count, by oql', 'find by name, in it', 'history', 'related', 'create', 'bulk create', 'update', 'bulk update', 'delete', 'bulk delete', 'stimulus', 'schema', 'attach', 'document']);
	}

	/**
	 * @param array<int, string> $aNames
	 *
	 * @return array<string, array{0: string}>
	 */
	private static function names(array $aNames): array
	{
		return array_combine($aNames, array_map(static fn (string $s): array => [$s], $aNames));
	}

	/**
	 * @dataProvider attributeRouteProvider
	 */
	public function testAnExcludedAttributeAnswersAsOneThatDoesNotExist(string $sRoute): void
	{
		$fRoute = $this->attributeRoutes()[$sRoute];
		// Both under the same setting: the checker lists the names it knows when it refuses one, and that
		// list is the datamodel as this channel serves it - without the excluded name in it either way.
		$aNames = [self::ABSENT_ATTRIBUTE, 'phone'];
		$this->exclude([], ['Person' => ['phone']]);
		$sAbsent = $this->outcome(static fn () => $fRoute(self::ABSENT_ATTRIBUTE), $aNames);
		$sExcluded = $this->outcome(static fn () => $fRoute('phone'), $aNames);

		$this->assertSame($sAbsent, $sExcluded, 'a hidden attribute and an absent one were answered differently');
		$this->assertStringNotContainsString(self::SENTINEL, $sExcluded);
	}

	/** @return array<string, callable(): mixed> */
	private function attributeReads(): array
	{
		return [
			'get'                      => fn () => ObjectGet::execute('Person', $this->iPerson),
			'get, every field'         => fn () => ObjectGet::execute('Person', $this->iPerson, '*'),
			'get, through the parent'  => fn () => ObjectGet::execute('Contact', $this->iPerson, '*'),
			'search'                   => fn () => ObjectSearchByClass::execute('Person', [], 20, 0, '', 'asc', '*'),
			'oql'                      => fn () => ObjectSearchByOQL::execute('SELECT Person', 20, 0, '', 'asc', '*'),
			'find by name'             => fn () => ObjectFindByName::execute('Person', 'Person', 50, '*'),
			'schema'                   => fn () => ClassSchema::execute('Person'),
			'update, dry run'          => fn () => ObjectUpdate::execute('Person', $this->iPerson, ['first_name' => 'Renamed']),
			'history'                  => fn () => ObjectHistory::execute('Person', $this->iPerson),
		];
	}

	/**
	 * @dataProvider attributeReadProvider
	 */
	public function testAnExcludedAttributeIsNotInAnyAnswer(string $sRoute): void
	{
		$fRead = $this->attributeReads()[$sRoute];
		// A change to it first, so that the history has a row to leave out.
		$oPerson = MetaModel::GetObject('Person', $this->iPerson);
		$oPerson->Set('phone', '+3355507733');
		$oPerson->DBUpdate();

		$this->exclude([], ['Person' => ['phone', 'mobile_phone']]);

		$sJson = json_encode($fRead());
		$this->assertStringNotContainsString('5550773', $sJson, 'the value is in the answer');
		$this->assertStringNotContainsString('"phone"', $sJson, 'the attribute is named in the answer');
		$this->assertStringNotContainsString('mobile_phone', $sJson, 'the attribute is named in the answer');
	}

	/** The word is in an excluded attribute only: nothing matches it, as if the attribute did not exist. */
	public function testAFullTextSearchDoesNotMatchInsideAnExcludedAttribute(): void
	{
		$this->assertGreaterThan(0, ToolOutput::Decode(ObjectFindByName::execute(self::SENTINEL, 'Person'))['total'], 'the control: it is found while nothing is excluded');

		$this->exclude([], ['Person' => ['phone']]);
		$aFound = ToolOutput::Decode(ObjectFindByName::execute(self::SENTINEL, 'Person'));

		$this->assertSame(0, $aFound['total'], 'found by a value that is excluded');
		$this->assertSame([], $aFound['objects']);
	}

	/** Rows about an excluded attribute are not in the history, and not in its total either. */
	public function testTheHistoryTotalDoesNotCountWhatIsExcluded(): void
	{
		$oPerson = MetaModel::GetObject('Person', $this->iPerson);
		$oPerson->Set('phone', '+3355507734');
		$oPerson->DBUpdate();

		$iAll = ToolOutput::Decode(ObjectHistory::execute('Person', $this->iPerson))['total'];
		$this->exclude([], ['Person' => ['phone']]);
		$aHistory = ToolOutput::Decode(ObjectHistory::execute('Person', $this->iPerson));

		$oOps = new DBObjectSet(DBObjectSearch::FromOQL_AllData(
			'SELECT CMDBChangeOpSetAttribute WHERE objclass = "Person" AND objkey = :k AND attcode = "phone"'
		), [], ['k' => $this->iPerson]);
		$this->assertGreaterThan(0, $oOps->Count(), 'the control: there are rows about it');
		$this->assertSame($iAll - $oOps->Count(), $aHistory['total'], 'the total counts rows that are not shown');
		$this->assertSame($aHistory['total'], count($aHistory['entries']));
	}

	public function testAnExcludedAttributeIsNotReportedAsChangedOnAWrite(): void
	{
		$this->exclude([], ['Person' => ['phone', 'mobile_phone']]);

		$sJson = json_encode(ObjectUpdate::execute('Person', $this->iPerson, ['first_name' => 'Other'], false));
		$this->assertStringNotContainsString('phone', $sJson);
	}

	public function testAMandatoryExcludedAttributeMakesCreatingImpossibleAndSaysWhyWithoutNamingIt(): void
	{
		$this->exclude([], ['Person' => ['org_id']]);

		try {
			ObjectCreate::execute('Person', ['name' => 'N', 'first_name' => 'F'], false);
			$this->fail('created');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString(ExclusionPolicy::SETTING_ATTRIBUTES, $e->getMessage());
			$this->assertStringNotContainsString('org_id', $e->getMessage());
		}
		$this->assertStringContainsString(ExclusionPolicy::SETTING_ATTRIBUTES, (string)ExclusionPolicy::RefusalToCreate('Person'));
	}

	// ------------------------------------------------------------------ the class

	/** @return array<string, callable(string): mixed> */
	private function classRoutes(): array
	{
		return [
			'get'                  => fn (string $c) => ObjectGet::execute($c, $this->iTeam),
			'search by class'      => fn (string $c) => ObjectSearchByClass::execute($c),
			'search by oql'        => fn (string $c) => ObjectSearchByOQL::execute("SELECT {$c}"),
			'oql, a join'          => fn (string $c) => ObjectSearchByOQL::execute("SELECT Person AS p JOIN {$c} AS t ON p.org_id = t.org_id"),
			'oql, a sub-query'     => fn (string $c) => ObjectSearchByOQL::execute("SELECT Person AS p WHERE p.org_id IN (SELECT {$c} AS t)"),
			'count'                => fn (string $c) => ObjectCount::execute($c),
			'count, by oql'        => fn (string $c) => ObjectCount::execute('', [], "SELECT {$c}"),
			'find by name, in it'  => fn (string $c) => ObjectFindByName::execute($this->sTeamName, $c),
			'history'              => fn (string $c) => ObjectHistory::execute($c, $this->iTeam),
			'related'              => fn (string $c) => ObjectGetRelated::execute($c, $this->iTeam, 'impacts'),
			'create'               => fn (string $c) => ObjectCreate::execute($c, ['name' => 'x', 'org_id' => $this->iOrg]),
			'bulk create'          => fn (string $c) => ObjectBulkCreate::execute($c, [['name' => 'x', 'org_id' => $this->iOrg]]),
			'update'               => fn (string $c) => ObjectUpdate::execute($c, $this->iTeam, ['name' => 'x']),
			'bulk update'          => fn (string $c) => ObjectBulkUpdate::execute($c, [$this->iTeam], ['name' => 'x']),
			'delete'               => fn (string $c) => ObjectDelete::execute($c, $this->iTeam),
			'bulk delete'          => fn (string $c) => ObjectBulkDelete::execute($c, [$this->iTeam]),
			'stimulus'             => fn (string $c) => ObjectApplyStimulus::execute($c, $this->iTeam, 'ev_x'),
			'schema'               => fn (string $c) => ClassSchema::execute($c),
			'attach'               => fn (string $c) => ObjectAttach::execute($c, $this->iTeam, 'x.txt', base64_encode('x')),
			'document'             => fn (string $c) => ObjectGetDocument::execute($c, $this->iTeamFile, 'contents'),
		];
	}

	/**
	 * @dataProvider classRouteProvider
	 */
	public function testAnExcludedClassAnswersAsOneThatDoesNotExist(string $sRoute): void
	{
		$fRoute = $this->classRoutes()[$sRoute];
		$aNames = [self::ABSENT_CLASS, 'Team'];
		$this->exclude(['Team'], []);
		$sAbsent = $this->outcome(static fn () => $fRoute(self::ABSENT_CLASS), $aNames);
		$sExcluded = $this->outcome(static fn () => $fRoute('Team'), $aNames);

		$this->assertSame($sAbsent, $sExcluded, 'a hidden class and an absent one were answered differently');
		$this->assertStringStartsWith('refused', $sExcluded);
	}

	public function testAnExcludedClassIsNotListed(): void
	{
		$this->exclude(['Team'], []);

		$aClasses = array_column(ToolOutput::Decode(ClassList::execute())['classes'] ?? [], 'class');
		$this->assertContains('Person', $aClasses, 'the control: other classes are still listed');
		$this->assertNotContains('Team', $aClasses);
	}

	/** A search on the parent leaves out the rows of an excluded subclass, and the total with them. */
	public function testTheRowsOfAnExcludedSubclassAreNotInASearchOnItsParentNorInItsCounts(): void
	{
		$this->exclude(['Team'], []);

		$oWithoutTeams = new DBObjectSet(DBObjectSearch::FromOQL('SELECT Contact WHERE finalclass != "Team"'));
		$aSearch = ToolOutput::Decode(ObjectSearchByClass::execute('Contact', [], 200));
		$this->assertSame($oWithoutTeams->Count(), $aSearch['total'], 'the total counts the excluded rows');
		$this->assertNotContains('Team', array_column($aSearch['objects'], 'class'));

		$aOql = ToolOutput::Decode(ObjectSearchByOQL::execute('SELECT Contact', 200));
		$this->assertSame($oWithoutTeams->Count(), $aOql['total']);

		$aCount = ToolOutput::Decode(ObjectCount::execute('Contact', [], '', ['finalclass']));
		$this->assertSame($oWithoutTeams->Count(), $aCount['total']);
		$this->assertStringNotContainsString('"Team"', json_encode($aCount['groups']), 'a bucket for the excluded class');

		$this->assertSame(
			$this->outcome(static fn () => ObjectGet::execute('Contact', 99999999), ['99999999']),
			$this->outcome(fn () => ObjectGet::execute('Contact', $this->iTeam), [(string)$this->iTeam]),
			'an object of the excluded class, asked for through its parent, was told apart from one that is not there'
		);
		$aFound = ToolOutput::Decode(ObjectFindByName::execute($this->sTeamName));
		$this->assertSame(0, $aFound['total']);
		$this->assertSame([], $aFound['objects']);
	}

	/** Said once and not guessed: a query that names a parent with an excluded child in a join is refused, without naming the class. */
	public function testAQueryThatCannotBeProvedIsRefusedWithoutNamingTheClass(): void
	{
		$this->exclude(['Team'], []);

		try {
			ObjectSearchByOQL::execute('SELECT Person AS p WHERE p.org_id IN (SELECT Contact AS c WHERE c.org_id = 1) AND p.org_id IN (SELECT Contact AS d)');
			$this->fail('answered');
		} catch (ToolCallException $e) {
			$this->assertStringNotContainsString('Contact', $e->getMessage());
			$this->assertStringNotContainsString('Team', $e->getMessage());
		}
	}

	public function testAFileFiledUnderAnExcludedClassIsNotThere(): void
	{
		$this->exclude(['Team'], []);

		$sUnknown = $this->outcome(static fn () => ObjectGetDocument::execute('Attachment', 99999999, 'contents'), ['99999999']);
		$this->assertSame($sUnknown, $this->outcome(fn () => ObjectGetDocument::execute('Attachment', $this->iTeamFile, 'contents'), [(string)$this->iTeamFile]));

		$aFiles = ToolOutput::Decode(ObjectSearchByClass::execute('Attachment', [], 200));
		$this->assertNotContains($this->iTeamFile, array_column($aFiles['objects'], 'id'));
		$this->assertSame(
			(new DBObjectSet(DBObjectSearch::FromOQL('SELECT Attachment WHERE item_class != "Team"')))->Count(),
			$aFiles['total'],
			'the total counts a file filed under the excluded class'
		);
	}

	/** What points at an excluded class goes with it: a link set to it is not in a read. */
	public function testWhatPointsAtAnExcludedClassIsNotServed(): void
	{
		if (!MetaModel::IsValidAttCode('Person', 'team_list')) {
			$this->markTestSkipped('Needs the people-to-teams link.');
		}

		$this->assertArrayHasKey('team_list', ToolOutput::Decode(ObjectGet::execute('Person', $this->iPerson, '*'))['object'], 'the control');
		$this->exclude(['Team'], []);

		$this->assertTrue(ExclusionPolicy::ExcludesAttribute('Person', 'team_list'));
		$this->assertArrayNotHasKey('team_list', ToolOutput::Decode(ObjectGet::execute('Person', $this->iPerson, '*'))['object']);
	}

	// ------------------------------------------------------------------ the settings

	/** @return array<string, array{0: mixed, 1: mixed}> */
	public static function malformedProvider(): array
	{
		return [
			'classes, not a list'             => ['Team', []],
			'a class the datamodel lacks'     => [['TeamXyzzy'], []],
			'an attribute the class lacks'    => [[], ['Person' => ['phonexyzzy']]],
			'an attribute of a missing class' => [[], ['TeamXyzzy' => ['name']]],
			'part of the friendly name'       => [[], ['Person' => ['first_name']]],
			'part of it, through the parent'  => [[], ['Contact' => ['name']]],
			'part of the complementary name'  => [[], ['UserRequest' => ['title']]],
		];
	}

	/**
	 * @dataProvider malformedProvider
	 */
	public function testASettingThatCannotBeHonouredStopsTheServiceForEveryone(mixed $mClasses, mixed $mAttributes): void
	{
		$this->exclude($mClasses, $mAttributes);

		$this->assertTrue(Settings::AccessSettingsAreMalformed());
		$oPolicy = MCPService::AccessPolicyOfCurrentRequest();
		foreach (['objects', 'datamodel', 'history', 'documents'] as $sToolset) {
			$this->assertFalse($oPolicy->allowsToolset($sToolset), "{$sToolset} is served although the exclusions cannot be honoured");
		}
	}

	public function testSettingsThatAreSoundServeEverythingElse(): void
	{
		$this->exclude(['Team'], ['Person' => ['phone']]);

		$this->assertFalse(Settings::AccessSettingsAreMalformed());
		$this->assertTrue(MCPService::AccessPolicyOfCurrentRequest()->allowsToolset('objects'));
	}

	// ------------------------------------------------------------------ what an account did

	public function testMyActivityNamesNoExcludedClassAndNoExcludedCode(): void
	{
		$sLogin = 'excl-me-'.bin2hex(random_bytes(3));
		$iUser = (int)$this->CreateUser($sLogin, self::profileId('Administrator'), 'Excl-Me-'.bin2hex(random_bytes(3)).'-1!', $this->iPerson)->GetKey();

		$oChange = MetaModel::NewObject('CMDBChange');
		$oChange->Set('date', date('Y-m-d H:i:s'));
		$oChange->Set('userinfo', $sLogin);
		$iChange = (int)$oChange->DBInsert();
		$oOp = MetaModel::NewObject('CMDBChangeOpSetAttributeScalar');
		$oOp->Set('change', $iChange);
		$oOp->Set('objclass', 'Person');
		$oOp->Set('objkey', $this->iPlainPerson);
		$oOp->Set('attcode', 'phone');
		$oOp->Set('oldvalue', 'a');
		$oOp->Set('newvalue', 'b');
		$oOp->DBInsert();

		$oRow = new AltiooEventMCPService();
		$oRow->Set('userinfo', $sLogin);
		$oRow->Set('message', 'row');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', 'core_object_update');
		$oRow->Set('user_id', $iUser);
		$oRow->Set('change_id', $iChange);
		$oRow->Set('changed_objects', "Person::{$this->iPlainPerson} updated (phone, first_name)\nTeam::{$this->iTeam} updated (name)");
		$oRow->Set('read_objects', "Person: {$this->iPlainPerson}\nTeam: {$this->iTeam}");
		$oRow->DBInsertNoReload();

		UserRights::Login($sLogin);
		try {
			$this->exclude(['Team'], ['Person' => ['phone']]);
			$sJson = json_encode(ToolOutput::Decode(MyActivity::execute()));
		} finally {
			UserRights::Deimpersonate();
		}

		$this->assertStringContainsString('Person', $sJson, 'the control: what is not excluded is named');
		$this->assertStringNotContainsString('"Team"', $sJson, 'an excluded class is named');
		$this->assertStringNotContainsString('Team::', $sJson);
		$this->assertStringNotContainsString('phone', $sJson, 'an excluded attribute code is listed');
	}

	/**
	 * The advice for notification events (doc/adr/0012, "Not covered"): exclude EventNotification, never Event.
	 * The audit trail and the account's own calls stay, and the notification classes answer as unknown.
	 */
	public function testExcludingEventNotificationLeavesTheAuditTrailAndMyActivity(): void
	{
		if (!MetaModel::IsValidClass('EventNotificationEmail')) {
			$this->markTestSkipped('Needs notifications.');
		}

		$sLogin = 'excl-evt-'.bin2hex(random_bytes(3));
		$iUser = (int)$this->CreateUser($sLogin, self::profileId('Administrator'), 'Excl-Evt-'.bin2hex(random_bytes(3)).'-1!', $this->iPerson)->GetKey();
		$oRow = new AltiooEventMCPService();
		$oRow->Set('userinfo', $sLogin);
		$oRow->Set('message', 'row');
		$oRow->Set('mcp_method', 'tools/call');
		$oRow->Set('mcp_name', 'core_object_get');
		$oRow->Set('user_id', $iUser);
		$oRow->DBInsertNoReload();

		$this->exclude(['EventNotification'], []);
		$this->assertFalse(Settings::AccessSettingsAreMalformed());

		UserRights::Login($sLogin);
		try {
			$this->assertNotSame([], ToolOutput::Decode(MyActivity::execute())['activity'], 'my activity lost the account\'s own calls');
			$this->assertGreaterThan(0, ToolOutput::Decode(ObjectSearchByClass::execute('AltiooEventMCPService'))['total'], 'the audit trail is no longer readable');

			foreach (['search' => fn (string $c) => ObjectSearchByClass::execute($c), 'get' => fn (string $c) => ObjectGet::execute($c, 1)] as $sRoute => $fRoute) {
				$this->assertSame(
					$this->outcome(static fn () => $fRoute('EventNotificationEmailz'), ['EventNotificationEmailz', 'EventNotificationEmail']),
					$this->outcome(static fn () => $fRoute('EventNotificationEmail'), ['EventNotificationEmailz', 'EventNotificationEmail']),
					"{$sRoute}: a notification class was told apart from one that does not exist"
				);
			}
		} finally {
			UserRights::Deimpersonate();
		}
	}

	private static function profileId(string $sName): int
	{
		$oSearch = DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = :name');

		return (int)(new DBObjectSet($oSearch, [], ['name' => $sName]))->Fetch()->GetKey();
	}
}
