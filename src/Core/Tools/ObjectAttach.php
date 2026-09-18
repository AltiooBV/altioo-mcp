<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ChangeTracking;
use Altioo\iTop\Extension\MCP\Helper\DocumentAccess;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
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
			.'The dry run validates the file and reports where it would land.';
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
			'mimetype_note' => [
				'type'        => ['string', 'null'],
				'description' => 'Why the stored media type is not the one that was declared, or null when it is. The file itself is unchanged either way.',
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
					'description' => 'Media type of the file, e.g. application/pdf or image/png. Worth naming: an image stored as image/* is one that can be looked at when it is read back. It is checked against the bytes you sent, so a file that turns out to be something else is stored as what it is and the response says so; leave it out and the type is read off the file.',
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
	 * @param string|null $mimetype       Media type, verified against the bytes; read off the file when not given
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
	): mixed
	{
		// The advisory scope, before anything reads it: a token pinned to dry
		// runs rehearses whatever the caller passed, and normalising here means
		// every branch and every reported `simulated` below is already right.
		$simulate = WritePlan::Simulated($simulate);

		$oTarget = self::target($class, $id);
		[$oDocument, $sMimeTypeNote] = self::document($filename, $content_base64, $mimetype);

		return ($att_code === null || $att_code === '')
			? self::asAttachment($oTarget, $class, $id, $oDocument, $simulate, $comment, $sMimeTypeNote)
			: self::asAttribute($oTarget, $class, $id, $att_code, $oDocument, $simulate, $comment, $sMimeTypeNote);
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
			throw new ToolCallException(MCPHelper::UnreadableClassRefusal($sClass)); // exists, but not for this account to read
		}

		if (ObjectHistory::IsReserved($sClass)) {
			throw new ToolCallException(sprintf(ObjectHistory::RESERVED_REFUSAL, $sClass));
		}
		$sAccessRefusal = AccessGrants::RefusalFor($sClass, $iId);
		if ($sAccessRefusal !== null) {
			throw new ToolCallException($sAccessRefusal);
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
	 * The one extra key a response carries when the stored media type is not
	 * the one that was asked for.
	 *
	 * Present only when there is something to say, so a caller that declared
	 * nothing surprising sees the same response shape it always did. The dry
	 * run reports it too, which is the point of a dry run: the answer to "what
	 * would this store" has to include the label.
	 *
	 * @return array{mimetype_note?: string}
	 */
	private static function mimeTypeNote(?string $sNote): array
	{
		// Always the key, null when there is nothing to say. A field that
		// appears only sometimes is a second response shape.
		return ['mimetype_note' => $sNote];
	}

	/**
	 * The file, decoded and bounded.
	 *
	 * The same ceiling as reading, applied to the decoded bytes rather than to
	 * the base64: what matters is what lands in the database, and a caller told
	 * the limit in the units it sent would still have to do the arithmetic.
	 *
	 * @throws ToolCallException
	 * @return array{0: ormDocument, 1: string|null} The file, and what to tell
	 *                                                the caller when the type
	 *                                                it declared was not used.
	 */
	private static function document(string $sFilename, string $sBase64, ?string $sMimeType): array
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

		// The declared type is a claim about bytes this endpoint is holding, so
		// it is checked against them rather than stored on trust. See
		// DocumentAccess::VerifiedMimeType() for why a disagreement overrides
		// rather than refuses.
		[$sVerified, $sNote] = DocumentAccess::VerifiedMimeType($sData, $sMimeType);

		return [new ormDocument($sData, $sVerified, $sFilename), $sNote];
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
	private static function asAttachment(DBObject $oTarget, string $sClass, int $iId, ormDocument $oDocument, bool $bSimulate, ?string $sComment, ?string $sMimeTypeNote = null): mixed
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
			return ToolOutput::Structured(['class' => self::ATTACHMENT_CLASS]
				+ WritePlan::Identity(self::ATTACHMENT_CLASS, null)
				+ [
					'simulated'   => true,
					'valid'       => true,
					'attached_to' => ['class' => $sClass, 'id' => $iId],
					'document'    => DocumentAccess::Describe($oDocument, self::ATTACHMENT_CLASS, 0, 'contents'),
				]
				+ self::mimeTypeNote($sMimeTypeNote));
		}

		ChangeTracking::Explain($sComment);

		// The answer is built inside the catch, not after it.
		//
		// DBInsert() commits and then returns, so everything below it runs with
		// the row already written - and a failure there is not a failed write,
		// it is a write nobody described. Left outside, a TypeError in the
		// description reached the SDK as an unhandled error: the caller was
		// told "Error while executing tool", with no reference and no id, for
		// an attachment that was sitting in the database. The reasonable next
		// move on that answer is to attach the file again.
		try {
			$iAttachmentId = $oAttachment->DBInsert();

			return ToolOutput::Structured(['class' => self::ATTACHMENT_CLASS]
				+ WritePlan::Identity(self::ATTACHMENT_CLASS, $iAttachmentId)
				+ [
					'simulated'   => false,
					'valid'       => true,
					'attached_to' => ['class' => $sClass, 'id' => $iId],
					'document'    => DocumentAccess::Describe($oDocument, self::ATTACHMENT_CLASS, $iAttachmentId, 'contents'),
				]
				+ self::mimeTypeNote($sMimeTypeNote));
		} catch (\Throwable $e) {
			// Committed or not, asked the same way core_object_create asks: an
			// unsaved object carries a negative temporary key, so a positive
			// one means the row reached the database.
			$iCommittedId = WritePlan::CommittedId($oAttachment);
			if ($iCommittedId === null) {
				throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to attach the document', $e));
			}

			// Stored, and the description of it is what failed. Reported as the
			// success it is, with the failure attached rather than substituted
			// for it - the file is not attached twice because the answer was
			// hard to build.
			return ToolOutput::Structured(['class' => self::ATTACHMENT_CLASS]
				+ WritePlan::Identity(self::ATTACHMENT_CLASS, $iCommittedId)
				+ [
					'simulated'   => false,
					'valid'       => true,
					'attached_to' => ['class' => $sClass, 'id' => $iId],
					'document'    => ['filename' => $oDocument->GetFileName(), 'mimetype' => $oDocument->GetMimeType()],
					'warning'     => MCPHelper::OpaqueFailure(
						"The document was attached as {$iCommittedId}, but the call failed while describing it",
						$e
					),
				]
				+ self::mimeTypeNote($sMimeTypeNote));
		}
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
	private static function asAttribute(DBObject $oTarget, string $sClass, int $iId, string $sAttCode, ormDocument $oDocument, bool $bSimulate, ?string $sComment, ?string $sMimeTypeNote = null): mixed
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
		// With the object in hand, UR_ALLOWED_DEPENDS is resolved and anything
		// short of a yes is a no. Read as a boolean, DEPENDS is 2 and therefore
		// truthy, which turns "ask me again with the object" into "yes".
		$oInstanceSet = new DBObjectSet(ObjectQuery::ById($sClass, $iId));
		if (UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_MODIFY, $oInstanceSet) !== UR_ALLOWED_YES) {
			throw new ToolCallException("Write access denied on attribute '{$sAttCode}'.");
		}

		$oTarget->Set($sAttCode, $oDocument);

		WritePlan::Check($oTarget, "{$sClass}::{$iId}");

		if ($bSimulate) {
			return ToolOutput::Structured(['class' => $sClass]
				+ WritePlan::Identity($sClass, $iId)
				+ [
					'simulated'   => true,
					'valid'       => true,
					'attached_to' => ['class' => $sClass, 'id' => $iId],
					'document'    => DocumentAccess::Describe($oDocument, $sClass, $iId, $sAttCode),
				]
				+ self::mimeTypeNote($sMimeTypeNote));
		}

		ChangeTracking::Explain($sComment);

		// Inside the catch for the same reason as the attachment branch: this
		// one updates an object that already exists, so "did it write" cannot
		// be answered from a key - but a failure while describing the write is
		// still not a failed write, and answering as though it were sends the
		// caller to store the file a second time.
		try {
			$oTarget->DBUpdate();

			return ToolOutput::Structured(['class' => $sClass]
				+ WritePlan::Identity($sClass, $iId)
				+ [
					'simulated'   => false,
					'valid'       => true,
					'attached_to' => ['class' => $sClass, 'id' => $iId],
					'document'    => DocumentAccess::Describe($oDocument, $sClass, $iId, $sAttCode),
				]
				+ self::mimeTypeNote($sMimeTypeNote));
		} catch (\Throwable $e) {
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to store the document', $e));
		}
	}
}
