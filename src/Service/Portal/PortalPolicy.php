<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Combodo\iTop\Portal\Helper\ContextManipulatorHelper;
use DBObject;
use DBObjectSet;
use DBSearch;
use MetaModel;
use UserRights;

/**
 * What one portal lets the current user do, read from the portal's own
 * configuration and nothing else.
 *
 * Portal mode has no configuration of its own: the portal's is the policy, so
 * an operator who customises the portal has customised this. It answers the
 * questions the portal itself answers, with the portal's own code wherever
 * that code can run outside the portal's kernel:
 *
 * - which classes are offered: those a brick granted to the user reaches
 *   (doc/adr/0001-portal-mode-bricks-bound-discovery.md);
 * - what may be read, and what written: the portal's scopes, where a class
 *   with no scope is refused, never left unfiltered; the change log is read
 *   when the portal offers it, and never written, whatever it offers;
 * - which fields, in each mode: the portal's forms, merged by the portal's
 *   own ObjectFormManager::PrepareFields();
 * - which stimuli: the portal's lifecycle, UserRights, and the write scope.
 *
 * Reading the portal is PortalLoader's (and PortalBricks'), the fields its forms
 * give are PortalForms', and what a query can reach is PortalReach's; this is
 * what is asked of the result.
 *
 * One portal per request, and once per request: the portal's compiled scopes
 * and lifecycle are PHP classes with fixed global names, so a process can hold
 * one portal's and never two.
 *
 * Anything that cannot be read is an MCPPortalException, and portal mode then
 * serves nothing for that portal. Never "the scopes without the bricks", never
 * "every attribute when the form could not be read": each of those is wider
 * than what the operator configured.
 *
 * @api
 * @since 1.2.0
 */
final class PortalPolicy
{
	public const MODE_VIEW = 'view';
	public const MODE_EDIT = 'edit';
	public const MODE_CREATE = 'create';

	private static ?self $oCurrent = null;

	private readonly PortalForms $oForms;

	/**
	 * @param object                                  $oDesign    ModuleDesign: the portal's configuration, for the action rules.
	 * @param object                                  $oScopes    ScopeValidatorHelper.
	 * @param object                                  $oLifecycle LifecycleValidatorHelper.
	 * @param array<string, array<string, mixed>>     $aForms     The portal's forms, by class then mode.
	 * @param array<string, array<int, string>>       $aReach     Class => ids of the granted bricks reaching it.
	 * @param array<string, array<string, array<int, DBSearch|null>>> $aBrickSets Class => brick id => the brick's own queries on it, null where it names the class outright.
	 * @param array<int, array<string, mixed>>        $aLists     The granted bricks' named lists.
	 * @param array<string, array<string, mixed>>     $aListDefs  The same lists by id, with what running one needs: its query, tabs and columns.
	 * @param array<int, array<string, mixed>>        $aCreates   The granted bricks' create actions.
	 * @param array<int, string>                      $aProfiles  The user's profiles, as the scopes are keyed.
	 */
	private function __construct(
		private readonly string $sPortal,
		private readonly object $oDesign,
		private readonly object $oScopes,
		private readonly object $oLifecycle,
		array $aForms,
		private readonly array $aReach,
		private readonly array $aBrickSets,
		private readonly array $aLists,
		private readonly array $aListDefs,
		private readonly array $aCreates,
		private readonly array $aProfiles,
	)
	{
		$this->oForms = new PortalForms($aForms, $sPortal);
	}

	/**
	 * The policy of the portal this request is served in.
	 *
	 * @throws MCPPortalException When the request is not in portal mode, or the portal cannot be read.
	 */
	public static function ForCurrentRequest(): self
	{
		$sPortal = PortalMode::Current();
		if ($sPortal === null) {
			throw PortalLoader::Refusal('PortalPolicy was asked for outside portal mode.');
		}

		if (self::$oCurrent === null || self::$oCurrent->sPortal !== $sPortal) {
			[$sId, $oDesign, $oScopes, $oLifecycle, $aForms, $aReach, $aBrickSets, $aLists, $aListDefs, $aCreates, $aProfiles] = PortalLoader::Load($sPortal);
			self::$oCurrent = new self($sId, $oDesign, $oScopes, $oLifecycle, $aForms, $aReach, $aBrickSets, $aLists, $aListDefs, $aCreates, $aProfiles);
		}

		return self::$oCurrent;
	}

