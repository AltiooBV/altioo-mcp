<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Rights\ClassRights;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;

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
	/** The blocks {@see Describe()} can answer with, and the value asking for all of them. */
	public const BLOCK_RIGHTS = 'rights';
	public const BLOCK_ATTRIBUTES = 'attributes';
	public const BLOCK_DERIVED = 'derived';
	public const BLOCK_RELATIONS = 'relations';
	public const BLOCK_LIFECYCLE = 'lifecycle';

	public const BLOCKS = [
		self::BLOCK_RIGHTS,
		self::BLOCK_ATTRIBUTES,
		self::BLOCK_DERIVED,
		self::BLOCK_RELATIONS,
		self::BLOCK_LIFECYCLE,
	];

	public const BLOCKS_ALL = '*';

	/** The `attributes` value that narrows on none of them. */
	public const ATTRIBUTES_ALL = '*';

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
		return ExclusionPolicy::Serves($sClass) && UserRights::IsActionAllowed($sClass, UR_ACTION_READ);
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
	 * The attributes arrive in two blocks, and the line between them is the
	 * question a caller is usually asking. `attributes` holds the ones the
	 * datamodel lets anybody write, which is the answer to "what can I set".
	 * `derived` holds the ones nobody writes: computed or structural, and on a
	 * class with many external keys most of the payload - iTop gives every such
	 * key a `_friendlyname` companion and, where the target can go obsolete, an
	 * `_obsolescence_flag`, so a hundred-attribute class is largely mechanical
	 * and a reader scanning for a writable field scrolls past all of it.
	 *
	 * Split rather than dropped, and split on IsWritable() rather than on a
	 * list of attribute classes: the datamodel already answers this, the answer
	 * moves when iTop adds an attribute type, and a hardcoded list would go
	 * quietly wrong the first time it did. An entry has the same shape in both
	 * blocks - `readOnly` still on it, still saying which side it is on - so a
	 * caller that wants them together can merge the two and lose nothing.
	 *
	 * The two are separate keys rather than one key and a flag because the flag
	 * was already there and did not help: `readOnly` told a reader which
	 * attributes to ignore only after it had read all of them.
	 *
	 * Readability is the caller's to check - see {@see IsReadable()} - because
	 * only the caller knows which exception its surface has to raise.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Describe(string $sClass, string $sInclude = self::BLOCKS_ALL, string $sAttributes = self::ATTRIBUTES_ALL, bool $bRequiredOnly = false): array
	{
		$aBlocks = self::requestedBlocks($sInclude);
		$aAttributes = AttributeDescription::attributes($sClass);

		if ($bRequiredOnly) {
			// Required and without a default: the ones a create has to be given. The others are filled in by the write.
			$aAttributes = array_filter($aAttributes, static fn (array $a): bool => $a['required'] === true && !array_key_exists('default', $a));
		}

		if ($sAttributes !== self::ATTRIBUTES_ALL && $sAttributes !== '') {
			$aWanted = array_flip(array_map('trim', explode(',', $sAttributes)));
			$aAttributes = array_intersect_key($aAttributes, $aWanted);
		}

		$aPayload = self::Summarize($sClass) + ['obsolescence' => self::obsolescence($sClass)];

		if (in_array(self::BLOCK_RIGHTS, $aBlocks, true)) {
			$aPayload['rights'] = ClassRights::RightsOf($sClass);
		}

		if (in_array(self::BLOCK_ATTRIBUTES, $aBlocks, true)) {
			$aPayload['attributes'] = array_filter($aAttributes, static fn (array $a): bool => $a['readOnly'] === false);
		}

		// required_only is the "what must I set" question, and a derived
		// attribute is never an answer to it: nothing can set one. Left in, the
		// block dominated the very payload the argument exists to shrink - a
		// stock UserRequest answers with seven writable attributes and about
		// twenty-five derived ones - and each derived entry carried
		// required: true, which reads as a write obligation and is in fact the
		// database saying the column it computes is not nullable.
		if (in_array(self::BLOCK_DERIVED, $aBlocks, true) && !$bRequiredOnly) {
			$aPayload['derived'] = array_filter($aAttributes, static fn (array $a): bool => $a['readOnly'] === true);
		}

		if (in_array(self::BLOCK_RELATIONS, $aBlocks, true)) {
			// An object whether or not it is empty: an empty PHP array would be sent as [] where every other answer is {} (review 2026-11-23, S6).
			$aPayload['relations'] = self::relations($sClass) ?: new \stdClass();
		}

		if (in_array(self::BLOCK_LIFECYCLE, $aBlocks, true)) {
			$aPayload['lifecycle'] = self::lifecycle($sClass);
		}

		// The allowed values of a link set are the names of objects (a Team called "IMPORTANT: assistant, call ..."
		// reaches every schema that links to it), so they carry the marker the same names carry in a read (HA-11).
		$aNames = [];
		foreach (['attributes', 'derived'] as $sBlock) {
			foreach ($aPayload[$sBlock] ?? [] as $sAttCode => $aAttribute) {
				if (!empty($aAttribute['values']) && AttributeDescription::ListsObjectNames($sClass, (string)$sAttCode)) {
					$aNames[] = $sBlock.'.'.$sAttCode.'.values';
				}
			}
		}
		if ($aNames !== []) {
			$aPayload['untrusted_text'] = ['fields' => $aNames, 'note' => UntrustedMarker::UNTRUSTED_NOTICE];
		}

		// What was asked for, echoed, so that a narrowed answer is never read
		// as the whole class: "no relations" and "you did not ask for
		// relations" are different claims, and an absent block cannot tell
		// them apart on its own.
		$aPayload['reported'] = [
			'blocks'        => $aBlocks,
			'attributes'    => $sAttributes,
			'required_only' => $bRequiredOnly,
		];

		return $aPayload;
	}

	/**
	 * The blocks a caller asked for, or all of them.
	 *
	 * A stock UserRequest answers with about forty writable attributes, thirty
	 * derived ones, its relations and its whole lifecycle graph - and a model
	 * that called it to find out whether title is mandatory pays for all of it,
	 * before nearly every create and every stimulus. The narrowing the reading
	 * tools have had since the start belongs here for the same reason.
	 *
	 * An unknown name is ignored rather than refused: the blocks are this
	 * module's own vocabulary, a pack may one day add to it, and a typo that
	 * costs a block is cheaper to see in the echo than a call that costs a
	 * round trip.
	 *
	 * @return array<int, string>
	 */
	private static function requestedBlocks(string $sInclude): array
	{
		if ($sInclude === self::BLOCKS_ALL || $sInclude === '') {
			return self::BLOCKS;
		}

		$aAsked = array_map('trim', explode(',', $sInclude));
		$aBlocks = array_values(array_intersect(self::BLOCKS, $aAsked));

		// Nothing recognised means nothing was really asked for; answering with
		// an empty class is worse than answering with the whole one.
		return $aBlocks === [] ? self::BLOCKS : $aBlocks;
	}

	/**
	 * Why an object of this class can count as obsolete, in the datamodel's
	 * own words.
	 *
	 * obsolescence_flag says that one is; it never says why, and the why is
	 * not guessable from the class. The stock conditions are three different
	 * shapes: a state on the object (status = 'obsolete'), a state inherited
	 * through an external key (a database instance is obsolete because its
	 * server is), and a date that has passed (a contract whose end date is
	 * fifteen months old). A caller told only "true" cannot tell which of
	 * those it is looking at, and a caller that wants to make an object
	 * obsolete - or stop it being one - has nothing to act on.
	 *
	 * Rendered from the expression iTop evaluates rather than from the XML, so
	 * it is what the database actually applies, COALESCE and all.
	 *
	 * @return array<string, mixed>
	 */
	private static function obsolescence(string $sClass): array
	{
		try {
			if (!MetaModel::IsObsoletable($sClass)) {
				return ['obsoletable' => false, 'condition' => null];
			}

			return [
				'obsoletable' => true,
				'condition'   => self::ObsolescenceCondition($sClass),
			];
		} catch (\Throwable $e) {
			// A class that cannot answer is reported as one that does not have
			// the notion, which is what the flag will say about its objects too.
			return ['obsoletable' => false, 'condition' => null];
		}
	}

	/**
	 * The condition that makes an object of the class obsolete, as OQL, or null when it cannot be said as OQL.
	 *
	 * iTop keeps it as an OQL expression and wraps it in `COALESCE(<condition>, 0)` to evaluate it, and renders it with
	 * backticks around every field: that is what a read said, and it looks like the SQL it is not (review 2026-11-23, C12).
	 * This is the condition itself, unwrapped, with the backticks that quote a field name taken off (never those inside
	 * a quoted value), and it is given only if iTop parses it back as OQL: otherwise the answer is null, never a string
	 * that is neither.
	 *
	 * @since 2.2.0
	 */
	public static function ObsolescenceCondition(string $sClass): ?string
	{
		try {
			$oExpression = MetaModel::GetObsolescenceExpression($sClass);
			$aArgs = $oExpression instanceof \FunctionOqlExpression && strtoupper((string)$oExpression->GetVerb()) === 'COALESCE' ? $oExpression->GetArgs() : [];
			if (!isset($aArgs[0]) || !is_object($aArgs[0])) {
				return null;
			}

			// Every attribute the condition reads must be one this caller is served (doc/adr/0012): an excluded attribute, a key
			// whose target is excluded, an external field through one, a name made of one. Any that is not, or that cannot be
			// resolved, and the condition is not said - it would name what the rest of the channel does not. An account
			// holding an exempt profile is not excluded from anything (ADR 0013), so it still reads it.
			foreach ($aArgs[0]->ListRequiredFields() as $sField) {
				$sCode = (string)$sField;
				$sCode = str_contains($sCode, '.') ? substr($sCode, strpos($sCode, '.') + 1) : $sCode;
				if (!MetaModel::IsValidAttCode($sClass, $sCode) || ExclusionPolicy::ExcludesAttribute($sClass, $sCode)) {
					return null;
				}
			}

			$sRendered = trim((string)$aArgs[0]->Render());
			if ($sRendered === '') {
				return null;
			}

			// Outside the quoted values only: a value may hold a backtick.
			$aParts = preg_split("/('(?:[^'\\\\]|\\\\.)*')/", $sRendered, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sRendered];
			$sPlain = '';
			foreach ($aParts as $i => $sPart) {
				$sPlain .= $i % 2 === 1 ? $sPart : str_replace('`', '', $sPart);
			}

			foreach ([$sPlain, $sRendered] as $sCandidate) {
				try {
					\Expression::FromOQL($sCandidate);

					return $sCandidate;
				} catch (\Throwable $e) {
					continue;
				}
			}
		} catch (\Throwable $e) {
			ErrorReport::Log('Could not read the obsolescence condition of '.$sClass.': '.get_class($e));
		}

		return null;
	}

	/**
	 * @param string $sClass The class for which to retrieve relation details
	 * @return array An array of relation details
	 */
	private static function relations(string $sClass): array
	{
		$aRelations = [];

		// EnumRelationsEx() takes the class and exists on iTop 3.2 and 3.3; EnumRelations() went with 3.3. It
		// names the relations that have a query for this class in either direction, and the answer here is the
		// downstream ones, as it always was: a relation with only an upstream query lists nothing, and is left out.
		/** @var array<string, mixed> $aOfClass Relation code => the directions it has a query for. */
		$aOfClass = MetaModel::EnumRelationsEx($sClass);
		foreach (array_keys($aOfClass) as $sRelation) {
			$aRelated = [];
			// The queries that lead to an excluded class are not listed (doc/adr/0012); their names are no class names.
			foreach (array_keys(ExclusionSearches::RelationQueries($sClass, $sRelation)) as $sNeighbour) {
				$aRelated[] = $sNeighbour;
			}
			if (!empty($aRelated)) {
				$aRelations[$sRelation] = $aRelated;
			}
		}

		return $aRelations;
	}

	/**
	 * What a state asks of its attributes, so that a model reads what a transition needs before it is refused.
	 *
	 * "mandatory" must hold a value in the state, "prompted" is what the transition into it asks for again
	 * (must change or must prompt) and "readOnly" cannot be edited while the object is there. A list is left out
	 * when empty, and an attribute the caller may not read or the operator excluded is not named (doc/adr/0012),
	 * as everywhere else in the schema (review 2026-11-23, C3).
	 *
	 * @param array<string, int> $aFlags The state's attribute list, attribute code => OPT_ATT_* flags.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function stateFlags(string $sClass, array $aFlags): array
	{
		$aMasks = [
			'mandatory' => OPT_ATT_MANDATORY,
			'prompted'  => OPT_ATT_MUSTCHANGE | OPT_ATT_MUSTPROMPT,
			'readOnly'  => OPT_ATT_READONLY,
		];

		$aOut = [];
		foreach ($aFlags as $sAttCode => $iFlags) {
			if (!MetaModel::IsValidAttCode($sClass, (string)$sAttCode)
				|| ExclusionPolicy::ExcludesAttribute($sClass, (string)$sAttCode)
				|| UserRights::IsActionAllowedOnAttribute($sClass, (string)$sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
				continue;
			}
			foreach ($aMasks as $sKey => $iMask) {
				if (((int)$iFlags & $iMask) !== 0) {
					$aOut[$sKey][] = (string)$sAttCode;
				}
			}
		}

		return $aOut;
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
		if ($sStateAttCode === '' || ExclusionPolicy::ExcludesAttribute($sClass, $sStateAttCode)) {
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
			] + self::stateFlags($sClass, $aStateDef['attribute_list'] ?? []);
		}

		return [
			'stateAttribute' => $sStateAttCode,
			'states'         => $aStates,
		];
	}
}
