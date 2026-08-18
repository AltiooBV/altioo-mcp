<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeDate;
use AttributeDateTime;
use AttributeDefinition;
use AttributeEmailAddress;
use AttributeURL;
use MetaModel;
use UserRights;

/**
 * The datamodel, read the way the calling user is allowed to see it.
 *
 * The class list and the class detail are each served twice - once as a
 * resource, once as a tool - because clients differ in what they actually
 * fetch: many never read resources at all, and support for resource templates
 * is thinner still. A model that cannot discover the schema falls back to
 * guessing attribute codes, so the schema has to be reachable from the surface
 * every client does support.
 *
 * Two surfaces, one implementation: what core_class_schema reports and what
 * itop://core/class/{class} reports cannot drift apart, and the access checks
 * are written once rather than per surface.
 *
 * @since 1.0.0
 */
final class DatamodelReader
{
	/**
	 * Most values reported for one attribute. A datamodel enumeration never
	 * comes close; anything that does is a list the model should be searching,
	 * not reading.
	 */
	public const MAX_ALLOWED_VALUES = 100;

	/**
	 * PHP date() tokens this module can turn into a regular expression.
	 *
	 * Deliberately only the numeric ones: a format built from anything else is
	 * reported without a pattern rather than with a wrong one.
	 */
	private const DATE_FORMAT_TOKENS = [
		'Y' => '\d{4}',
		'y' => '\d{2}',
		'm' => '\d{2}',
		'n' => '\d{1,2}',
		'd' => '\d{2}',
		'j' => '\d{1,2}',
		'H' => '\d{2}',
		'G' => '\d{1,2}',
		'i' => '\d{2}',
		's' => '\d{2}',
	];

