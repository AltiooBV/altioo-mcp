<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use Altioo\iTop\Extension\MCP\Exception\MCPDocumentException;
use AttributeBlob;
use DBObject;
use DBObjectSet;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\ImageContent;
use MetaModel;
use ormDocument;
use UserRights;

/**
 * The one way bytes leave this endpoint.
 *
 * Reading an object never returns file content: {@see ObjectSerializer::Value()}
 * reports a blob as its filename, type and size, and that is not a limitation
 * to be worked around. AttributeBlob::GetForJSON() base64-encodes the whole file
 * into the response, base64 costs a third again on top, and core_object_get
 * defaults to every attribute - so one call on a ticket carrying a 4 MB PDF is a
 * 5.4 MB body, larger than the context window it is being read into, paid for by
 * the caller before it can decide it did not want it. A search returning fifty
 * rows multiplies that by fifty. And it cannot be taken back: once the bytes are
 * in the conversation they stay there.
 *
 * What was wrong was not the ceiling but that there was no door at all. The
 * ceiling says bytes never arrive *unasked*, in a result that fans out. It never
 * said the file may not be read. So the metadata now carries a `uri`, and asking
 * for that URI - through the resource template or through core_object_get_document,
 * because plenty of clients read neither resources nor templates - returns the
 * one file. One document, named explicitly, per call. That is the shape the
 * ceiling was protecting, and it is the shape this preserves.
 *
 * Everything a byte has to pass to get out is here, so that there is one place
 * to read and one place to change: the rights, the ceiling, and the decision
 * between an image a model can actually look at and a blob it can only hand on.
 *
 * @since 1.0.0
 */
final class DocumentAccess
{
	/** The URI path, under the namespace: itop://core/document/{class}/{id}/{att_code}. */
	public const URI_PATH = 'document';

	/** What the operator may raise or lower. */
	public const MODULE_SETTING_MAX_BYTES = 'mcp_max_document_bytes';

	/**
	 * 5 MB, which is a large attachment and a very large thing to read.
	 *
	 * Chosen against what happens on the client rather than against what iTop
	 * can serve: 5 MB of base64 is roughly 6.7 MB of JSON, and a client that
	 * puts that in front of a model has spent most of a context window on one
	 * file. An operator who wants a 40 MB installer readable can say so; the
	 * default should not decide that for them.
	 */
	public const DEFAULT_MAX_BYTES = 5242880;

	/** Where the bytes of an image are worth reading rather than only carrying. */
	private const IMAGE_PREFIX = 'image/';

	/**
	 * The URI that reads one document, as it appears in the metadata of a read.
	 *
	 * @since 1.0.0
	 */
	public static function Uri(string $sClass, int $iId, string $sAttCode): string
	{
		return sprintf('itop://core/%s/%s/%d/%s', self::URI_PATH, $sClass, $iId, $sAttCode);
	}

	/**
	 * The ceiling, as configured.
	 *
	 * @since 1.0.0
	 */
	public static function MaxBytes(): int
	{
		$iMax = MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, self::MODULE_SETTING_MAX_BYTES, self::DEFAULT_MAX_BYTES);

		if (!is_int($iMax) || $iMax < 1) {
			MCPHelper::LogError("Itop configuration parameter '".self::MODULE_SETTING_MAX_BYTES."' should be a positive integer");

			return self::DEFAULT_MAX_BYTES;
		}

