<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use AttributeCaseLog;
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
	/**
	 * The gates whose answer can differ from one object to the next.
	 *
	 * Reading is settled by the time an object is in a result, and creating
	 * is asked of a class rather than of an object that does not exist yet.
	 * What is left is the four writes, which a silo or an addon grading per
	 * object answers one object at a time.
	 */
	public const OBJECT_RIGHTS_KEYS = ['modify', 'bulkModify', 'delete', 'bulkDelete'];

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
	 * The `may` value that reports every gate and narrows on none.
	 *
	 * A rights block always carries all eight grades, so one call already
	 * answers "what may I create, modify and delete here" - what it could not
	 * do was answer it for the classes one of those gates refuses, because
	 * asking for rights and narrowing on them were the same request. This
	 * separates them. Spelled '*' rather than named after a gate, so nothing
	 * reads it as a ninth one.
	 *
	 * @since 1.0.0
	 */
	public const RIGHTS_ALL = '*';

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
		// Trimmed and deduplicated, which iTop's own registration is not.
		//
		// A class declares its categories as one string - "core/cmdb,
		// grant_by_profile" - and MetaModel explodes it on the comma without
		// trimming, so a declaration written with a space after the comma
		// registers the key " grant_by_profile", and one written without it
		// registers "grant_by_profile". Both are in EnumCategories(), and
		// printed in a list they look like the same word twice.
		$aCategories = array_map('trim', MetaModel::EnumCategories());
		$aCategories = array_filter($aCategories, static fn (string $sCategory): bool => $sCategory !== '');
		$aCategories = array_unique($aCategories);
		sort($aCategories);

		return array_values($aCategories);
	}

	/**
	 * Every class in a category, including the ones iTop's own lookup misses.
	 *
	 * The same spacing quirk costs more than a duplicate in a list.
	 * Registration keys the bucket untrimmed and GetClasses() trims what it is
	 * asked for, so "grant_by_profile" finds the classes that declared it
	 * without a space and silently misses every class that declared it with
	 * one - which in iTop's own core is most of them.
	 *
	 * HasCategory() reaches them: it matches against the class's raw
	 * declaration, spaces and all. What it does not do is match on a word
	 * boundary, so it is only asked where that cannot mislead - where the
	 * requested category is not part of a longer one. Elsewhere the exact
	 * lookup stands alone, which is what this module did everywhere until now.
	 *
	 * @return array<int, string>
	 */
	private static function classesInCategory(string $sCategory): array
	{
		$aClasses = MetaModel::GetClasses($sCategory);

		foreach (self::Categories() as $sKnown) {
			if ($sKnown !== $sCategory && str_contains($sKnown, $sCategory)) {
				// "core" would drag in "core/cmdb". The exact answer is the
				// safe one.
				return $aClasses;
			}
		}

		foreach (MetaModel::GetClasses('') as $sClass) {
			if (!in_array($sClass, $aClasses, true) && MetaModel::HasCategory($sClass, $sCategory)) {
				$aClasses[] = $sClass;
			}
		}

		return $aClasses;
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

		foreach (self::classesInCategory($sCategory) as $sClass) {
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
	 * Everything `may` accepts: the gates, plus {@see RIGHTS_ALL}.
	 *
	 * Separate from {@see RightsKeys()} because the two are asked different
	 * questions. RightsKeys() is the keys a rights block carries, which is what
	 * FilterByRight() indexes into; this is what a caller may send, and '*' is
	 * a request rather than a key.
	 *
	 * @return array<int, string>
	 * @since 1.0.0
	 */
	public static function MayValues(): array
	{
		return array_merge([self::RIGHTS_ALL], self::RIGHTS_KEYS);
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
			static fn (array $aClass): array => $aClass + ['rights' => self::RightsOf($aClass['class'])],
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
		if ($sRight === '' || $sRight === self::RIGHTS_ALL) {
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
		$aAttributes = self::attributes($sClass);

		if ($bRequiredOnly) {
			$aAttributes = array_filter($aAttributes, static fn (array $a): bool => $a['required'] === true);
		}

		if ($sAttributes !== self::ATTRIBUTES_ALL && $sAttributes !== '') {
			$aWanted = array_flip(array_map('trim', explode(',', $sAttributes)));
			$aAttributes = array_intersect_key($aAttributes, $aWanted);
		}

		$aPayload = self::Summarize($sClass) + ['obsolescence' => self::obsolescence($sClass)];

		if (in_array(self::BLOCK_RIGHTS, $aBlocks, true)) {
			$aPayload['rights'] = self::RightsOf($sClass);
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
			$aPayload['relations'] = self::relations($sClass);
		}

		if (in_array(self::BLOCK_LIFECYCLE, $aBlocks, true)) {
			$aPayload['lifecycle'] = self::lifecycle($sClass);
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
	 * @since 1.0.0
	 */
	public static function RightsOf(string $sClass): array
	{
		$sCreate = self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_CREATE));
		$sBulkModify = self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_MODIFY));

		$aRights = [
			'read'       => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_READ)),
			'bulkRead'   => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_READ)),
			'create'     => $sCreate,
			'bulkCreate' => self::stricter($sCreate, $sBulkModify),
			'modify'     => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY)),
			'bulkModify' => $sBulkModify,
			'delete'     => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_DELETE)),
			'bulkDelete' => self::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_DELETE)),
		];

		return self::narrowedByTheBarrier($aRights, $sClass);
	}

	/**
	 * The gates as this endpoint answers them, not only as UserRights does.
	 *
	 * The block exists so that a model can pick a call that will succeed
	 * instead of discovering the refusal by making it - and on the classes
	 * that decide what this endpoint may do, it was doing the opposite. iTop
	 * says an administrator may modify UserToken, so the block said
	 * modify: "yes", and the write was then refused by a barrier the block
	 * never mentioned. A caller could learn that its own escalation was
	 * blocked only by attempting one.
	 *
	 * So the barrier is asked here as well. It has three answers and the block
	 * already has three grades to say them with:
	 *
	 * - refused outright - mcp_allow_access_administration is off, and no
	 *   object of the class can be written whatever iTop says: "no", which is
	 *   documented as final and is exactly that.
	 * - allowed for other people's access only - the setting is on, and
	 *   whether this particular row reaches the caller's own credential is a
	 *   question about the row: "depends", which is what that grade means.
	 * - not a granting class at all: untouched.
	 *
	 * `restricted` says which of those it is in words, because "no" alone
	 * sends a caller to ask an administrator for a right that no profile can
	 * grant - the fix is a module setting or the console, and naming it is the
	 * difference between a refusal and a dead end.
	 *
	 * Reads are never narrowed: listing a token to see when it expires is
	 * useful and discloses nothing, which is why the barrier does not touch
	 * them either.
	 *
	 * @param array<string, string> $aRights
	 *
	 * @return array<string, mixed>
	 */
	private static function narrowedByTheBarrier(array $aRights, string $sClass): array
	{
		$aGates = ['create', 'bulkCreate', 'modify', 'bulkModify', 'delete', 'bulkDelete'];

		try {
			// The change log first, and it is the one refusal that takes the
			// reads down with it. Every tool on this endpoint refuses these
			// classes outright - reads included, since core_object_history is
			// the way in - and the block said 'yes' with restricted: null,
			// which is the shape of a class nothing guards. A reviewer reading
			// that concludes the audit log is writable; a model reading it
			// makes the call and learns otherwise. Neither is the block's job.
			if (ObjectHistory::IsReserved($sClass)) {
				foreach (array_keys($aRights) as $sGate) {
					if ($sGate !== 'restricted') {
						$aRights[$sGate] = 'no';
					}
				}

				return $aRights + ['restricted' => sprintf(ObjectHistory::RESERVED_REFUSAL, $sClass)];
			}

			if (!AccessGrants::IsBarred($sClass)) {
				return $aRights + ['restricted' => null];
			}

			$bGranting              = AccessGrants::IsGranting($sClass);
			$bDelegating            = AccessGrants::IsDelegating($sClass);
			$bAutomation            = AccessGrants::IsAutomation($sClass);
			$bPersonal              = AccessGrants::IsPersonal($sClass);
			$bAdministrationAllowed = MCPHelper::AllowsAccessAdministration();
			$bAutomationAllowed     = MCPHelper::AllowsAutomationAdministration();
		} catch (\Throwable $e) {
			// A question about the barrier must not cost the block. Reporting
			// iTop's own answer is what this did before the barrier existed.
			return $aRights + ['restricted' => null];
		}

		if ($bPersonal && !$bGranting) {
			// 'depends' on every write: whether the row is the caller's own is
			// a question about the row, which is exactly what the grade says.
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = self::stricter($aRights[$sGate], 'depends');
			}

			$aRights['restricted'] = sprintf(
				'%s holds one person\'s own settings. A write is allowed on your own row and refused on anyone else\'s, '
				.'whatever your profile says - iTop\'s own API for these only ever touches the account it is called by, and so does this endpoint. '
				.'Reading is unaffected.',
				$sClass
			);

			return $aRights;
		}

		if ($bAutomation && !$bGranting) {
			$aRights['restricted'] = $bAutomationAllowed
				? sprintf(
					'%s is part of iTop\'s automation - a standing instruction that makes the instance act on its own, later, '
					.'on changes made by anyone. mcp_allow_automation_administration is on, so it may be written here.',
					$sClass
				)
				: sprintf(
					'%s is part of iTop\'s automation - a standing instruction that makes the instance act on its own, later, on changes made by '
					.'anyone, and outside this endpoint entirely. Nothing here can send mail or call a URL directly, so staging one cannot be graded '
					.'against what you may do: it is refused at all, whatever your profile says. Turn on mcp_allow_automation_administration, or use '
					.'the iTop console. Reading is unaffected.',
					$sClass
				);

			if (!$bAutomationAllowed) {
				foreach ($aGates as $sGate) {
					$aRights[$sGate] = 'no';
				}
			}

			return $aRights;
		}

		// The delegating half next, and only where the granting half has
		// nothing to say: a class that is both is refused by the stricter of
		// the two, and that is the one below.
		if ($bDelegating && !$bGranting) {
			// Always 'depends', never 'no': this half settles nothing from the
			// class alone. Whether the write is allowed is a question about the
			// row - which class the definition points at, and what this caller
			// may do to that class - and 'depends' is the grade that says
			// exactly that. The setting is not consulted, because this half
			// does not read it.
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = self::stricter($aRights[$sGate], 'depends');
			}

			$aRights['restricted'] = sprintf(
				'%s defines work iTop\'s synchronisation engine carries out later, and the engine consults no rights at all. '
				.'So a definition may be written here only for a class you could write yourself - create, modify and delete, in bulk - '
				.'and never for one that decides who may reach this endpoint, which no setting permits, because the engine also writes '
				.'without the check that keeps an administering call away from your own access. Name the target (scope_class, or '
				.'sync_source_id on a row hanging off a source) so the call can be graded. Reading is unaffected.',
				$sClass
			);

			return $aRights;
		}

		foreach ($aGates as $sGate) {
			$aRights[$sGate] = $bAdministrationAllowed
				? self::stricter($aRights[$sGate], 'depends')
				: 'no';
		}

		$aRights['restricted'] = $bAdministrationAllowed
			? sprintf(
				'%s decides what this endpoint may do, so a write is refused when it reaches the access you are '
				.'connected with - your own account, your own tokens, a profile link naming you. Everything else is allowed '
				.'because mcp_allow_access_administration is on.',
				$sClass
			)
			: sprintf(
				'%s decides what this endpoint may do, so it cannot be written here at all, whatever your profile says. '
				.'Turn on mcp_allow_access_administration to administer other people\'s access, or use the iTop console. '
				.'Reading is unaffected.',
				$sClass
			);

		return $aRights;
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
			] + self::allowedValues($oAttDef) + self::dependsOn($oAttDef, $sClass) + self::writeHint($oAttDef);
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
			return ['values' => self::codeKeyed($aValues)];
		}

		return [
			'values'          => self::codeKeyed(array_slice($aValues, 0, self::MAX_ALLOWED_VALUES, true)),
			'valuesTruncated' => true,
			'valuesTotal'     => count($aValues),
		];
	}

	/**
	 * The allowed values as a map, whatever iTop keyed them with.
	 *
	 * iTop answers code => label, and for most attributes the codes are
	 * strings, so the payload carries an object and a caller reads the key it
	 * has to send. A stopwatch sub-item does not: AttributeStopWatch's
	 * GetSubItemAllowedValues() returns [0 => label, 1 => label], PHP calls
	 * that a list, and json_encode drops the keys - so sla_tto_passed arrived
	 * as ["no","yes"]. What survived was GetBooleanLabel(), which resolves
	 * through the dictionary: the *labels*, localised, with the 0 and 1 that
	 * are actually stored gone. On a French instance the same read answers
	 * ["non","oui"].
	 *
	 * Nothing writes those - a sub-item is computed, so it is reported under
	 * `derived` - but a search reads them, and "WHERE sla_tto_passed = 'no'"
	 * filters on a string the column never holds.
	 *
	 * Casting to an object is what keeps the keys: an array cast alone does
	 * not, because PHP folds a numeric-string key straight back to an int.
	 * An attribute whose values are already string-keyed encodes identically
	 * either way, so one shape covers both and a caller has one thing to
	 * parse.
	 *
	 * @param mixed $mValues As GetAllowedValues() answered: a map, or null.
	 */
	private static function codeKeyed(mixed $mValues): mixed
	{
		return is_array($mValues) ? (object) $mValues : $mValues;
	}

	/**
	 * The value iTop would have accepted, when the refusal is only a format.
	 *
	 * A date-time here is `Y-m-d H:i:s` - a space, no offset - and every other
	 * system a model has met uses RFC 3339, so the ISO form is what a first
	 * attempt sends. The schema already carries the pattern, and a refusal
	 * still costs a round trip to read it; this spends one strtotime() to put
	 * the corrected string in the refusal itself.
	 *
	 * Suggested, never applied. An offset-bearing value is an instant, iTop
	 * stores wall-clock time in the instance's own zone, and silently moving a
	 * timestamp by an hour is a worse failure than the refusal it replaced -
	 * so the converted value is shown, and the caller sends it or does not.
	 *
	 * Returns '' when there is nothing useful to say, which is most refusals:
	 * a value that is not a string, an attribute that is not a date, or a
	 * string no date parser recognises.
	 *
	 * @param mixed $value As the caller sent it.
	 *
	 * @since 1.0.0
	 */
	public static function ValueHint(string $sClass, string $sAttCode, mixed $value): string
	{
		if (!is_string($value) || $value === '') {
			return '';
		}

		try {
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		} catch (\Throwable $e) {
			return '';
		}

		if (!$oAttDef instanceof AttributeDateTime) {
			// AttributeDate extends it, so this covers both.
			return '';
		}

		$sFormat = $oAttDef::GetInternalFormat();
		$iTimestamp = strtotime($value);
		if ($iTimestamp === false) {
			return '';
		}

		$sCorrected = date($sFormat, $iTimestamp);
		if ($sCorrected === $value) {
			// Already in the right shape; whatever iTop refused, it was not
			// the format.
			return '';
		}

		return sprintf(
			' iTop stores this attribute as %s, not RFC 3339: send "%s".',
			$sFormat,
			$sCorrected
		);
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
				'condition'   => MetaModel::GetObsolescenceExpression($sClass)->Render(),
			];
		} catch (\Throwable $e) {
			// A class that cannot answer is reported as one that does not have
			// the notion, which is what the flag will say about its objects too.
			return ['obsoletable' => false, 'condition' => null];
		}
	}

	/**
	 * The attributes this one is computed from, where the datamodel says so.
	 *
	 * The gap this closes: a UserRequest's priority is writable, mandatory,
	 * and reported as both - and every value a caller sets is thrown away,
	 * because ComputeValues() derives it from urgency and impact on every
	 * write. A model reading the schema literally, which is what this tool
	 * tells it to do, sets priority and finds out afterwards from the
	 * `overridden` block on the write. The schema knew and did not say.
	 *
	 * Read rather than listed: the declaration is
	 * <dependencies><attribute id="impact"/>... in the class XML, compiled to
	 * depends_on and answered by GetPrerequisiteAttributes(). So a datamodel
	 * that derives something else, in a pack nobody here has seen, reports it
	 * the same way - and a class that stops deriving it stops saying so
	 * without anyone editing this file.
	 *
	 * A declared dependency is not proof that the value is recomputed - the
	 * recomputation is the class's own PHP, and the declaration is what the
	 * console uses to refresh the field - so the wording says what is
	 * declared, and the write still reports what actually survived.
	 *
	 * @return array<string, mixed> Empty for an attribute that depends on nothing.
	 */
	private static function dependsOn(AttributeDefinition $oAttDef, string $sClass): array
	{
		try {
			$aPrerequisites = $oAttDef->GetPrerequisiteAttributes($sClass);
		} catch (\Throwable $e) {
			return [];
		}

		if (!is_array($aPrerequisites) || $aPrerequisites === []) {
			return [];
		}

		return ['dependsOn' => array_values(array_filter($aPrerequisites, 'is_string'))];
	}

	/**
	 * How to write an attribute that is not written the way it reads.
	 *
	 * Most attributes take back what a read returned. A case log does not: it
	 * reads as the log and is written one entry at a time, so a type name is
	 * the whole of what a model is told and neither of the two accepted shapes
	 * is in it. Left to work that out, the reasonable guesses are sending the
	 * rendered log back as the new value - which is the log twice - or looking
	 * for an add-a-log-entry tool, which this module deliberately does not
	 * ship: a work note is task-shaped, and task-shaped tools belong to a pack.
	 *
	 * Reported per attribute rather than in the write tools' descriptions,
	 * because it is a property of the attribute in front of the caller and
	 * because the schema is where a model already goes for the attribute code.
	 * {@see RestValue} is what makes both shapes arrive intact.
	 *
	 * The append is what the source does, and what an instance was watched
	 * doing. `AttributeCaseLog::FromJSONToValue()` passes a string straight
	 * through and takes `add_item` as the entry, refusing it without a
	 * `message`; `MakeRealValue()` then clones the log already on the object
	 * and appends to that clone, which is where "keeps the entries already
	 * there" comes from. Two successive plain-string writes on a live instance
	 * left two entries with the first intact, and the dry run reported the same
	 * shape the real write then produced - worth confirming for this type in
	 * particular, since a case log is where a simulated diff could plausibly
	 * disagree with the real path. It did not.
	 *
	 * @return array<string, string> Empty for a type that needs no hint.
	 */
	private static function writeHint(AttributeDefinition $oAttDef): array
	{
		if ($oAttDef instanceof AttributeCaseLog) {
			return [
				'writeHint' => 'Write the new entry, not the whole log: pass its text as a plain string, or as {"add_item": {"message": "..."}}. iTop adds it to the log and keeps the entries already there.',
			];
		}

		return [];
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
