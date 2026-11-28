<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Read\AttributeDescription;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * A key or a link set typed on the parent of an excluded class is excluded (doc/adr/0012, Amendment 3).
 *
 * `lnkContactToTicket.contact_id` points at `Contact`, and what a `Contact` key holds is a `Person` or a `Team`:
 * with `Person` excluded the link row served the person's name and email, and the ticket's `contacts_list` their id.
 * The whole attribute is absent instead, and with it the external fields through the key, the friendly name of the
 * link class (made of `contact_name`) and what a schema lists for the set. The same shape holds for `Server` behind
 * `FunctionalCI`, in `lnkFunctionalCIToTicket`.
 *
 * Each route is asked once about the excluded name and once about a name the datamodel does not have, and the
 * answers must be the same word for word: a different one tells which names exist.
 */
class ExclusionParentTypedTest extends ItopDataTestCaseAlias
{
	private const SURNAME = 'Zxqparentkey';

	private int $iOrg;
	private int $iCaller;
	private int $iPerson;
	private int $iTicket;
	private int $iLink;
	private int $iServer;
	private int $iServerLink;
	private string $sServerName;

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['Person', 'Server', 'UserRequest', 'lnkContactToTicket', 'lnkFunctionalCIToTicket'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}

		$sSuffix = bin2hex(random_bytes(4));
		$this->iOrg = (int)$this->CreateOrganization('ParentKey '.$sSuffix)->GetKey();
		$this->iCaller = (int)$this->CreatePerson(1, $this->iOrg)->GetKey();
		$oPerson = $this->CreatePerson(2, $this->iOrg);
		$oPerson->Set('name', self::SURNAME);
		$oPerson->Set('email', 'zxq.'.$sSuffix.'@parentkey.example');
		$oPerson->DBUpdate();
		$this->iPerson = (int)$oPerson->GetKey();
		$this->iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $this->iCaller])->GetKey();
		$this->iLink = (int)$this->createObject('lnkContactToTicket', ['ticket_id' => $this->iTicket, 'contact_id' => $this->iPerson])->GetKey();

		$this->sServerName = 'Zxqserver'.$sSuffix;
		$this->iServer = (int)$this->createObject('Server', ['name' => $this->sServerName, 'org_id' => $this->iOrg])->GetKey();
		$this->iServerLink = (int)$this->createObject('lnkFunctionalCIToTicket', ['ticket_id' => $this->iTicket, 'functionalci_id' => $this->iServer])->GetKey();

		$this->exclude([]);
	}

	protected function tearDown(): void
	{
		$this->exclude([]);
		parent::tearDown();
	}

	/** @param array<int, string> $aClasses */
	private function exclude(array $aClasses): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, []);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	/**
	 * What a call came to, with the names it was given taken out: the words of a refusal, or "ok".
	 *
	 * @param array<int, string> $aNames
	 */
	private function outcome(callable $fCall, array $aNames): string
	{
		try {
			$fCall();
			$sOut = 'ok';
		} catch (ToolCallException $e) {
			$sOut = 'refused '.$e->getMessage();
		}
		$sOut = preg_replace('/\.? You may try "[^"]*" instead\./', '', $sOut);

		return str_replace($aNames, '#', $sOut);
	}

	/**
	 * @param callable(string): mixed $fRoute
	 */
	private function assertAbsentLikeAnUnknownName(callable $fRoute, string $sName, string $sRoute, bool $bRefuses = true): void
	{
		$sUnknown = $sName.'z';
		$aNames = [$sUnknown, $sName];
		$sAbsent = $this->outcome(static fn () => $fRoute($sUnknown), $aNames);
		$sExcluded = $this->outcome(static fn () => $fRoute($sName), $aNames);

		$this->assertSame($sAbsent, $sExcluded, "{$sRoute}: '{$sName}' was told apart from a name the datamodel does not have");
		if ($bRefuses) {
			$this->assertStringStartsWith('refused', $sExcluded, "{$sRoute}: '{$sName}' was accepted");
		}
	}

	/** @return array<int, string> */
	private function derivedFromTheContactKey(): array
	{
		return ['contact_id', 'contact_name', 'contact_email', 'contact_id_friendlyname', 'friendlyname'];
	}

	// ------------------------------------------------------------------ the key, the set and what hangs on them

	public function testTheControlServesThePersonThroughTheLinkWhileNothingIsExcluded(): void
	{
		$aRow = ToolOutput::Decode(ObjectGet::execute('lnkContactToTicket', $this->iLink, '*'))['object'];
		$this->assertSame(self::SURNAME, $aRow['contact_name']);
		$this->assertArrayHasKey('contact_id', $aRow);
		$this->assertArrayHasKey('friendlyname', $aRow);
		$this->assertArrayHasKey('contacts_list', ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iTicket, '*'))['object']);
	}

	public function testAKeyTypedOnTheParentOfAnExcludedClassIsExcludedWithWhatHangsOnIt(): void
	{
		$this->exclude(['Person']);

		foreach ($this->derivedFromTheContactKey() as $sCode) {
			$this->assertTrue(ExclusionPolicy::ExcludesAttribute('lnkContactToTicket', $sCode), "{$sCode} is served");
		}
		// What is not about the excluded class stays.
		$this->assertFalse(ExclusionPolicy::ExcludesAttribute('lnkContactToTicket', 'ticket_id'));
		$this->assertFalse(ExclusionPolicy::ExcludesAttribute('lnkContactToTicket', 'ticket_ref'));
		$this->assertFalse(ExclusionPolicy::ExcludesAttribute('Organization', 'friendlyname'));

		$sJson = json_encode(ToolOutput::Decode(ObjectGet::execute('lnkContactToTicket', $this->iLink, '*'))['object']);
		$this->assertStringNotContainsString(self::SURNAME, $sJson, 'the excluded person\'s name');
		$this->assertStringNotContainsString('parentkey.example', $sJson, 'the excluded person\'s email');
		foreach ($this->derivedFromTheContactKey() as $sCode) {
			$this->assertStringNotContainsString('"'.$sCode.'"', $sJson, "{$sCode} is in the row");
		}
		$this->assertStringContainsString('ticket_ref', $sJson, 'the control: the rest of the row is served');
	}

	public function testTheDefaultReadOfAClassWhoseNameIsNotServedStillAnswers(): void
	{
		$this->exclude(['Person']);

		$aRow = ToolOutput::Decode(ObjectGet::execute('lnkContactToTicket', $this->iLink))['object'];
		$this->assertSame($this->iLink, (int)$aRow['id']);
		$this->assertArrayNotHasKey('friendlyname', $aRow);

		$aSearch = ToolOutput::Decode(ObjectSearchByClass::execute('lnkContactToTicket', ['ticket_id' => $this->iTicket]));
		$this->assertSame(1, $aSearch['total']);
		$this->assertStringNotContainsString(self::SURNAME, json_encode($aSearch));
		$aOql = ToolOutput::Decode(ObjectSearchByOQL::execute('SELECT lnkContactToTicket WHERE ticket_id = '.$this->iTicket));
		$this->assertSame(1, $aOql['total']);
		$this->assertStringNotContainsString(self::SURNAME, json_encode($aOql));

		// Typed out, the name is an attribute the class has not, as one the datamodel lacks.
		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ObjectGet::execute('lnkContactToTicket', $this->iLink, $a), 'friendlyname', 'get');
	}

	public function testEveryRouteAnswersForTheExcludedLinkAttributesAsForOnesThatDoNotExist(): void
	{
		$this->exclude(['Person']);

		foreach (['contact_id', 'contact_name', 'contact_email', 'contact_id_friendlyname'] as $sCode) {
			$aRoutes = [
				'get'            => fn (string $a) => ObjectGet::execute('lnkContactToTicket', $this->iLink, $a),
				'search, filter' => fn (string $a) => ObjectSearchByClass::execute('lnkContactToTicket', [$a => 'x']),
				'search, fields' => fn (string $a) => ObjectSearchByClass::execute('lnkContactToTicket', [], 50, 0, '', 'asc', $a),
				'search, order'  => fn (string $a) => ObjectSearchByClass::execute('lnkContactToTicket', [], 50, 0, $a),
				'oql, filter'    => fn (string $a) => ObjectSearchByOQL::execute("SELECT lnkContactToTicket WHERE {$a} LIKE 'x%'"),
				'oql, order'     => fn (string $a) => ObjectSearchByOQL::execute('SELECT lnkContactToTicket', 50, 0, $a),
				'count, filter'  => fn (string $a) => ObjectCount::execute('lnkContactToTicket', [$a => 'x']),
				'count, group'   => fn (string $a) => ObjectCount::execute('lnkContactToTicket', [], '', [$a]),
				'update'         => fn (string $a) => ObjectUpdate::execute('lnkContactToTicket', $this->iLink, [$a => 'x']),
			];
			foreach ($aRoutes as $sRoute => $fRoute) {
				$this->assertAbsentLikeAnUnknownName($fRoute, $sCode, "{$sRoute} ({$sCode})");
			}
		}
	}

	public function testALinkSetToTheParentOfAnExcludedClassIsAbsentOnEveryRoute(): void
	{
		$this->exclude(['Person']);

		$this->assertTrue(ExclusionPolicy::ExcludesAttribute('UserRequest', 'contacts_list'));
		$this->assertTrue(ExclusionPolicy::ExcludesAttribute('Ticket', 'contacts_list'));
		$this->assertContains('contacts_list', ExclusionPolicy::ExcludedAttributes('UserRequest'));

		$aGet = ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iTicket, '*'))['object'];
		$this->assertArrayNotHasKey('contacts_list', $aGet);
		$this->assertStringNotContainsString(self::SURNAME, json_encode($aGet));

		$aRoutes = [
			'get'      => fn (string $a) => ObjectGet::execute('UserRequest', $this->iTicket, $a),
			'oql'      => fn (string $a) => ObjectSearchByOQL::execute("SELECT UserRequest WHERE {$a} = 'x'"),
			'search'   => fn (string $a) => ObjectSearchByClass::execute('UserRequest', [], 50, 0, '', 'asc', $a),
			// A write that names the set: the dry run is the existence oracle of the review, and it answers as for any unknown name.
			'dry run'  => fn (string $a) => ObjectUpdate::execute('UserRequest', $this->iTicket, [$a => [['contact_id' => $this->iPerson, 'role_text' => 'x']]]),
			'dry run, a name that is not there' => fn (string $a) => ObjectUpdate::execute('UserRequest', $this->iTicket, [$a => [['contact_id' => 99999999]]]),
		];
		foreach ($aRoutes as $sRoute => $fRoute) {
			$this->assertAbsentLikeAnUnknownName($fRoute, 'contacts_list', $sRoute);
		}
		// A schema asked for one attribute refuses an unknown one; the set is refused in the same words.
		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ClassSchema::execute('UserRequest', 'attributes', $a), 'contacts_list', 'schema');

		// An existing person and an id nobody has are answered alike, which is the point of the dry run above.
		$sExisting = $this->outcome(fn () => ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => [['contact_id' => $this->iPerson]]]), ['contacts_list']);
		$sMissing = $this->outcome(fn () => ObjectUpdate::execute('UserRequest', $this->iTicket, ['contacts_list' => [['contact_id' => 99999999]]]), ['contacts_list']);
		$this->assertSame($sMissing, $sExisting);
	}

	public function testTheSchemaNamesNoExcludedPersonThroughAnyAttribute(): void
	{
		$this->exclude(['Person']);

		foreach (['UserRequest', 'Ticket', 'lnkContactToTicket'] as $sClass) {
			$aSchema = ToolOutput::Decode(ClassSchema::execute($sClass, 'all'));
			$sJson = json_encode($aSchema);
			$this->assertStringNotContainsString(self::SURNAME, $sJson, "the schema of {$sClass} names the person");
			$this->assertStringNotContainsString('parentkey.example', $sJson);
			$this->assertArrayNotHasKey('contacts_list', (array)($aSchema['attributes'] ?? []), "{$sClass} lists the link set");
			$this->assertArrayNotHasKey('contacts_list', (array)($aSchema['derived'] ?? []));
		}
		$aLink = ToolOutput::Decode(ClassSchema::execute('lnkContactToTicket', 'all'));
		$this->assertStringNotContainsString('contact_', json_encode($aLink['attributes'] ?? []), 'the schema of the link class names a field through the key');
	}

	/**
	 * RE-2 N-2: an attribute whose allowed values are class names listed the excluded class (`target_class` of a trigger,
	 * a parent's `finalclass`), and a value that named one was accepted where an unknown one was refused with the list.
	 */
	public function testNoAttributeWhoseValuesAreClassesListsAnExcludedClass(): void
	{
		$this->assertArrayHasKey('Person', MetaModel::GetAttributeDef('Contact', 'finalclass')->GetAllowedValues(), 'the control: the datamodel lists Person');
		$this->assertArrayHasKey('Person', MetaModel::GetAttributeDef('TriggerOnObjectCreate', 'target_class')->GetAllowedValues());

		$this->exclude(['Person']);

		$aSchema = ToolOutput::Decode(ClassSchema::execute('TriggerOnObjectCreate', 'attributes', 'target_class'))['attributes']['target_class'];
		$this->assertArrayNotHasKey('Person', (array)$aSchema['values'], 'target_class lists the excluded class');
		$this->assertStringNotContainsString('"Person"', json_encode($aSchema['values']));
		$this->assertSame(
			count(MetaModel::GetAttributeDef('TriggerOnObjectCreate', 'target_class')->GetAllowedValues()) - 1,
			$aSchema['valuesTotal'] ?? count((array)$aSchema['values']),
			'exactly the excluded class is left out of the list and its total'
		);

		// A value naming the excluded class is refused as one the datamodel does not have, with the same list.
		$this->assertAbsentLikeAnUnknownName(
			fn (string $a) => ObjectSearchByClass::execute('Contact', ['finalclass' => $a], 10, 0),
			'Person',
			'search on finalclass'
		);

		// A parent's finalclass lists the classes under it: the excluded one is left out and its sibling is not.
		$aFinal = AttributeDescription::ServedValues(MetaModel::GetAttributeDef('Contact', 'finalclass'));
		$this->assertArrayNotHasKey('Person', (array)$aFinal, 'the finalclass of Contact lists the excluded subclass');
		$this->assertArrayHasKey('Team', (array)$aFinal, 'the control: a class that is served is still listed');
	}

	public function testFindingByNameNeverReachesTheNameOfAnExcludedPerson(): void
	{
		$this->exclude(['Person']);

		foreach (['lnkContactToTicket', ''] as $sClass) {
			$aFound = ToolOutput::Decode(ObjectFindByName::execute(self::SURNAME, $sClass, 50, '*'));
			$this->assertSame(0, $aFound['total'], "found by the person's name (class '{$sClass}')");
			$this->assertStringNotContainsString(self::SURNAME, json_encode($aFound['objects']));
		}
	}

	public function testTheSettingsStayWellFormedWhenTheExclusionIsDerived(): void
	{
		$this->exclude(['Person']);

		$this->assertFalse(ExclusionPolicy::IsMalformed(), (string)ExclusionPolicy::Problem());
		$this->assertFalse(AccessPolicy::SettingsAreMalformed());
		$this->exclude(['Server']);
		$this->assertFalse(ExclusionPolicy::IsMalformed(), (string)ExclusionPolicy::Problem());
		$this->assertFalse(AccessPolicy::SettingsAreMalformed());
	}

	// ------------------------------------------------------------------ the same shape behind FunctionalCI

	public function testAKeyAndALinkSetTypedOnFunctionalCIAreExcludedWithServerExcluded(): void
	{
		$this->exclude(['Server']);

		foreach (['functionalci_id', 'functionalci_name', 'functionalci_id_friendlyname', 'friendlyname'] as $sCode) {
			$this->assertTrue(ExclusionPolicy::ExcludesAttribute('lnkFunctionalCIToTicket', $sCode), "{$sCode} is served");
		}
		$this->assertTrue(ExclusionPolicy::ExcludesAttribute('UserRequest', 'functionalcis_list'));

		$sRow = json_encode(ToolOutput::Decode(ObjectGet::execute('lnkFunctionalCIToTicket', $this->iServerLink, '*'))['object']);
		$this->assertStringNotContainsString($this->sServerName, $sRow);
		$this->assertStringNotContainsString('"functionalci_id"', $sRow);
		$this->assertStringContainsString('ticket_ref', $sRow, 'the control: the rest of the row is served');

		$aTicket = ToolOutput::Decode(ObjectGet::execute('UserRequest', $this->iTicket, '*'))['object'];
		$this->assertArrayNotHasKey('functionalcis_list', $aTicket);
		$this->assertStringNotContainsString($this->sServerName, json_encode($aTicket));

		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ObjectSearchByOQL::execute("SELECT lnkFunctionalCIToTicket WHERE {$a} LIKE 'Zxq%'"), 'functionalci_name', 'oql');
		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ObjectUpdate::execute('UserRequest', $this->iTicket, [$a => [['functionalci_id' => $this->iServer]]]), 'functionalcis_list', 'dry run');

		$sSchema = json_encode(ToolOutput::Decode(ClassSchema::execute('UserRequest', 'all')));
		$this->assertStringNotContainsString($this->sServerName, $sSchema);
		$this->assertStringNotContainsString('functionalcis_list', $sSchema);

		$this->assertFalse(ExclusionPolicy::IsMalformed(), (string)ExclusionPolicy::Problem());
	}

	// ------------------------------------------------------------------ a friendly name made of an excluded value

	/** A class that names itself by an excluded organisation's name does not serve its name: not in a read, a filter or a search. */
	public function testAFriendlyNameMadeOfAnExcludedValueIsNotServed(): void
	{
		if (!MetaModel::IsValidClass('lnkPersonToTeam')) {
			$this->markTestSkipped('Needs the people-to-teams link.');
		}

		$this->exclude(['Person']);

		$this->assertTrue(ExclusionPolicy::ExcludesAttribute('lnkPersonToTeam', 'friendlyname'), 'the link between a person and a team is named by the person');
		$this->assertFalse(ExclusionPolicy::ExcludesAttribute('Team', 'friendlyname'), 'the control: a team is named by itself');
		$this->assertTrue(ExclusionPolicy::ExcludesAttribute('lnkPersonToTeam', 'person_id_friendlyname'));
		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ObjectSearchByOQL::execute("SELECT lnkPersonToTeam WHERE {$a} LIKE 'Zxq%'"), 'friendlyname', 'oql');
		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ObjectSearchByClass::execute('lnkPersonToTeam', [$a => 'x']), 'friendlyname', 'filter');
		$this->assertAbsentLikeAnUnknownName(fn (string $a) => ObjectSearchByClass::execute('lnkPersonToTeam', [], 50, 0, $a), 'friendlyname', 'sort');
	}

	// ------------------------------------------------------------------ what each mode shows of an object

	public function testTheConsoleShowsNoAttributeTheRuleExcludes(): void
	{
		$this->exclude(['Person']);

		$oShown = ObjectAccess::Current()->ShownOf(MetaModel::GetObject('UserRequest', $this->iTicket));
		$this->assertFalse($oShown('contacts_list'));
		$this->assertTrue($oShown('title'));
	}
}
