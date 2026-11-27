<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Portal;

use Altioo\iTop\Extension\MCP\Helper\CoreElement;
use Altioo\iTop\Extension\MCP\Helper\ObjectId;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Read\StoredValue;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use DBSearch;
use MetaModel;
use UserRights;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;

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
	 * The access of the portal this request is served in.
	 *
	 * What RequestScope::EnterPortal() has ObjectAccess build on each call to
	 * Current(), so a portal that cannot be read refuses the tool that needs it
	 * and not the connection.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the request is not in portal mode, or the portal cannot be read.
	 */
	public static function ForCurrentRequest(): self
	{
		return new self(PortalPolicy::ForCurrentRequest());
	}

	/**
	 * Whether an element may be served in portal mode at all, before any of
	 * the filters every element goes through.
	 *
	 * Two things are needed. The element declares that it enforces the
	 * portal's rules ({@see PortalAware}), and either it is this module's own
	 * (defined in a file under this module's src/Core/, {@see CoreElement::IsOwn()};
	 * declaring the Core\ namespace is not enough) or the operator listed it in mcp_portal_extensions, by its
	 * identifier or its class name (ADR 0017). The declaration is not
	 * verifiable by the server and the listing is the operator's consent, so
	 * neither is enough alone: an element that is listed but does not declare
	 * is a console element, and one that declares but is not listed is not
	 * served. A setting that is not a list lists nothing.
	 *
	 * @param string                  $sIdentifier Qualified name or URI the registry awarded the element, as the operator may have listed it.
	 * @param array<int, string>|null $aListed     What mcp_portal_extensions lists; null reads the setting. For tests, which have no configuration.
	 *
	 * @since 1.2.0
	 */
	public static function Serves(object $oElement, string $sIdentifier = '', ?array $aListed = null): bool
	{
		if (!$oElement instanceof PortalAware) {
			return false;
		}

		if (CoreElement::IsOwn($oElement)) {
			return true;
		}

		$aListed ??= Settings::GetPortalExtensions();

		// An element registered under an identifier it took with overrides() is
		// listed by its class name only: the operator's consent went to a name
		// whose holder was somebody else, and is not handed on to whatever
		// replaces it (ADR 0017, amended 2026-11-12).
		$bTookTheName = method_exists($oElement, 'overrides') && $oElement->overrides() !== null;

		return (!$bTookTheName && $sIdentifier !== '' && in_array($sIdentifier, $aListed, true)) || in_array(get_class($oElement), $aListed, true);
	}

	/**
	 * The object, if UserRights and the portal's scopes both let the caller
	 * read it. The change log is never an object here: a portal shows it as
	 * history, which carries the gates on the object a row is about.
	 */
	#[\Override]
	public function Readable(string $sClass, int $iId): ?DBObject
	{
		if (ChangeLogReservation::IsReserved($sClass)) {
			return null;
		}

		$oObject = parent::Readable($sClass, $iId);
		if ($oObject === null || ChangeLogReservation::IsReserved(get_class($oObject))) {
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
		$sClass = get_class($oObject);
		$aFlags = $this->oPolicy->Fields($sClass, PortalPolicy::MODE_VIEW, $oObject);

		return static fn (string $sAttCode): bool => isset($aFlags[$sAttCode])
			&& ($aFlags[$sAttCode] & OPT_ATT_HIDDEN) !== OPT_ATT_HIDDEN
			&& !ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode);
	}

	/**
	 * Only what the portal's view form shows of the object, and what the
	 * caller sent: a value they supplied is theirs already. The rest -
	 * presets, defaults, computed and lifecycle-filled attributes - is shown
	 * on the same terms as a read, never because a write happened to set it.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the portal's forms cannot be read.
	 */
	#[\Override]
	public function Disclosed(DBObject $oObject, array $aChanges, array $aSupplied): array
	{
		$fShown = $this->ShownOf($oObject);
		$aSent  = array_flip($aSupplied);

		return array_filter(
			$aChanges,
			static fn (string $sAttCode): bool => isset($aSent[$sAttCode]) || $fShown($sAttCode),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * The rows of a link set as the portal draws them, and no others.
	 *
	 * The portal hides a row whose remote object its scopes do not let the
	 * user read (ObjectFormManager::PrepareFields(), "Filtering links regarding
	 * scopes", and BsLinkedSetFieldRenderer::PrepareItems(), which skips them)
	 * and draws the columns it lists for the remote class. `Portal user` reads
	 * every class through UserRights, so a link set serialised from the ORM
	 * would hand over what only the scope withholds. A row is shown here when
	 * the remote object is readable as {@see Readable()} would say - the 1-n
	 * set's row itself, the n-n set's object behind the link's key to it - and
	 * carries only the portal's list columns of that class, within what its
	 * view form shows of the object and what the attribute rights allow. A blob
	 * is never inlined, and the rows the caller supplied on this very call
	 * (an object not yet written) are theirs to see.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the portal's forms cannot be read.
	 */
	#[\Override]
	public function LinkedRows(DBObject $oObject, string $sAttCode, ?int $iMost = null): ?array
	{
		[$aLinks, $oAttDef, $sRemoteClass] = $this->linksOf($oObject, $sAttCode);
		if ($oAttDef === null) {
			return [[], 0];
		}

		$aRows = [];
		$iShown = 0;
		$oInstanceSet = null;
		foreach ($aLinks as $aLink) {
			if (!$aLink['shown']) {
				continue;
			}
			++$iShown;
			if ($iMost !== null && count($aRows) >= $iMost) {
				continue;
			}

			$aRows[] = $oAttDef instanceof \AttributeLinkedSetIndirect
				? $this->indirectRow($oObject, $oAttDef, $aLink['link'], $aLink['remote'], $oInstanceSet)
				: $this->directRow($oAttDef, $aLink['link'], $oInstanceSet);
		}

		return [$aRows, $iShown];
	}

	/**
	 * Every link the portal hides from the user in a set the caller sends the
	 * complete list of is added to that list, so that the write changes what the
	 * user can see and keeps the rest, as the portal's own form does: it submits
	 * the changes to the rows it showed, never the rows it did not.
	 *
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the portal's forms cannot be read.
	 */
	#[\Override]
	public function KeepHiddenLinks(DBObject $oObject, string $sAttCode, DBObjectSet $oNewSet): void
	{
		[$aLinks, $oAttDef] = $this->linksOf($oObject, $sAttCode);
		if ($oAttDef === null) {
			return;
		}

		foreach ($aLinks as $aLink) {
			if (!$aLink['shown'] && !$aLink['link']->IsNew()) {
				$oNewSet->AddObject($aLink['link']);
			}
		}
	}

	/**
	 * The links of a set as it stands now, each with the remote object the
	 * portal would ask about and whether the user is shown it. One query per
	 * class and chunk of ids, not per row.
	 *
	 * @return array{0: array<int, array{link: DBObject, remote: DBObject|null, shown: bool}>, 1: \AttributeLinkedSet|null, 2: string} The links, the attribute (null if it is no link set), the remote class.
	 */
	private function linksOf(DBObject $oObject, string $sAttCode): array
	{
		$oAttDef = MetaModel::GetAttributeDef(get_class($oObject), $sAttCode);
		$oSet = $oObject->Get($sAttCode);
		if (!$oAttDef->IsLinkSet() || !$oSet instanceof \ormLinkSet) {
			return [[], null, ''];
		}

		$sKeyToRemote = $oAttDef instanceof \AttributeLinkedSetIndirect ? $oAttDef->GetExtKeyToRemote() : null;
		$sRemoteClass = $sKeyToRemote === null
			? $oAttDef->GetLinkedClass()
			: MetaModel::GetAttributeDef($oAttDef->GetLinkedClass(), $sKeyToRemote)->GetTargetClass();

		$aLinks = [];
		$oSet->Rewind();
		while ($oLink = $oSet->Fetch()) {
			$aLinks[] = $oLink;
		}
		$oSet->Rewind();

		$aIds = [];
		foreach ($aLinks as $oLink) {
			$aIds[] = $sKeyToRemote === null ? (int)$oLink->GetKey() : (int)$oLink->Get($sKeyToRemote);
		}
		$aReadable = $this->oPolicy->ReadableObjects($sRemoteClass, $aIds);

		$aClassified = [];
		foreach ($aLinks as $iAt => $oLink) {
			$iRemote = $aIds[$iAt];
			$oRemote = $aReadable[$iRemote] ?? null;

			// An object of this very call, which nothing else knows, is the caller's own; a link
			// with no remote object behind it holds nothing to show.
			$bNew = $sKeyToRemote === null && $oLink->IsNew();
			$bShown = $bNew || ($oRemote !== null && $this->consoleReads($oRemote, $sRemoteClass));

			$aClassified[] = ['link' => $oLink, 'remote' => $oRemote, 'shown' => $bShown && ($sKeyToRemote === null || $iRemote > 0)];
		}

		return [$aClassified, $oAttDef, $sRemoteClass];
	}

	/**
	 * The questions {@see Readable()} asks of the console once the object is in
	 * hand: the class it is declared as and the one it is, on the object, and
	 * the operator's exclusions.
	 */
	private function consoleReads(DBObject $oRemote, string $sDeclared): bool
	{
		$sFinal = get_class($oRemote);
		$oOne = DBObjectSet::FromObject($oRemote);

		return UserRights::IsActionAllowed($sDeclared, UR_ACTION_READ, $oOne)
			&& UserRights::IsActionAllowed($sFinal, UR_ACTION_READ)
			&& UserRights::IsActionAllowed($sFinal, UR_ACTION_READ, $oOne)
			&& !ExclusionPolicy::ExcludesClass($sFinal)
			&& !ExclusionSearches::IsFiledUnderExcluded($oRemote);
	}

	/**
	 * A 1-n set's row: the object itself, with the columns the portal lists
	 * for the class of the set.
	 *
	 * @return array<string, mixed>
	 */
	private function directRow(\AttributeLinkedSet $oAttDef, DBObject $oRow, ?DBObjectSet &$oInstanceSet): array
	{
		$sRowClass = get_class($oRow);
		$sLinkedClass = $oAttDef->GetLinkedClass();
		$fShown = $this->ShownOf($oRow);

		$aRow = [];
		if (!$oRow->IsNew()) {
			$aRow['id'] = ObjectId::Normalise($oRow->GetKey());
			ReadSummary::Note($sRowClass, (int)$oRow->GetKey());
		}
		if ($sRowClass !== $sLinkedClass) {
			$aRow['finalclass'] = $sRowClass;
		}

		foreach ($this->oPolicy->ListColumns($sLinkedClass) as $sCode) {
			if ($sCode === 'friendlyname') {
				if (MetaModel::IsValidAttCode($sRowClass, $sCode) && !ExclusionPolicy::ExcludesAttribute($sRowClass, $sCode)) {
					$aRow[$sCode] = $oRow->GetName();
				}
				continue;
			}
			if ($sCode === $oAttDef->GetExtKeyToMe() || $sCode === 'finalclass' || !$fShown($sCode)) {
				continue;
			}
			$this->addColumn($aRow, $oRow, $sRowClass, $sCode, $oInstanceSet);
		}

		return $aRow;
	}

	/**
	 * An n-n set's row: the link, which names the remote object by its key
	 * and its name (what the portal's table starts with), and the link's own
	 * attributes the portal lets the user edit.
	 *
	 * @return array<string, mixed>
	 */
	private function indirectRow(DBObject $oHost, \AttributeLinkedSetIndirect $oAttDef, DBObject $oLink, ?DBObject $oRemote, ?DBObjectSet &$oInstanceSet): array
	{
		$sLinkClass = get_class($oLink);
		$sKeyToRemote = $oAttDef->GetExtKeyToRemote();

		$aRow = [];
		if ($oRemote !== null && !ExclusionPolicy::ExcludesAttribute($sLinkClass, $sKeyToRemote)) {
			$aRow[$sKeyToRemote] = ObjectId::Normalise($oRemote->GetKey());
			if (!ExclusionPolicy::ExcludesAttribute(get_class($oRemote), 'friendlyname')) {
				$aRow[$sKeyToRemote.'_friendlyname'] = $oRemote->GetName();
			}
			ReadSummary::Note(get_class($oRemote), (int)$oRemote->GetKey());
		}
		if ($sLinkClass !== $oAttDef->GetLinkedClass()) {
			$aRow['finalclass'] = $sLinkClass;
		}

		// ObjectFormManager::GetZListAttDefsFilteredForIndirectLinkClass(), which is private: the
		// list's columns, but not the state, the keys at either end or the class, and only those
		// that are writable and neither hidden nor read-only in the host's default state.
		$sStateCode = MetaModel::GetStateAttributeCode(get_class($oHost));
		$sDefaultState = MetaModel::GetDefaultState(get_class($oHost));
		foreach ($this->oPolicy->ListColumns($oAttDef->GetLinkedClass()) as $sCode) {
			if ($sCode === 'friendlyname' || $sCode === $sStateCode || $sCode === $oAttDef->GetExtKeyToMe() || $sCode === $sKeyToRemote || $sCode === 'finalclass') {
				continue;
			}
			if (!MetaModel::IsValidAttCode($sLinkClass, $sCode) || !MetaModel::GetAttributeDef($sLinkClass, $sCode)->IsWritable()) {
				continue;
			}
			$iFlags = MetaModel::GetAttributeFlags($sLinkClass, $sDefaultState, $sCode);
			if (($iFlags & (OPT_ATT_HIDDEN | OPT_ATT_READONLY)) !== 0) {
				continue;
			}
			$this->addColumn($aRow, $oLink, $sLinkClass, $sCode, $oInstanceSet);
		}

		return $aRow;
	}

	/**
	 * One column of a row, as stored ({@see StoredValue}), when it is one of the columns
	 * AttributeLinkedSet::GetForJSON() gives (stored, scalar, not a field of another class) and the user may read it:
	 * not excluded, not refused by the attribute rights, masked when sensitive, and never a blob.
	 *
	 * @param array<string, mixed> $aRow
	 */
	private function addColumn(array &$aRow, DBObject $oObject, string $sClass, string $sCode, ?DBObjectSet &$oInstanceSet): void
	{
		if (!MetaModel::IsValidAttCode($sClass, $sCode) || ExclusionPolicy::ExcludesAttribute($sClass, $sCode)) {
			return;
		}
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sCode);
		if ($oAttDef instanceof \AttributeBlob || $oAttDef->IsExternalField() || !$oAttDef->IsBasedOnDBColumns() || !$oAttDef->IsScalar()) {
			return;
		}
		if (!ObjectSerializer::MayReadAttribute($oObject, $sClass, $sCode, $oInstanceSet)) {
			return;
		}

		$aRow[$sCode] = ObjectSerializer::IsSensitive($oAttDef, $sClass)
			? ObjectSerializer::MASK
			: StoredValue::Of($oAttDef, $oObject->Get($sCode));
	}

	/** The portal's forms show them, as for a read of the object. */
	#[\Override]
	public function ShowsAttachmentsOf(DBObject $oObject): bool
	{
		return PortalForms::ShowsAttachments($oObject);
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

		$aSettable = $this->oPolicy->Writable($sClass, (int)$oObject->GetKey()) === null
			? []
			: self::Settable($sClass, $this->oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject));

		return [
			'attachments'     => self::AttachmentsOf($oObject),
			'may_attach'      => $this->attachRefusal($oObject, $aSettable) === null,
			'settable_fields' => $aSettable,
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
		if (!PortalForms::ShowsAttachments($oObject)) {
			return [];
		}

		$oSearch = DBObjectSearch::FromOQL('SELECT Attachment WHERE item_class = :class AND item_id = :item_id');
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, ['id' => true], ['class' => get_class($oObject), 'item_id' => $oObject->GetKey()]);

		$aAttachments = [];
		while ($oAttachment = $oSet->Fetch()) {
			$oDocument = $oAttachment->Get('contents');
			// Listed with its name, type and size, which is what is read of it.
			ReadSummary::Note('Attachment', (int)$oAttachment->GetKey());
			$aAttachments[] = [
				'id'       => (int)$oAttachment->GetKey(),
				'filename' => $oDocument instanceof \ormDocument ? $oDocument->GetFileName() : null,
				'mimetype' => $oDocument instanceof \ormDocument ? $oDocument->GetMimeType() : null,
				'size'     => $oDocument instanceof \ormDocument ? $oDocument->GetSize() : null,
				// No `uri`: it names a resource template that portal mode does not serve, so a client that followed it
				// was answered "Resource not found". core_object_get_document reads the file, by this id (review
				// 2026-11-18, RT-13; ADR 0011 gates the read in that tool, and a second route would need the same gates).
			];
		}

		return $aAttachments;
	}

	/**
	 * A new object as one of the portal's create actions makes it: of the
	 * class the action names, or the one its factory method - named in the
	 * brick's configuration, never by the client - chooses from the object
	 * it starts from; preset by the action's rules, run by the portal's own
	 * helper with that object and the rows above it as sources. The client's
	 * fields are held to the create form afterwards ({@see RefusalToSet()}).
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When no action is named, or the portal does not offer it from there.
	 * @throws \Altioo\iTop\Extension\MCP\Exception\MCPPortalException When the portal's configuration cannot be read.
	 */
	#[\Override]
	public function Prepared(string $sAction, int $iFromId): DBObject
	{
		if ($sAction === '') {
			throw ToolRefusal::Because('In portal mode a creation runs one of the portal\'s create actions: pass action, from core_class_list, and from_id when it starts from an object.');
		}
		$aAction = $this->actionOf($sAction);
		[$oFrom, $aSources] = $this->startingPoint($aAction, $iFromId);

		$sClass = $aAction['class'] ?? $this->oPolicy->ChosenClass((string)$aAction['factory_method'], $oFrom);
		if (!MetaModel::IsValidClass($sClass) || MetaModel::IsAbstract($sClass) || !$this->oPolicy->MayCreate($sClass)) {
			throw ToolRefusal::Because('The portal does not let you create what this action creates.');
		}

		$oObject = MetaModel::NewObject($sClass);
		$this->oPolicy->ApplyRules($aAction['rules'], $aSources, $oObject);

		return $oObject;
	}

	/**
	 * The create action of that id, among those the granted bricks offer.
	 *
	 * @return array<string, mixed>
	 */
	private function actionOf(string $sAction): array
	{
		foreach ($this->oPolicy->CreateActions() as $aAction) {
			if ($aAction['action'] === $sAction) {
				return $aAction;
			}
		}

		// Not quoted back: the id is the client's text.
		throw ToolRefusal::Because('The portal offers no such create action. Call core_class_list for the ones it offers.');
	}

	/**
	 * The object an action starts from, and the objects its rules may read:
	 * that one, and the row above it on each level of its browse brick - as
	 * the portal hands its rules every object of the row the user clicked.
	 *
	 * @param array<string, mixed> $aAction
	 *
	 * @return array{0: DBObject|null, 1: array<string, int>}
	 */
	private function startingPoint(array $aAction, int $iFromId): array
	{
		$sList = $aAction['from_list'] ?? null;
		if (!is_string($sList)) {
			if ($iFromId !== 0) {
				throw ToolRefusal::Because('This create action starts from nothing: leave from_id out.');
			}

			return [null, []];
		}

		$oFrom = $iFromId > 0 ? PortalRows::RowOf($this->oPolicy, $sList, $iFromId) : null;
		if ($oFrom === null) {
			throw ToolRefusal::Because(sprintf(
				'This create action starts from a %s: from_id must be one listed by core_portal_list on %s.',
				(string)$aAction['from'],
				$sList
			));
		}

		$aSources = [get_class($oFrom) => (int)$oFrom->GetKey()];
		$oRow = $oFrom;
		$aLevel = $this->oPolicy->ListDefinition($sList);
		while (is_array($aLevel) && is_string($aLevel['parent']) && is_string($aLevel['parent_att']) && $aLevel['parent_att'] !== '') {
			$oRow = PortalRows::RowOf($this->oPolicy, $aLevel['parent'], (int)$oRow->Get($aLevel['parent_att']));
			if ($oRow === null) {
				break;
			}
			$aSources[get_class($oRow)] = (int)$oRow->GetKey();
			$aLevel = $this->oPolicy->ListDefinition($aLevel['parent']);
		}

		return [$oFrom, $aSources];
	}

	/** What the portal's scopes do not let the caller read. */
	#[\Override]
	public function Hides(string $sClass, int $iId): bool
	{
		return ChangeLogReservation::IsReserved($sClass) || ExclusionPolicy::ExcludesClass($sClass) || $this->oPolicy->Readable($sClass, $iId) === null;
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

	#[\Override]
	public function AttachmentsAreObjects(): bool
	{
		return false;
	}

	/**
	 * The attachment, when the portal would show it: filed under an object the
	 * user may read and whose forms show attachments. Never through UserRights
	 * on the class, which would be wider than the portal. A draft - an upload
	 * somebody has not sent yet - is nobody's file to read, and unknown, hidden
	 * and draft are one answer.
	 */
	#[\Override]
	public function ShownAttachment(int $iId): ?DBObject
	{
		if ($iId < 1 || !MetaModel::IsValidClass('Attachment')) {
			return null;
		}

		$oSearch = DBObjectSearch::FromOQL('SELECT Attachment WHERE id = :id');
		$oSearch->AllowAllData();
		$oAttachment = (new DBObjectSet($oSearch, [], ['id' => $iId]))->Fetch();
		if ($oAttachment === null || (string)$oAttachment->Get('temp_id') !== '' || (int)$oAttachment->Get('item_id') < 1) {
			return null;
		}

		$sHostClass = (string)$oAttachment->Get('item_class');
		if (!MetaModel::IsValidClass($sHostClass)) {
			return null;
		}
		$oHost = $this->Readable($sHostClass, (int)$oAttachment->Get('item_id'));

		return $oHost !== null && PortalForms::ShowsAttachments($oHost) ? $oAttachment : null;
	}

	#[\Override]
	public function RefusalToAttach(DBObject $oObject): ?string
	{
		$sClass = get_class($oObject);
		$aSettable = $this->oPolicy->Writable($sClass, (int)$oObject->GetKey()) === null
			? []
			: self::Settable($sClass, $this->oPolicy->Fields($sClass, PortalPolicy::MODE_EDIT, $oObject));

		return $this->attachRefusal($oObject, $aSettable);
	}

	/**
	 * The three things that leave the portal's upload field editable: the
	 * attachment module allows attachments on the class, its state is not
	 * read-only for the portals, and the edit form has a field the user can
	 * change (a form with none is shown read-only, upload included) - after
	 * the portal's write scope, which the form is only reached through.
	 *
	 * @param array<int, string> $aSettable The edit form's settable fields.
	 */
	private function attachRefusal(DBObject $oObject, array $aSettable): ?string
	{
		$sClass = get_class($oObject);
		$iId = (int)$oObject->GetKey();

		$sWhy = $this->RefusalToChange($oObject);
		if ($sWhy !== null) {
			return $sWhy;
		}
		if (!PortalForms::ShowsAttachments($oObject)) {
			return "The portal does not take attachments on {$sClass}.";
		}
		if (\AttachmentPlugIn::IsReadonlyState($oObject, (string)$oObject->GetState(), \AttachmentPlugIn::ENUM_GUI_PORTALS) === true) {
			return "The portal takes no attachments on {$sClass} {$iId} in its current state.";
		}
		if ($aSettable === []) {
			return "The portal's edit form for {$sClass} {$iId} has nothing the user can change now, so it takes no attachment either.";
		}

		return null;
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
		return (\Altioo\iTop\Extension\MCP\Helper\ClassRefusal::LooksLikeClassName($sStimulus) ? "The portal does not offer '{$sStimulus}'" : 'The portal does not offer that stimulus')
			." on {$sClass} {$oObject->GetKey()} in state '{$sState}'. "
			.($aOffered === [] ? 'It offers none in this state.' : 'It offers: '.implode(', ', array_keys($aOffered)).'.');
	}

	/**
	 * A field the portal's edit form does not leave settable - or, for a
	 * transition, one its transition form does not leave editable either. Named,
	 * each with why, before any value is converted: a field the form hides or
	 * shows read-only is not a value the user could have typed in.
	 */
	#[\Override]
	public function RefusalToSet(DBObject $oObject, array $aFields, string $sStimulus = ''): ?string
	{
		$sClass = get_class($oObject);
		$bNew = $oObject->IsNew();
		// A stimulus sets what the portal's own transition form leaves editable, and nothing else (ADR 0005, amended 2026-11-19): the
		// portal never lets a transition submit an edit-form field, so an assistant changes one of those with core_object_update first.
		// What the datamodel's target state prompts for is in that form already (PortalForms::TransitionFields()).
		$aSettable = $sStimulus === ''
			? self::Settable($sClass, $this->oPolicy->Fields($sClass, $bNew ? PortalPolicy::MODE_CREATE : PortalPolicy::MODE_EDIT, $oObject))
			: self::Settable($sClass, $this->oPolicy->TransitionFields($sClass, $sStimulus, $oObject));
		$sForm = $bNew ? 'create' : ($sStimulus === '' ? 'edit' : 'transition');

		$aIssues = [];
		foreach (array_keys($aFields) as $sAttCode) {
			if (!in_array((string)$sAttCode, $aSettable, true)) {
				// Quoted only when it is shaped like a code: the text lands in the audit row.
				$aIssues[] = (\Altioo\iTop\Extension\MCP\Helper\ClassRefusal::LooksLikeClassName((string)$sAttCode) ? "'{$sAttCode}'" : 'A key')
					." is not a field the portal's {$sForm} form lets you set";
			}
		}

		return $aIssues === []
			? null
			: implode('; ', $aIssues).'. Fields you may set: '.(implode(', ', array_unique($aSettable)) ?: 'none').'.'
				.($sStimulus === '' ? '' : ' To change another field of the request, use core_object_update first.');
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

		// A row the set already held is not the caller's choice. The rows the portal hides were
		// never theirs to send, and {@see KeepHiddenLinks()} keeps them in; a caller cannot name
		// a stored row (a value they send is a new object), so nothing else gets past here.
		$aStored = [];
		$oOriginal = $oSet->GetOriginalSet();
		if ($oOriginal !== null) {
			$oOriginal->Rewind();
			while ($oStored = $oOriginal->Fetch()) {
				$aStored[(int)$oStored->GetKey()] = true;
			}
			$oOriginal->Rewind();
		}
		foreach ($oSet as $oLink) {
			if (!$oLink->IsNew() && isset($aStored[(int)$oLink->GetKey()])) {
				continue;
			}
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
