<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Mcp\Exception\ToolCallException;
use MetaModel;

/**
 * The `output_fields` argument: its spelling in a tool's schema, its parsing into the attribute
 * list a read reports, and the refusal for a code the class does not have.
 *
 * An attribute the operator excludes is refused as unknown, the same wording as one that does not
 * exist (ADR 0012). It was the argument half of `ObjectSerializer` in 1.x.
 *
 * @api
 * @since 2.0.0
 */
final class OutputFields
{
	/** What a caller asks for to get every readable attribute. */
	public const ALL_FIELDS = '*';

	/**
	 * Turns an output_fields argument into the list Serialize() takes.
	 *
	 * Returning every attribute of every object is what makes a search
	 * unusable: fifty tickets with forty attributes each, most of them
	 * irrelevant to the question asked, is a payload no context window
	 * survives - and the caller pays for all of it. iTop's REST API answers
	 * this with output_fields, defaulting to id and friendlyname, and the
	 * spelling is deliberately the same one here.
	 *
	 * An unknown attribute code is refused rather than ignored: a model that
	 * asked for "assignee" on a class that calls it agent_id has to be told,
	 * or it reads the absence as "this ticket has no assignee".
	 *
	 * @return array<int, string>|null The attribute codes to report, or null for all of them.
	 *
	 * @throws ToolCallException When a code is not an attribute of the class.
	 * @since 1.0.0
	 */
	public static function ParseFieldList(string $sClass, string $sOutputFields): ?array
	{
		$sOutputFields = trim($sOutputFields);
		if ($sOutputFields === '' || $sOutputFields === self::ALL_FIELDS) {
			return null;
		}

		$aFields = [];
		$aUnknown = [];

		foreach (explode(',', $sOutputFields) as $sAttCode) {
			$sAttCode = trim($sAttCode);
			if ($sAttCode === '') {
				continue;
			}
			if ($sAttCode === 'id' || $sAttCode === 'class') {
				// Always reported anyway, and not attributes of the class.
				continue;
			}
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode) || ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode)) {
				$aUnknown[] = $sAttCode;
				continue;
			}

			$aFields[] = $sAttCode;
		}

		if (!empty($aUnknown)) {
			throw new ToolCallException(self::UnknownAttributes($aUnknown, $sClass));
		}

		return $aFields;
	}

	/**
	 * The refusal for attribute codes a class does not have - or has, and the
	 * portal's view form hides. One wording for both, so a portal user cannot
	 * tell which real attributes the form withholds from the text.
	 *
	 * @param array<int, string> $aCodes
	 *
	 * @since 1.2.0
	 */
	public static function UnknownAttributes(array $aCodes, string $sClass): string
	{
		$aShown = array_map(
			static fn (string $sCode): string => ClassRefusal::LooksLikeClassName($sCode) ? "'{$sCode}'" : 'a code that is not an attribute code',
			array_values($aCodes)
		);

		return sprintf(
			"Unknown attribute%s %s on class '%s'. Call core_class_schema for the attribute codes of the class.",
			count($aCodes) > 1 ? 's' : '',
			implode(', ', $aShown),
			$sClass
		);
	}

	/**
	 * The input schema property both the search tools and ObjectGet declare,
	 * so that one argument does not end up with two spellings.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function FieldsSchemaProperty(string $sDefault): array
	{
		return [
			'type'        => 'string',
			'description' => 'Comma-separated attribute codes to return, e.g. "title, status, agent_id". "'.self::ALL_FIELDS.'" returns every readable attribute, which is large - ask for what you need. Call core_class_schema for the codes. Always returned whatever you ask for: the id, and whether the object is archived or obsolete - a row described by the fields you named would otherwise look the same whether it is live or soft-deleted.',
			'default'     => $sDefault,
		];
	}
}
