<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use ReflectionClass;

/**
 * Whether a class is one of this module's own elements: defined in a file under
 * this module's own src/Core/.
 *
 * The namespace proves nothing. A tool pack can declare a class in
 * Altioo\iTop\Extension\MCP\Core\ through its own autoloader, so a namespace
 * test would let it take the reserved "core" tool namespace, be served in
 * portal mode without the operator listing it (ADR 0017), and skip the
 * element contract's portal check. Where the class is defined cannot be
 * claimed that way. The namespace prefix is kept as a cheap first test, and
 * the file decides.
 *
 * The module directory is wherever this file is loaded from, so the answer is
 * about the copy that is running: iTop compiles a module into
 * env-production/<module>/ and loads it from there. Both sides go through
 * realpath(), so a symlinked checkout agrees with itself. Nothing is cached:
 * a path computed on each call is not state that outlives a request.
 *
 * @internal
 * @since 2.0.0
 */
final class CoreElement
{
	/** The namespace this module's own elements are declared in; a first test only. */
	private const NAMESPACE_PREFIX = 'Altioo\\iTop\\Extension\\MCP\\Core\\';

	/**
	 * @param object|string $mClass An element, or its class name.
	 *
	 * @return bool False for a class with no file (an internal class, one built with eval), one outside the namespace, or one defined anywhere but under src/Core/ of this module.
	 */
	public static function IsOwn(object|string $mClass): bool
	{
		$sClass = is_object($mClass) ? get_class($mClass) : ltrim($mClass, '\\');
		if (!str_starts_with($sClass, self::NAMESPACE_PREFIX) || !class_exists($sClass)) {
			return false;
		}

		$sFile = (new ReflectionClass($sClass))->getFileName();
		$sFile = $sFile === false ? false : realpath($sFile);
		$sCore = realpath(dirname(__DIR__).'/Core');
		if ($sFile === false || $sCore === false) {
			return false;
		}

		return str_starts_with($sFile, $sCore.DIRECTORY_SEPARATOR);
	}
}
