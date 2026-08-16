<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\DocumentAccess;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use AttributeBlob;
use DBObject;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use ormDocument;
use UserRights;

/**
 * Put a file on an object: an attachment, or a blob attribute.
 *
 * Its own tool rather than a `fields` entry on core_object_create, although
 * that route technically works - RestUtils understands `{data, mimetype,
 * filename}` and {@see \Altioo\iTop\Extension\MCP\Helper\RestValue} hands it the
 * shape it expects. Two reasons not to leave it there.
 *
 * The first is the ceiling. A base64 payload buried in an open-ended attribute
 * map is invisible to any limit that is not looking for it, so the size check
 * that guards reading would have had no counterpart on the way in.
 *
 * The second is that a file is not an attribute value like the others. The
 * caller has to be told what will happen to a file it cannot see afterwards -
 * which object it lands on, how large it is, what it will be called - and a
 * dry run that says "would set contents" says none of that.
 *
 * What it deliberately does not do is fetch. "Download this URL and attach it"
 * is a request for iTop to make an HTTP call to an address chosen by whatever
 * the model was reading, from inside the network iTop sits in, with iTop's
 * credentials on the wire. The bytes come from the caller or they do not come.
 *
 * @since 1.0.0
 */
class ObjectAttach extends AbstractMCPTool
{
	/** iTop's own attachment class, from the module that may or may not be installed. */
	private const ATTACHMENT_CLASS = 'Attachment';

	public function getNamespace(): string
	{
		return 'core';
	}

	/** Bytes, which an operator may well want to serve and may well not. */
	public function getToolset(): string
	{
		return 'documents';
	}

	protected function defaultTitle(): string
	{
		return 'Attach Document';
	}

