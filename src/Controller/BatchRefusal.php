<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Helper\MCPMethod;
use Altioo\iTop\Extension\MCP\Model\MCPResult;

/**
 * A JSON-RPC batch is refused, whatever the protocol version.
 *
 * The SDK accepts a JSON array of up to 100 requests and answers each, but the audit row is made once
 * per HTTP request from the body's own `method` and `params`, which a list does not have: N tool calls
 * left one row with no tool name, no parameters and no `simulate`, so a review, `core_my_activity` and a
 * SIEM saw none of them. Batching is optional in 2025-03-26 and was removed after it, so refusing it is
 * conformant on every revision this endpoint serves.
 *
 * @internal
 * @since 2.1.0
 */
final class BatchRefusal
{
	public const MESSAGE = 'Batch requests are not accepted. Send one JSON-RPC request per HTTP request.';

	/** Whether the body is a JSON array, which is how a batch - or the empty one - is spelled. */
	public static function IsBatch(string $sBody): bool
	{
		return str_starts_with(ltrim($sBody, " \t\r\n\xEF\xBB\xBF"), '[');
	}

	/** Answers the request and returns the result the audit row is made from. */
	public static function Refuse(): MCPResult
	{
		MCPResponse::EmitBatchRefusal(self::MESSAGE);

		$oResult = new MCPResult(MCPResult::REQUEST_REJECTED, self::MESSAGE);
		$oResult->httpStatus = 400;
		$oResult->mcpMethod = MCPMethod::BATCH;

		return $oResult;
	}
}
