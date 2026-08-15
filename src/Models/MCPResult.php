<?php

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Models;

/**
 * Minimal MCP response structure.
 *
 * @package MCP
 */
class MCPResult implements \JsonSerializable
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

	// ------------------------------------------------------------------
	// Audit only - see jsonSerialize(): these never reach the caller
	// ------------------------------------------------------------------

	/**
	 * @var string|null The reference correlating this call to log/error.log.
	 *
	 * The caller is given it in the message of an internal error, so that the
	 * two can be matched by hand; recording it here is what lets the audit row
	 * be matched to the log entry without reading the message.
	 */
	public ?string $errorReference = null;

	/** @var int|null Wall-clock time the whole request took, in milliseconds. */
	public ?int $durationMs = null;

	/** @var int|null Size of the response body, in bytes. */
	public ?int $responseBytes = null;

	public function __construct(int $code = self::OK, string $message = '')
	{
		$this->code    = $code;
		$this->message = $message;
	}

	public function isSuccess(): bool
	{
		return $this->code === self::OK;
	}

	/**
	 * The shape MCPController::outputJsonResultException() puts on the wire.
	 *
	 * Named rather than inferred from the public properties, so that a field
	 * added for the audit trail - a timing, a log reference - does not silently
	 * become part of what an error response carries back to the caller.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'code'          => $this->code,
			'message'       => $this->message,
			'mcpMethod'     => $this->mcpMethod,
			'mcpName'       => $this->mcpName,
			'requestParams' => $this->requestParams,
		];
	}

	public function SanitizeContent(): void
	{
		// Override to strip sensitive data before logging
	}
}
