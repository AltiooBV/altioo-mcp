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
}