	public function getDescription(): ?string
	{
		return 'Attach a file to an iTop object, or set one of its document attributes. '
			.'The content is passed base64-encoded, and must be content you already hold: this tool does not download anything. '
			.'Leave att_code out to add an Attachment to the object, the way the console does; give it to set a specific blob attribute, e.g. a picture. '
			.'Runs as a dry run by default: call it with simulate=true to have iTop validate the file and report where it would land, show that to the user, then call again with simulate=false to store it.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Attach a document to an iTop object',
			false,  // readOnlyHint
			false,  // destructiveHint — additive, except on a blob attribute it replaces
			false,  // idempotentHint — calling twice attaches twice
			false,  // openWorldHint — nothing outside iTop is contacted
		);
	}

	/**
	 * Withdrawn when nothing here could work.
	 *
	 * Blob attributes exist on stock classes, so the tool is worth offering
	 * without the attachments module; without it, calls that leave att_code out
	 * are refused with the reason rather than silently doing something else.
	 */
	public function isAvailable(): bool
	{
		return true;
	}

	public function getOutputSchema(): ?array
	{
		return WritePlan::OutcomeSchema([
			'attached_to' => [
				'type'        => 'object',
				'description' => 'The object the document lands on: its class and id.',
				'additionalProperties' => true,
			],
			'document'    => [
				'type'        => 'object',
				'description' => 'The stored document: filename, mimetype, size in bytes, and the uri that reads it back.',
				'additionalProperties' => true,
			],
		]);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'          => [
					'type'        => 'string',
					'description' => 'iTop class of the object to attach the file to, e.g. UserRequest.',
				],
				'id'             => [
					'type'        => 'integer',
					'description' => 'The ID of that object.',
					'minimum'     => 1,
				],
				'filename'       => [
					'type'        => 'string',
					'description' => 'Name the file is stored under, e.g. invoice-2026-03.pdf. A path is reduced to its last element.',
				],
				'content_base64' => [
					'type'        => 'string',
					'description' => 'The file itself, base64-encoded. Content you already hold — nothing is downloaded on your behalf.',
				],
				'mimetype'       => [
					'type'        => 'string',
					'description' => 'Media type of the file, e.g. application/pdf or image/png. Worth naming: an image stored as image/* is one that can be looked at when it is read back, and anything unnamed is stored as application/octet-stream.',
				],
				'att_code'       => [
					'type'        => 'string',
					'description' => 'Blob attribute to set on the object itself, replacing what it holds. Leave it out to add an Attachment instead, which is what the console\'s Attachments tab does and what a ticket usually wants.',
				],
				'simulate'       => WritePlan::SimulateSchemaProperty('store the file'),
				'comment'        => ChangeTracking::CommentSchemaProperty('the file is being attached'),
			],
			'required' => ['class', 'id', 'filename', 'content_base64'],
		];
	}

	/**
	 * @param string      $class          The class of the object to attach to
	 * @param int         $id             The ID of that object
	 * @param string      $filename       Name the file is stored under
	 * @param string      $content_base64 The file, base64-encoded
	 * @param string|null $mimetype       Media type; application/octet-stream when not given
	 * @param string|null $att_code       Blob attribute to set instead of adding an Attachment
	 * @param bool        $simulate       When true (default), everything is checked and nothing is stored
	 * @param string|null $comment        Why the file is being attached, recorded in the object's history
	 *
	 * @return mixed Where the document landed, and what it is
	 *
	 * @throws ToolCallException When the object, the attribute, the encoding or the size rules the call out.
	 */
	public static function execute(
		string  $class,
		int     $id,
		string  $filename,
		string  $content_base64,
		?string $mimetype = null,
		?string $att_code = null,
		bool    $simulate = WritePlan::SIMULATE_BY_DEFAULT,
		?string $comment = null,
	): mixed {
		$oTarget = self::target($class, $id);
		$oDocument = self::document($filename, $content_base64, $mimetype);

		return ($att_code === null || $att_code === '')
			? self::asAttachment($oTarget, $class, $id, $oDocument, $simulate, $comment)
			: self::asAttribute($oTarget, $class, $id, $att_code, $oDocument, $simulate, $comment);
	}

	/**
	 * The object being attached to, if this caller may modify that one.
	 *
	 * Attaching a file to a ticket is modifying the ticket, whichever of the two
	 * rows the bytes end up in - so the right that is checked is the right to
	 * modify the target, per object, and not the right to create an Attachment
	 * somewhere.
	 *
	 * @throws ToolCallException
	 */
	private static function target(string $sClass, int $iId): DBObject
	{
		if ($iId < 1) {
			throw new ToolCallException('Invalid ID. Please specify a valid object ID.');
		}
		if (!MetaModel::IsValidClass($sClass) || !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			throw new ToolCallException("Unknown class '{$sClass}'."); // hide that the class exists
		}
		if (MetaModel::DBIsReadOnly()) {
			throw new ToolCallException('The database is in read-only mode, cannot attach documents.');
		}

		$oSet = new DBObjectSet(ObjectQuery::ById($sClass, $iId));
		if ($oSet->Count() === 0) {
			throw new ToolCallException("Object {$sClass}::{$iId} not found."); // hide that the object exists
		}

		// Fetched before the rights check and rewound after, not the other way
		// round: the check is handed this same set, the addon is free to iterate
		// it, and a Fetch() on a spent cursor returns null - which would surface
		// as a fatal on the next line rather than as a refusal. The class comes
		// from the object for the same reason it does everywhere else here:
		// Fetch() instantiates the leaf from a finalclass column already read.
		/** @var DBObject $oObject */
		$oObject = $oSet->Fetch();
		$oSet->Rewind();

		$sFinalClass = get_class($oObject);
		if ($sFinalClass !== $sClass) {
			throw new ToolCallException("Object {$sClass}::{$iId} is of class '{$sFinalClass}'. Rerun with the correct final class.");
		}

		if (!UserRights::IsActionAllowed($sClass, UR_ACTION_MODIFY, $oSet)) {
			throw new ToolCallException("Access denied: cannot modify {$sClass}::{$iId}.");
		}

		if ($oObject->IsReadOnly()) {
			throw new ToolCallException("Object {$sClass}::{$iId} is read-only, cannot attach to it.");
		}

		return $oObject;
	}

	/**
	 * The file, decoded and bounded.
	 *
	 * The same ceiling as reading, applied to the decoded bytes rather than to
	 * the base64: what matters is what lands in the database, and a caller told
	 * the limit in the units it sent would still have to do the arithmetic.
	 *
	 * @throws ToolCallException
	 */
	private static function document(string $sFilename, string $sBase64, ?string $sMimeType): ormDocument
	{
		// basename() and not a rejection: a client that sends a path is a
		// client that had one, not an attack, and the last element is what it
		// meant. What must not survive is anything a later reader might treat
		// as a path.
		$sFilename = trim(basename(str_replace('\\', '/', $sFilename)));
		if ($sFilename === '' || $sFilename === '.' || $sFilename === '..') {
			throw new ToolCallException('A filename is required.');
		}

		$sData = base64_decode($sBase64, true);
		if ($sData === false) {
			throw new ToolCallException('content_base64 is not valid base64. Send the file base64-encoded, with no data: prefix and no line breaks.');
		}
		if ($sData === '') {
			throw new ToolCallException('The file is empty.');
		}

		$iMax = DocumentAccess::MaxBytes();
		if (strlen($sData) > $iMax) {
			throw new ToolCallException(sprintf(
				'The file is %d bytes and this instance accepts at most %d through MCP (%s).',
				strlen($sData),
				$iMax,
				DocumentAccess::MODULE_SETTING_MAX_BYTES
			));
		}

		return new ormDocument($sData, ($sMimeType === null || $sMimeType === '') ? 'application/octet-stream' : $sMimeType, $sFilename);
	}

	/**
	 * The console's answer: a row in Attachment pointing at the object.
	 *
	 * SetItem() is iTop's own, and does more than fill two columns - it carries
	 * the organisation across, which is what the attachment's own visibility
	 * rules are read from. temp_id is emptied because a temporary attachment is
	 * one still tied to a form that has not been submitted; this one is already
	 * attached to a saved object.
	 *
	 * @throws ToolCallException
	 */
	private static function asAttachment(DBObject $oTarget, string $sClass, int $iId, ormDocument $oDocument, bool $bSimulate, ?string $sComment): mixed
	{
		if (!MetaModel::IsValidClass(self::ATTACHMENT_CLASS)) {
			throw new ToolCallException(
				'This iTop has no Attachment class, so a file can only be stored in a document attribute. '
				.'Call core_class_schema on the class and pass att_code, or install the attachments module.'
			);
		}
		if (!UserRights::IsActionAllowed(self::ATTACHMENT_CLASS, UR_ACTION_CREATE)) {
			throw new ToolCallException('Access denied: cannot create attachments.');
		}

		$oAttachment = MetaModel::NewObject(self::ATTACHMENT_CLASS);
		$oAttachment->SetItem($oTarget);
		$oAttachment->Set('temp_id', '');
		$oAttachment->Set('contents', $oDocument);

		WritePlan::Check($oAttachment, "An attachment on {$sClass}::{$iId}");

		if ($bSimulate) {
			return ToolOutput::Structured([
				'class'       => self::ATTACHMENT_CLASS,
				'simulated'   => true,
				'attached_to' => ['class' => $sClass, 'id' => $iId],
				'document'    => DocumentAccess::Describe($oDocument, self::ATTACHMENT_CLASS, 0, 'contents'),
			]);
		}

		ChangeTracking::Explain($sComment);

		try {
			$iAttachmentId = $oAttachment->DBInsert();
		} catch (\Exception $e) {
			throw new ToolCallException('Failed to attach the document: '.$e->getMessage());
		}

		return ToolOutput::Structured([
			'class'       => self::ATTACHMENT_CLASS,
			'id'          => $iAttachmentId,
			'simulated'   => false,
			'attached_to' => ['class' => $sClass, 'id' => $iId],
			'document'    => DocumentAccess::Describe($oDocument, self::ATTACHMENT_CLASS, $iAttachmentId, 'contents'),
		]);
	}

	/**
	 * The other answer: the file is the value of an attribute on the object.
	 *
	 * This one replaces what was there, which is why the dry run reports the
	 * document that would go in: a picture set on the wrong object is not
	 * recoverable from the response that says it worked.
	 *
	 * @throws ToolCallException
	 */
	private static function asAttribute(DBObject $oTarget, string $sClass, int $iId, string $sAttCode, ormDocument $oDocument, bool $bSimulate, ?string $sComment): mixed
	{
		if (!MetaModel::IsValidAttCode($sClass, $sAttCode)) {
			throw new ToolCallException("Unknown attribute '{$sAttCode}' on class '{$sClass}'.");
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if (!$oAttDef instanceof AttributeBlob) {
			throw new ToolCallException("Attribute '{$sAttCode}' on '{$sClass}' does not hold a document. Use core_object_update for it.");
		}
		if (!$oAttDef->IsWritable()) {
			throw new ToolCallException("Attribute '{$sAttCode}' is not writable.");
		}
		if (!UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY)) {
			throw new ToolCallException("Write access denied on attribute '{$sAttCode}'.");
		}

		$oTarget->Set($sAttCode, $oDocument);

		WritePlan::Check($oTarget, "{$sClass}::{$iId}");

		if ($bSimulate) {
			return ToolOutput::Structured([
				'class'       => $sClass,
				'id'          => $iId,
				'simulated'   => true,
				'attached_to' => ['class' => $sClass, 'id' => $iId],
				'document'    => DocumentAccess::Describe($oDocument, $sClass, $iId, $sAttCode),
			]);
		}

		ChangeTracking::Explain($sComment);

		try {
			$oTarget->DBUpdate();
		} catch (\Exception $e) {
			throw new ToolCallException('Failed to store the document: '.$e->getMessage());
		}

		return ToolOutput::Structured([
			'class'       => $sClass,
			'id'          => $iId,
			'simulated'   => false,
			'attached_to' => ['class' => $sClass, 'id' => $iId],
			'document'    => DocumentAccess::Describe($oDocument, $sClass, $iId, $sAttCode),
		]);
	}
}
