<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Controller\MCPController;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the audit row keeps of a call a tool refused.
 *
 * The refusal is the caller's answer and can quote the value it refused, so
 * the row says a fixed sentence at every level and holds the tool's own text
 * only at debug.
 */
class RefusalTextTest extends ItopDataTestCaseAlias
{
	private const QUOTED = 'jane.doe.refused@example.com';

	private function refusedRow(string $sLevel): \AltiooEventMCPService
	{
		MetaModel::GetConfig()->SetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_LOG_LEVEL, $sLevel);
		$sName = 'refusal-text-'.bin2hex(random_bytes(4));

		$oResult = new MCPResult(MCPResult::TOOL_REFUSED, MCPController::TOOL_REFUSED_MESSAGE);
		$oResult->mcpMethod = 'tools/call';
		$oResult->mcpName = $sName;
		$oResult->toolText = "Value '".self::QUOTED."' is not allowed for 'email'.";
		$oResult->requestParams = '{"name":"'.$sName.'"}';
		(new ReflectionMethod(MCPController::class, 'logIfConfigured'))->invoke(null, $oResult);

		$oSearch = new DBObjectSearch('AltiooEventMCPService');
		$oSearch->AddCondition('mcp_name', $sName, '=');
		$oRow = (new DBObjectSet($oSearch))->Fetch();
		$this->assertNotNull($oRow, 'the refusal was audited at level '.$sLevel);

		return $oRow;
	}

	/**
	 * @dataProvider notDebugProvider
	 */
	public function testBelowDebugTheRowHoldsTheFixedTextAndNeverTheTools(string $sLevel): void
	{
		$oRow = $this->refusedRow($sLevel);

		$this->assertSame('error', $oRow->Get('status'));
		$this->assertSame(MCPController::TOOL_REFUSED_MESSAGE, $oRow->Get('message'));
		$this->assertSame('', (string)$oRow->Get('refusal_text'));
		foreach (['message', 'refusal_text', 'request_params', 'changed_objects', 'read_objects', 'change_comment', 'userinfo'] as $sAttribute) {
			$this->assertStringNotContainsString(self::QUOTED, (string)$oRow->Get($sAttribute), $sAttribute);
		}
	}

	/** @return array<string, array{0: string}> */
	public static function notDebugProvider(): array
	{
		return ['info' => ['info'], 'error' => ['error']];
	}

	public function testAtDebugTheRowAlsoHoldsTheToolsText(): void
	{
		$oRow = $this->refusedRow('debug');

		$this->assertSame(MCPController::TOOL_REFUSED_MESSAGE, $oRow->Get('message'), 'the message stays fixed');
		$this->assertStringContainsString(self::QUOTED, (string)$oRow->Get('refusal_text'));
	}
}
