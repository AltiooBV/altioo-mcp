<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use JsonException;

/**
 * How this module turns a payload into JSON, in one place.
 *
 * Every surface encodes the same way or the surfaces disagree, and the
 * disagreement would not be cosmetic. Default json_encode() escapes slashes
 * and non-ASCII, so the same class label would come back as "équipement"
 * from itop://core/classes and as "équipement" from core_class_list - and on
 * malformed UTF-8, which a hand-edited datamodel or a legacy dictionary
 * produces, it returns false rather than raising anything, so a surface
 * calling it bare would hand a boolean to the SDK and the caller would get
 * "unhandled type: boolean" from inside the transport.
 *
 * The flags are the ones {@see ToolOutput} documents:
 * unescaped slashes and unicode because a model reads this and URLs and
 * accented text should survive as themselves; substitution rather than failure
 * on invalid UTF-8; and an exception rather than a false return, so a caller
 * that cannot be encoded is a refusal with a reason instead of a value nobody
 * checked.
 *
 * No pretty printing, on either surface. Indentation is tokens, and the SDK's
 * own formatters add it to anything handed back as an array - which is the
 * other half of why both surfaces return an encoded string rather than the
 * array itself.
 *
 * @api
 * @since 1.0.0
 */
final class JsonPayload
{
	/**
	 * @param mixed $data Anything json_encode can take.
	 *
	 * @throws JsonException When the value cannot be encoded at all. Callers
	 *                       translate that into the refusal their surface
	 *                       speaks - ToolCallException, ResourceReadException.
	 *
	 * @since 1.0.0
	 */
	public static function Encode($data): string
	{
		return json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
		);
	}
}
