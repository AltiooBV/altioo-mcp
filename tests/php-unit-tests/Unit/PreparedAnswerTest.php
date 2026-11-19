<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\BatchRefusal;
use Altioo\iTop\Extension\MCP\Controller\MCPResponse;
use Altioo\iTop\Extension\MCP\Controller\PreparedAnswer;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * An answer is decided before it is written: the audit row is made from the one and
 * written between the two (RequestPipelineOrderTest holds the order in the controller).
 * Here, the half that can be run: building an answer puts nothing on the wire.
 */
class PreparedAnswerTest extends TestCase
{
	public function testNothingIsWrittenUntilEmit(): void
	{
		$sBody = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"ok"}]}}';
		// No headers: header() warns once PHPUnit has written, and what is asserted is the body.
		$oResponse = new Response(200, [], $sBody);

		ob_start();
		$oAnswer = MCPResponse::PrepareResponse($oResponse);
		$sBefore = (string)ob_get_clean();

		$this->assertSame('', $sBefore, 'preparing the answer wrote to the wire');
		$this->assertSame(MCPResult::OK, $oAnswer->result->code);
		$this->assertSame(strlen($sBody), $oAnswer->result->responseBytes, 'the row learns the size of the body before the body goes out');

		ob_start();
		$oAnswer->Emit();
		$this->assertSame($sBody, (string)ob_get_clean());
	}

	public function testAnErrorResultIsReadFromTheBodyBeforeItIsWritten(): void
	{
		$oResponse = new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"Nope"}}');

		ob_start();
		$oAnswer = MCPResponse::PrepareResponse($oResponse);
		$this->assertSame('', (string)ob_get_clean());

		$this->assertSame(-32602, $oAnswer->result->code);
		$this->assertSame('Nope', $oAnswer->result->message);
	}

	public function testABatchRefusalIsRecordedAsOneBeforeItIsWritten(): void
	{
		ob_start();
		$oAnswer = BatchRefusal::Refuse();
		$sBefore = (string)ob_get_clean();

		$this->assertSame('', $sBefore);
		$this->assertSame(400, $oAnswer->result->httpStatus);
		$this->assertSame(BatchRefusal::MESSAGE, $oAnswer->result->message);
	}

	public function testAFailureIsAnsweredOnlyOnEmit(): void
	{
		$oResult = new MCPResult(MCPResult::INTERNAL_ERROR, 'The MCP request could not be completed. Server log reference: ref');

		ob_start();
		$oAnswer = MCPResponse::PrepareException($oResult);
		$sBefore = (string)ob_get_clean();

		$this->assertInstanceOf(PreparedAnswer::class, $oAnswer);
		$this->assertSame('', $sBefore, 'preparing the error answer wrote to the wire');
		$this->assertSame($oResult, $oAnswer->result);
	}
}
