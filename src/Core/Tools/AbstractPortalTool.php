<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\EditPolicy;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use Altioo\iTop\Extension\MCP\Service\PortalPolicy;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;
use UserRights;

/**
 * What the tools served in portal mode share.
 *
 * Each one answers from {@see PortalPolicy} and nothing else: the portal's
 * bricks say what is offered, its scopes what may be read and written, its
 * forms which fields. None of them is served to a console user, who has the
 * object tools, and none of them asks UserRights alone, which grants "Portal
 * user" read on every bizmodel class.
 *
 * An object the portal does not show the user is answered as one that does
 * not exist, whichever it is, as the portal's own object page answers it.
 *
 * @internal
 * @since 1.2.0
 */
abstract class AbstractPortalTool extends AbstractMCPTool implements PortalAware
{
	public function getNamespace(): string
	{
		return 'core';
	}

	/** Reading and writing the objects themselves, as the console's object tools do. */
	public function getToolset(): string
	{
		return 'objects';
	}

	/** Portal mode only: a console user has the object tools. */
	public function isAvailable(): bool
	{
		return PortalMode::Current() !== null;
	}

	/**
	 * The policy of this request's portal, or the refusal a client is given
	 * when the portal cannot be read - already logged where it was raised.
	 */
	protected static function policy(): PortalPolicy
	{
		try {
			return PortalPolicy::ForCurrentRequest();
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}
	}

	/**
	 * Runs a step that reads the portal's configuration, turning a failure to
	 * read it into the refusal a client is given.
	 *
	 * @template T
	 * @param callable(): T $fStep
	 *
	 * @return T
	 */
	protected static function portalStep(callable $fStep): mixed
	{
		try {
			return $fStep();
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		}
	}

	/**
	 * One answer for "no such object" and "not one the portal shows you".
	 * The class is quoted only when it is shaped like one.
	 */
	protected static function notFound(string $sClass, int $iId): ToolCallException
	{
		if (!MCPHelper::LooksLikeClassName($sClass)) {
			return new ToolCallException('That is not a class name. Call core_portal_describe for the classes this portal offers.');
		}

		return new ToolCallException(sprintf(
			'The portal shows you no %s with id %d. Ids come from core_portal_list.',
			$sClass,
			$iId
		));
	}

	/**
	 * The object, if the portal lets the user read it.
	 *
	 * The change log is not found either. A portal that offers it shows
	 * history, which core_object_history serves - with the gates on the
	 * object a row is about, which a row read here as an object would skip.
	 */
	protected static function readable(PortalPolicy $oPolicy, string $sClass, int $iId): DBObject
	{
		if (ObjectHistory::IsReserved($sClass)) {
			throw self::notFound($sClass, $iId);
		}

		$oObject = $oPolicy->Readable($sClass, $iId) ?? throw self::notFound($sClass, $iId);
		if (ObjectHistory::IsReserved(get_class($oObject))) {
			throw self::notFound($sClass, $iId);
		}

		return $oObject;
	}

	/**
	 * Refuses a list of the change log, which core_object_history serves
	 * one object at a time.
	 */
	protected static function refuseReserved(string $sClass): void
	{
		if (ObjectHistory::IsReserved($sClass)) {
			throw new ToolCallException('This list is the change log: core_object_history gives the history of one object the portal shows you.');
		}
	}

	/**
	 * The object, if the portal lets the user change it. One the user may
	 * read but not change is told so, since they can already see it.
	 */
	protected static function writable(PortalPolicy $oPolicy, string $sClass, int $iId): DBObject
	{
		$oObject = self::readable($oPolicy, $sClass, $iId);
		if ($oPolicy->Writable($sClass, $iId) === null) {
			throw new ToolCallException(sprintf(
				'The portal lets you read %s %d but not change it.',
				get_class($oObject),
				$iId
			));
		}

		return $oObject;
	}

	/**
	 * An object as the portal shows it: the attributes named, each one the
	 * user may read, external keys with the name of what they point at.
	 *
	 * @param array<int, string> $aAttCodes
	 *
	 * @return array<string, mixed>
	 */
	protected static function serialized(DBObject $oObject, array $aAttCodes): array
	{
		$sClass = get_class($oObject);

		$aFields = [];
		foreach ($aAttCodes as $sAttCode) {
			if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
				continue;
			}
			$aFields[] = $sAttCode;

			// The id alone says nothing to the person reading the answer; the
			// portal shows the name.
			$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
			if ($oAttDef->IsExternalKey() && MetaModel::IsValidAttCode($sClass, $sAttCode.'_friendlyname')) {
				$aFields[] = $sAttCode.'_friendlyname';
			}
		}
		if (MetaModel::IsValidAttCode($sClass, 'friendlyname')) {
			array_unshift($aFields, 'friendlyname');
		}

