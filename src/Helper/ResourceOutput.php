<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use JsonException;
use Mcp\Exception\ResourceReadException;

/**
 * What a resource or a resource template hands back, encoded the way a tool
 * encodes it.
 *
 * The twin of {@see ToolOutput::Json()}, and deliberately a twin rather than a
 * second implementation: both call {@see JsonPayload::Encode()}, so the two
 * surfaces cannot drift apart in what they emit. All that differs is the
 * refusal each speaks - a tool raises ToolCallException, a resource raises
 * ResourceReadException - which the SDK turns into the error shape its own
 * surface defines.
 *
 * Returns a string rather than an array on purpose. The SDK's
 * ResourceResultFormatter accepts either, but it JSON-encodes an array with
 * JSON_PRETTY_PRINT: handing back the array would indent every response, which
 * is the same duplication-and-whitespace cost {@see ToolOutput} exists to
 * avoid. A string is passed through as the resource's own content, under the
 * mime type the element declares.
 *
 * @api
 * @since 1.0.0
 */
final class ResourceOutput
{
	/**
	 * @param mixed $data The shape the resource documents in its description.
	 *
	 * @throws ResourceReadException When the result cannot be encoded at all.
	 *
	 * @since 1.0.0
	 */
	public static function Json($data): string
	{
		try {
			return JsonPayload::Encode($data);
		} catch (JsonException $e) {
			// A read that cannot be encoded is a read that failed, and saying
			// so is the whole point: returning json_encode()'s false instead
			// reached the SDK as a boolean and came back to the caller as
			// "unhandled type", with nothing naming the resource.
			throw new ResourceReadException('The content of this resource could not be encoded: '.$e->getMessage());
		}
	}
}
