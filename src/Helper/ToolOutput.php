<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use JsonException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

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
 *
 * @since 1.0.0
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
	 * @since 1.0.0
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

	/**
	 * The return of a tool that declares an output schema.
	 *
	 * A declared outputSchema obliges the server to send structuredContent, and
	 * the SDK only builds that half from an array return - which is exactly the
	 * path {@see Json()} exists to avoid, because it also pretty-prints a second
	 * copy into the text content. This builds both halves directly: the
	 * structured one for a client that validates, the compact text one for the
	 * many that only read content.
	 *
	 * That does send the payload twice, which is why it is not the default. Use
	 * it where the result is a handful of scalars whose shape never varies - a
	 * write outcome, a dry-run plan - and where a second copy costs a few
	 * hundred bytes. A read that returns objects should keep to {@see Json()}
	 * and declare no output schema: the shape of an object depends on the class
	 * and on output_fields, so a schema could not describe it anyway, and the
	 * duplication would be measured in tokens per call.
	 *
	 * @param array<string, mixed> $aData The shape the tool's getOutputSchema() declares.
	 *
	 * @throws ToolCallException When the result cannot be encoded at all.
	 * @since 1.0.0
	 */
	public static function Structured(array $aData): CallToolResult
	{
		return new CallToolResult([self::Json($aData)], false, $aData);
	}

	/**
	 * The payload of a result, whichever of the three shapes it arrived in.
	 *
	 * For the pack that subclasses a core tool to add something to what it
	 * returns. Calling parent::execute() gets back whatever that tool chose -
	 * a TextContent from {@see Json()}, a CallToolResult from
	 * {@see Structured()}, or a plain array - and a subclass that wants to add
	 * a key has to get at the data underneath without caring which. Doing it by
	 * hand means an instanceof ladder in every such tool, and a json_decode
	 * whose failure mode is silently returning null.
	 *
	 * Re-encode the result you build with {@see Json()} or
	 * {@see Structured()}; this only opens the envelope.
	 *
	 * @param mixed $mResult What execute() returned.
	 *
	 * @return array<mixed> The decoded payload.
	 *
	 * @throws ToolCallException When it holds no JSON object that can be read back.
	 *
	 * @since 1.0.0
	 */
	public static function Decode($mResult): array
	{
		if (is_array($mResult)) {
			return $mResult;
		}

		if ($mResult instanceof CallToolResult) {
			// The structured half is the same data, already decoded.
			$aStructured = $mResult->structuredContent ?? null;
			if (is_array($aStructured)) {
				return $aStructured;
			}

			$aContent = $mResult->content ?? [];
			$mResult = $aContent[0] ?? null;
		}

		if (!$mResult instanceof TextContent) {
			throw new ToolCallException(sprintf(
				'This result carries no readable payload (got %s).',
				is_object($mResult) ? get_class($mResult) : gettype($mResult)
			));
		}

		try {
			$mDecoded = json_decode($mResult->text, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new ToolCallException('This result is not JSON and cannot be extended: '.$e->getMessage());
		}

		if (!is_array($mDecoded)) {
			throw new ToolCallException('This result is a JSON scalar rather than an object, so there is nothing to add to.');
		}

		return $mDecoded;
	}
}
