<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * The entry point's source, read as the sequence it runs in.
 *
 * A request passes the gates of MCPController::GATES in their order, then
 * what handleRequest() does after them. The order is the security property, and
 * these helpers lay it out as one text, so a contract test can ask whether one
 * call comes before another whichever class it now lives in. Each gate's body
 * is preceded by a marker naming it, `[[HostIsServedGate]]`, so a test can also
 * ask that a gate comes before a call.
 */
final class RequestPipelineSource
{
	/**
	 * The gates in the order the controller runs them.
	 *
	 * @return array<int, class-string>
	 */
	public static function Gates(): array
	{
		return (new ReflectionClassConstant(MCPController::class, 'GATES'))->getValue();
	}

	/**
	 * The gates' Check() bodies in order, each behind its marker, then the body of
	 * handleRequest().
	 */
	public static function Flattened(bool $bStripComments = true): string
	{
		$sSource = '';
		foreach (self::Gates() as $sGate) {
			$sSource .= '[['.substr((string)strrchr($sGate, '\\'), 1)."]]\n".self::bodyOf($sGate, 'Check')."\n";
		}
		$sSource .= self::bodyOf(MCPController::class, 'serve');

		return $bStripComments ? self::withoutComments($sSource) : $sSource;
	}

	public static function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((string)$oMethod->getFileName());

		return implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));
	}

	/**
	 * Comments out, because the comments explain the very ordering that is asserted
	 * and would otherwise satisfy every search on their own. Markers survive: they
	 * are not PHP comments, so they are re-added around the gates' bodies.
	 */
	private static function withoutComments(string $sSource): string
	{
		$sCode = '';
		foreach (token_get_all('<?php '.$sSource) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
