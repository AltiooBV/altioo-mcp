<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

/**
 * What this user's portal offers, read from the portal's own configuration.
 *
 * What core_class_list and core_class_schema answer in portal mode
 * (doc/adr/0006-one-tool-surface-the-portal-is-a-policy.md). On the console
 * they describe the datamodel as UserRights sees it, which for a "Portal
 * user" is every bizmodel class; in portal mode they describe the portal: the classes a brick granted to
 * the user reaches, whether its scopes let them read or change any of each,
 * the fields its forms show in each mode, the stimuli its lifecycle allows,
 * and its lists and create actions. A custom portal exposing a custom class
 * is described the same way, which is how a model learns it at runtime.
 *
 * Without a class, the overview: every offered class without its fields,
 * because a field list per mode per class is most of the payload and a model
 * needs it for one class at a time. With one, that class in full.
 *
 * Nothing here reads an object. The fields of a given object follow its
 * state and any condition in the layout, so those listed are a new object's.
 *
 * @internal
 * @since 1.2.0
 */
final class PortalDescription
{
	/** What the class tools say they answer in portal mode: how to read the overview and a class. */
	public const READING = 'Everything comes from the portal\'s own configuration, so a class missing here is one the portal does not offer this user, whatever the datamodel holds; and every portal is configured differently, so read the ids here rather than assuming any. '
		.'Choose a list or a create action by its title and class: those say what it is. A list with a parent is a browse level under that list, narrowed with core_portal_list parent_id; a create action with a from_list starts from a row of that list. '
		.'A create action with class_chosen_by_portal has no class yet: the portal picks it from the object chosen to start from (in iTop\'s stock portal, a service subcategory). A class the user may create lists such actions in create_chosen_by_portal, and core_object_create runs one. '
		.'Each stimulus says the states it applies in (from_states). '
		.'history says whether the portal shows history: when true, core_object_history gives the history of an object it shows you, limited to the fields its view form shows. '
		.'A field marked read_only is shown but cannot be set; mandatory ones must be. A form marked default is the class\'s "details" list, used because the portal declares no form for it. attachments says whether the portal shows an object\'s attachments beside its forms. '
		.'The fields of an existing object follow its state, so those listed are a new object\'s: core_object_get gives one object\'s fields, and what may be done to it now.';

	/**
	 * One class in full, fields included; refused alike whether it is
	 * unknown or not offered, so this cannot map out the classes the portal
	 * withholds. A value that is no class name is not quoted back
	 * (MCPHelper::LooksLikeClassName()).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ToolCallException When the portal does not offer the class.
	 * @throws MCPPortalException When the portal cannot be read.
	 */
	public static function Of(PortalPolicy $oPolicy, string $sClass): array
	{
		if (!in_array($sClass, self::offeredClasses($oPolicy), true)) {
			if (!MCPHelper::LooksLikeClassName($sClass)) {
				throw new ToolCallException('That is not a class name. Call core_class_list for the classes this portal offers.');
			}
			throw new ToolCallException(sprintf(
				"The portal offers no class '%s'. Call core_class_list for the ones it offers.",
				$sClass
			));
		}

		return self::full($oPolicy, $sClass);
	}

