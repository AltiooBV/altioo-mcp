<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Exception;

use Exception;

/**
 * A request refused on its shape alone, before any credential was looked at.
 *
 * Separate from MCPAuthException because the answer is different in both
 * directions: these are not 401s, so no WWW-Authenticate challenge belongs on
 * them - offering a credential would not help a caller whose Host is wrong -
 * and unlike a generic throwable their message is written here and safe to
 * return.
 *
 * The HTTP status travels on the exception rather than being inferred from the
 * message, and is carried as the exception code.
 */
class MCPRequestRejectedException extends Exception
{
	/** The request arrived under a hostname this instance does not serve. */
	public const HTTP_FORBIDDEN = 403;

	/** A POST that did not announce a JSON body. */
	public const HTTP_UNSUPPORTED_MEDIA_TYPE = 415;

	public function httpStatus(): int
	{
		return $this->getCode() === 0 ? self::HTTP_FORBIDDEN : (int)$this->getCode();
	}
}
