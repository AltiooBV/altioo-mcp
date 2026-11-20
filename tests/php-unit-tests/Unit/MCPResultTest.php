<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Model\MCPResult;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

class MCPResultTest extends TestCase
{
	public function testDefaultsToSuccess(): void
	{
		$oResult = new MCPResult();

		$this->assertSame(MCPResult::OK, $oResult->code);
		$this->assertSame('', $oResult->message);
		$this->assertTrue($oResult->isSuccess());
	}

	public function testCarriesCodeAndMessage(): void
	{
		$oResult = new MCPResult(MCPResult::UNAUTHORIZED, 'Invalid login');

		$this->assertSame(MCPResult::UNAUTHORIZED, $oResult->code);
		$this->assertSame('Invalid login', $oResult->message);
		$this->assertFalse($oResult->isSuccess());
	}

	/**
	 * The controller stores an MCP error code straight from the JSON-RPC
	 * payload, so any non-zero value has to read as failure - not just the two
	 * named constants.
	 */
	public function testAnyNonZeroCodeIsAFailure(): void
	{
		$this->assertFalse((new MCPResult(MCPResult::INTERNAL_ERROR))->isSuccess());
		$this->assertFalse((new MCPResult(-32601))->isSuccess());
		$this->assertFalse((new MCPResult(42))->isSuccess());
	}

	public function testAuditFieldsStartNull(): void
	{
		$oResult = new MCPResult();

		$this->assertNull($oResult->mcpMethod);
		$this->assertNull($oResult->mcpName);
		$this->assertNull($oResult->requestParams);
	}

	/**
	 * MCPResponse::PrepareException() json_encodes the result
	 * directly, so the public shape is part of the wire contract: the code and the words, nothing else.
	 */
	public function testSerialisesToTheCodeAndTheWords(): void
	{
		$oResult = new MCPResult(MCPResult::INTERNAL_ERROR, 'boom');

		$this->assertSame(['code' => MCPResult::INTERNAL_ERROR, 'message' => 'boom'], json_decode(json_encode($oResult), true));
	}

	/**
	 * Review 2026-11-18, RT-8: a request refused before any tool ran was answered with the audit row's own fields,
	 * `mcpMethod`, `mcpName` and `requestParams`, which named this module's internals to the caller and held a constant or null.
	 */
	public function testTheAuditContextNeverReachesTheCaller(): void
	{
		$oResult = new MCPResult(MCPResult::UNAUTHORIZED, 'No credential was sent.');
		$oResult->mcpMethod = 'exceptions';
		$oResult->mcpName = 'ObjectGet';
		$oResult->requestParams = '{"name":"x"}';
		$oResult->client = 'a client';

		$aDecoded = json_decode(json_encode($oResult), true);

		foreach (['mcpMethod', 'mcpName', 'requestParams', 'client'] as $sField) {
			$this->assertArrayNotHasKey($sField, $aDecoded);
		}
		$this->assertStringNotContainsString('mcp', strtolower(json_encode($oResult)));
	}

	/**
	 * The audit trail records how long a call took and which log entry
	 * explains it. Neither is the caller's business, and both would have
	 * ridden along the moment they were added, had the wire shape stayed
	 * whatever the public properties happened to be.
	 */
	public function testAuditOnlyFieldsStayOutOfTheResponse(): void
	{
		$oResult = new MCPResult(MCPResult::INTERNAL_ERROR, 'boom');
		$oResult->errorReference = 'a1b2c3d4';
		$oResult->durationMs = 1234;
		$oResult->responseBytes = 99;
		$oResult->challengeError = 'invalid_token';

		$aDecoded = json_decode(json_encode($oResult), true);

		$this->assertArrayNotHasKey('errorReference', $aDecoded);
		$this->assertArrayNotHasKey('durationMs', $aDecoded);
		$this->assertArrayNotHasKey('responseBytes', $aDecoded);
		$this->assertArrayNotHasKey('challengeError', $aDecoded);
	}

	public function testAuditOnlyFieldsStartNull(): void
	{
		$oResult = new MCPResult();

		$this->assertNull($oResult->errorReference);
		$this->assertNull($oResult->durationMs);
		$this->assertNull($oResult->responseBytes);
	}

	public function testConstantsAreDistinct(): void
	{
		$aCodes = [MCPResult::OK, MCPResult::UNAUTHORIZED, MCPResult::INTERNAL_ERROR];

		$this->assertCount(3, array_unique($aCodes));
		$this->assertSame(0, MCPResult::OK, 'isSuccess() compares against 0');
	}
}
