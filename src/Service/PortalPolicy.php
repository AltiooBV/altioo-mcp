<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
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
use Combodo\iTop\Portal\Helper\LifecycleValidatorHelper;
use Combodo\iTop\Portal\Helper\ScopeValidatorHelper;
use Combodo\iTop\Portal\Service\TemplatesProvider\TemplatesProviderService;
use DBObject;
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
 *   with no scope is refused, never left unfiltered;
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

	private static ?self $oCurrent = null;

	/**
	 * @param object                                  $oScopes    ScopeValidatorHelper.
	 * @param object                                  $oLifecycle LifecycleValidatorHelper.
	 * @param array<string, array<string, mixed>>     $aForms     The portal's forms, by class then mode.
	 * @param array<string, array<int, string>>       $aReach     Class => ids of the granted bricks reaching it.
	 * @param array<int, array<string, mixed>>        $aLists     The granted bricks' named lists.
	 * @param array<int, array<string, mixed>>        $aCreates   The granted bricks' create actions.
	 * @param array<int, string>                      $aProfiles  The user's profiles, as the scopes are keyed.
	 */
	private function __construct(
		private readonly string $sPortal,
		private readonly object $oScopes,
		private readonly object $oLifecycle,
		private readonly array $aForms,
		private readonly array $aReach,
		private readonly array $aLists,
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
	 */
	public function ReadSet(string $sClass): ?DBSearch
	{
		if (!MetaModel::IsValidClass($sClass) || UserRights::IsActionAllowed($sClass, UR_ACTION_READ) === UR_ALLOWED_NO) {
			return null;
		}

		return $this->oScopes->GetScopeFilterForProfiles($this->aProfiles, $sClass, UR_ACTION_READ);
	}

	/**
	 * The objects of a class the user may change, or null when they may change none.
	 *
	 * The read set intersected with the write scope, as the portal does. The
	 * intersection drops the AllowAllData flag a scope's ignore_silos sets, so
	 * it is put back here: without it a siloed user loses the services they
	 * need to open a request.
	 */
	public function WriteSet(string $sClass): ?DBSearch
	{
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
	 * The ids of the granted bricks that reach a class, through itself or a
	 * parent. Empty means no brick offers it, and it is not offered here.
	 *
	 * @return array<int, string>
	 */
	public function ReachedBy(string $sClass): array
	{
		if (!MetaModel::IsValidClass($sClass)) {
			return [];
		}

		return self::BricksReaching($this->aReach, MetaModel::EnumParentClasses($sClass, ENUM_PARENT_CLASSES_ALL));
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
			$oScopes = new ScopeValidatorHelper($oDesign, $sPortal, $sCompiled);
			$oLifecycle = new LifecycleValidatorHelper($oDesign, $sPortal, $sCompiled);

			// Forms and Lists write into a container parameter and read
			// nothing else from it, so a bare container stands in for the
			// portal's kernel.
			$oContainer = new Container();
			$oContainer->setParameter('combodo.portal.instance.conf', []);
			(new Forms($oDesign))->Process($oContainer);
			(new Lists($oDesign))->Process($oContainer);
			$aConf = $oContainer->getParameter('combodo.portal.instance.conf');

			[$aReach, $aLists, $aCreates] = self::granted($oBricks->GetBricks() ?? []);
		} catch (\Throwable $oException) {
			throw new MCPPortalException(MCPHelper::OpaqueFailure(sprintf("Could not read portal '%s'", $sPortal), $oException), 0, $oException);
		}

		return new self(
			$sPortal,
			$oScopes,
			$oLifecycle,
			is_array($aConf['forms'] ?? null) ? $aConf['forms'] : [],
			$aReach,
			$aLists,
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
	 * is left out: what that method does is PHP, and is never guessed.
	 *
	 * @param iterable<int, object> $aBricks
	 *
	 * @return array{0: array<string, array<int, string>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
	 */
	private static function granted(iterable $aBricks): array
	{
		$aReach = [];
		$aLists = [];
		$aCreates = [];

		foreach ($aBricks as $oBrick) {
			$sBrick = (string)$oBrick->GetId();

			if ($oBrick instanceof ManageBrick) {
				$sClass = DBSearch::FromOQL($oBrick->GetOql())->GetClass();
				$aReach[$sClass][] = $sBrick;

				$aGrouping = $oBrick->GetGrouping();
				$aTabs = [];
				foreach ($aGrouping['tabs']['groups'] ?? [] as $aGroup) {
					$aTabs[] = ['id' => (string)$aGroup['id'], 'title' => \Dict::S((string)$aGroup['title'])];
				}

				$aLists[] = [
					'list'       => $sBrick,
					'kind'       => 'manage',
					'title'      => \Dict::S((string)$oBrick->GetTitle()),
					'class'      => $sClass,
					'tabs'       => $aTabs,
					'tabs_by'    => $aGrouping['tabs']['attribute'] ?? null,
					'grouped_by' => $aGrouping['areas']['attribute'] ?? null,
				];
			} elseif ($oBrick instanceof BrowseBrick) {
				self::levels($sBrick, \Dict::S((string)$oBrick->GetTitle()), $oBrick->GetLevels(), [], $aReach, $aLists, $aCreates);
			} elseif ($oBrick instanceof CreateBrick) {
				$sClass = (string)$oBrick->GetClass();
				$aReach[$sClass][] = $sBrick;
				$aCreates[] = [
					'action' => $sBrick,
					'class'  => $sClass,
					'title'  => \Dict::S((string)$oBrick->GetTitle()),
					'from'   => null,
					'rules'  => array_values($oBrick->GetRules()),
				];
			}
		}

		return [$aReach, $aLists, $aCreates];
	}

	/**
	 * One BrowseBrick level and those under it.
	 *
	 * @param array<int, array<string, mixed>>        $aLevels
	 * @param array<int, string>                      $aPath   The level ids above this one.
	 * @param array<string, array<int, string>>       $aReach
	 * @param array<int, array<string, mixed>>        $aLists
	 * @param array<int, array<string, mixed>>        $aCreates
	 */
	private static function levels(string $sBrick, string $sTitle, array $aLevels, array $aPath, array &$aReach, array &$aLists, array &$aCreates): void
	{
		foreach ($aLevels as $aLevel) {
			$sClass = DBSearch::FromOQL((string)$aLevel['oql'])->GetClass();
			$aReach[$sClass][] = $sBrick;

			$aLevelPath = array_merge($aPath, [(string)$aLevel['id']]);
			$sList = $sBrick.'/'.implode('/', $aLevelPath);

			$aLists[] = [
				'list'       => $sList,
				'kind'       => 'browse',
				'title'      => isset($aLevel['title']) ? \Dict::S((string)$aLevel['title']) : $sTitle,
				'class'      => $sClass,
				'parent_att' => $aLevel['parent_att'] ?? null,
			];

			foreach ($aLevel['actions'] ?? [] as $sAction => $aAction) {
				if (($aAction['type'] ?? null) !== self::ACTION_CREATE_FROM_THIS || ($aAction['factory']['type'] ?? null) !== 'class') {
					continue;
				}

				$sCreated = (string)$aAction['factory']['value'];
				$aReach[$sCreated][] = $sBrick;
				$aCreates[] = [
					'action' => $sList.'/'.$sAction,
					'class'  => $sCreated,
					'title'  => $sTitle,
					'from'   => $sClass,
					'rules'  => array_values($aAction['rules'] ?? []),
				];
			}

			self::levels($sBrick, $sTitle, $aLevel['levels'] ?? [], $aLevelPath, $aReach, $aLists, $aCreates);
		}
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
