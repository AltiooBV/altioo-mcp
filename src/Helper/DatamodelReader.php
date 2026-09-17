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
 * @api
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
	 * The keys a rights block carries, in the order rights() writes them.
	 *
	 * Written here rather than derived from rights(), which cannot be called
	 * without iTop - and held level with it by SchemaToolsContractTest, which
	 * reads that method's source. A key that exists in the block and not here
	 * is one a caller cannot narrow on; one here and not in the block is a
	 * filter that silently matches nothing.
	 */
	private const RIGHTS_KEYS = [
		'read',
		'bulkRead',
		'create',
		'bulkCreate',
		'modify',
		'bulkModify',
		'delete',
		'bulkDelete',
	];

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
	 * the same answer, and both surfaces have to carry the count and the
	 * echoed narrowings. A bare array on either side leaves a client reading
	 * itop://core/classes with no count, unable to tell a complete list from a
	 * clipped one, and looking at a different shape from the one
	 * core_class_list documents for identical data.
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
	public static function ClassListPayload(string $sCategory = '', string $sFilter = '', string $sMay = ''): array
	{
		$aClasses = self::FilterByText(self::ListClasses($sCategory), $sFilter);

		// Rights cost a gate call per class, so they are read after the cheap
		// narrowing has run and only when they were asked for. A list with no
		// $sMay carries no rights block, which is what keeps itop://core/classes
		// the same answer it has always been.
		if ($sMay !== '') {
			$aClasses = self::FilterByRight(self::WithRights($aClasses), $sMay);
		}

		return [
			'category' => $sCategory,
			'filter'   => $sFilter,
			'may'      => $sMay,
			'total'    => count($aClasses),
			'classes'  => $aClasses,
		];
	}

	/**
	 * The gates {@see ClassListPayload()} can narrow on, which are the keys a
	 * rights block carries.
	 *
	 * Public because the tool builds its enum and its refusal message from
	 * this: a second list written out in the schema would be a second thing to
	 * keep level with rights(), and the one a client validates against.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function RightsKeys(): array
	{
		return self::RIGHTS_KEYS;
	}

	/**
	 * Every summary with the caller's rights on that class attached.
	 *
	 * Impure, and separated from the filtering for the reason FilterByText is
	 * separate from ListClasses: what needs UserRights is one step, and the
	 * decision made from its answer is a pure one that a unit suite can hold.
	 *
	 * @param array<int, array<string, mixed>> $aClasses Summaries, as returned by {@see ListClasses()}.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function WithRights(array $aClasses): array
	{
		return array_values(array_map(
			static fn (array $aClass): array => $aClass + ['rights' => self::rights($aClass['class'])],
			$aClasses
		));
	}

	/**
	 * Keeps the classes whose $sRight gate is not a refusal.
	 *
	 * 'depends' survives on purpose. It is the addon asking for the object
	 * before it answers, so a class graded that way is one the caller may well
	 * be able to act on - dropping it hides work that can be done, and
	 * reporting it as 'yes' claims an answer nobody gave. It comes back with
	 * its own word, as it does everywhere else here.
	 *
	 * Only a known refusal removes anything: a summary carrying no rights block
	 * is kept rather than dropped, because nothing about it says the caller was
	 * refused.
	 *
	 * Pure - it touches no MetaModel - which is what lets it be tested without
	 * a live iTop.
	 *
	 * @param array<int, array<string, mixed>> $aClasses Summaries carrying a rights block, as returned by {@see WithRights()}.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function FilterByRight(array $aClasses, string $sRight): array
	{
		if ($sRight === '') {
			return array_values($aClasses);
		}

		$aAllowed = array_filter(
			$aClasses,
			static fn (array $aClass): bool => ($aClass['rights'][$sRight] ?? null) !== 'no'
		);

		return array_values($aAllowed);
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
	 * One class in full: the summary, the caller's rights on it, plus
	 * attributes, relations and lifecycle.
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
			'rights'     => self::rights($sClass),
			'attributes' => self::attributes($sClass),
			'relations'  => self::relations($sClass),
			'lifecycle'  => self::lifecycle($sClass),
		];
	}

	/**
	 * What this caller may do to objects of $sClass, as the class-level gate
	 * answers it.
	 *
	 * Every write tool asks this same gate before it looks at any object -
	 * ObjectUpdate and ObjectCreate refuse on it, and the bulk tools refuse on
	 * the UR_ACTION_BULK_* half - so reporting it up front is what lets a model
	 * pick a call that can succeed instead of discovering the refusal by making
	 * it. iTop's console answers the same question by rendering a button or
	 * not; a client with no buttons has only this.
	 *
	 * Read as a gate, never as an outcome. 'yes' means the call gets past the
	 * class check and no further: the object can still refuse it through a
	 * silo, a lifecycle state or the datamodel's own DoCheckToWrite(), and an
	 * abstract class or a read-only database refuses whatever the rights say -
	 * isAbstract sits in the same payload for the first of those. 'no' is the
	 * firmer half, and the useful one: the tools raise on it before an object
	 * is ever fetched, so no object exists that could get past it.
	 *
	 * bulkCreate is the one key here iTop does not answer: there is no
	 * UR_ACTION_BULK_CREATE, so ObjectBulkCreate gates on UR_ACTION_CREATE and
	 * UR_ACTION_BULK_MODIFY together, and this reports the stricter of the two.
	 * It is derived rather than read because the alternative is a model working
	 * the conjunction out for itself, and nothing in 'create' or 'bulkModify'
	 * says they are the pair that decides it - the other two bulk tools are
	 * guessable from their names and this one is not, so a model looking for
	 * permission to create in bulk finds no key for it and concludes the call
	 * is ungated, or absent.
	 *
	 * @return array<string, string>
	 */
	private static function rights(string $sClass): array
	{
		$sCreate = self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_CREATE));
		$sBulkModify = self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_MODIFY));

		return [
			'read'       => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_READ)),
			'bulkRead'   => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_READ)),
			'create'     => $sCreate,
			'bulkCreate' => self::stricter($sCreate, $sBulkModify),
			'modify'     => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY)),
			'bulkModify' => $sBulkModify,
			'delete'     => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_DELETE)),
			'bulkDelete' => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_DELETE)),
		];
	}

	/**
	 * The stricter of two grades, which is how a tool gated on both answers.
	 *
	 * 'no' wins over everything, because either refusal ends the call on its
	 * own. 'depends' wins over 'yes' for the same reason one step later: a
	 * conjunction is only settled when both halves are, and one half still
	 * asking for the object leaves the pair asking for it.
	 */
	private static function stricter(string $sLeft, string $sRight): string
	{
		if ($sLeft === 'no' || $sRight === 'no') {
			return 'no';
		}
		if ($sLeft === 'depends' || $sRight === 'depends') {
			return 'depends';
		}

		return 'yes';
	}

	/**
	 * One tri-state rights answer, as a word a model can act on.
	 *
	 * DEPENDS keeps its own word rather than being folded into either
	 * neighbour. It is the addon saying "ask again, with the object in hand",
	 * and a model told 'yes' or 'no' instead has been told something nobody
	 * answered - the same mistake, one surface further out, that reading these
	 * as booleans makes in the tools.
	 *
	 * The parameter carries no type, on purpose. UserRights answers with the
	 * UR_ALLOWED_* constants, but the return type is not declared on the iTop
	 * side, and a bool arriving at an `int` hint under strict_types is a
	 * TypeError rather than a wrong answer. The cast reads both: true and false
	 * land on UR_ALLOWED_YES and UR_ALLOWED_NO, which are 1 and 0.
	 *
	 * @param mixed $mAllowed A UR_ALLOWED_* answer, as iTop returned it.
	 */
	private static function grade($mAllowed): string
	{
		return match ((int)$mAllowed) {
			UR_ALLOWED_NO => 'no',
			UR_ALLOWED_YES => 'yes',
			default => 'depends',
		};
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
	 * readOnly and modify are two keys on purpose. readOnly is the datamodel's
	 * answer - the attribute is computed or structural, nobody writes it, and
	 * no administrator can grant it. modify is this caller's answer, and an
	 * administrator can change it. Collapsed into one key a model can no longer
	 * tell "nobody may write this" from "you may not", and reports the wrong
	 * one of the two to the user - the second is worth raising with whoever
	 * grants the rights, the first never is.
	 *
	 * Unlike the class-level gate, this one is close to final under a stock
	 * install: iTop's shipped addon documents that it ignores the instance set
	 * for attributes, so there is no per-object answer waiting behind it. A
	 * datamodel that does grade per object says so with 'depends'.
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

			// The write right, which is the one a model gets wrong: it reads a
			// schema, picks an attribute off it and is refused at write time.
			// UR_ACTION_MODIFY is the right for both paths - ObjectCreate gates
			// a field it is about to set on the same action ObjectUpdate does -
			// so one answer covers creating and updating alike.
			$iModify = UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY);

			$aAttributes[$sAttCode] = [
				'label'         => MetaModel::GetLabel($sClass, $sAttCode),
				'description'   => $oAttDef->GetDescription(),
				'type'          => get_class($oAttDef),
				'format'        => self::format($oAttDef),
				'pattern'       => self::pattern($oAttDef),
				'required'      => $oAttDef->IsNullAllowed() === false,
				'readOnly'      => $oAttDef->IsWritable() === false,
				'modify'        => self::grade($iModify),
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
	 * hardcoded - which follows an iTop upgrade rather than a datamodel: the
	 * internal format is a literal on the attribute class, not a setting, and
	 * the configurable one is GetFormat(), the display format, which is not
	 * what crosses this wire. Unlike `format`, a pattern is asserted by every
	 * validator, so it is the part that actually keeps a date-time honest.
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
