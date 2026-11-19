<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Closure;

/**
 * An answer that is decided and not yet on the wire: what the audit row is made
 * from, and the one thing that writes it out.
 *
 * Why the two are apart. PHP learns that a client has hung up only when it writes
 * output, and then ends the script. A call that is answered first and recorded after
 * is therefore not recorded for a caller that sends a write and closes the socket:
 * the write is committed, no audit row exists, and the write cap, which counts
 * from the rows, never counts it. So nothing in the request is written to the wire
 * until the row is: MCPController::serve() holds one of these, writes the row from
 * its result, and only then calls Emit(). The alternative, ignore_user_abort(),
 * would change how the process answers a hang-up for every request it serves
 * (guide section 6.1); the order does not.
 *
 * @internal
 * @since 2.1.0
 */
final class PreparedAnswer
{
	/** @var Closure(): void */
	private Closure $fnEmit;

	/**
	 * @param MCPResult $result What the audit row is made from.
	 * @param Closure(): void $fnEmit Writes the answer: headers, status and body. Called once, by Emit().
	 */
	public function __construct(public readonly MCPResult $result, Closure $fnEmit)
	{
		$this->fnEmit = $fnEmit;
	}

	/**
	 * Writes the answer to the wire. The last thing a request does: after it the
	 * script may end at any point, if the client is gone.
	 */
	public function Emit(): void
	{
		($this->fnEmit)();
	}
}
