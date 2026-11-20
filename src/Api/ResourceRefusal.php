<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Api;

use Mcp\Server\Exception\ResourceReadException;
use Throwable;

/**
 * What a resource or a resource template throws to tell the caller why it cannot be read:
 * `throw ResourceRefusal::Because('...')`. The counterpart of {@see ToolRefusal}, for the same reasons, including
 * that the SDK declares `ResourceReadException` `final`, so this returns the SDK's exception rather than extending it.
 *
 * @api
 * @since 2.1.0
 */
final class ResourceRefusal
{
	public static function Because(string $sMessage, int $iCode = 0, ?Throwable $oPrevious = null): ResourceReadException
	{
		return new ResourceReadException($sMessage, $iCode, $oPrevious);
	}

	/** Whether something thrown is a resource refusal: one made by {@see self::Because()}, or by the SDK itself. */
	public static function Is(Throwable $oThrown): bool
	{
		return $oThrown instanceof ResourceReadException;
	}
}
