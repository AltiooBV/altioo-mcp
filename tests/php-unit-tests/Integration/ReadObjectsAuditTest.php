<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Test\Support\CallsToolsOnTheWire;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * Every tool that hands an object back tells the audit row which one.
 *
 * ReadSummaryTest pins the format. This runs each reading tool through the
 * SDK's tools/call against real objects and asserts the objects arrive in
 * ReadSummary - the line MCPController writes to `read_objects` - and that
 * nothing the objects hold does. A read path added without telling the audit
 * row is the failure this exists to catch, and it is silent otherwise: the
 * row would simply be short.
 */
class ReadObjectsAuditTest extends ItopDataTestCaseAlias
{
	use CallsToolsOnTheWire;

	/** Text that must never reach the column: it is what the objects say. */
	private const SECRET = 'Zorglub-private-value';

	private int $iOrg;
	private int $iPerson;
	private int $iTicket;

	protected function setUp(): void
	{
		parent::setUp();

		$this->iOrg = (int)$this->CreateOrganization('ReadObjectsAudit '.bin2hex(random_bytes(4)))->GetKey();
		$this->iPerson = (int)$this->CreatePerson(1, $this->iOrg)->GetKey();
		$this->iTicket = (int)$this->CreateUserRequest(1, [
			'org_id'      => $this->iOrg,
			'caller_id'   => $this->iPerson,
			'title'       => self::SECRET,
			'description' => self::SECRET,
		])->GetKey();
		ReadSummary::Forget();
	}

	protected function tearDown(): void
	{
		ReadSummary::Forget();
		parent::tearDown();
	}

	/**
	 * @dataProvider readingToolProvider
	 *
	 * @param array<string, mixed> $aArguments
	 */
	public function testEachReadingToolNotesTheObjectItReturned(string $sTool, array $aArguments, string $sExpectedClass): void
	{
		$aArguments = $this->resolved($aArguments);

		$oResult = $this->call($sTool, $aArguments);
		$this->assertFalse($oResult->isError ?? false, $sTool.' answered an error: '.json_encode($oResult));

		$sLine = ReadSummary::Compose();
		$this->assertMatchesRegularExpression('/\b'.$sExpectedClass.': [\d, ]*\b'.($sExpectedClass === 'Person' ? $this->iPerson : $this->iTicket).'\b/', $sLine, $sTool.' returned an object the audit row does not name: "'.$sLine.'"');
		$this->assertStringNotContainsString(self::SECRET, $sLine);
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string}> */
	public static function readingToolProvider(): array
	{
		return [
			'get'          => ['core_object_get', ['class' => 'UserRequest', 'id' => '@ticket'], 'UserRequest'],
			'search class' => ['core_object_search_by_class', ['class' => 'UserRequest', 'filters' => ['title' => self::SECRET]], 'UserRequest'],
			'search oql'   => ['core_object_search_by_oql', ['oql' => 'SELECT UserRequest WHERE id = @ticket'], 'UserRequest'],
			'history'      => ['core_object_history', ['class' => 'UserRequest', 'id' => '@ticket'], 'UserRequest'],
			'person'       => ['core_object_get', ['class' => 'Person', 'id' => '@person'], 'Person'],
		];
	}

	public function testAGraphNotesTheObjectsItReturned(): void
	{
		$oServer = $this->createObject('Server', ['name' => 'ReadObjectsAudit-'.bin2hex(random_bytes(3)), 'org_id' => $this->iOrg]);
		$oSolution = $this->createObject('ApplicationSolution', ['name' => 'ReadObjectsAudit-'.bin2hex(random_bytes(3)), 'org_id' => $this->iOrg]);
		$this->createObject('lnkApplicationSolutionToFunctionalCI', ['applicationsolution_id' => $oSolution->GetKey(), 'functionalci_id' => $oServer->GetKey()]);
		ReadSummary::Forget();

		$oResult = $this->call('core_object_get_related', ['class' => 'Server', 'id' => (int)$oServer->GetKey(), 'relation' => 'impacts', 'direction' => 'down']);
		$this->assertFalse($oResult->isError ?? false, json_encode($oResult));

		$sLine = ReadSummary::Compose();
		$this->assertStringContainsString('ApplicationSolution: '.$oSolution->GetKey(), $sLine, 'the graph has its own node builder, which is why it notes for itself');
	}

	/**
	 * A search that matches nothing returned nothing, and the audit row says
	 * so rather than naming what the query asked about.
	 */
	public function testAReadThatReturnsNothingNotesNothing(): void
	{
		$this->call('core_object_search_by_oql', ['oql' => 'SELECT UserRequest WHERE id = 0']);

		$this->assertSame('', ReadSummary::Compose());
	}

	/**
	 * A dry run echoes the object it would write, which has no key yet.
	 */
	public function testADryRunsNewObjectIsNotNoted(): void
	{
		$this->call('core_object_create', ['class' => 'Organization', 'fields' => ['name' => 'ReadObjectsAudit '.bin2hex(random_bytes(4))], 'simulate' => true]);

		$this->assertStringNotContainsString('Organization: 0', ReadSummary::Compose());
		$this->assertStringNotContainsString('Organization: -', ReadSummary::Compose());
	}

	/**
	 * @param array<string, mixed> $aArguments
	 *
	 * @return array<string, mixed>
	 */
	private function resolved(array $aArguments): array
	{
		array_walk_recursive($aArguments, function (&$mValue): void {
			if (is_string($mValue)) {
				$mValue = str_replace(['@ticket', '@person'], [(string)$this->iTicket, (string)$this->iPerson], $mValue);
				if (ctype_digit($mValue)) {
					$mValue = (int)$mValue;
				}
			}
		});

		return $aArguments;
	}
}
