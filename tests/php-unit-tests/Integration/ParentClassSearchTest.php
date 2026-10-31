<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use MetaModel;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A search on a parent class says which class each row is.
 *
 * Both search tools tell the caller that "Ticket" answers across UserRequest
 * and Incident and that each result reports the class it actually is. For a
 * while it did not: the class was keyed on MetaModel::DBGetClassField(), which
 * iTop answers '' for a leaf class, every fetched object is a leaf, and the
 * '' key had been dropped - so the rows came back with id and friendlyname and
 * nothing to tell a request from an incident.
 *
 * Contact, Person and Team rather than Ticket: itop-structure declares them, so
 * the two leaves exist on every instance, where Incident needs a module that a
 * request-management-only install does not have.
 *
 * Needs a real datamodel because the bug was in what iTop answers for a leaf.
 */
class ParentClassSearchTest extends ItopDataTestCaseAlias
{
	private int $iOrg;

	/** @var array<string, int> Leaf class => id of the contact created in it. */
	private array $aContacts = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->iOrg = (int)$this->CreateOrganization('ParentClassSearchTest '.bin2hex(random_bytes(4)))->GetKey();
		$this->aContacts['Person'] = (int)$this->createObject('Person', [
			'org_id'     => $this->iOrg,
			'name'       => 'ParentClassSearchTest',
			'first_name' => 'Person',
		])->GetKey();
		$this->aContacts['Team'] = (int)$this->createObject('Team', [
			'org_id' => $this->iOrg,
			'name'   => 'ParentClassSearchTest Team',
		])->GetKey();
	}

	public function testASearchByClassOnAParentNamesEachRowsClass(): void
	{
		$aResult = ToolOutput::Decode(ObjectSearchByClass::execute('Contact', ['org_id' => $this->iOrg]));

		$this->assertSame($this->aContacts, $this->classesById($aResult['objects']));
	}

	public function testAnOqlSearchOnAParentNamesEachRowsClass(): void
	{
		$aResult = ToolOutput::Decode(ObjectSearchByOQL::execute("SELECT Contact WHERE org_id = {$this->iOrg}"));

		$this->assertSame($this->aContacts, $this->classesById($aResult['objects']));
	}

	/** The object, not only the envelope, so a caller keeping the object alone keeps its class. */
	public function testAnObjectReadThroughItsParentNamesItsClass(): void
	{
		$aResult = ToolOutput::Decode(ObjectGet::execute('Contact', $this->aContacts['Team']));

		$this->assertSame('Team', $aResult['object']['class']);
	}

	/** Asking for it by name is not an unknown attribute: it is reported anyway. */
	public function testClassMayBeNamedInOutputFields(): void
	{
		$aResult = ToolOutput::Decode(ObjectSearchByClass::execute('Contact', ['org_id' => $this->iOrg], output_fields: 'class, name'));

		$this->assertSame($this->aContacts, $this->classesById($aResult['objects']));
	}

	/**
	 * @param array<int, array<string, mixed>> $aObjects
	 *
	 * @return array<string, int>
	 */
	private function classesById(array $aObjects): array
	{
		$aClasses = [];
		foreach ($aObjects as $aObject) {
			$this->assertArrayHasKey('class', $aObject, 'every row says what it is');
			$aClasses[$aObject['class']] = (int)$aObject['id'];
		}
		ksort($aClasses);

		return $aClasses;
	}
}
