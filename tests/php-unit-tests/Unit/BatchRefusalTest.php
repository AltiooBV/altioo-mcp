<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Controller\BatchRefusal;
use Altioo\iTop\Extension\MCP\Controller\CallRequest;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Http\Discovery\Psr17Factory;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * A JSON-RPC batch is refused because the audit row cannot describe one: the row is made from the body's own
 * `method` and `params`, and a list has neither.
 */
class BatchRefusalTest extends TestCase
{
	private const BATCH = '[{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"core_object_update","arguments":{"simulate":false}}},'
		.'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"core_object_delete","arguments":{"simulate":false}}}]';

	/** @dataProvider bodies */
	public function testOnlyAnArrayIsABatch(string $sBody, bool $bBatch): void
	{
		$this->assertSame($bBatch, BatchRefusal::IsBatch($sBody));
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public function bodies(): array
	{
		return [
			'two calls'                  => [self::BATCH, true],
			'leading whitespace'         => ["\n  ".self::BATCH, true],
			'a byte order mark'          => ["\xEF\xBB\xBF".self::BATCH, true],
			'the empty batch'            => ['[]', true],
			'one request'                => ['{"jsonrpc":"2.0","id":1,"method":"tools/list"}', false],
			'a request mentioning a list' => ['{"params":[{"a":1}],"method":"x"}', false],
			'nothing'                    => ['', false],
		];
	}

	/**
	 * What the defect was: a batch of two writes made a row with no method, no tool and no parameters, so no
	 * `simulate` either. Kept as the reason the batch is refused rather than described.
	 */
	public function testABatchLeavesTheAuditRowWithNoMethodToolOrParameters(): void
	{
		$oFactory = new Psr17Factory();
		$oRequest = $oFactory->createServerRequest('POST', '/')->withBody($oFactory->createStream(self::BATCH));

		$oResult = CallRequest::GetMCPInfoFromRequest($oRequest, new MCPResult(MCPResult::OK, 'ok'));

		$this->assertNull($oResult->mcpMethod);
		$this->assertNull($oResult->mcpName);
		$this->assertNull($oResult->requestParams);
	}
}
