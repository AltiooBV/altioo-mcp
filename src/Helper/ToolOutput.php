<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use JsonException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;

/**
 * What a tool hands back, in one representation rather than two.
 *
 * Returning a plain array from execute() looks like the obvious thing, and the
 * SDK then does something expensive with it: ToolResultFormatter JSON-encodes
 * it with JSON_PRETTY_PRINT into a TextContent, and ToolReference copies the
 * same array into structuredContent. The response carries the whole result
 * twice, once indented. A client that forwards both - and it has no reason not
 * to, since no core tool declares an output schema for the structured half to
 * be validated against - spends roughly twice the tokens on every read.
 *
 * Returning a TextContent takes both branches away: the formatter passes
 * Content through untouched, and structured content is only extracted from
 * arrays and plain objects. The result is compact JSON, which is what the
 * model reads anyway.
 *
 * A pack may keep returning arrays; nothing breaks. This is the cheaper way,
 * and what every core tool does.
 */
final class ToolOutput
{
	/**
	 * Compact, no pretty printing: indentation is tokens, and nothing reads
	 * this but a model.
	 *
	 * Slashes and unicode are left unescaped so that URLs and accented text
	 * survive as themselves rather than as escape sequences - shorter, and
	 * easier for a model to quote back.
	 *
	 * @param mixed $data Anything json_encode can take: the shape the tool documents.
	 *
	 * @throws ToolCallException When the result cannot be encoded at all.
	 */
	public static function Json($data): TextContent
	{
		try {
			$sJson = json_encode(
				$data,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
			);
		} catch (JsonException $e) {
			// The caller gets a tool error it can act on rather than a
			// protocol error that ends the call.
			throw new ToolCallException('The result of this call could not be encoded: '.$e->getMessage());
		}

		return new TextContent($sJson);
	}
}