	/**
	 * For tests, and for a runner that serves more than one request.
	 *
	 * @internal Request state, set and cleared by Service\Server\RequestScope; a pack never calls it.
	 */
	public static function Forget(): void
	{
		self::$oCurrent = null;
	}

	public function Portal(): string
	{
		return $this->sPortal;
	}

	/**
	 * The objects of a class the user may read, or null when they may read none.
	 *
	 * Null is a refusal, and the reason this method exists rather than the
	 * portal's AddScopeToQuery(): that one leaves a query on a class with no
	 * scope unfiltered and reports it in a boolean, which a caller that forgets
	 * to look at turns into "every object".
	 *
	 * Null too when the scope can never hold an object of the class. A child
	 * class inherits its parent's scope, and a portal author keeps end users
	 * out of a subclass by pinning finalclass in it - the stock Ticket scope
	 * allows only UserRequest and Incident, which leaves Change and Problem a
	 * scope that matches nothing. The portal shows them nothing, and so does
	 * this: such a class is not one the user can see.
	 */
	public function ReadSet(string $sClass): ?DBSearch
	{
		// An excluded class is not one the portal offers through this channel (doc/adr/0012).
		if (!ExclusionPolicy::Serves($sClass) || UserRights::IsActionAllowed($sClass, UR_ACTION_READ) === UR_ALLOWED_NO) {
			return null;
		}

		$oScope = $this->oScopes->GetScopeFilterForProfiles($this->aProfiles, $sClass, UR_ACTION_READ);
		if ($oScope === null || !PortalReach::CanHoldAnyOf($oScope, PortalReach::ConcreteClasses($sClass))) {
			return null;
		}

		return $oScope;
	}

	/**
	 * The objects of a class the user may change, or null when they may change none.
	 *
	 * The read set intersected with the write scope, as the portal does. The
	 * intersection drops the AllowAllData flag a scope's ignore_silos sets, so
	 * it is put back here: without it a siloed user loses the services they
	 * need to open a request.
	 *
	 * Never for the change log: a write there forges an audit record, and a
	 * portal granting one would not be what an operator meant by showing
	 * history. So no edit, stimulus or create action is ever offered on it.
	 */
	public function WriteSet(string $sClass): ?DBSearch
	{
		if (ChangeLogReservation::IsReserved($sClass)) {
			return null;
		}

		$oRead = $this->ReadSet($sClass);
		if ($oRead === null || UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY) === UR_ALLOWED_NO) {
			return null;
		}

		$oWrite = $this->oScopes->GetScopeFilterForProfiles($this->aProfiles, $sClass, UR_ACTION_MODIFY);
		if ($oWrite === null) {
			return null;
		}

		$oSet = $oRead->Intersect($oWrite);
		if ($this->oScopes->IsAllDataAllowedForScope($this->aProfiles, $sClass)) {
			$oSet->AllowAllData();
		}

