<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Models\MCPResult;
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
	 * MCPController::outputJsonResultException() json_encodes the result
	 * directly, so the public shape is part of the wire contract.
	 */
	public function testSerialisesToJsonWithPublicProperties(): void
	{
		$oResult = new MCPResult(MCPResult::INTERNAL_ERROR, 'boom');
		$oResult->mcpMethod = 'tools/call';
		$oResult->mcpName = 'ObjectGet';

		$aDecoded = json_decode(json_encode($oResult), true);

		$this->assertSame(MCPResult::INTERNAL_ERROR, $aDecoded['code']);
		$this->assertSame('boom', $aDecoded['message']);
		$this->assertSame('tools/call', $aDecoded['mcpMethod']);
		$this->assertSame('ObjectGet', $aDecoded['mcpName']);
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

		$aDecoded = json_decode(json_encode($oResult), true);

		$this->assertArrayNotHasKey('errorReference', $aDecoded);
		$this->assertArrayNotHasKey('durationMs', $aDecoded);
		$this->assertArrayNotHasKey('responseBytes', $aDecoded);
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