	/**
	 * ECMA-262 SyntaxCharacter, plus '/'. Escaping any of these is valid both
	 * there and in PCRE; escaping anything else is not.
	 */
	private const REGEX_SYNTAX_CHARACTERS = ['^', '$', '\\', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/'];

	/**
	 * Whether the class exists *and* the caller may read it.
	 *
	 * The two are deliberately answered together: callers report both as
	 * "unknown class", so that probing this endpoint cannot map out the classes
	 * a user is not allowed to see.
	 *
	 * @since 1.0.0
	 */
	public static function IsReadable(string $sClass): bool
	{
		return MetaModel::IsValidClass($sClass) && UserRights::IsActionAllowed($sClass, UR_ACTION_READ);
	}

	/**
	 * Categories declared by the datamodel, e.g. 'bizmodel' or 'searchable'.
	 *
	 * MetaModel keeps '' as the bucket holding every class; it is not a
	 * category anyone can ask for, so it is dropped here.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function Categories(): array
	{
		$aCategories = array_filter(MetaModel::EnumCategories(), static fn ($sCategory): bool => $sCategory !== '');
		sort($aCategories);

		return array_values($aCategories);
	}

	/**
	 * Every readable class, or those of one category, summarised and sorted by
	 * class name.
	 *
	 * @param string $sCategory A category from {@see Categories()}; '' for all classes.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function ListClasses(string $sCategory = ''): array
	{
		$aClasses = [];

		foreach (MetaModel::GetClasses($sCategory) as $sClass) {
			// Skip classes the current user has no read access to
			if (!UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				continue;
			}

			$aClasses[] = self::Summarize($sClass);
		}

		usort($aClasses, static fn ($a, $b) => strcmp($a['class'], $b['class']));

		return $aClasses;
	}

	/**
	 * Keeps the summaries whose name, label or description contains $sText.
	 *
	 * All three count, and case does not: a model looking for "ticket" should
	 * find UserRequest, whose class name says nothing about tickets.
	 *
	 * Pure - it touches no MetaModel - which is what lets it be tested without
	 * a live iTop.
	 *
	 * @param array<int, array<string, mixed>> $aClasses Summaries, as returned by {@see ListClasses()}.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function FilterByText(array $aClasses, string $sText): array
	{
		if ($sText === '') {
			return array_values($aClasses);
		}

		$aMatching = array_filter($aClasses, static function (array $aClass) use ($sText): bool {
			foreach (['class', 'label', 'description'] as $sField) {
				$sValue = $aClass[$sField] ?? '';
				if (is_string($sValue) && stripos($sValue, $sText) !== false) {
					return true;
				}
			}

			return false;
		});

		return array_values($aMatching);
	}

	/**
	 * The class list as both surfaces report it: the narrowing that was asked
	 * for, how many classes came back, and the classes themselves.
	 *
	 * The envelope is here rather than in the tool because the resource serves
	 * the same answer and used to serve a bare array instead - so a client
	 * reading itop://core/classes got no count, could not tell a complete list
	 * from a clipped one, and saw a different shape from the one
	 * core_class_list documents for the identical data.
	 *
	 * category and filter are echoed back even when empty, which is what makes
	 * the two surfaces the same shape: the resource takes no arguments, so its
	 * answer is this envelope with both narrowings unset rather than a
	 * different envelope.
	 *
	 * The category is validated by the caller, not here - only the caller
	 * knows whether an unknown one is a ToolCallException or a
	 * ResourceReadException. See {@see Categories()}.
	 *
	 * @param string $sCategory A category from {@see Categories()}; '' for all classes.
	 * @param string $sFilter   Case-insensitive text matched against name, label and description; '' for no filter.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function ClassListPayload(string $sCategory = '', string $sFilter = ''): array
	{
		$aClasses = self::FilterByText(self::ListClasses($sCategory), $sFilter);

		return [
			'category' => $sCategory,
			'filter'   => $sFilter,
			'total'    => count($aClasses),
			'classes'  => $aClasses,
		];
	}

	/**
	 * What a class is and where it sits in the hierarchy, without its contents.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Summarize(string $sClass): array
	{
		return [
			'class'          => $sClass,
			'label'          => MetaModel::GetName($sClass),
			'description'    => MetaModel::GetClassDescription($sClass),
			'isAbstract'     => MetaModel::IsAbstract($sClass),
			'isRoot'         => MetaModel::IsRootClass($sClass),
			'isHierarchical' => MetaModel::IsHierarchicalClass($sClass),
			'rootClass'      => MetaModel::GetRootClass($sClass),
			'parentClass'    => MetaModel::GetParentClass($sClass),
		];
	}

	/**
	 * One class in full: the summary, plus attributes, relations and lifecycle.
	 *
	 * Readability is the caller's to check - see {@see IsReadable()} - because
	 * only the caller knows which exception its surface has to raise.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Describe(string $sClass): array
	{
		return self::Summarize($sClass) + [
			'attributes' => self::attributes($sClass),
			'relations'  => self::relations($sClass),
			'lifecycle'  => self::lifecycle($sClass),
		];
	}

	/**
	 * The attributes of $sClass this caller may read.
	 *
	 * Filtered by the same right the object tools apply, for two reasons. The
	 * schema is what a model builds its next call from, so listing an attribute
	 * it may not read sends it to ask for something that comes back missing:
	 * ObjectSerializer skips unreadable attributes silently, and a model reads
	 * an absent field as an empty one rather than as a refusal. And a label, a
	 * description and an enumeration of allowed values say a good deal about a
	 * field even when none of its values are ever returned.
	 *
	 * UR_ALLOWED_DEPENDS keeps the attribute: it means the answer varies by
	 * object, and the object tools decide it per object. Only an outright
	 * refusal for the whole class removes it here.
	 *
	 * @param string $sClass The class for which to retrieve attribute details
	 * @return array An array of attribute details
	 */
	private static function attributes(string $sClass): array
	{
		$aAttributes = [];

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
				continue;
			}

			$aAttributes[$sAttCode] = [
				'label'         => MetaModel::GetLabel($sClass, $sAttCode),
				'description'   => $oAttDef->GetDescription(),
				'type'          => get_class($oAttDef),
				'format'        => self::format($oAttDef),
				'pattern'       => self::pattern($oAttDef),
				'required'      => $oAttDef->IsNullAllowed() === false,
				'readOnly'      => $oAttDef->IsWritable() === false,
				'nullable'      => $oAttDef->IsNullAllowed(),
				'isExternalKey' => $oAttDef->IsExternalKey(),
				'isScalar'      => $oAttDef->IsScalar(),
				'isSensible'    => ObjectSerializer::IsSensitive($oAttDef),
			] + self::allowedValues($oAttDef);
		}

