<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
// Same namespace, imported anyway: it extends a portal class, so it can be
// loaded only where the portal is, and nothing should load it to find out.
use Altioo\iTop\Extension\MCP\Service\PortalBrickTemplates;
use Combodo\iTop\Application\TwigBase\Twig\Extension;
use Combodo\iTop\Portal\Brick\BrickCollection;
use Combodo\iTop\Portal\Brick\BrowseBrick;
use Combodo\iTop\Portal\Brick\CreateBrick;
use Combodo\iTop\Portal\Brick\ManageBrick;
use Combodo\iTop\Portal\DependencyInjection\SilexCompatBootstrap\PortalXmlConfiguration\Forms;
use Combodo\iTop\Portal\DependencyInjection\SilexCompatBootstrap\PortalXmlConfiguration\Lists;
use Combodo\iTop\Portal\Form\ObjectFormManager;
use Combodo\iTop\Portal\Helper\ApplicationHelper;
use Combodo\iTop\Portal\Helper\ContextManipulatorHelper;
use Combodo\iTop\Portal\Helper\LifecycleValidatorHelper;
use Combodo\iTop\Portal\Helper\ScopeValidatorHelper;
use Combodo\iTop\Portal\Service\TemplatesProvider\TemplatesProviderService;
use DBObject;
use DBObjectSet;
use DBSearch;
use MetaModel;
use Symfony\Component\DependencyInjection\Container;
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
 * One portal per request, and once per request: the portal's compiled scopes
 * and lifecycle are PHP classes with fixed global names, so a process can hold
 * one portal's and never two.
 *
 * Anything that cannot be read is an MCPPortalException, and portal mode then
 * serves nothing for that portal. Never "the scopes without the bricks", never
 * "every attribute when the form could not be read": each of those is wider
 * than what the operator configured.
 *
 * @internal
 * @since 1.2.0
 */
final class PortalPolicy
{
	public const MODE_VIEW = 'view';
	public const MODE_EDIT = 'edit';
	public const MODE_CREATE = 'create';

	/** A BrowseBrick action that opens a creation form. */
	private const ACTION_CREATE_FROM_THIS = 'create_from_this';

	/** How such an action says what it creates: BrowseBrick's ENUM_FACTORY_TYPE_*. */
	private const FACTORY_CLASS = 'class';
	private const FACTORY_METHOD = 'method';

	public const BRICK_MANAGE = 'manage';
	public const BRICK_BROWSE = 'browse';
	public const BRICK_CREATE = 'create';

