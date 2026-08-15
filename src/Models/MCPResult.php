<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Models;

/**
 * Minimal MCP response structure.
 *
 * @package MCP
 */
class MCPResult
{
	/** Result: no issue has been encountered */
	const OK = 0;
	/** Result: missing/wrong credentials or the user does not have enough rights */
	const UNAUTHORIZED = 1;
	/** Result: the operation could not be performed */
	const INTERNAL_ERROR = 100;

	/** @var int Result code */
	public int $code;

	/** @var string Result message */
	public string $message;

	// ------------------------------------------------------------------
	// MCP context — populated by MCPService for audit logging
	// ------------------------------------------------------------------

	/** @var string|null MCP JSON-RPC method (tools/call, resources/read, prompts/get, ...) */
	public ?string $mcpMethod = null;

	/** @var string|null Tool/resource/prompt name that was invoked */
	public ?string $mcpName = null;

	/** @var string|null Raw JSON request params for audit trail */
	public ?string $requestParams = null;

	public function __construct(int $code = self::OK, string $message = '')
	{
		$this->code    = $code;
		$this->message = $message;
	}

	public function isSuccess(): bool
	{
		return $this->code === self::OK;
	}

	public function SanitizeContent(): void
	{
		// Override to strip sensitive data before logging
	}
}
