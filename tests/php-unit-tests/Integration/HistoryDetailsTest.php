<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory as HistoryReader;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBSearch;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C16 (the part that fixes): what the history of an object said of a text field, of a link set and of an
 * attachment, and what it still withholds from an account that is not shown the object.
 */
class HistoryDetailsTest extends ItopDataTestCaseAlias
{
	private int $iTicket = 0;

	private int $iOrg = 0;

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['UserRequest', 'Server', 'lnkFunctionalCIToTicket', 'Attachment'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass)) {
				$this->markTestSkipped("Needs {$sClass}.");
			}
		}
		$this->exclude([]);
		$this->iOrg = (int)$this->CreateOrganization('HistoryDetails '.bin2hex(random_bytes(4)))->GetKey();
		$this->iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $this->CreatePerson(1, $this->iOrg)->GetKey()])->GetKey();
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

	/** @return array<int, array<string, mixed>> */
	private function entries(string $sAttCode = ''): array
	{
		return ToolOutput::Decode(ObjectHistory::execute('UserRequest', $this->iTicket, $sAttCode))['entries'];
	}

	/** @return array<int, array<string, mixed>> */
	private function entriesOf(string $sOperation): array
	{
		return array_values(array_filter($this->entries(), static fn (array $a): bool => $a['operation'] === $sOperation));
	}

	private function linkAServer(): int
	{
		$iServer = (int)$this->createObject('Server', ['name' => 'HistoryDetails '.bin2hex(random_bytes(3)), 'org_id' => $this->iOrg])->GetKey();
		$oTicket = MetaModel::GetObject('UserRequest', $this->iTicket);
		$oSet = $oTicket->Get('functionalcis_list');
		$oSet->AddItem(MetaModel::NewObject('lnkFunctionalCIToTicket', ['functionalci_id' => $iServer]));
		$oTicket->Set('functionalcis_list', $oSet);
		$oTicket->DBUpdate();

		return $iServer;
	}

	public function testATextFieldReportsTheTextItHadBefore(): void
	{
		$oTicket = MetaModel::GetObject('UserRequest', $this->iTicket);
		foreach (['<p>first</p>', '<p>second</p>'] as $sSolution) {
			$oTicket->Set('solution', $sSolution);
			$oTicket->DBUpdate();
		}

		$aRows = array_values(array_filter($this->entries('solution'), static fn (array $a): bool => $a['attribute'] === 'solution'));

		$this->assertNotSame([], $aRows, 'the solution was changed twice');
		$this->assertSame('<p>first</p>', $aRows[0]['from'], 'the newest change replaced the first text');
		$this->assertNull($aRows[0]['to'], 'iTop keeps no new text, and the entry does not invent one');
	}

	public function testALinkSetChangeSaysWhichObjectWasLinked(): void
	{
		$iServer = $this->linkAServer();

		$aRows = array_values(array_filter($this->entries(), static fn (array $a): bool => isset($a['link'])));

		$this->assertNotSame([], $aRows, 'a link was added');
		$this->assertSame(['type' => 'added', 'class' => 'Server', 'id' => $iServer], $aRows[0]['link'], 'the class it is, not the parent its key points at');
	}

	/** The closure the tool gives says no for an object the account is not shown: the entry says what happened, not to which. */
	public function testALinkedObjectTheAccountIsNotShownIsNotNamed(): void
	{
		$iServer = $this->linkAServer();

		$aHistory = HistoryReader::Within(
			MetaModel::GetObject('UserRequest', $this->iTicket),
			static fn (DBObjectSearch $oSearch): ?DBSearch => $oSearch,
			static fn (string $sAttCode): bool => true,
			'',
			50,
			0,
			null,
			static fn (string $sClass, int $iId): ?string => null,
			static fn (): bool => true
		);
		$aRows = array_values(array_filter($aHistory['entries'], static fn (array $a): bool => isset($a['link'])));

		$this->assertNotSame([], $aRows);
		$this->assertSame(['type' => 'added'], $aRows[0]['link']);
		$this->assertStringNotContainsString((string)$iServer, json_encode($aRows));
	}

	/** An excluded class takes the link set typed on it with it (ADR 0012): its rows are not in the history at all. */
	public function testALinkSetOfAnExcludedClassIsNotInTheHistory(): void
	{
		$this->linkAServer();
		$this->exclude(['Server']);

		$this->assertSame([], array_values(array_filter($this->entries(), static fn (array $a): bool => isset($a['link']) || ($a['attribute'] ?? '') === 'functionalcis_list')));
	}

	public function testAnAttachmentAddedNamesItsFileAndId(): void
	{
		$aDone = ToolOutput::Decode(ObjectAttach::execute('UserRequest', $this->iTicket, 'notes.txt', base64_encode("notes\n"), 'text/plain', null, false, 'test'));

		$aRows = $this->entriesOf('CMDBChangeOpAttachmentAdded');

		$this->assertCount(1, $aRows);
		$this->assertSame(['filename' => 'notes.txt', 'id' => (int)$aDone['id']], $aRows[0]['attachment']);
	}

	public function testAnAttachmentOfAnExcludedClassIsNotNamed(): void
	{
		ObjectAttach::execute('UserRequest', $this->iTicket, 'notes.txt', base64_encode("notes\n"), 'text/plain', null, false, 'test');
		$this->exclude(['Attachment']);

		$aRows = $this->entriesOf('CMDBChangeOpAttachmentAdded');

		$this->assertCount(1, $aRows, 'the operation is still part of the history of the ticket');
		$this->assertArrayNotHasKey('attachment', $aRows[0]);
		$this->assertStringNotContainsString('notes.txt', json_encode($aRows));
	}
}