		return $aAttributes;
	}

	/**
	 * What a client may put in an attribute, when that is a list worth sending.
	 *
	 * An enumeration is a handful of codes declared in the datamodel, and a
	 * model that cannot see them invents them - so those are reported in full.
	 *
	 * An external key is not. GetAllowedValues() on one runs a query over the
	 * whole target table and returns every row: on ticket.caller_id that is the
	 * entire Person table, in a payload the caller pays for on every schema
	 * read, describing objects the object-level rights of the calling user were
	 * never consulted about. The target class is reported instead, which is
	 * what a model needs to go and search it.
	 *
	 * Anything else that declares values is capped, because nothing in the
	 * datamodel promises a short list.
	 *
	 * @return array<string, mixed>
	 */
	private static function allowedValues(AttributeDefinition $oAttDef): array
	{
		if ($oAttDef->IsExternalKey()) {
			return [
				'values'      => null,
				'targetClass' => $oAttDef->GetTargetClass(),
				'valuesHint'  => 'Search the target class for the object you want, then pass its id.',
			];
		}

		$aValues = $oAttDef->GetAllowedValues();
		if (!is_array($aValues) || count($aValues) <= self::MAX_ALLOWED_VALUES) {
			return ['values' => $aValues];
		}

		return [
			'values'          => array_slice($aValues, 0, self::MAX_ALLOWED_VALUES, true),
			'valuesTruncated' => true,
			'valuesTotal'     => count($aValues),
		];
	}

	/**
	 * The JSON Schema `format` an attribute's values honour, or null.
	 *
	 * Only claimed where iTop's own syntax really is the one the format names.
	 * A format is a promise about the string on the wire: a wrong one has the
	 * model send a value iTop then refuses, which is worse than saying nothing.
	 */
	private static function format(AttributeDefinition $oAttDef): ?string
	{
		// Order matters: AttributeDate extends AttributeDateTime.
		if ($oAttDef instanceof AttributeDate) {
			return 'date'; // 'Y-m-d' is exactly RFC 3339 full-date.
		}

		if ($oAttDef instanceof AttributeDateTime) {
			// Deliberately not 'date-time'. iTop reads and writes
			// 'Y-m-d H:i:s' - a space, no timezone - and
			// AttributeDateTime::MakeRealValue() throws on anything else, so a
			// model told 'date-time' would send the RFC 3339 form and have
			// every create and update rejected. The pattern carries the real
			// syntax instead.
			return null;
		}

		if ($oAttDef instanceof AttributeEmailAddress) {
			return 'email';
		}

		if ($oAttDef instanceof AttributeURL) {
			return 'uri';
		}

		return null;
	}

	/**
	 * The JSON Schema `pattern` an attribute's values match, or null.
	 *
	 * Dates and date-times only, and read off the attribute rather than
	 * hardcoded: a datamodel that changes the internal format stays correctly
	 * described. Unlike `format`, a pattern is asserted by every validator, so
	 * it is the part that actually keeps a date-time honest.
	 */
	private static function pattern(AttributeDefinition $oAttDef): ?string
	{
		if (!$oAttDef instanceof AttributeDateTime) {
			return null;
		}

		return self::PatternFromDateFormat($oAttDef::GetInternalFormat());
	}

	/**
	 * Translates a PHP date() format into an anchored regular expression.
	 *
	 * Returns null rather than guessing: a format carrying a token this does
	 * not know, or a backslash escape, yields no pattern at all. Pure - it
	 * touches no MetaModel - which is what lets it be tested without iTop.
	 *
	 * @since 1.0.0
	 */
	public static function PatternFromDateFormat(string $sFormat): ?string
	{
		if ($sFormat === '' || str_contains($sFormat, '\\')) {
			return null;
		}

		$sPattern = '';
		foreach (str_split($sFormat) as $sChar) {
			if (isset(self::DATE_FORMAT_TOKENS[$sChar])) {
				$sPattern .= self::DATE_FORMAT_TOKENS[$sChar];

				continue;
			}

			if (ctype_alpha($sChar)) {
				return null;
			}

			// Not preg_quote(): it escapes '-' and ':' too, and "\-" is an
			// invalid identity escape in ECMA-262, which is the flavour JSON
			// Schema patterns are read as. Only the syntax characters, which
			// are escapable in both flavours, are escaped here.
			$sPattern .= in_array($sChar, self::REGEX_SYNTAX_CHARACTERS, true) ? '\\'.$sChar : $sChar;
		}

		return '^'.$sPattern.'$';
	}

	/**
	 * @param string $sClass The class for which to retrieve relation details
	 * @return array An array of relation details
	 */
	private static function relations(string $sClass): array
	{
		$aRelations = [];

		foreach (MetaModel::EnumRelations() as $sRelation) {
			$aRelated = [];
			foreach (MetaModel::EnumRelationQueries($sClass, $sRelation) as $sNeighbour => $aQueryInfo) {
				$aRelated[] = $sNeighbour;
			}
			if (!empty($aRelated)) {
				$aRelations[$sRelation] = $aRelated;
			}
		}

		return $aRelations;
	}

	/**
	 * @param string $sClass The class for which to retrieve lifecycle details
	 * @return array An array of lifecycle details
	 */
	private static function lifecycle(string $sClass): array
	{
		if (!MetaModel::HasLifecycle($sClass)) {
			return [];
		}
		$sStateAttCode = MetaModel::GetStateAttributeCode($sClass);
		if ($sStateAttCode === '') {
			return [];
		}

		$aStates = [];
		foreach (MetaModel::EnumStates($sClass) as $sState => $aStateDef) {
			$aTransitions = [];
			foreach (MetaModel::EnumTransitions($sClass, $sState) as $sStimulusCode => $aTransitionDef) {
				$aTransitions[$sStimulusCode] = $aTransitionDef['target_state'];
			}
			$aStates[$sState] = [
				'label'       => MetaModel::GetStateLabel($sClass, $sState),
				'transitions' => $aTransitions,
			];
		}

		return [
			'stateAttribute' => $sStateAttCode,
			'states'         => $aStates,
		];
	}
}
