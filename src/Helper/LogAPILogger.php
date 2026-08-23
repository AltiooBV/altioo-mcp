<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

namespace Altioo\iTop\Extension\MCP\Helper;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use LogAPI;

/**
 * PSR-3 LoggerInterface adapter for iTop/Combodo LogAPI.
 *
 * Maps the eight PSR-3 log levels onto LogAPI's six levels:
 *
 *   PSR-3          → LogAPI
 *   ─────────────────────────────────
 *   emergency      → Error
 *   alert          → Error
 *   critical       → Error
 *   error          → Error
 *   warning        → Warning
 *   notice         → Info
 *   info           → Info
 *   debug          → Debug
 *
 * PSR-3 has no equivalent of LogAPI's Ok / Trace levels; those remain
 * accessible through the typed helpers {@see ok()} and {@see trace()}.
 *
 * Usage
 * -----
 * ```php
 * // Wrap the default IssueLog channel
 * $logger = new LogAPILogger(IssueLog::class);
 *
 * // Wrap a specific channel
 * $logger = new LogAPILogger(IssueLog::class, 'MyModule');
 *
 * // Use it anywhere a Psr\Log\LoggerInterface is expected
 * $logger->error('Something went wrong', ['exception' => $e]);
 * $logger->info('User {user} logged in', ['user' => 'jdoe']);
 * ```
 *
 * Message interpolation
 * ---------------------
 * Implements PSR-3 §1.2: `{placeholder}` tokens in `$message` are replaced
 * with the matching key from `$context`.  Remaining context entries are passed
 * through to LogAPI as-is so they appear in file logs and EventIssue data.
 *
 * @see https://www.php-fig.org/psr/psr-3/
 * @api
 * @since 1.0.0
 */
class LogAPILogger extends AbstractLogger
{
	/**
	 * Map of PSR-3 levels → LogAPI level constants.
	 *
	 * PSR-3 defines eight levels (RFC 5424); LogAPI defines six.
	 * emergency / alert / critical all collapse to Error because LogAPI
	 * has no higher-severity bucket.
	 */
	private const LEVEL_MAP = [
		LogLevel::EMERGENCY => LogAPI::LEVEL_ERROR,
		LogLevel::ALERT     => LogAPI::LEVEL_ERROR,
		LogLevel::CRITICAL  => LogAPI::LEVEL_ERROR,
		LogLevel::ERROR     => LogAPI::LEVEL_ERROR,
		LogLevel::WARNING   => LogAPI::LEVEL_WARNING,
		LogLevel::NOTICE    => LogAPI::LEVEL_INFO,
		LogLevel::INFO      => LogAPI::LEVEL_INFO,
		LogLevel::DEBUG     => LogAPI::LEVEL_DEBUG,
	];

	/**
	 * @var class-string<LogAPI>  Concrete LogAPI subclass to delegate to
	 *                            (e.g. IssueLog::class, SetupLog::class).
	 */
	private string $logClass;

	/**
	 * @var string|null  Channel forwarded to LogAPI.  Null lets LogAPI use
	 *                   the class's own CHANNEL_DEFAULT constant.
	 */
	private ?string $channel;

	/**
	 * @param class-string<LogAPI> $logClass  LogAPI implementation to use.
	 * @param string|null          $channel   Optional channel override.
	 *
	 * @throws \InvalidArgumentException When $logClass is not a subclass of LogAPI.
	 * @since 1.0.0
	 */
	public function __construct(string $logClass, ?string $channel = null)
	{
		if (!is_a($logClass, LogAPI::class, true)) {
			throw new \InvalidArgumentException(
				sprintf('"%s" must be a subclass of LogAPI.', $logClass)
			);
		}

		$this->logClass = $logClass;
		$this->channel  = $channel;
	}

	// -------------------------------------------------------------------------
	// Psr\Log\AbstractLogger implementation
	// -------------------------------------------------------------------------

	/**
	 * Logs with an arbitrary PSR-3 level.
	 *
	 * {@inheritDoc}
	 *
	 * @throws \InvalidArgumentException For unrecognised PSR-3 levels (PSR-3 §1.1).
	 * @since 1.0.0
	 */
	public function log($level, $message, array $context = []): void
	{
		if (!isset(self::LEVEL_MAP[$level])) {
			throw new \InvalidArgumentException(
				sprintf('"%s" is not a valid PSR-3 log level.', $level)
			);
		}

		$itopLevel       = self::LEVEL_MAP[$level];
		$interpolated    = $this->interpolate((string) $message, $context);
		$channel         = $this->resolveChannel($context);

		($this->logClass)::Log($itopLevel, $interpolated, $channel, $context);
	}

	// -------------------------------------------------------------------------
	// Convenience factory methods
	// -------------------------------------------------------------------------

	/**
	 * Returns a new instance scoped to a different channel without mutating
	 * the current logger (immutable-style helper).
	 *
	 * @param string $channel
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public function withChannel(string $channel): static
	{
		$clone          = clone $this;
		$clone->channel = $channel;

		return $clone;
	}

	// -------------------------------------------------------------------------
	// PSR-3 §1.2 – Message interpolation
	// -------------------------------------------------------------------------

	/**
	 * Replaces `{placeholder}` tokens with values from $context.
	 *
	 * Per PSR-3: placeholder names MUST be composed of `[A-Za-z0-9_.]` and
	 * values MUST be castable to string.  Non-stringable values are silently
	 * skipped (token left as-is) to avoid fatal errors.
	 *
	 * @param string  $message Raw message with optional {placeholder} tokens.
	 * @param array   $context Key/value pairs used for interpolation.
	 *
	 * @return string Interpolated message.
	 */
	private function interpolate(string $message, array $context): string
	{
		// Fast-path: no placeholders in the message.
		if (!str_contains($message, '{')) {
			return $message;
		}

		$replace = [];
		foreach ($context as $key => $value) {
			if (is_null($value) || is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
				$replace['{'.$key.'}'] = (string) $value;
			}
		}

		return strtr($message, $replace);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the channel to use for a given log call.
	 *
	 * Priority:
	 *   1. `channel` key inside $context (per-call override)
	 *   2. Channel set on this logger instance
	 *   3. null  →  LogAPI uses the class's CHANNEL_DEFAULT
	 *
	 * @param array $context
	 *
	 * @return string|null
	 */
	private function resolveChannel(array $context): ?string
	{
		if (isset($context['channel']) && is_string($context['channel'])) {
			return $context['channel'];
		}

		return $this->channel;
	}
}
