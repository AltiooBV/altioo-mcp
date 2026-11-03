<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service;

use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use DBSearch;
use MetaModel;
use UserRights;

/**
 * {@see ObjectAccess} in portal mode: the console's answer, narrowed by the
 * portal's. Each method asks the console's question first and the portal's
 * after it, so the portal can only take away.
 *
 * @internal
 * @since 1.2.0
 */
final class PortalAccess extends ObjectAccess
{
	public function __construct(private readonly PortalPolicy $oPolicy)
	{
	}

	/**
	 * The object, if UserRights and the portal's scopes both let the caller
	 * read it. The change log is never an object here: a portal shows it as
	 * history, which carries the gates on the object a row is about.
	 */
	#[\Override]
	public function Readable(string $sClass, int $iId): ?DBObject
	{
		if (ObjectHistory::IsReserved($sClass)) {
			return null;
		}

		$oObject = parent::Readable($sClass, $iId);
		if ($oObject === null || ObjectHistory::IsReserved(get_class($oObject))) {
			return null;
		}

		return $this->oPolicy->Readable($sClass, $iId) === null ? null : $oObject;
	}

	/**
	 * The query intersected with the portal's read scope on its class.
	 *
	 * DBObjectSearch extends DBSearch; Psalm runs with no iTop checkout to say
	 * so (psalm.xml).
	 *
	 * @psalm-suppress InvalidArgument
	 */
	#[\Override]
	public function Bounded(DBObjectSearch $oSearch): ?DBSearch
	{
		return $this->oPolicy->Bounded($oSearch, $oSearch->GetClass());
	}

	/**
	 * The attributes the portal's view form shows for the object as it is
	 * now. One the form hides is not the caller's to read, in a read or in
	 * the object's history.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the portal's forms cannot be read.
	 */
	#[\Override]
	public function ShownOf(DBObject $oObject): \Closure
	{
		$aFlags = $this->oPolicy->Fields(get_class($oObject), PortalPolicy::MODE_VIEW, $oObject);

		return static fn (string $sAttCode): bool => isset($aFlags[$sAttCode])
			&& ($aFlags[$sAttCode] & OPT_ATT_HIDDEN) !== OPT_ATT_HIDDEN;
	}

	/** When a brick granted to the caller reaches the change log, and a scope covers it. */
	#[\Override]
	public function ShowsHistory(): bool
	{
		return $this->oPolicy->ShowsHistory();
	}

	/**
	 * The view form's attributes, with the name beside each external key and
	 * the object's own name: the id alone says nothing to the person reading
	 * the answer, and the portal shows the name.
	 */
	#[\Override]
	public function VisibleFields(DBObject $oObject): array
	{
		$sClass = get_class($oObject);

		$aFields = MetaModel::IsValidAttCode($sClass, 'friendlyname') ? ['friendlyname'] : [];
		foreach (self::Shown($sClass, $this->oPolicy->Fields($sClass, PortalPolicy::MODE_VIEW, $oObject)) as $sAttCode) {
			$aFields[] = $sAttCode;
			if (MetaModel::GetAttributeDef($sClass, $sAttCode)->IsExternalKey() && MetaModel::IsValidAttCode($sClass, $sAttCode.'_friendlyname')) {
				$aFields[] = $sAttCode.'_friendlyname';
			}
		}

		return array_values(array_unique($aFields));
	}

	/**
	 * None. The first and last operations on an object may be changes to a
	 * field the portal hides; core_object_history gives what the portal
	 * shows of them.
	 */
	#[\Override]
	public function Attribution(DBObject $oObject): ?array
	{
		return null;
	}

	/**
	 * The console's gates, narrowed: a change only where the portal's write
	 * scope holds the object, and no delete or bulk change, which the portal
	 * offers nowhere.
	 */
	#[\Override]
	public function RightsOn(DBObject $oObject, ?DBObjectSet &$oInstanceSet): array
	{
		$aRights = parent::RightsOn($oObject, $oInstanceSet);
		foreach (array_keys($aRights) as $sGate) {
			if ($sGate !== 'modify' || $this->oPolicy->Writable(get_class($oObject), (int)$oObject->GetKey()) === null) {
				$aRights[$sGate] = 'no';
			}
		}

		return $aRights;
	}

	/**
	 * The console's stimuli, less those the portal does not offer on the
	 * object now: its lifecycle denies them, or its write scope does not
	 * hold the object.
	 */
	#[\Override]
	public function StimuliOn(DBObject $oObject, array $aRights, ?DBObjectSet &$oInstanceSet): ?array
	{
		$aStimuli = parent::StimuliOn($oObject, $aRights, $oInstanceSet);
		if ($aStimuli === null) {
			return null;
		}

		$aOffered = $this->oPolicy->StimuliOn($oObject);
		$aStimuli['available'] = array_values(array_filter(
			$aStimuli['available'],
			static fn (array $aStimulus): bool => isset($aOffered[$aStimulus['stimulus']])
		));

		return $aStimuli;
	}

