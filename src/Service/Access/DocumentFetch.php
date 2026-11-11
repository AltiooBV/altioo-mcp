<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Access;

use Altioo\iTop\Extension\MCP\Exception\MCPDocumentException;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Service\Read\DocumentAccess;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectHistory;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectQuery;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use AttributeBlob;
use DBObject;
use DBObjectSet;
use MetaModel;
use ormDocument;
use UserRights;

/**
 * Whether a document may be read, and the read: the checks half of what was
 * DocumentAccess before 2.0.
 *
 * It asks {@see ObjectAccess} and ObjectAccess reads through ObjectSerializer,
 * which describes a blob through {@see DocumentAccess}: the two halves cannot
 * be one class without Read importing Access. DocumentAccess keeps the URI,
 * the ceiling, the type check and the content; this keeps the rights.
 *
 * @internal
 * @since 2.0.0
 */
final class DocumentFetch
{
	public static function Fetch(string $sClass, int $iId, string $sAttCode): ormDocument
	{
		// Here and not only in the callers, because there is more than one of
		// them and a caller is where it was missed. CMDBChangeOpSetAttributeBlob
		// keeps the replaced version of a blob in prevdata, and its rows carry
		// no organisation and no attribute right of their own: read by id, they
		// are every past document on the instance, silos and today's
		// per-attribute rights notwithstanding.
		if (ObjectHistory::IsReserved($sClass)) {
			throw new MCPDocumentException(sprintf(ObjectHistory::RESERVED_REFUSAL, $sClass));
		}
		try {
			$oAccess = ObjectAccess::Current();
		} catch (MCPPortalException $e) {
			throw new MCPDocumentException($e->getMessage());
		}
		// In portal mode a file is not reached as an object of its own: the
		// portal checks the object it is filed under, and so does this
		// (doc/adr/0011-portal-mode-reads-and-adds-attachments.md).
		$bPortalFile = $sClass === 'Attachment' && !$oAccess->AttachmentsAreObjects();

		if (!ExclusionPolicy::Serves($sClass) || (!$bPortalFile && !UserRights::IsActionAllowed($sClass, UR_ACTION_READ))) {
			throw new MCPDocumentException(ClassRefusal::Unreadable($sClass)); // exists, but not for this account to read
		}
		if ($iId < 1) {
			throw new MCPDocumentException('Invalid ID. Please specify a valid object ID.');
		}
		if (!MetaModel::IsValidAttCode($sClass, $sAttCode) || ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode)) {
			throw new MCPDocumentException("Unknown attribute '{$sAttCode}' on class '{$sClass}'.");
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if (!$oAttDef instanceof AttributeBlob) {
			throw new MCPDocumentException(
				"Attribute '{$sAttCode}' on '{$sClass}' holds no document. Read it with core_object_get."
			);
		}
		// The class-level gate, before an object is read at all: an attribute
		// refused for the whole class is refused here, and no query is spent on
		// it. UR_ALLOWED_DEPENDS passes - it means the answer varies by object,
		// which is the question asked again below once there is an object.
		if (!$bPortalFile && UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ) === UR_ALLOWED_NO) {
			throw new MCPDocumentException("Read access denied on attribute '{$sAttCode}'.");
		}

		if ($bPortalFile) {
			// Unknown, out of reach and a draft answer alike.
			$oObject = $oAccess->ShownAttachment($iId)
				?? throw new MCPDocumentException("Object {$sClass}::{$iId} not found.");

			return self::served($oObject->Get($sAttCode), $sClass, $iId, $sAttCode);
		}

		// Through a set, because a set is what applies object-level rights -
		// the difference between may read a User and may read their own.
		$oSet = new DBObjectSet(ObjectQuery::ById($sClass, $iId));
		if ($oSet->Count() === 0) {
			throw new MCPDocumentException("Object {$sClass}::{$iId} not found."); // hide that the object exists
		}

		// Fetched before the check and rewound after, not the other way round:
		// the check is handed this same set, an addon is free to iterate it, and
		// a Fetch() on a spent cursor returns null - which would surface as a
		// fatal rather than as a refusal.
		/** @var DBObject $oObject */
		$oObject = $oSet->Fetch();
		$oSet->Rewind();

		// A file filed under an object of an excluded class is not there.
		if (ExclusionPolicy::IsFiledUnderExcluded($oObject)) {
			throw new MCPDocumentException("Object {$sClass}::{$iId} not found.");
		}

		// And again with the object in hand. Under the shipped addon this
		// answers the same as above - it ignores the set for attributes - so
		// what protects the document on someone else's record is the set
		// itself: ObjectQuery::ById goes through GetSelectFilter, which only
		// returns objects this caller may read. The set is passed because the
		// API is tri-state and an addon that does grade per object says so with
		// UR_ALLOWED_DEPENDS, which a truthy test would read as a yes.
		if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ, $oSet) !== UR_ALLOWED_YES) {
			throw new MCPDocumentException("Read access denied on attribute '{$sAttCode}'.");
		}

		// A portal serves a document of another class only if its view form
		// shows the attribute, and only for an object it shows.
		if (!$oAccess->AttachmentsAreObjects()) {
			$oShown = $oAccess->Readable($sClass, $iId);
			if ($oShown === null) {
				throw new MCPDocumentException("Object {$sClass}::{$iId} not found.");
			}
			try {
				$bShown = $oAccess->ShownOf($oShown)($sAttCode);
			} catch (MCPPortalException $e) {
				throw new MCPDocumentException($e->getMessage());
			}
			if (!$bShown) {
				throw new MCPDocumentException("Read access denied on attribute '{$sAttCode}'.");
			}
		}

		return self::served($oObject->Get($sAttCode), $sClass, $iId, $sAttCode);
	}

	/**
	 * The document, once it is certain it may be read: present, and within the ceiling.
	 *
	 * @throws MCPDocumentException
	 */
	private static function served(mixed $value, string $sClass, int $iId, string $sAttCode): ormDocument
	{
		if (!$value instanceof ormDocument || $value->IsEmpty()) {
			throw new MCPDocumentException("{$sClass}::{$iId} carries no document in '{$sAttCode}'.");
		}

		$iBytes = strlen((string)$value->GetData());
		$iMax = DocumentAccess::MaxBytes();
		if ($iBytes > $iMax) {
			// Refused rather than cut. Half a PDF is not a smaller PDF, and a
			// caller told the size can decide what to do about it - which is
			// more than it could do with a corrupt file it was not warned about.
			throw new MCPDocumentException(sprintf(
				"'%s' is %s and this instance serves at most %s through MCP (%s). Download it from the console instead.",
				$value->GetFileName(),
				DocumentAccess::HumanBytes($iBytes),
				DocumentAccess::HumanBytes($iMax),
				DocumentAccess::MODULE_SETTING_MAX_BYTES
			));
		}

		// Noted once it is certain to be returned, size limit included.
		ReadSummary::Note($sClass, $iId);

		return $value;
	}
}