		return $oSet;
	}

	/**
	 * Whether the user may create objects of a class, as the portal's own
	 * create page decides it: UserRights' create grant, and a write scope.
	 */
	public function MayCreate(string $sClass): bool
	{
		return ExclusionPolicy::Serves($sClass)
			&& ExclusionPolicy::RefusalToCreate($sClass) === null
			&& UserRights::IsActionAllowed($sClass, UR_ACTION_CREATE) !== UR_ALLOWED_NO
			&& $this->WriteSet($sClass) !== null;
	}

	/**
	 * The ids of the granted bricks that reach a class, through itself or a
	 * parent. Empty means no brick offers it, and it is not offered here.
	 *
	 * A brick on a parent reaches the class only if its own query can hold
	 * one: the stock "ongoing tickets" brick pins finalclass to UserRequest
	 * and Incident, so it is not a way to a Change.
	 *
	 * @return array<int, string>
	 */
	public function ReachedBy(string $sClass): array
	{
		if (!MetaModel::IsValidClass($sClass)) {
			return [];
		}

		$aConcrete = PortalReach::ConcreteClasses($sClass);
		$aLineage = MetaModel::EnumParentClasses($sClass, ENUM_PARENT_CLASSES_ALL);

		return array_values(array_filter(
			PortalReach::BricksReaching($this->aReach, $aLineage),
			function (string $sBrick) use ($aLineage, $aConcrete): bool {
				foreach ($aLineage as $sReached) {
					foreach ($this->aBrickSets[$sReached][$sBrick] ?? [] as $oSet) {
						if ($oSet === null || PortalReach::CanHoldAnyOf($oSet, $aConcrete)) {
							return true;
						}
					}
				}

				return false;
			}
		));
	}

	/**
	 * The classes the granted bricks name, each with the bricks naming it.
	 * Their subclasses are reached too; {@see ReachedBy()} answers for one.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function Reach(): array
	{
		return $this->aReach;
	}

	/**
	 * The named lists the granted bricks define: each ManageBrick, with its
	 * tabs, and each BrowseBrick level.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function Lists(): array
	{
		return $this->aLists;
	}

	/**
	 * What running one of {@see Lists()} takes, or null when no granted brick
	 * defines a list of that id.
	 *
	 * Its class and query; for a ManageBrick, its columns and tabs, each tab
	 * with the condition the brick gives it; for a BrowseBrick level, the
	 * attributes the level shows and, below the first level, the list above
	 * it and the attribute pointing there. Never handed to a client: the
	 * client names a list, and the query is run here
	 * (doc/adr/0001-portal-mode-bricks-bound-discovery.md).
	 *
	 * @return array<string, mixed>|null
	 */
	public function ListDefinition(string $sList): ?array
	{
		return $this->aListDefs[$sList] ?? null;
	}

	/**
	 * A query narrowed to what the user may read of a class, or null when
	 * they may read none of it.
	 *
	 * The portal's AddScopeToQuery(), less its blind spot: there, a class
	 * with no scope leaves the query as it was. The intersection drops the
	 * AllowAllData flag a scope's ignore_silos sets, and it is put back as
	 * the portal puts it back.
	 */
	public function Bounded(DBSearch $oQuery, string $sClass): ?DBSearch
	{
		$oScope = $this->ReadSet($sClass);
		if ($oScope === null) {
			return null;
		}

		$oBounded = $oQuery->Intersect($oScope);
		if ($oScope->IsAllDataAllowed()) {
			$oBounded->AllowAllData();
		}

		return $oBounded;
	}

	/**
	 * One object, when the scopes let the user read it; null otherwise,
	 * whether it does not exist or is out of their reach - the two answer
	 * alike, as the portal's object page answers them.
	 *
	 * By the scopes and not by the bricks, as the portal's object pages are
	 * (doc/adr/0001): a ticket's caller or service must still resolve. Asked
	 * of the class named and then of the class the object actually is, since
	 * a child class may carry a narrower scope than its parent.
	 */
	public function Readable(string $sClass, int $iId): ?DBObject
	{
		return $this->within($sClass, $iId, fn (string $sOf): ?DBSearch => $this->ReadSet($sOf));
	}

	/**
	 * Whether the portal shows history: a granted brick reaches the change
	 * log and a read scope covers it, which is how a portal is configured to
	 * show an object's history. What of it the user then reads is the
	 * change log's scope, through {@see Bounded()}.
	 *
	 * A brick on one subclass of CMDBChangeOp is not enough: an object's
	 * history is every operation on it, and such a brick shows some of them.
	 */
	public function ShowsHistory(): bool
	{
		return $this->ReachedBy(ObjectHistory::HISTORY_CLASS) !== []
			&& $this->ReadSet(ObjectHistory::HISTORY_CLASS) !== null;
	}

	/** {@see Readable()}, for the objects the user may change. */
	public function Writable(string $sClass, int $iId): ?DBObject
	{
		return $this->Readable($sClass, $iId) === null
			? null
			: $this->within($sClass, $iId, fn (string $sOf): ?DBSearch => $this->WriteSet($sOf));
	}

	/**
	 * The stimuli the user may apply to this object, now: those of
	 * {@see Stimuli()} that leave its current state, and that UserRights
	 * grants on the object rather than on its class.
	 *
	 * Empty for an object the user may not change.
	 *
	 * @return array<string, string> Code => label.
	 */
	public function StimuliOn(DBObject $oObject): array
	{
		$sClass = get_class($oObject);
		if ($this->Writable($sClass, (int)$oObject->GetKey()) === null) {
			return [];
		}

		$aTransitions = MetaModel::EnumTransitions($sClass, (string)$oObject->GetState());
		$oInstanceSet = DBObjectSet::FromObject($oObject);

		$aStimuli = [];
		foreach ($this->Stimuli($sClass) as $sCode => $sLabel) {
			if (!isset($aTransitions[$sCode])) {
				continue;
			}

			// With the object in hand an addon has what it needs to answer,
			// so only a yes is a yes: DEPENDS here is not one.
			$mAllowed = UserRights::IsStimulusAllowed($sClass, $sCode, $oInstanceSet);
			if ($mAllowed === true || $mAllowed === UR_ALLOWED_YES) {
				$aStimuli[$sCode] = $sLabel;
			}
		}

		return $aStimuli;
	}

	/**
	 * The states each stimulus leaves, so that a client can tell "close" on
	 * a resolved request from "close" on a new one before reading either.
	 *
	 * @param array<int, string> $aCodes
	 *
	 * @return array<string, array<int, string>> Code => the states it applies in.
	 */
	public static function StatesOf(string $sClass, array $aCodes): array
	{
		$aFrom = array_fill_keys($aCodes, []);

		/** @var array<string, mixed> $aStates */
		$aStates = MetaModel::EnumStates($sClass);
		foreach (array_map('strval', array_keys($aStates)) as $sState) {
			/** @var array<string, mixed> $aTransitions */
			$aTransitions = MetaModel::EnumTransitions($sClass, $sState);
			foreach (array_map('strval', array_keys($aTransitions)) as $sCode) {
				if (isset($aFrom[$sCode])) {
					$aFrom[$sCode][] = $sState;
				}
			}
		}

		return $aFrom;
	}

	/**
	 * Runs a create action's rules on a new object, with the portal's own
	 * ContextManipulatorHelper: presets copy the caller's contact and
	 * organisation, or the service picked, into the object.
	 *
	 * The helper is built without its constructor. That constructor takes the
	 * portal's router for the callback URLs, which a rule applied here never
	 * follows; it then reads the rules with Init(), which is public, and keeps
	 * the scope validator, which PrepareObject() asks before it reads a rule's
	 * source. Both are done here. Pinned in doc/itop-branch-notes.md.
	 *
	 * @param array<int, string>     $aRules   The action's rule ids, as the brick lists them.
	 * @param array<string, int>     $aSources Class => id of the objects the action starts from.
	 *
	 * @throws MCPPortalException When the rules cannot be read or one of them fails.
	 */
	public function ApplyRules(array $aRules, array $aSources, DBObject $oObject): void
	{
		if ($aRules === []) {
			return;
		}

		try {
			$oHelper = (new \ReflectionClass(ContextManipulatorHelper::class))->newInstanceWithoutConstructor();
			$oHelper->Init($this->oDesign->GetNodes('/module_design/action_rules/action_rule'));
			(new \ReflectionProperty(ContextManipulatorHelper::class, 'oScopeValidator'))->setValue($oHelper, $this->oScopes);

			$oHelper->PrepareObject(['rules' => array_values($aRules), 'sources' => $aSources], $oObject);
		} catch (\Throwable $oException) {
			throw new MCPPortalException(ErrorReport::OpaqueFailure(
				sprintf("Could not apply the rules of a create action in portal '%s'", $this->sPortal),
				$oException
			), 0, $oException);
		}
	}

	/**
	 * The class a create action whose class the portal chooses would create,
	 * from the object it starts from: the portal's factory method, run here as
	 * the portal runs it, and only the class of what it returns kept, as the
	 * portal keeps only that.
	 *
	 * The method is the one the brick's configuration names, never one a
	 * client sent - the portal itself takes it from its URL.
	 *
	 * @throws MCPPortalException When the method cannot be called or does not return an object.
	 */
	public function ChosenClass(string $sFactoryMethod, DBObject $oFrom): string
	{
		$sMethod = ltrim($sFactoryMethod, '\\');

		try {
			if (!is_callable($sMethod)) {
				throw new \RuntimeException("not callable: {$sMethod}");
			}
			$oCreated = call_user_func($sMethod, $oFrom);
		} catch (\Throwable $oException) {
			throw new MCPPortalException(ErrorReport::OpaqueFailure(
				sprintf("Could not choose the class to create in portal '%s'", $this->sPortal),
				$oException
			), 0, $oException);
		}

		if (!$oCreated instanceof DBObject) {
			throw PortalLoader::Refusal(sprintf("The factory method %s of portal '%s' returned no object.", $sMethod, $this->sPortal));
		}

		return get_class($oCreated);
	}

	/**
	 * The create actions the granted bricks offer: a CreateBrick, or a
	 * BrowseBrick level's create_from_this.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function CreateActions(): array
	{
		return $this->aCreates;
	}

	/**
	 * The id of the form the portal declares for a class in a mode, or null
	 * when it declares none.
	 *
	 * @see PortalForms::FormOf()
	 */
	public function FormOf(string $sClass, string $sMode): ?string
	{
		return $this->oForms->FormOf($sClass, $sMode);
	}

	/**
	 * The fields the portal's form for a class shows in a mode, with the flags it gives each.
	 *
	 * @see PortalForms::Fields()
	 *
	 * @return array<string, int>
	 *
	 * @throws MCPPortalException
	 */
	public function Fields(string $sClass, string $sMode, ?DBObject $oObject = null): array
	{
		return $this->oForms->Fields($sClass, $sMode, $oObject);
	}

	/**
	 * The stimuli the user may apply to objects of a class, code => label.
	 *
	 * The portal's lifecycle (a deny-list), UserRights, and a write scope:
	 * a stimulus changes the object, so a class the user may not change has
	 * none. Whether one applies to a given object also depends on its state.
	 *
	 * @return array<string, string>
	 */
	public function Stimuli(string $sClass): array
	{
		if ($this->WriteSet($sClass) === null) {
			return [];
		}

		$aAllowedByPortal = $this->oLifecycle->GetStimuliForProfiles($this->aProfiles, $sClass);

		$aStimuli = [];
		foreach (MetaModel::EnumStimuli($sClass) as $sCode => $oStimulus) {
			if (!$oStimulus instanceof \StimulusUserAction || !in_array($sCode, $aAllowedByPortal, true)) {
				continue;
			}

			$mAllowed = UserRights::IsStimulusAllowed($sClass, $sCode);
			if ($mAllowed === false || $mAllowed === UR_ALLOWED_NO) {
				continue;
			}

			$aStimuli[(string)$sCode] = $oStimulus->GetLabel();
		}

		return $aStimuli;
	}

	/**
	 * The object of a class with this id, if the set each class gives is one
	 * it is in: the class named, then the class the object turns out to be.
	 *
	 * @param callable(string): ?DBSearch $fSetOf
	 */
	private function within(string $sClass, int $iId, callable $fSetOf): ?DBObject
	{
		if ($iId < 1 || !MetaModel::IsValidClass($sClass)) {
			return null;
		}

		$oObject = self::fetchFrom($fSetOf($sClass), $iId);
		if ($oObject === null) {
			return null;
		}

		$sFinal = get_class($oObject);

		return $sFinal === $sClass ? $oObject : self::fetchFrom($fSetOf($sFinal), $iId);
	}

	/**
	 * The object with this id in a set, or null.
	 *
	 * The id is bound, not written into the query. Obsolete objects are
	 * kept, as every read by id here keeps them ({@see \Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery}).
	 */
	private static function fetchFrom(?DBSearch $oSet, int $iId): ?DBObject
	{
		if ($oSet === null) {
			return null;
		}

		// AddCondition() rather than an expression on the alias: a scope that
		// unites several profiles' queries is a DBUnionSearch, whose members
		// each have their own.
		$oSearch = $oSet->DeepClone();
		$oSearch->AddCondition('id', $iId, '=');
		$oSearch->SetShowObsoleteData(true);
		if ($oSet->IsAllDataAllowed()) {
			$oSearch->AllowAllData();
		}

		$oObject = (new DBObjectSet($oSearch))->Fetch();

		return $oObject instanceof DBObject ? $oObject : null;
	}

}