	/**
	 * The attachments the portal lists beside the object, and the fields its
	 * edit form lets the caller set now - empty when it may not change it.
	 */
	#[\Override]
	public function Extras(DBObject $oObject): array
	{
		$sClass = get_class($oObject);

		return [
			'attachments'     => self::AttachmentsOf($oObject),
			'settable_fields' => $this->oPolicy->Writable($sClass, (int)$oObject->GetKey()) === null
				? []
				: self::Settable($sClass, $this->oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject)),
		];
	}

	/**
	 * The attributes of a form the user is shown: not hidden by it, and not
	 * refused by UserRights.
	 *
	 * @param array<string, int> $aFlags As PortalPolicy::Fields() answers.
	 *
	 * @return array<int, string>
	 */
	public static function Shown(string $sClass, array $aFlags): array
	{
		$aShown = [];
		foreach ($aFlags as $sAttCode => $iFlags) {
			if (($iFlags & OPT_ATT_HIDDEN) === OPT_ATT_HIDDEN
				|| UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
				continue;
			}
			$aShown[] = $sAttCode;
		}

		return $aShown;
	}

	/**
	 * The attributes of a form the user may set: shown, and neither read-only
	 * nor computed from another field.
	 *
	 * @param array<string, int> $aFlags As PortalPolicy::Fields() answers.
	 *
	 * @return array<int, string>
	 */
	public static function Settable(string $sClass, array $aFlags): array
	{
		return array_values(array_filter(
			self::Shown($sClass, $aFlags),
			static fn (string $sAttCode): bool => ($aFlags[$sAttCode] & (OPT_ATT_READONLY | OPT_ATT_SLAVE)) === 0
		));
	}

	/**
	 * The attachments the portal lists beside the object: none unless its
	 * forms show them. Listed whatever their own scope, as the portal lists
	 * them by host object.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function AttachmentsOf(DBObject $oObject): array
	{
		if (!PortalPolicy::ShowsAttachments($oObject)) {
			return [];
		}

		$oSearch = DBObjectSearch::FromOQL('SELECT Attachment WHERE item_class = :class AND item_id = :item_id');
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, ['id' => true], ['class' => get_class($oObject), 'item_id' => $oObject->GetKey()]);

		$aAttachments = [];
		while ($oAttachment = $oSet->Fetch()) {
			$oDocument = $oAttachment->Get('contents');
			$aAttachments[] = [
				'id'       => (int)$oAttachment->GetKey(),
				'filename' => $oDocument instanceof \ormDocument ? $oDocument->GetFileName() : null,
				'mimetype' => $oDocument instanceof \ormDocument ? $oDocument->GetMimeType() : null,
				'size'     => $oDocument instanceof \ormDocument ? $oDocument->GetSize() : null,
			];
		}

		return $aAttachments;
	}

	/** What the portal's scopes do not let the caller read. */
	#[\Override]
	public function Hides(string $sClass, int $iId): bool
	{
		return ObjectHistory::IsReserved($sClass) || $this->oPolicy->Readable($sClass, $iId) === null;
	}

	/** An object outside the portal's write scope, or the change log, which nothing here writes. */
	#[\Override]
	public function RefusalToChange(DBObject $oObject): ?string
	{
		$sClass = get_class($oObject);
		$iId = (int)$oObject->GetKey();

		return $this->oPolicy->Writable($sClass, $iId) === null
			? "The portal lets you read {$sClass} {$iId} but not change it."
			: null;
	}

	/** A stimulus the portal's lifecycle does not offer on the object in its state. */
	#[\Override]
	public function RefusalToApply(DBObject $oObject, string $sStimulus): ?string
	{
		$aOffered = $this->oPolicy->StimuliOn($oObject);
		if (isset($aOffered[$sStimulus])) {
			return null;
		}

		$sClass = get_class($oObject);
		$sState = (string)$oObject->GetState();

		// The code is the client's text: quoted only when it is shaped like one.
		return (\Altioo\iTop\Extension\MCP\Helper\MCPHelper::LooksLikeClassName($sStimulus) ? "The portal does not offer '{$sStimulus}'" : 'The portal does not offer that stimulus')
			." on {$sClass} {$oObject->GetKey()} in state '{$sState}'. "
			.($aOffered === [] ? 'It offers none in this state.' : 'It offers: '.implode(', ', array_keys($aOffered)).'.');
	}

	/**
	 * A field the portal's edit form does not leave settable - or, for a
	 * transition, one its target state does not prompt for either. Named,
	 * each with why, before any value is converted: a field the form hides or
	 * shows read-only is not a value the user could have typed in.
	 */
	#[\Override]
	public function RefusalToSet(DBObject $oObject, array $aFields, string $sStimulus = ''): ?string
	{
		$sClass = get_class($oObject);
		$aSettable = self::Settable($sClass, $this->oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject));
		$aAlso = $sStimulus === '' ? [] : self::Prompted($oObject, $sStimulus);
		$sForm = $sStimulus === '' ? 'edit' : 'transition or edit';

		$aIssues = [];
		foreach (array_keys($aFields) as $sAttCode) {
			if (!in_array((string)$sAttCode, $aSettable, true) && !in_array((string)$sAttCode, $aAlso, true)) {
				$aIssues[] = "'{$sAttCode}' is not a field the portal's {$sForm} form lets you set";
			}
		}

		return $aIssues === []
			? null
			: implode('; ', $aIssues).'. Fields you may set: '.(implode(', ', array_merge($aSettable, $aAlso)) ?: 'none').'.';
	}

	/**
	 * What the portal's pickers would have offered: an object the caller can
	 * read. An external key's target, a 1-n set's items, an n-n set's remote
	 * objects.
	 */
	#[\Override]
	public function RefusalsForReach(DBObject $oObject, array $aAttCodes): array
	{
		$aIssues = [];
		foreach ($aAttCodes as $sAttCode) {
			$sOutOfReach = $this->outOfReach($oObject, $sAttCode);
			if ($sOutOfReach !== null) {
				$aIssues[$sAttCode] = $sOutOfReach;
			}
		}

		return $aIssues;
	}

	/**
	 * The fields a transition's target state prompts for: those it must
	 * change or prompt for, and those it makes mandatory that are empty.
	 *
	 * @return array<int, string>
	 */
	public static function Prompted(DBObject $oObject, string $sStimulus): array
	{
		$sClass = get_class($oObject);
		$aTransitions = MetaModel::EnumTransitions($sClass, (string)$oObject->GetState());
		$sTarget = (string)($aTransitions[$sStimulus]['target_state'] ?? '');
		$aStates = MetaModel::EnumStates($sClass);

		/** @var array<string, int> $aFlags */
		$aFlags = array_map('intval', $aStates[$sTarget]['attribute_list'] ?? []);

		$aPrompted = [];
		foreach ($aFlags as $sAttCode => $iFlags) {
			if (($iFlags & (OPT_ATT_MUSTCHANGE | OPT_ATT_MUSTPROMPT)) !== 0
				|| (($iFlags & OPT_ATT_MANDATORY) !== 0 && $oObject->Get($sAttCode) == '')) {
				$aPrompted[] = $sAttCode;
			}
		}

		return $aPrompted;
	}

	private function outOfReach(DBObject $oObject, string $sAttCode): ?string
	{
		$sClass = get_class($oObject);
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);

		if ($oAttDef->IsExternalKey()) {
			$iTarget = (int)$oObject->Get($sAttCode);
			if ($iTarget > 0 && $this->oPolicy->Readable($oAttDef->GetTargetClass(), $iTarget) === null) {
				return sprintf("'%s' points at a %s the portal does not show you.", $sAttCode, $oAttDef->GetTargetClass());
			}

			return null;
		}

		if (!$oAttDef->IsLinkSet()) {
			return null;
		}

		// An n-n set points through its link class at the remote class; a 1-n
		// set holds the objects themselves.
		$sExtKey = $oAttDef instanceof \AttributeLinkedSetIndirect ? $oAttDef->GetExtKeyToRemote() : null;
		$oSet = $oObject->Get($sAttCode);
		if (!$oSet instanceof \ormLinkSet) {
			return null;
		}
		foreach ($oSet as $oLink) {
			if ($sExtKey === null) {
				if (!$oLink->IsNew() && $this->oPolicy->Readable(get_class($oLink), (int)$oLink->GetKey()) === null) {
					return sprintf("'%s' holds an item the portal does not show you.", $sAttCode);
				}
				continue;
			}

			$sRemote = MetaModel::GetAttributeDef(get_class($oLink), $sExtKey)->GetTargetClass();
			$iRemote = (int)$oLink->Get($sExtKey);
			if ($iRemote > 0 && $this->oPolicy->Readable($sRemote, $iRemote) === null) {
				return sprintf("'%s' links a %s the portal does not show you.", $sAttCode, $sRemote);
			}
		}

		return null;
	}
}
