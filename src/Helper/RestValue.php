<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

/**
 * Bridges the MCP SDK's decoding convention to the one RestUtils expects.
 *
 * The SDK decodes inbound JSON-RPC with `json_decode($input, true)`, so a
 * nested JSON object arrives as a PHP associative array. iTop's REST layer was
 * written against `json_decode($input)` and therefore branches on objects:
 *
 *   - {@see \RestUtils::FindObjectFromKey()} treats `is_object($key)` as search
 *     criteria and anything else as an id / OQL string;
 *   - {@see \AttributeCaseLog::FromJSONToValue()} tests `isset($json->add_item)`;
 *   - {@see \AttributeBlob::FromJSONToValue()} reads `$json->data`.
 *
 * Passing an array where those expect a `stdClass` does not fail loudly, it
 * silently takes the wrong branch. Re-encoding and decoding without the assoc
 * flag restores the distinction JSON already carried: objects become
 * `stdClass`, lists stay PHP arrays - which is what link sets
 * ({@see \AttributeLinkedSet}) and tag sets require.
 * @api
 * @since 1.0.0
 */
final class RestValue
{
	/**
	 * Normalises one attribute value coming from an MCP tool argument.
	 *
	 * Scalars and nulls are returned untouched, so a plain-string caselog
	 * append or an ext-key id keeps its exact type.
	 *
	 * @param mixed $value Value as decoded by the MCP SDK.
	 *
	 * @return mixed Value shaped the way {@see \RestUtils::MakeValue()} expects.
	 *
	 * @throws \JsonException When the value cannot round-trip through JSON
	 *                        (e.g. malformed UTF-8). Callers already wrap
	 *                        MakeValue() in a try/catch reporting the offending
	 *                        attribute, so this surfaces as a field error.
	 *
	 * @since 1.0.0
	 */
	public static function FromDecodedJson(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}

		$sJson = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return json_decode($sJson, false, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Same as {@see FromDecodedJson()} over a whole `fields` map, keys kept.
	 *
	 * @param array<string, mixed> $aFields
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \JsonException
	 * @since 1.0.0
	 */
	public static function FromDecodedJsonFields(array $aFields): array
	{
		$aOut = [];
		foreach ($aFields as $sAttCode => $value) {
			$aOut[$sAttCode] = self::FromDecodedJson($value);
		}

		return $aOut;
	}
}
