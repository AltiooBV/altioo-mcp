<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Exception;

use Exception;

/**
 * Authentication/authorisation failure raised by the controller itself.
 *
 * Its message is written by this module and is safe to return to the caller.
 * That is the whole point of the type: every *other* throwable reaching the
 * entry point carries text we did not author - iTop CoreException messages
 * embed SQL fragments, class and table names - and is answered with a generic
 * message instead.
 *
 * It may also carry the RFC 6750 error code the WWW-Authenticate challenge
 * names, for a refusal a client can act on without reading the message.
 *
 * @since 1.0.0
 */
class MCPAuthException extends Exception
{
	/** @var string|null RFC 6750 error code for the challenge, e.g. invalid_token. */
	public readonly ?string $challengeError;

	public function __construct(string $message, int $code, ?string $challengeError = null)
	{
		parent::__construct($message, $code);
		$this->challengeError = $challengeError;
	}
}