	/**
	 * Every offered class without its fields, the lists, the create actions
	 * and whether the portal shows history.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MCPPortalException When the portal cannot be read.
	 */
	public static function Overview(PortalPolicy $oPolicy): array
	{
		$aClasses = [];
		foreach (self::offeredClasses($oPolicy) as $sClass) {
			$aClasses[] = self::summary($oPolicy, $sClass);
		}

		return [
			'portal'         => $oPolicy->Portal(),
			'classes'        => $aClasses,
			'lists'          => self::readableLists($oPolicy),
			'create_actions' => self::servedCreateActions($oPolicy),
			'history'        => $oPolicy->ShowsHistory(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function full(PortalPolicy $oPolicy, string $sClass): array
	{
		$aClass = self::summary($oPolicy, $sClass);

		$aModes = [PortalPolicy::MODE_VIEW => true];
		if ($aClass['edit']) {
			$aModes[PortalPolicy::MODE_EDIT] = true;
		}
		if ($aClass['create'] !== [] || $aClass['create_chosen_by_portal'] !== []) {
			$aModes[PortalPolicy::MODE_CREATE] = true;
		}

		$aForms = [];
		foreach (array_keys($aModes) as $sMode) {
			$sForm = $oPolicy->FormOf($sClass, $sMode);
			$aFields = [];
			foreach ($oPolicy->Fields($sClass, $sMode) as $sAttCode => $iFlags) {
				$aField = self::field($sClass, $sAttCode, $iFlags, $sMode);
				if ($aField !== null) {
					$aFields[] = $aField;
				}
			}

			$aForms[$sMode] = [
				'form'    => $sForm ?? 'default',
				'default' => $sForm === null,
				'fields'  => $aFields,
			];
		}

		return ['portal' => $oPolicy->Portal()] + $aClass + [
			// Shown beside every form but a transition's, as a plugin field
			// rather than an attribute; listed by core_object_get.
			'attachments' => PortalPolicy::ShowsAttachments(MetaModel::NewObject($sClass)),
			'forms'       => $aForms,
		];
	}

	/**
	 * A class's place in the portal, without its fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary(PortalPolicy $oPolicy, string $sClass): array
	{
		$aCreate = [];
		$aChosen = [];
		foreach (self::servedCreateActions($oPolicy) as $aAction) {
			if ($aAction['class'] === $sClass) {
				$aCreate[] = $aAction['action'];
			} elseif ($aAction['class'] === null) {
				$aChosen[] = $aAction['action'];
			}
		}

		// Which class such an action creates is known only once the object it
		// starts from is picked, so it is listed against every class the user
		// may create at all - never as the class it creates.
		if ($aChosen !== [] && !$oPolicy->MayCreate($sClass)) {
			$aChosen = [];
		}

		$aLabels = $oPolicy->Stimuli($sClass);
		$aFrom = PortalPolicy::StatesOf($sClass, array_keys($aLabels));
		$aStimuli = [];
		foreach ($aLabels as $sCode => $sLabel) {
			$aStimuli[] = ['code' => $sCode, 'label' => $sLabel, 'from_states' => $aFrom[$sCode] ?? []];
		}

		return [
			'class'       => $sClass,
			'label'       => MetaModel::GetName($sClass),
			'description' => MetaModel::GetClassDescription($sClass),
			'reached_by'  => $oPolicy->ReachedBy($sClass),
			'read'        => true,
			'edit'        => $oPolicy->WriteSet($sClass) !== null,
			// Whether a file can be added at all: the class takes attachments and may be edited. A state or an
			// edit form can still refuse one object; core_object_get says may_attach for that.
			'attach'      => $oPolicy->WriteSet($sClass) !== null && PortalPolicy::ShowsAttachments(MetaModel::NewObject($sClass)),
			'create'      => $aCreate,
			'create_chosen_by_portal' => $aChosen,
			'stimuli'     => $aStimuli,
		];
	}


	/**
	 * The classes offered: every concrete class a granted brick reaches,
	 * through itself or a parent, that the scopes let the user read.
	 *
	 * Both conditions, because they answer different things
	 * (doc/adr/0001-portal-mode-bricks-bound-discovery.md): a class with a
	 * read scope that no brick offers is absent, and so is a class a brick
	 * reaches that the scopes refuse - in the stock portal, a Change or a
	 * Problem, which the Ticket scope pins out by finalclass. Those are the
	 * agents' classes, and an end user never sees one in the portal.
	 *
	 * The change log is not a class offered here: a portal reaching it shows
	 * history, which the overview says and core_object_history serves.
	 *
	 * @return array<int, string>
	 */
	private static function offeredClasses(PortalPolicy $oPolicy): array
	{
		$aClasses = [];
		foreach (array_keys($oPolicy->Reach()) as $sRoot) {
			if (!MetaModel::IsValidClass($sRoot)) {
				continue;
			}

			foreach (MetaModel::EnumChildClasses($sRoot, ENUM_CHILD_CLASSES_ALL) as $sClass) {
				if (!isset($aClasses[$sClass]) && !MetaModel::IsAbstract($sClass) && !ObjectHistory::IsReserved($sClass)
					&& $oPolicy->ReachedBy($sClass) !== [] && $oPolicy->ReadSet($sClass) !== null) {
					$aClasses[$sClass] = true;
				}
			}
		}

		$aNames = array_keys($aClasses);
		sort($aNames);

		return $aNames;
	}

	/**
	 * The lists whose class the user may read any of. One the scopes refuse
	 * would list nothing, so it is not offered; nor is one of the change log,
	 * which core_object_history serves.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function readableLists(PortalPolicy $oPolicy): array
	{
		return array_values(array_filter(
			$oPolicy->Lists(),
			static fn (array $aList): bool => !ObjectHistory::IsReserved((string)$aList['class'])
				&& $oPolicy->ReadSet((string)$aList['class']) !== null
		));
	}

	/**
	 * The create actions whose class the user may create.
	 *
	 * A creation needs the class's write scope and UserRights' create grant,
	 * as the portal's own create page checks. The action rules each one
	 * names are what will preset the new object; they are reported, and run
	 * on the server, never by the client.
	 *
	 * An action whose class the portal chooses with a method, from the
	 * object the user picks, has no class to check yet: it is offered when
	 * the user may read what it starts from, with class null and
	 * class_chosen_by_portal. The method is the portal's business and is not
	 * reported.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function servedCreateActions(PortalPolicy $oPolicy): array
	{
		$aServed = [];
		foreach ($oPolicy->CreateActions() as $aAction) {
			$sClass = $aAction['class'];
			if ($sClass === null) {
				if ($aAction['from'] === null || $oPolicy->ReadSet((string)$aAction['from']) === null) {
					continue;
				}
			} elseif (!$oPolicy->MayCreate($sClass)) {
				continue;
			}

			$aServed[] = [
				'action'                 => $aAction['action'],
				'class'                  => $sClass,
				'class_chosen_by_portal' => $sClass === null,
				'title'                  => $aAction['title'],
				'from'                   => $aAction['from'],
				// The list from_id is picked from, for core_object_create.
				'from_list'              => $aAction['from_list'] ?? null,
				'rules'                  => $aAction['rules'],
			];
		}

		return $aServed;
	}

	/**
	 * One field of a form, or null when it is hidden from the user.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function field(string $sClass, string $sAttCode, int $iFlags, string $sMode): ?array
	{
		if (($iFlags & OPT_ATT_HIDDEN) === OPT_ATT_HIDDEN
			|| UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
			return null;
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		$aField = [
			'attribute' => $sAttCode,
			'label'     => MetaModel::GetLabel($sClass, $sAttCode),
			'type'      => get_class($oAttDef),
		];

		if ($sMode !== PortalPolicy::MODE_VIEW) {
			$aField['read_only'] = ($iFlags & (OPT_ATT_READONLY | OPT_ATT_SLAVE)) !== 0;
			$aField['mandatory'] = ($iFlags & OPT_ATT_MANDATORY) === OPT_ATT_MANDATORY;
		}

		if ($oAttDef->IsExternalKey()) {
			$aField['target_class'] = $oAttDef->GetTargetClass();
		} elseif ($oAttDef->IsLinkSet()) {
			$aField['linked_class'] = $oAttDef->GetLinkedClass();
		} elseif ($oAttDef->IsScalar()) {
			$aValues = $oAttDef->GetAllowedValues();
			if (is_array($aValues) && $aValues !== []) {
				$aField['values'] = (object)array_slice($aValues, 0, DatamodelReader::MAX_ALLOWED_VALUES, true);
				if (count($aValues) > DatamodelReader::MAX_ALLOWED_VALUES) {
					$aField['values_truncated'] = true;
				}
			}
		}

		return $aField;
	}
}
