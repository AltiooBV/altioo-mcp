<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Combodo\iTop\Application\TwigBase\Twig\Extension;
use Combodo\iTop\Portal\Form\ObjectFormManager;
use Combodo\iTop\Portal\Helper\ApplicationHelper;
use DBObject;
use MetaModel;

/**
 * The fields one portal's forms give a class, in each mode.
 *
 * The portal's forms, merged by the portal's own ObjectFormManager::PrepareFields().
 * Which of a class's objects the user may reach is PortalPolicy's to answer; this
 * answers only what the portal's forms say of a class.
 *
 * @internal
 * @since 2.0.0
 */
final class PortalForms
{
	/**
	 * @param array<string, array<string, mixed>> $aForms The portal's forms, by class then mode.
	 */
	public function __construct(
		private readonly array $aForms,
		private readonly string $sPortal,
	)
	{
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
		$oObject ??= MetaModel::NewObject($sClass);

		return $this->prepared(
			$sClass,
			$sMode,
			self::WithReadOnlyHonoured(ApplicationHelper::GetLoadedFormFromClass($this->aForms, $sClass, $sMode)),
			$oObject,
			$sMode
		);
	}

	/**
	 * The fields the portal's transition form shows when a user applies a stimulus to an object, with the flags it gives each.
	 *
	 * Built as the portal's own ObjectController::ApplyStimulusAction() builds it: the form the portal declares for that
	 * stimulus on the class (or a parent), else its default one - a list with no fields of its own, so that the
	 * datamodel's target-state flags decide - plus the stimulus code, then merged by the portal's own PrepareFields() in
	 * its transition mode. A field the declared form shows read-only, or hides, is not a field the user could have changed
	 * in the transition, whatever the target state prompts for (review 2026-11-18, RT-11: `team_id` on `ev_reopen`).
	 *
	 * @return array<string, int>
	 * @throws MCPPortalException When the form cannot be prepared.
	 */
	public function TransitionFields(string $sClass, string $sStimulus, DBObject $oObject): array
	{
		try {
			$aStimuliForms = ApplicationHelper::GetLoadedFormFromClass($this->aForms, $sClass, ObjectFormManager::ENUM_MODE_APPLY_STIMULUS);
			$aForm = is_array($aStimuliForms) && isset($aStimuliForms[$sStimulus]) && is_array($aStimuliForms[$sStimulus])
				? $aStimuliForms[$sStimulus]
				: ['id' => 'apply-stimulus', 'type' => 'custom_list', 'fields' => [], 'layout' => null];
			$aForm['stimulus_code'] = $sStimulus;
		} catch (\Throwable $oException) {
			throw new MCPPortalException(ErrorReport::OpaqueFailure(
				sprintf("Could not read the transition form of %s on %s in portal '%s'", $sStimulus, $sClass, $this->sPortal),
				$oException
			), 0, $oException);
		}

		return $this->prepared($sClass, ObjectFormManager::ENUM_MODE_APPLY_STIMULUS, self::WithReadOnlyHonoured($aForm), $oObject, $sStimulus);
	}

	/**
	 * A form definition run through the portal's own ObjectFormManager, and the flags it came to.
	 *
	 * @param array<string, mixed> $aForm
	 *
	 * @return array<string, int>
	 */
	private function prepared(string $sClass, string $sMode, array $aForm, DBObject $oObject, string $sNamed): array
	{
		try {
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
			throw new MCPPortalException(ErrorReport::OpaqueFailure(
				sprintf("Could not read the '%s' form of %s in portal '%s'", $sNamed, $sClass, $this->sPortal),
				$oException
			), 0, $oException);
		}

		// A layout can carry plugin fields (attachments) that are not
		// attributes; nothing here knows how to serve those.
		$aFields = [];
		foreach ($aFlags as $sAttCode => $iFlags) {
			if (is_string($sAttCode) && MetaModel::IsValidAttCode($sClass, $sAttCode) && !ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode)) {
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
}
