<?php


namespace Altioo\iTop\Extension\MCP\Helper;

use LogAPI;

class MCPLog extends LogAPI
{
	const CHANNEL_DEFAULT = 'MCPLog';

	protected static $m_oFileLog = null;
}