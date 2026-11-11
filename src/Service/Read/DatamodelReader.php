<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrantFamilies;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrantRefusals;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;

use MetaModel;
use UserRights;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Rights\GateGrades;

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
			// Skip classes the current user has no read access to, and those the operator excluded (doc/adr/0012)
			if (ExclusionPolicy::ExcludesClass($sClass) || !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
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
		if ($sRight === '' || $sRight === GateGrades::RIGHTS_ALL) {
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
		$aAttributes = AttributeDescription::attributes($sClass);

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
		$sCreate = GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_CREATE));
		$sBulkModify = GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_MODIFY));

		$aRights = [
			'read'       => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_READ)),
			'bulkRead'   => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_READ)),
			'create'     => $sCreate,
			'bulkCreate' => GateGrades::stricter($sCreate, $sBulkModify),
			'modify'     => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY)),
			'bulkModify' => $sBulkModify,
			'delete'     => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_DELETE)),
			'bulkDelete' => GateGrades::grade(UserRights::IsActionAllowed($sClass, UR_ACTION_BULK_DELETE)),
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

			$bListed = AccessGrantFamilies::IsOperatorListed($sClass, Settings::GetEscalationClasses());
			$bBarred = AccessGrantFamilies::IsBarred($sClass);
			if (!$bBarred && !$bListed) {
				return $aRights + ['restricted' => null];
			}

			$bGranting              = AccessGrantFamilies::IsGranting($sClass);
			$bDelegating            = AccessGrantFamilies::IsDelegating($sClass);
			$bAutomation            = AccessGrantFamilies::IsAutomation($sClass);
			$bRecording             = AccessGrantFamilies::IsRecording($sClass);
			$bDetection             = AccessGrantFamilies::IsDetection($sClass);
			$bPersonal              = AccessGrantFamilies::IsPersonal($sClass);
			$bAdministrationAllowed = Settings::AllowsAccessAdministration();
			$bEscalationAllowed     = Settings::AllowsPrivilegeEscalation();
		} catch (\Throwable $e) {
			// A question about the barrier must not cost the block. Reporting
			// iTop's own answer is what this did before the barrier existed.
			return $aRights + ['restricted' => null];
		}

		// Before everything else, and 'no' with no setting named: this one has
		// no switch, and a caller told "unless X is on" goes and asks for X.
		if ($bRecording) {
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = 'no';
			}

			$aRights['restricted'] = sprintf(AccessGrantRefusals::RECORDING_REFUSAL, $sClass);

			return $aRights;
		}

		// Listed by the operator: refused while the setting is off, as
		// RefusalGiven() does, ahead of the built-in families. With it on, a
		// class nothing else here knows is an ordinary write, and one that is
		// also built in is graded below as it would be anyway.
		if ($bListed) {
			if (!$bEscalationAllowed) {
				foreach ($aGates as $sGate) {
					$aRights[$sGate] = 'no';
				}

				return $aRights + ['restricted' => sprintf(AccessGrantRefusals::OPERATOR_ESCALATION_REFUSAL, $sClass)];
			}

			if (!$bBarred) {
				return $aRights + ['restricted' => sprintf(
					'%s is listed in mcp_privilege_escalation_classes, and mcp_allow_privilege_escalation is on, so it may be written here.',
					$sClass
				)];
			}
		}

		if ($bDetection && !$bGranting) {
			$aRights['restricted'] = sprintf(AccessGrantRefusals::DETECTION_REFUSAL, $sClass);

			if (!$bEscalationAllowed) {
				foreach ($aGates as $sGate) {
					$aRights[$sGate] = 'no';
				}
			}

			return $aRights;
		}

		if ($bPersonal && !$bGranting) {
			// 'depends' on every write: whether the row is the caller's own is
			// a question about the row, which is exactly what the grade says.
			foreach ($aGates as $sGate) {
				$aRights[$sGate] = GateGrades::stricter($aRights[$sGate], 'depends');
			}

			$aRights['restricted'] = $bAdministrationAllowed
				? sprintf(
					'%s holds one person\'s own settings. mcp_allow_access_administration is on, so another account\'s row may be written here; '
					.'with it off, only your own is. Reading is unaffected.',
					$sClass
				)
				: sprintf(
					'%s holds one person\'s own settings. A write is allowed on your own row and refused on anyone else\'s, whatever your profile says - '
					.'iTop\'s own API for these only ever touches the account it is called by, and so does this endpoint. '
					.'Turn on mcp_allow_access_administration to administer other people\'s, or use the iTop console. Reading is unaffected.',
					$sClass
				);

			return $aRights;
		}

		if ($bAutomation && !$bGranting) {
			$aRights['restricted'] = $bEscalationAllowed
				? sprintf(
					'%s is part of iTop\'s automation - a standing instruction that makes the instance act on its own, later, '
					.'on changes made by anyone. mcp_allow_privilege_escalation is on, so it may be written here.',
					$sClass
				)
				: sprintf(
					'%s is part of iTop\'s automation - a standing instruction that makes the instance act on its own, later, on changes made by '
					.'anyone, and outside this endpoint entirely. Nothing here can send mail or call a URL directly, so staging one cannot be graded '
					.'against what you may do: it is refused at all, whatever your profile says. Turn on mcp_allow_privilege_escalation, or use '
					.'the iTop console. Reading is unaffected.',
					$sClass
				);

			if (!$bEscalationAllowed) {
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
				$aRights[$sGate] = GateGrades::stricter($aRights[$sGate], 'depends');
			}

			$aRights['restricted'] = sprintf(
				'%s defines work iTop\'s synchronisation engine carries out later, and the engine checks no rights on what it writes. '
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
				? GateGrades::stricter($aRights[$sGate], 'depends')
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
			foreach (array_keys(ExclusionPolicy::RelationQueries($sClass, $sRelation)) as $sNeighbour) {
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
			];
		}

		return [
			'stateAttribute' => $sStateAttCode,
			'states'         => $aStates,
		];
	}
}
