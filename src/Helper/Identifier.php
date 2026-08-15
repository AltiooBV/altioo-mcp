<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * How a PHP class name becomes the identifier a client sees.
 *
 * MCP servers name their tools in snake_case - search_issues,
 * get_file_contents, create_pull_request - near enough universally that a
 * model has seen thousands of them and none in any other shape. It costs
 * nothing to match, and a surface that does not match is a surface a model
 * has to be told about.
 *
 * Deriving it from the class name rather than asking for it keeps one name in
 * one place: a pack writes a PHP class, and the identifier follows.
 */
final class Identifier
{
	/**
	 * ObjectSearchByOQL becomes object_search_by_oql.
	 *
	 * The split is on the boundary between a lower-case character and an
	 * upper-case one, and on the last capital of a run followed by lower case.
	 * The second half is what keeps an acronym together: without it OQL comes
	 * out as o_q_l, and ClassSchema is fine either way, so the naive version
	 * looks correct until the first acronym.
	 */
	public static function SnakeCase(string $sName): string
	{
		$sSnake = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $sName);

		return strtolower($sSnake ?? $sName);
	}
}
