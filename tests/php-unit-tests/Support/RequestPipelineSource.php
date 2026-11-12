<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Altioo\iTop\Extension\MCP\Controller\MCPController;
use ReflectionClassConstant;

/**
 * The entry point's source, read as the sequence it runs in.
 *
 * A request passes the gates of MCPController::GATES in their order, then
 * what serve() does after them. The order is the security property, and this
 * lays it out as one list of what is called and used, read from the PHP tokens
 * by SourceCalls, so a contract test can ask whether one call comes before
 * another whichever class it now lives in, and a comment or a string that names
 * a call is not one. Each gate's body is preceded by a marker naming it,
 * `[[HostIsServedGate]]`, so a test can also ask that a gate comes before a call.
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
	 * The calls and constants of each gate's Check() in order, each gate behind
	 * its marker, then those of serve(), as the specs SourceCalls describes.
	 *
	 * @return array<int, string>
	 */
	public static function Sequence(): array
	{
		$aSequence = [];
		foreach (self::Gates() as $sGate) {
			$aSequence[] = self::Marker($sGate);
			$aSequence = array_merge($aSequence, SourceCalls::OfClass($sGate)->Uses('Check'));
		}

		return array_merge($aSequence, SourceCalls::OfClass(MCPController::class)->Uses('serve'));
	}

	/**
	 * Is each of these in the sequence, the first of each after the first of the
	 * one before?
	 *
	 * @param array<int, string> $aSpecs
	 */
	public static function InOrder(array $aSpecs): bool
	{
		return SourceCalls::InOrder(self::Sequence(), $aSpecs);
	}

	/** Is this call or marker anywhere in the sequence? */
	public static function Has(string $sSpec): bool
	{
		return in_array(strtolower(ltrim($sSpec, '\\')), array_map('strtolower', self::Sequence()), true);
	}

	/**
	 * What serve() does in its `catch` blocks, in order.
	 *
	 * @return array<int, string>
	 */
	public static function ErrorPath(): array
	{
		return SourceCalls::OfClass(MCPController::class)->UsesInCatch('serve');
	}

	/**
	 * The gates' Check() bodies behind their markers, then serve(), as code: no
	 * comments, no whitespace. For the few checks about shape.
	 */
	public static function Code(): string
	{
		$sCode = '';
		foreach (self::Gates() as $sGate) {
			$sCode .= self::Marker($sGate)."\n".SourceCalls::OfClass($sGate)->Code('Check')."\n";
		}

		return $sCode.SourceCalls::OfClass(MCPController::class)->Code('serve');
	}

	/**
	 * The spec of a call the pipeline makes, from the short name a test uses: a
	 * gate marker, `ResetSession`, `ForgetAuthToken` and so on. A name that is
	 * not known is an error, so a test cannot ask about a call that is spelled
	 * wrongly and pass for want of it.
	 */
	public static function Spec(string $sName): string
	{
		$sRoot = 'Altioo\\iTop\\Extension\\MCP\\';

		return match ($sName) {
			'ResetSession' => 'LoginWebPage::ResetSession',
			'DoLogin' => 'LoginWebPage::DoLogin',
			'RequestScope::Open' => $sRoot.'Service\\Server\\RequestScope::Open',
			'MCPService::run' => $sRoot.'Service\\Server\\MCPService::run',
			'AccessPolicyOfCurrentRequest' => $sRoot.'Service\\Server\\ServedElements::AccessPolicyOfCurrentRequest',
			'ForgetAuthToken', 'PromoteBearerToAuthToken' => $sRoot.'Helper\\MCPHttp::'.$sName,
			'MCPHttp::CarriesACredential' => $sRoot.'Helper\\MCPHttp::CarriesACredential',
			'discardRequestSession' => $sRoot.'Controller\\MCPController::discardRequestSession',
			'EmitResponse', 'OutputJsonResultException' => $sRoot.'Controller\\MCPResponse::'.$sName,
			default => str_starts_with($sName, '[[') ? $sName : throw new \LogicException("the pipeline has no call known as {$sName}"),
		};
	}

	public static function Marker(string $sGate): string
	{
		return '[['.substr((string)strrchr($sGate, '\\'), 1).']]';
	}
}