		return ObjectSerializer::Serialize($oObject, $sClass, array_values(array_unique($aFields)));
	}

	/**
	 * The attributes of a form the user is shown: not hidden by it, and not
	 * refused by UserRights.
	 *
	 * @param array<string, int> $aFlags As PortalPolicy::Fields() answers.
	 *
	 * @return array<int, string>
	 */
	protected static function shown(string $sClass, array $aFlags): array
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
	protected static function settable(string $sClass, array $aFlags): array
	{
		return array_values(array_filter(
			self::shown($sClass, $aFlags),
			static fn (string $sAttCode): bool => ($aFlags[$sAttCode] & (OPT_ATT_READONLY | OPT_ATT_SLAVE)) === 0
		));
	}

	/**
	 * Why a value the portal's form would not have accepted is refused, or
	 * null when it would have.
	 *
	 * The portal limits what an external key or a link may point at to what
	 * the user can read of the class it points to - its pickers search
	 * through the scopes. Without that, a request could be filed against a
	 * service, or a contact added, that the user cannot see.
	 */
	protected static function outOfReach(PortalPolicy $oPolicy, DBObject $oObject, string $sAttCode): ?string
	{
		$sClass = get_class($oObject);
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);

		if ($oAttDef->IsExternalKey()) {
			$iTarget = (int)$oObject->Get($sAttCode);
			if ($iTarget > 0 && $oPolicy->Readable($oAttDef->GetTargetClass(), $iTarget) === null) {
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
				if (!$oLink->IsNew() && $oPolicy->Readable(get_class($oLink), (int)$oLink->GetKey()) === null) {
					return sprintf("'%s' holds an item the portal does not show you.", $sAttCode);
				}
				continue;
			}

			$sRemote = MetaModel::GetAttributeDef(get_class($oLink), $sExtKey)->GetTargetClass();
			$iRemote = (int)$oLink->Get($sExtKey);
			if ($iRemote > 0 && $oPolicy->Readable($sRemote, $iRemote) === null) {
				return sprintf("'%s' links a %s the portal does not show you.", $sAttCode, $sRemote);
			}
		}

		return null;
	}

	/**
	 * Refuses the fields a portal form would not have let the user set.
	 *
	 * Named, each with why, before any value is converted: a field the form
	 * hides or shows read-only is not a value the user could have typed in.
	 *
	 * @param array<string, mixed> $aFields
	 * @param array<int, string>   $aSettable What the form lets them set.
	 * @param array<int, string>   $aAlso     What else may be set: a transition's prompted fields.
	 */
	protected static function refuseUnsettable(array $aFields, array $aSettable, array $aAlso, string $sForm): void
	{
		$aIssues = [];
		foreach (array_keys($aFields) as $sAttCode) {
			if (!in_array((string)$sAttCode, $aSettable, true) && !in_array((string)$sAttCode, $aAlso, true)) {
				$aIssues[] = "'{$sAttCode}' is not a field the portal's {$sForm} form lets you set";
			}
		}

		if ($aIssues !== []) {
			throw new ToolCallException(implode('; ', $aIssues).'. Fields you may set: '.(implode(', ', array_merge($aSettable, $aAlso)) ?: 'none').'.');
		}
	}

	/**
	 * Sets values already validated, and establishes what the write would do:
	 * the portal's reach on what they point at, the datamodel's filters on
	 * the keys, and iTop's own pre-write check.
	 *
	 * @param array<string, mixed> $aValues Converted values, as WritePlan::ValidatedValues() returns them.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} Requested, overridden and changes.
	 */
	protected static function plan(PortalPolicy $oPolicy, DBObject $oObject, array $aValues, string $sWhat): array
	{
		$sClass = get_class($oObject);

		$aIssues = [];
		foreach ($aValues as $sAttCode => $realValue) {
			try {
				$oObject->Set($sAttCode, $realValue);
			} catch (\Throwable $e) {
				$aIssues[$sAttCode] = MCPHelper::RejectedValue("Failed to set attribute '{$sAttCode}'", $e);
			}
		}
		if ($aIssues !== []) {
			throw new ToolCallException('Refused: '.implode(', ', $aIssues));
		}

		// What the portal's pickers would have offered: an object the user can
		// read. Asked once the values are in, as they are what is pointed at.
		foreach (array_keys($aValues) as $sAttCode) {
			$sOutOfReach = self::outOfReach($oPolicy, $oObject, $sAttCode);
			if ($sOutOfReach !== null) {
				$aIssues[$sAttCode] = $sOutOfReach;
			}
		}
		// Once everything is Set(), because a datamodel filter reads the
		// object and this call may have moved what it reads. See EditPolicy.
		$aIssues += EditPolicy::RefusalsForExternalKeys($oObject, array_keys($aValues));
		if ($aIssues !== []) {
			throw new ToolCallException('Refused: '.implode(', ', $aIssues));
		}

		// Before the check, because the check is what moves them: CheckToWrite()
		// runs DoComputeValues(), which overwrites what it derives.
		$aRequested = WritePlan::Requested($oObject, $sClass, array_keys($aValues));
		WritePlan::Check($oObject, $sWhat);
		$aOverridden = WritePlan::Overridden($oObject, $sClass, $aRequested);
		WritePlan::CheckRequested($oObject, $aRequested, $aOverridden, $sWhat);

		return [$aRequested, $aOverridden, WritePlan::Changes($oObject, $sClass)];
	}

	/**
	 * The attachments the portal lists beside an object, without their
	 * content. Read as the portal reads them: by the host object, which the
	 * user has already been allowed to see, and with no scope of their own.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected static function attachmentsOf(DBObject $oObject): array
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
