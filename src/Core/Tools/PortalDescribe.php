<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use UserRights;

/**
 * What this user's portal offers, read from the portal's own configuration.
 *
 * Portal mode's replacement for the schema tools. Those describe the
 * datamodel as UserRights sees it, which for a "Portal user" is every
 * bizmodel class; this describes the portal: the classes a brick granted to
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
 * @since 1.2.0
 */
class PortalDescribe extends AbstractMCPTool implements PortalAware
{
	public function getNamespace(): string
	{
		return 'core';
	}

	/**
	 * The schema tools' group, because in portal mode this is what replaces
	 * them: an operator or a token narrowed to "datamodel" means the
	 * description of what can be worked with, whichever user is asking.
	 */
	public function getToolset(): string
	{
		return 'datamodel';
	}

	protected function defaultTitle(): string
	{
		return 'Describe the Portal';
	}

	/**
	 * Served in portal mode only. A console user has the schema tools, and an
	 * answer about a portal they are not in would describe someone else's
	 * rights.
	 */
	public function isAvailable(): bool
	{
		return PortalMode::Current() !== null;
	}

	public function getDescription(): ?string
	{
		return 'Describe what this user\'s iTop portal offers them: the classes they can work with, and for each whether they may read and change objects of it, how objects are created, the fields shown when viewing, editing and creating one, and the stimuli (state changes) they may apply. '
			.'Everything comes from the portal\'s own configuration, so a class missing here is one the portal does not offer this user, whatever the datamodel holds. '
			.'Without "class" the answer is an overview with no fields; pass a class from it to get that class\'s fields. '
			.'A create action with class_chosen_by_portal has no class yet: the portal picks it from the object chosen to start from, a service subcategory in the stock portal. '
			.'A field marked read_only is shown but cannot be set; mandatory ones must be. A form marked default is the class\'s "details" list, used because the portal declares no form for it. '
			.'The fields of an existing object follow its state, so those listed are a new object\'s.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Describe the portal',
			true,   // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class' => [
					'type'        => 'string',
					'description' => 'A class from the overview, e.g. UserRequest, to get its fields in each mode. Empty (the default) returns the overview.',
					'default'     => '',
				],
			],
			'required' => [],
		];
	}

	/**
	 * @param string $class Optional class to describe in full, fields included
	 *
	 * @return mixed The portal id, its classes, lists and create actions; or one class in full
	 * @throws ToolCallException if the class is not one the portal offers, or the portal cannot be read
	 */
	public static function execute(string $class = ''): mixed
	{
		$sClass = trim($class);

		try {
			$oPolicy = PortalPolicy::ForCurrentRequest();

			if ($sClass === '') {
				return ToolOutput::Json(self::overview($oPolicy));
			}

			// Unknown and not offered answer alike, so this cannot map out
			// the classes the portal withholds.
			if (!in_array($sClass, self::offeredClasses($oPolicy), true)) {
				throw new ToolCallException(sprintf(
					"The portal offers no class '%s'. Call this tool without a class for the ones it offers.",
					$sClass
				));
			}

			return ToolOutput::Json(self::full($oPolicy, $sClass));
		} catch (MCPPortalException $oException) {
			// Already logged where it was thrown, and written for the caller.
			throw new ToolCallException($oException->getMessage());
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function overview(PortalPolicy $oPolicy): array
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
		if ($aClass['create'] !== []) {
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

		return ['portal' => $oPolicy->Portal()] + $aClass + ['forms' => $aForms];
	}

	/**
	 * A class's place in the portal, without its fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary(PortalPolicy $oPolicy, string $sClass): array
	{
		$aCreate = [];
		foreach (self::servedCreateActions($oPolicy) as $aAction) {
			if ($aAction['class'] === $sClass) {
				$aCreate[] = $aAction['action'];
			}
		}

		$aStimuli = [];
		foreach ($oPolicy->Stimuli($sClass) as $sCode => $sLabel) {
			$aStimuli[] = ['code' => $sCode, 'label' => $sLabel];
		}

		return [
			'class'       => $sClass,
			'label'       => MetaModel::GetName($sClass),
			'description' => MetaModel::GetClassDescription($sClass),
			'reached_by'  => $oPolicy->ReachedBy($sClass),
			'read'        => true,
			'edit'        => $oPolicy->WriteSet($sClass) !== null,
			'create'      => $aCreate,
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
				if (!isset($aClasses[$sClass]) && !MetaModel::IsAbstract($sClass)
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
	 * would list nothing, so it is not offered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function readableLists(PortalPolicy $oPolicy): array
	{
		return array_values(array_filter(
			$oPolicy->Lists(),
			static fn (array $aList): bool => $oPolicy->ReadSet((string)$aList['class']) !== null
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
			} elseif (!MetaModel::IsValidClass($sClass)
				|| UserRights::IsActionAllowed($sClass, UR_ACTION_CREATE) === UR_ALLOWED_NO
				|| $oPolicy->WriteSet($sClass) === null) {
				continue;
			}

			$aServed[] = [
				'action'                 => $aAction['action'],
				'class'                  => $sClass,
				'class_chosen_by_portal' => $sClass === null,
				'title'                  => $aAction['title'],
				'from'                   => $aAction['from'],
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
