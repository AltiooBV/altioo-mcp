<?php
/**
 * Global-namespace test doubles for iTop's logging API.
 *
 * These classes must carry their exact iTop names in the global namespace, so
 * they cannot be PSR-4 autoloaded; tests/bootstrap.php requires this file.
 *
 * LogAPI itself is only declared when the real one is absent (i.e. when the
 * tests run without a reachable iTop). TestIssueLog extends whichever LogAPI is
 * in play and records calls instead of writing them, so LogAPILoggerTest
 * behaves identically with and without iTop.
 *
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

if (!class_exists('LogAPI', false)) {
	/**
	 * Mirrors the level constants and Log() signature of iTop's
	 * core/log.class.inc.php.
	 */
	abstract class LogAPI
	{
		public const CHANNEL_DEFAULT = '';

		public const LEVEL_ERROR = 'Error';
		public const LEVEL_WARNING = 'Warning';
		public const LEVEL_INFO = 'Info';
		public const LEVEL_OK = 'Ok';
		public const LEVEL_DEBUG = 'Debug';
		public const LEVEL_TRACE = 'Trace';

		public static function Log($sLevel, $sMessage, $sChannel = null, $aContext = array())
		{
		}
	}
}

/**
 * Recording channel, standing in for IssueLog.
 */
class TestIssueLog extends LogAPI
{
	public const CHANNEL_DEFAULT = 'TestIssueLog';

	/** @var array<int, array{level: string, message: string, channel: ?string, context: array}> */
	public static array $aRecorded = [];

	public static function Log($sLevel, $sMessage, $sChannel = null, $aContext = array())
	{
		self::$aRecorded[] = [
			'level' => $sLevel,
			'message' => $sMessage,
			'channel' => $sChannel,
			'context' => $aContext,
		];
	}

	public static function Reset(): void
	{
		self::$aRecorded = [];
	}
}

/**
 * Deliberately not a LogAPI, to exercise the constructor guard.
 */
class NotALogAPI
{
}
