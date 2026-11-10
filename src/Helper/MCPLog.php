<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */


namespace Altioo\iTop\Extension\MCP\Helper;

use LogAPI;

/**
 * @api
 * @since 1.0.0
 */
class MCPLog extends LogAPI
{
	const CHANNEL_DEFAULT = 'MCPLog';

	protected static $m_oFileLog = null;

	/**
	 * Opens this module's log, log/error.log under the iTop root, for the
	 * request or the background task that is starting. Safe to call again.
	 *
	 * @since 1.3.0
	 */
	public static function EnableForModule(): void
	{
		self::Enable(APPROOT.'log/error.log');
	}
}