		return $iMax;
	}

	/**
	 * One document, if this caller may read that object and that attribute.
	 *
	 * Every gate a read goes through applies here too, in the same order and for
	 * the same reasons: an unknown class and a forbidden one answer alike, an
	 * object the caller may not see is reported missing, and the per-attribute
	 * right is checked because "may read a Person" and "may read their photo"
	 * are two different permissions in iTop.
	 *
	 * @throws MCPDocumentException When the document cannot be read, or should not be.
	 * @since 1.0.0
	 */
	public static function Fetch(string $sClass, int $iId, string $sAttCode): ormDocument
	{
		if (!MetaModel::IsValidClass($sClass) || !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			throw new MCPDocumentException("Unknown class '{$sClass}'."); // hide that the class exists
		}
		if ($iId < 1) {
			throw new MCPDocumentException('Invalid ID. Please specify a valid object ID.');
		}
		if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
			throw new MCPDocumentException("Unknown attribute '{$sAttCode}' on class '{$sClass}'.");
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if (!$oAttDef instanceof AttributeBlob) {
			throw new MCPDocumentException(
				"Attribute '{$sAttCode}' on '{$sClass}' holds no document. Read it with core_object_get."
			);
		}
		if (!UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ)) {
			throw new MCPDocumentException("Read access denied on attribute '{$sAttCode}'.");
		}

		// Through a set, because a set is what applies object-level rights -
		// the difference between may read a User and may read their own.
		$oSet = new DBObjectSet(ObjectQuery::ById($sClass, $iId));
		if ($oSet->Count() === 0) {
			throw new MCPDocumentException("Object {$sClass}::{$iId} not found."); // hide that the object exists
		}

		/** @var DBObject $oObject */
		$oObject = $oSet->Fetch();
		$value = $oObject->Get($sAttCode);

		if (!$value instanceof ormDocument || $value->IsEmpty()) {
			throw new MCPDocumentException("{$sClass}::{$iId} carries no document in '{$sAttCode}'.");
		}

		$iBytes = strlen((string)$value->GetData());
		$iMax = self::MaxBytes();
		if ($iBytes > $iMax) {
			// Refused rather than cut. Half a PDF is not a smaller PDF, and a
			// caller told the size can decide what to do about it - which is
			// more than it could do with a corrupt file it was not warned about.
			throw new MCPDocumentException(sprintf(
				"'%s' is %s and this instance serves at most %s through MCP (%s). Download it from the console instead.",
				$value->GetFileName(),
				self::humanBytes($iBytes),
				self::humanBytes($iMax),
				self::MODULE_SETTING_MAX_BYTES
			));
		}

		return $value;
	}

	/**
	 * The document as MCP content: an image a model can look at, or a blob.
	 *
	 * The distinction is not cosmetic. ImageContent is what a client renders and
	 * what a vision model reads, so the screenshot pasted into a ticket - by
	 * some distance the attachment most worth reading - becomes something the
	 * assistant can actually answer questions about. Anything else is carried as
	 * an embedded resource: still the whole file, still exact, simply not
	 * something the model is being invited to interpret as a picture.
	 *
	 * @return ImageContent|BlobResourceContents
	 * @since 1.0.0
	 */
	public static function ContentFor(ormDocument $oDocument, string $sUri)
	{
		$sMimeType = (string)$oDocument->GetMimeType();
		$sBase64 = base64_encode((string)$oDocument->GetData());

		if (str_starts_with($sMimeType, self::IMAGE_PREFIX)) {
			return new ImageContent($sBase64, $sMimeType);
		}

		return new BlobResourceContents($sUri, $sMimeType === '' ? 'application/octet-stream' : $sMimeType, $sBase64);
	}

	/**
	 * What a read reports about a document it is not returning.
	 *
	 * The `uri` is the whole difference between "there is a file here you
	 * cannot have" and "there is a file here, ask for it by name". It is left
	 * out when the object has no key yet, which is the dry run of a creation:
	 * there is nothing to address.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Describe(ormDocument $oDocument, string $sClass, int $iId, string $sAttCode): array
	{
		$aDescription = [
			'filename' => $oDocument->GetFileName(),
			'mimetype' => $oDocument->GetMimeType(),
			'size'     => strlen((string)$oDocument->GetData()),
		];

		if ($iId > 0) {
			$aDescription['uri'] = self::Uri($sClass, $iId, $sAttCode);
		}

		return $aDescription;
	}

	/**
	 * Bytes as a person reads them, for a message a person will read.
	 */
	private static function humanBytes(int $iBytes): string
	{
		if ($iBytes < 1024) {
			return $iBytes.' B';
		}
		if ($iBytes < 1048576) {
			return round($iBytes / 1024, 1).' kB';
		}

		return round($iBytes / 1048576, 1).' MB';
	}
}