	private static ?self $oCurrent = null;

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
		private readonly array $aForms,
		private readonly array $aReach,
		private readonly array $aBrickSets,
		private readonly array $aLists,
		private readonly array $aListDefs,
		private readonly array $aCreates,
		private readonly array $aProfiles,
	)
	{
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
			throw self::refusal('PortalPolicy was asked for outside portal mode.');
		}

		if (self::$oCurrent === null || self::$oCurrent->sPortal !== $sPortal) {
			self::$oCurrent = self::load($sPortal);
		}

		return self::$oCurrent;
	}

	/** For tests, and for a runner that serves more than one request. */
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
		if (!MetaModel::IsValidClass($sClass) || UserRights::IsActionAllowed($sClass, UR_ACTION_READ) === UR_ALLOWED_NO) {
			return null;
		}

		$oScope = $this->oScopes->GetScopeFilterForProfiles($this->aProfiles, $sClass, UR_ACTION_READ);
		if ($oScope === null || !self::canHoldAnyOf($oScope, self::concreteClasses($sClass))) {
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
		if (ObjectHistory::IsReserved($sClass)) {
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
		return MetaModel::IsValidClass($sClass)
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

		$aConcrete = self::concreteClasses($sClass);
		$aLineage = MetaModel::EnumParentClasses($sClass, ENUM_PARENT_CLASSES_ALL);

		return array_values(array_filter(
			self::BricksReaching($this->aReach, $aLineage),
			function (string $sBrick) use ($aLineage, $aConcrete): bool {
				foreach ($aLineage as $sReached) {
					foreach ($this->aBrickSets[$sReached][$sBrick] ?? [] as $oSet) {
						if ($oSet === null || self::canHoldAnyOf($oSet, $aConcrete)) {
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
			throw new MCPPortalException(MCPHelper::OpaqueFailure(
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
			throw new MCPPortalException(MCPHelper::OpaqueFailure(
				sprintf("Could not choose the class to create in portal '%s'", $this->sPortal),
				$oException
			), 0, $oException);
		}

		if (!$oCreated instanceof DBObject) {
			throw self::refusal(sprintf("The factory method %s of portal '%s' returned no object.", $sMethod, $this->sPortal));
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
	 * The id of the portal form used for a class in a mode, or null when the
	 * portal declares none and falls back to the class's "details" list.
	 */
	public function FormOf(string $sClass, string $sMode): ?string
	{
		// GetLoadedFormFromClass() looks in the same two places, in the same
		// order, before it generates the default.
		foreach (array_merge([$sClass], MetaModel::EnumParentClasses($sClass, ENUM_PARENT_CLASSES_EXCLUDELEAF, false)) as $sDeclaring) {
			if (isset($this->aForms[$sDeclaring][$sMode]['id'])) {
				return (string)$this->aForms[$sDeclaring][$sMode]['id'];
			}
		}

		return null;
	}

	/**
	 * The fields the portal's form shows for a class in a mode, with the
	 * OPT_ATT_* flags it gives each.
	 *
	 * Merged by iTop's own PrepareFields(): the form's field list, the fields
	 * its layout names, the datamodel's flags for the object's state, and the
	 * mandatory attributes left empty. A Twig layout is rendered first, as the
	 * portal renders it, because a layout may name fields conditionally.
	 *
	 * The fields depend on the object - its state, and any condition in the
	 * layout - so the answer for a new object is not the answer for one in
	 * progress. Pass the object when there is one.
	 *
	 * Never "all": an empty answer is an empty answer.
	 *
	 * @return array<string, int>
	 * @throws MCPPortalException When the form cannot be prepared.
	 */
	public function Fields(string $sClass, string $sMode, ?DBObject $oObject = null): array
	{
		try {
			$oObject ??= MetaModel::NewObject($sClass);

			$aForm = self::WithReadOnlyHonoured(ApplicationHelper::GetLoadedFormFromClass($this->aForms, $sClass, $sMode));
			if (($aForm['layout']['type'] ?? null) === 'twig') {
				$aForm['layout'] = ['type' => 'xhtml', 'content' => self::rendered((string)$aForm['layout']['content'], $oObject)];
			}

			$oManager = new ObjectFormManager();
			$oManager->SetObject($oObject);
			$oManager->SetMode($sMode);
			$oManager->SetFormProperties($aForm);
			$oManager->PrepareFormAndHTMLDocument();
			$oManager->PrepareFields();

			// Private, with no accessor: PrepareFields() was written to feed
			// Build(), which renders. Pinned in doc/itop-branch-notes.md.
			$aFlags = (new \ReflectionProperty(ObjectFormManager::class, 'aFieldsAtts'))->getValue($oManager);
		} catch (\Throwable $oException) {
			throw new MCPPortalException(MCPHelper::OpaqueFailure(
				sprintf("Could not read the '%s' form of %s in portal '%s'", $sMode, $sClass, $this->sPortal),
				$oException
			), 0, $oException);
		}

		// A layout can carry plugin fields (attachments) that are not
		// attributes; nothing here knows how to serve those.
		$aFields = [];
		foreach ($aFlags as $sAttCode => $iFlags) {
			if (is_string($sAttCode) && MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				$aFields[$sAttCode] = (int)$iFlags;
			}
		}

		return $aFields;
	}

	/**
	 * Whether the portal's forms show an object's attachments.
	 *
	 * Through a plugin field rather than an attribute, so {@see Fields()}
	 * leaves it out; and whether the layout names it or not, since the
	 * portal adds it to any form whose object the attachment module allows
	 * attachments on (ObjectFormManager's fallback), except a transition's.
	 */
	public static function ShowsAttachments(DBObject $oObject): bool
	{
		return class_exists('Attachment') && class_exists('AttachmentPlugIn')
			&& \AttachmentPlugIn::IsAttachmentAllowedForObject($oObject);
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
	 * The bricks reaching any of a class's ancestors, the class included.
	 *
	 * @param array<string, array<int, string>> $aReach   As Reach() answers.
	 * @param array<int, string>                $aLineage The class and its parents.
	 *
	 * @return array<int, string>
	 */
	public static function BricksReaching(array $aReach, array $aLineage): array
	{
		$aBricks = [];
		foreach ($aLineage as $sClass) {
			foreach ($aReach[$sClass] ?? [] as $sBrick) {
				$aBricks[$sBrick] = true;
			}
		}

		return array_keys($aBricks);
	}

	/**
	 * A form definition with its fields' read_only option honoured.
	 *
	 * iTop stores `<fields><field><read_only>` as `readonly` and reads it back
	 * as `read_only`, so the portal shows such a field writable. The operator
	 * wrote read-only, and read-only is the narrower reading: it is honoured
	 * here, and wins over anything else the field says.
	 *
	 * @param array<string, mixed> $aForm
	 *
	 * @return array<string, mixed>
	 */
	public static function WithReadOnlyHonoured(array $aForm): array
	{
		if (!isset($aForm['fields']) || !is_array($aForm['fields'])) {
			return $aForm;
		}

		foreach ($aForm['fields'] as $sAttCode => $aOptions) {
			if (is_array($aOptions) && ($aOptions['readonly'] ?? false) === true) {
				$aForm['fields'][$sAttCode]['read_only'] = true;
			}
		}

		return $aForm;
	}

	/**
	 * What a brick class is to portal mode: one of iTop's own ManageBrick,
	 * BrowseBrick and CreateBrick, or nothing.
	 *
	 * A subclass of one of them is read as that type (doc/adr/0002): a custom
	 * brick class extending ManageBrick still declares its data the way a
	 * ManageBrick does, and reads stay bounded by the scopes whatever its
	 * controller adds. A class that is none of them - one extending
	 * PortalBrick directly, a FilterBrick - reaches nothing.
	 */
	public static function BrickKind(string $sBrickClass): ?string
	{
		$sBrickClass = ltrim($sBrickClass, '\\');

		foreach ([ManageBrick::class => self::BRICK_MANAGE, BrowseBrick::class => self::BRICK_BROWSE, CreateBrick::class => self::BRICK_CREATE] as $sType => $sKind) {
			// By name first, so that the answer does not need the portal
			// module loaded for the stock classes themselves.
			if ($sBrickClass === $sType || is_a($sBrickClass, $sType, true)) {
				return $sKind;
			}
		}

		return null;
	}

	/**
	 * The create action a BrowseBrick action declares, or null when it is
	 * not one.
	 *
	 * Its class is named, or chosen by a method the portal calls with the
	 * object the user picked: on an instance with both request and incident
	 * management, iTop's own itop-full-itil makes the services brick's action
	 * \Ticket::CreateFromServiceSubcategory, which answers UserRequest or
	 * Incident from the subcategory's request type. Such an action is kept,
	 * with no class - what the method returns is PHP, and is never guessed -
	 * and the method with it, for the step that creates, which can run it on
	 * the server as the portal does. It reaches no class: only a named one
	 * does.
	 *
	 * @param array<string, mixed> $aAction As BrowseBrick::GetLevels() gives one level's action.
	 *
	 * @return array{action: string, class: string|null, factory_method: string|null, title: string, from: string, rules: array<int, string>}|null
	 */
	public static function CreateActionOf(string $sAction, array $aAction, string $sFrom, string $sTitle): ?array
	{
		if (($aAction['type'] ?? null) !== self::ACTION_CREATE_FROM_THIS) {
			return null;
		}

		$sFactory = (string)($aAction['factory']['type'] ?? '');
		$sValue = trim((string)($aAction['factory']['value'] ?? ''));
		if ($sValue === '' || !in_array($sFactory, [self::FACTORY_CLASS, self::FACTORY_METHOD], true)) {
			return null;
		}

		return [
			'action'         => $sAction,
			'class'          => $sFactory === self::FACTORY_CLASS ? $sValue : null,
			'factory_method' => $sFactory === self::FACTORY_METHOD ? $sValue : null,
			'title'          => $sTitle,
			'from'           => $sFrom,
			'rules'          => array_values(array_map('strval', (array)($aAction['rules'] ?? []))),
		];
	}

	/**
	 * Whether a query bounded by these finalclass conditions can return an
	 * object of any of these classes.
	 *
	 * @param array<int, string>|null $aAllowed  The classes finalclass is pinned to, or null when it is not.
	 * @param array<int, string>      $aDenied   The classes finalclass is told to differ from.
	 * @param array<int, string>      $aConcrete The concrete classes an object of the class asked about can be.
	 */
	public static function CanHold(?array $aAllowed, array $aDenied, array $aConcrete): bool
	{
		foreach ($aConcrete as $sClass) {
			if (($aAllowed === null || in_array($sClass, $aAllowed, true)) && !in_array($sClass, $aDenied, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads a portal's configuration and the bricks granted to the current user.
	 *
	 * @throws MCPPortalException
	 */
	private static function load(string $sPortal): self
	{
		foreach ([\ModuleDesign::class, BrickCollection::class, ScopeValidatorHelper::class, ObjectFormManager::class, Container::class] as $sNeeded) {
			if (!class_exists($sNeeded)) {
				throw self::refusal(sprintf(
					"Portal mode cannot read portal '%s': %s is not available. The portal module (itop-portal-base) has to be installed.",
					$sPortal,
					$sNeeded
				));
			}
		}

		// The portal's own field merge is public from iTop 3.2.2. Before
		// that it is inlined in the method that renders, and portal mode
		// would have to restate it - which is how two copies of a security
		// rule start to disagree.
		if (!method_exists(ObjectFormManager::class, 'PrepareFields')) {
			throw self::refusal(sprintf(
				"Portal mode cannot read portal '%s' on iTop %s: it needs iTop 3.2.2 or later to read the portal's forms.",
				$sPortal,
				defined('ITOP_VERSION') ? ITOP_VERSION : 'of unknown version'
			));
		}

		try {
			$oDesign = new \ModuleDesign($sPortal);

			// 3.2.0 bricks take no templates service; from 3.2.1 a brick
			// overriding a template cannot be read without one.
			$oBricks = class_exists(TemplatesProviderService::class)
				? new BrickCollection($oDesign, new PortalBrickTemplates())
				: new BrickCollection($oDesign);

			$sCompiled = self::compiledRulesPath();

			// iTop's helpers compile the portal's rules into $sCompiled when no
			// file is there yet, writing it in place; a second request starting at
			// the same moment could require a half-written file and fail with a
			// ParseError. Built one at a time, the second finds the first's file whole.
			// A portal shares these files with no other, so it waits for no other.
			$rLock = @fopen(self::CompileLockPath($sCompiled, $sPortal), 'c');
			if ($rLock !== false) {
				flock($rLock, LOCK_EX);
			}
			try {
				$oScopes = new ScopeValidatorHelper($oDesign, $sPortal, $sCompiled);
				$oLifecycle = new LifecycleValidatorHelper($oDesign, $sPortal, $sCompiled);
			} finally {
				if ($rLock !== false) {
					flock($rLock, LOCK_UN);
					fclose($rLock);
				}
			}

			// Forms and Lists write into a container parameter and read
			// nothing else from it, so a bare container stands in for the
			// portal's kernel.
			$oContainer = new Container();
			$oContainer->setParameter('combodo.portal.instance.conf', []);
			(new Forms($oDesign))->Process($oContainer);
			(new Lists($oDesign))->Process($oContainer);
			$aConf = $oContainer->getParameter('combodo.portal.instance.conf');

			[$aReach, $aBrickSets, $aLists, $aListDefs, $aCreates] = self::granted($oBricks->GetBricks() ?? []);
		} catch (\Throwable $oException) {
			throw new MCPPortalException(MCPHelper::OpaqueFailure(sprintf("Could not read portal '%s'", $sPortal), $oException), 0, $oException);
		}

		return new self(
			$sPortal,
			$oDesign,
			$oScopes,
			$oLifecycle,
			is_array($aConf['forms'] ?? null) ? $aConf['forms'] : [],
			$aReach,
			$aBrickSets,
			$aLists,
			$aListDefs,
			$aCreates,
			array_values(array_map('strval', UserRights::ListProfiles()))
		);
	}

	/**
	 * What the granted bricks reach, list and create.
	 *
	 * The bricks here are already the granted ones: BrickCollection keeps a
	 * brick only when it is active and its security passes for the user's
	 * profiles, which is the portal's own test. Visibility on the home page or
	 * in the menu is layout and is not looked at - a brick hidden from both is
	 * often the one doing the work.
	 *
	 * A create_from_this whose class is chosen by a method rather than named
	 * is listed with no class and reaches nothing: what that method does is
	 * PHP, and is never guessed ({@see CreateActionOf()}). A brick that is
	 * none of iTop's three data types nor a subclass of one reaches nothing
	 * ({@see BrickKind()}).
	 *
	 * @param iterable<int, object> $aBricks
	 *
	 * @return array{0: array<string, array<int, string>>, 1: array<string, array<string, array<int, DBSearch|null>>>, 2: array<int, array<string, mixed>>, 3: array<string, array<string, mixed>>, 4: array<int, array<string, mixed>>}
	 */
	private static function granted(iterable $aBricks): array
	{
		$aReach = [];
		$aBrickSets = [];
		$aLists = [];
		$aListDefs = [];
		$aCreates = [];

		foreach ($aBricks as $oBrick) {
			$sBrick = (string)$oBrick->GetId();
			$sKind = self::BrickKind(get_class($oBrick));

			if ($sKind === self::BRICK_MANAGE) {
				$oSet = DBSearch::FromOQL($oBrick->GetOql());
				$sClass = $oSet->GetClass();
				$aReach[$sClass][] = $sBrick;
				$aBrickSets[$sClass][$sBrick][] = $oSet;

				$aGrouping = $oBrick->GetGrouping();
				$aTabs = [];
				$aConditions = [];
				foreach ($aGrouping['tabs']['groups'] ?? [] as $aGroup) {
					$aTabs[] = ['id' => (string)$aGroup['id'], 'title' => \Dict::S((string)$aGroup['title'])];
					$aConditions[(string)$aGroup['id']] = (string)$aGroup['condition'];
				}

				$aListDefs[$sBrick] = [
					'kind'       => self::BRICK_MANAGE,
					'class'      => $sClass,
					'oql'        => (string)$oBrick->GetOql(),
					'columns'    => array_values(array_map('strval', $oBrick->GetFields())),
					'tabs'       => $aConditions,
					'tabs_by'    => $aGrouping['tabs']['attribute'] ?? null,
					'parent'     => null,
					'parent_att' => null,
				];

				$aLists[] = [
					'list'       => $sBrick,
					'kind'       => self::BRICK_MANAGE,
					'title'      => \Dict::S((string)$oBrick->GetTitle()),
					'class'      => $sClass,
					'tabs'       => $aTabs,
					'tabs_by'    => $aGrouping['tabs']['attribute'] ?? null,
					'grouped_by' => $aGrouping['areas']['attribute'] ?? null,
				];
			} elseif ($sKind === self::BRICK_BROWSE) {
				self::levels($sBrick, \Dict::S((string)$oBrick->GetTitle()), $oBrick->GetLevels(), [], $aReach, $aBrickSets, $aLists, $aListDefs, $aCreates);
			} elseif ($sKind === self::BRICK_CREATE) {
				$sClass = (string)$oBrick->GetClass();
				$aReach[$sClass][] = $sBrick;
				$aBrickSets[$sClass][$sBrick][] = null;
				$aCreates[] = [
					'action'         => $sBrick,
					'class'          => $sClass,
					'factory_method' => null,
					'title'          => \Dict::S((string)$oBrick->GetTitle()),
					'from'           => null,
					'from_list'      => null,
					'rules'          => array_values($oBrick->GetRules()),
				];
			}
		}

		return [$aReach, $aBrickSets, $aLists, $aListDefs, $aCreates];
	}

	/**
	 * One BrowseBrick level and those under it.
	 *
	 * @param array<int, array<string, mixed>>        $aLevels
	 * @param array<int, string>                      $aPath   The level ids above this one.
	 * @param array<string, array<int, string>>       $aReach
	 * @param array<string, array<string, array<int, DBSearch|null>>> $aBrickSets
	 * @param array<int, array<string, mixed>>        $aLists
	 * @param array<string, array<string, mixed>>     $aListDefs
	 * @param array<int, array<string, mixed>>        $aCreates
	 */
	private static function levels(string $sBrick, string $sTitle, array $aLevels, array $aPath, array &$aReach, array &$aBrickSets, array &$aLists, array &$aListDefs, array &$aCreates): void
	{
		foreach ($aLevels as $aLevel) {
			$oSet = DBSearch::FromOQL((string)$aLevel['oql']);
			$sClass = $oSet->GetClass();
			$aReach[$sClass][] = $sBrick;
			$aBrickSets[$sClass][$sBrick][] = $oSet;

			$aLevelPath = array_merge($aPath, [(string)$aLevel['id']]);
			$sList = $sBrick.'/'.implode('/', $aLevelPath);

			$aLists[] = [
				'list'       => $sList,
				'kind'       => self::BRICK_BROWSE,
				'title'      => isset($aLevel['title']) ? \Dict::S((string)$aLevel['title']) : $sTitle,
				'class'      => $sClass,
				// The level above, whose rows parent_id is one of: how a
				// client walks down a browse brick it has never seen.
				'parent'     => $aPath === [] ? null : $sBrick.'/'.implode('/', $aPath),
				'parent_att' => $aLevel['parent_att'] ?? null,
			];

			// What the level shows of each object: its name, its description
			// and tooltip, and the fields it lists beside them.
			$aColumns = [];
			foreach (['name_att', 'description_att', 'tooltip_att'] as $sKey) {
				if (is_string($aLevel[$sKey] ?? null) && $aLevel[$sKey] !== '') {
					$aColumns[] = $aLevel[$sKey];
				}
			}
			$aListDefs[$sList] = [
				'kind'       => self::BRICK_BROWSE,
				'class'      => $sClass,
				'oql'        => (string)$aLevel['oql'],
				'columns'    => array_values(array_unique(array_merge($aColumns, array_map('strval', array_keys($aLevel['fields'] ?? []))))),
				'tabs'       => [],
				'tabs_by'    => null,
				'parent'     => $aPath === [] ? null : $sBrick.'/'.implode('/', $aPath),
				'parent_att' => $aLevel['parent_att'] ?? null,
			];

			foreach ($aLevel['actions'] ?? [] as $sAction => $aAction) {
				$aCreate = is_array($aAction) ? self::CreateActionOf($sList.'/'.(string)$sAction, $aAction, $sClass, $sTitle) : null;
				if ($aCreate === null) {
					continue;
				}

				// The level it is offered on, whose path holds the objects
				// the action's rules read: the subcategory picked, and the
				// service and family above it.
				$aCreate['from_list'] = $sList;

				$aCreates[] = $aCreate;
				if ($aCreate['class'] !== null) {
					$aReach[$aCreate['class']][] = $sBrick;
					// Created by name: nothing narrows which class it is.
					$aBrickSets[$aCreate['class']][$sBrick][] = null;
				}
			}

			self::levels($sBrick, $sTitle, $aLevel['levels'] ?? [], $aLevelPath, $aReach, $aBrickSets, $aLists, $aListDefs, $aCreates);
		}
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
	 * kept, as every read by id here keeps them ({@see \Altioo\iTop\Extension\MCP\Helper\ObjectQuery}).
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

	/**
	 * The concrete classes an object of a class can be: itself and its
	 * subclasses, abstract ones left out.
	 *
	 * @return array<int, string>
	 */
	private static function concreteClasses(string $sClass): array
	{
		return array_values(array_filter(
			MetaModel::EnumChildClasses($sClass, ENUM_CHILD_CLASSES_ALL),
			static fn (string $sChild): bool => !MetaModel::IsAbstract($sChild)
		));
	}

	/**
	 * Whether a query can return an object of any of these classes, as far
	 * as its finalclass conditions say.
	 *
	 * Read from the top-level AND of each query only, and only where the
	 * condition is the selected alias's finalclass against literal values.
	 * Anything else - a parameter, an OR, a condition written the other way
	 * round - is no conclusion, and the answer stays yes: this can only ever
	 * take a class away, never offer one.
	 *
	 * Typed object rather than DBSearch: the static analysis runs without
	 * iTop, and cannot know that the two kinds of search below are DBSearches.
	 *
	 * @param object             $oSearch   A DBSearch.
	 * @param array<int, string> $aConcrete
	 */
	private static function canHoldAnyOf(object $oSearch, array $aConcrete): bool
	{
		if ($oSearch instanceof \DBUnionSearch) {
			foreach ($oSearch->GetSearches() as $oMember) {
				if (self::canHoldAnyOf($oMember, $aConcrete)) {
					return true;
				}
			}

			return false;
		}

		if (!$oSearch instanceof \DBObjectSearch) {
			return true;
		}

		$aAllowed = null;
		$aDenied = [];
		foreach (self::conjuncts($oSearch->GetCriteria()) as $oCondition) {
			if (!$oCondition instanceof \BinaryExpression) {
				continue;
			}

			$oField = $oCondition->GetLeftExpr();
			if (!$oField instanceof \FieldExpression || $oField->GetName() !== 'finalclass' || $oField->GetParent() !== $oSearch->GetClassAlias()) {
				continue;
			}

			$aValues = self::literals($oCondition->GetRightExpr());
			if ($aValues === null) {
				continue;
			}

			switch (strtoupper((string)$oCondition->GetOperator())) {
				case '=':
				case 'IN':
					$aAllowed = $aAllowed === null ? $aValues : array_values(array_intersect($aAllowed, $aValues));
					break;
				case '!=':
				case 'NOT IN':
					$aDenied = array_merge($aDenied, $aValues);
					break;
			}
		}

		return self::CanHold($aAllowed, $aDenied, $aConcrete);
	}

	/**
	 * The terms of an expression's top-level AND, or the expression itself.
	 *
	 * @return array<int, object>
	 */
	private static function conjuncts(object $oExpression): array
	{
		if ($oExpression instanceof \BinaryExpression && strtoupper((string)$oExpression->GetOperator()) === 'AND') {
			return array_merge(self::conjuncts($oExpression->GetLeftExpr()), self::conjuncts($oExpression->GetRightExpr()));
		}

		return [$oExpression];
	}

	/**
	 * The literal values of a scalar or a list of scalars, or null for
	 * anything else.
	 *
	 * @return array<int, string>|null
	 */
	private static function literals(object $oExpression): ?array
	{
		$aItems = $oExpression instanceof \ListExpression ? $oExpression->GetItems() : [$oExpression];

		$aValues = [];
		foreach ($aItems as $oItem) {
			if (!$oItem instanceof \ScalarExpression) {
				return null;
			}
			$aValues[] = (string)$oItem->GetValue();
		}

		return $aValues;
	}

	/**
	 * A Twig layout rendered the way the portal renders it before reading its
	 * fields: a sandbox environment holding only the layout, with the
	 * filters and functions iTop registers for its own templates.
	 */
	private static function rendered(string $sLayout, DBObject $oObject): string
	{
		$oTwig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['form' => $sLayout]));
		foreach (Extension::GetFilters() as $oFilter) {
			$oTwig->addFilter($oFilter);
		}
		foreach (Extension::GetFunctions() as $oFunction) {
			$oTwig->addFunction($oFunction);
		}

		return $oTwig->render('form', ['oObject' => $oObject]);
	}

	/**
	 * A refusal this module composed: the detail - a path, a version, a
	 * missing module - goes to the log, and the caller is told only that the
	 * portal could not be read.
	 */
	private static function refusal(string $sDetail): MCPPortalException
	{
		MCPHelper::LogError($sDetail);

		return new MCPPortalException(
			'This portal\'s configuration could not be read, so nothing is served for it. '
			.'The administrator will find the reason in the iTop error log.'
		);
	}

	/**
	 * The file a portal's compilation is locked on, beside the files it writes.
	 *
	 * @param string $sCompiled The directory the rules are compiled in, with its trailing slash.
	 */
	public static function CompileLockPath(string $sCompiled, string $sPortal): string
	{
		return $sCompiled.$sPortal.'.compile.lock';
	}

	/**
	 * Where the portal's scopes and lifecycle are compiled for this endpoint.
	 *
	 * Under iTop's cache directory, which setup clears with the portal's own
	 * cache, and in a directory of this module's, so that the portal's kernel
	 * cache is never written to from here.
	 *
	 * @throws MCPPortalException
	 */
	private static function compiledRulesPath(): string
	{
		$sPath = rtrim(\utils::GetCachePath(), '/').'/altioo-mcp/portals/';
		if (!is_dir($sPath) && !@mkdir($sPath, 0770, true) && !is_dir($sPath)) {
			throw self::refusal(sprintf('Portal mode could not create %s to compile the portal rules in.', $sPath));
		}

		return $sPath;
	}
}
