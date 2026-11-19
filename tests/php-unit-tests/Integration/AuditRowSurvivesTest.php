<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Controller\CallLog;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use CMDBSource;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, HA-7: a failed audit insert dropped the row, and the caller could make it fail - a character
 * of four bytes on a table still in 3-byte utf8, a tool name of 70 000 characters quoted in the message. The row is
 * what the write cap counts from (ADR 0019), so the caller could keep its writes out of the count.
 *
 * The utf8 table is emulated, as the review did: the audit table and the message column are converted for the
 * length of a test and put back after it. A DDL statement ends iTop's test transaction, so the rows are removed by hand.
 */
class AuditRowSurvivesTest extends ItopDataTestCaseAlias
{
	private const CLASS_NAME = 'AltiooEventMCPService';

	/** @var array<string, string> */
	private array $aRestore = [];
	private string $sName;
	private string $sEmoji;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass(self::CLASS_NAME)) {
			$this->markTestSkipped('Needs the audit class.');
		}
		$this->sName = 'zz_ha7_'.bin2hex(random_bytes(4));
		$this->sEmoji = "\u{1F600}";
		CallLog::ForgetColumnCharsets();
	}

	protected function tearDown(): void
	{
		foreach (array_reverse($this->aRestore) as $sSql) {
			CMDBSource::Query($sSql);
		}
		$this->aRestore = [];
		$oSet = new DBObjectSet(DBObjectSearch::FromOQL('SELECT '.self::CLASS_NAME.' WHERE mcp_name = :n', ['n' => $this->sName]));
		while ($oRow = $oSet->Fetch()) {
			$oRow->DBDelete();
		}
		$this->forgetWhatWasReadOfTheSchema();
		parent::tearDown();
	}

	/** iTop keeps what it read of a table, and the module what it read of the columns; the schema is about to change under both. */
	private function forgetWhatWasReadOfTheSchema(): void
	{
		(new \ReflectionMethod(CMDBSource::class, '_TablesInfoCacheReset'))->invoke(null);
		CallLog::ForgetColumnCharsets();
	}

	private function table(): string
	{
		return MetaModel::DBGetTable(self::CLASS_NAME);
	}

	/** Puts the audit table, and Event's message column, into 3-byte utf8 until tearDown. */
	private function convertToThreeByteUtf8(): void
	{
		$sMode = (string)CMDBSource::QueryToScalar('SELECT @@SESSION.sql_mode');
		if (!str_contains($sMode, 'STRICT')) {
			$this->markTestSkipped('The database is not in a strict SQL mode, so a character it cannot store is cut instead of refused.');
		}

		$sTable = $this->table();
		$sEventTable = MetaModel::DBGetTable('Event');
		$aTable = CMDBSource::QueryToArray("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$sTable}'");
		$sCollation = (string)$aTable[0]['c'];
		$aMessage = CMDBSource::QueryToArray("SELECT COLUMN_TYPE AS t, COLLATION_NAME AS c, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$sEventTable}' AND COLUMN_NAME = 'message'");
		$aRow = $aMessage[0];
		$sNull = $aRow['n'] === 'YES' ? 'NULL' : 'NOT NULL';

		$this->aRestore['table'] = "ALTER TABLE `{$sTable}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$sCollation}";
		$this->aRestore['message'] = "ALTER TABLE `{$sEventTable}` MODIFY `message` {$aRow['t']} CHARACTER SET utf8mb4 COLLATE {$aRow['c']} {$sNull}";
		CMDBSource::Query("ALTER TABLE `{$sTable}` CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci");
		CMDBSource::Query("ALTER TABLE `{$sEventTable}` MODIFY `message` {$aRow['t']} CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci {$sNull}");
		$this->forgetWhatWasReadOfTheSchema();
	}

	private function result(string $sMessage, string $sClient): MCPResult
	{
		$oResult = new MCPResult(MCPResult::TOOL_REFUSED, $sMessage);
		$oResult->mcpMethod = 'tools/call';
		$oResult->mcpName = $this->sName;
		$oResult->client = $sClient;
		$oResult->durationMs = 1;

		return $oResult;
	}

	/** @return array<int, \DBObject> */
	private function rows(): array
	{
		$aRows = [];
		$oSet = new DBObjectSet(DBObjectSearch::FromOQL('SELECT '.self::CLASS_NAME.' WHERE mcp_name = :n', ['n' => $this->sName]));
		while ($oRow = $oSet->Fetch()) {
			$aRows[] = $oRow;
		}

		return $aRows;
	}

	public function testALongToolNameQuotedInTheMessageStillLeavesTheRow(): void
	{
		$sMessage = 'Tool not found: "'.str_repeat('x', 70000).'".';
		CallLog::LogIfConfigured($this->result($sMessage, 'client '.$this->sEmoji));

		$aRows = $this->rows();
		$this->assertCount(1, $aRows, 'a long message cost the call its row');
		$this->assertLessThanOrEqual(65535, strlen((string)$aRows[0]->Get('message')));
		$this->assertStringStartsWith('Tool not found: "xxx', (string)$aRows[0]->Get('message'));
		$this->assertSame('client '.$this->sEmoji, $aRows[0]->Get('client'), 'a utf8mb4 table keeps the text as it came');
	}

	public function testACharacterOfFourBytesOnAThreeByteTableIsReplacedAndTheRowIsKept(): void
	{
		$this->convertToThreeByteUtf8();

		CallLog::LogIfConfigured($this->result('refused '.$this->sEmoji, 'client '.$this->sEmoji));

		$aRows = $this->rows();
		$this->assertCount(1, $aRows, 'an emoji in the client name cost the call its row');
		$this->assertSame("client \u{FFFD}", $aRows[0]->Get('client'));
		$this->assertSame("refused \u{FFFD}", $aRows[0]->Get('message'));
	}

	/**
	 * Whatever the first insert fails on, the row is written once more from what the server built. Staged
	 * by letting the table change under a column that was read as taking four bytes.
	 */
	public function testAFailedInsertIsRetriedWithOnlyWhatTheServerBuilt(): void
	{
		CallLog::LogIfConfigured($this->result('primes the cache', 'primer'));
		$this->assertCount(1, $this->rows());
		$this->convertToThreeByteUtf8();
		// Read as it is not: the cache now says four bytes are fine for every column the row sets.
		$oMethod = new \ReflectionProperty(CallLog::class, 'aFourByteColumns');
		$oMethod->setValue(null, array_fill_keys(['message', 'mcp_name', 'client', 'change_comment', 'request_params', 'refusal_text', 'changed_objects'], true));

		CallLog::LogIfConfigured($this->result('refused '.$this->sEmoji, 'client '.$this->sEmoji));

		$aRows = $this->rows();
		$this->assertCount(2, $aRows, 'the failed insert was not retried');
		$aReduced = array_values(array_filter($aRows, static fn ($o) => $o->Get('client') === ''));
		$this->assertCount(1, $aReduced);
		$this->assertSame('error', $aReduced[0]->Get('status'));
		$this->assertSame('tools/call', $aReduced[0]->Get('mcp_method'));
		$this->assertStringNotContainsString($this->sEmoji, (string)$aReduced[0]->Get('message'));
	}
}
