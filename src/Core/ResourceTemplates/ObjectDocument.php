<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\ResourceTemplates;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPResourceTemplate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetDocument as ObjectGetDocumentTool;
use Altioo\iTop\Extension\MCP\Exception\MCPDocumentException;
use Altioo\iTop\Extension\MCP\Service\Access\DocumentFetch;
use Altioo\iTop\Extension\MCP\Service\Read\DocumentAccess;
use Altioo\iTop\Extension\MCP\Service\Rights\ChangeLogReservation;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;
use Altioo\iTop\Extension\MCP\Api\ResourceRefusal;

/**
 * One document, by the URI a read reported.
 *
 * The natural home for bytes in MCP: resources are the half of the protocol
 * meant for content a client fetches deliberately, one URI at a time, and
 * resource contents - text or blob - are the shape the protocol already has for
 * them - so a client that knows how to save an attachment saves this one
 * without being told anything new.
 *
 * Served as core_object_get_document too, for the same reason the class schema
 * is served twice: plenty of clients never fetch resources, and support for
 * templates is thinner still. A model that cannot reach a document through one
 * door reaches it through the other, and both go through the same checks.
 *
 * @internal
 * @since 1.0.0
 */
class ObjectDocument extends AbstractMCPResourceTemplate
{
	protected function defaultTitle(): string
	{
		return 'iTop Document';
	}

	public function getDescription(): ?string
	{
		return 'Read '.ObjectGetDocumentTool::RETURNS.'. '
			.'URI: itop://core/document/{class}/{id}/{att_code}, which core_object_get reports as the "uri" of that attribute. '
			.'The core_object_get_document tool returns the same thing for clients that do not read resource templates.';
	}

	/** Bytes, which an operator may well want to serve and may well not. */
	public function getToolset(): string
	{
		return 'documents';
	}

	protected function getResourceNamespace(): string
	{
		return 'core';
	}

	protected function getResourcePath(): string
	{
		return DocumentAccess::URI_PATH.'/{class}/{id}/{att_code}';
	}

	/**
	 * Not one type: a document is whatever was uploaded, and saying
	 * application/json here would label every PDF as JSON.
	 */
	public function getMimeType(): ?string
	{
		return null;
	}

	public function getAnnotations(): ?Annotations
	{
		return new Annotations(
			[Role::User, Role::Assistant],
			0.5,
		);
	}

	/**
	 * @param string $uri      The full URI that was called
	 * @param string $class    The {class} variable, e.g. 'Attachment'
	 * @param string $id       The {id} variable - a string, because a URI carries no types
	 * @param string $att_code The {att_code} variable, e.g. 'contents'
	 *
	 * @return mixed The document as resource contents: its text for a text file, base64 for anything else, an image included
	 *
	 * @throws \Mcp\Server\Exception\ResourceReadException (see ResourceRefusal) When the object, the attribute or the document cannot be read.
	 */
	public function read(string $uri, string $class, string $id, string $att_code): mixed
	{
		if (!ctype_digit($id)) {
			throw ResourceRefusal::Because("Invalid id '{$id}' in '{$uri}'.");
		}
		// CMDBChangeOpSetAttributeBlob stores the previous contents of a blob,
		// so this is the same way into the change log core_object_get_document
		// refuses - and the one that was missed when that refusal went in.
		if (ChangeLogReservation::IsReserved($class)) {
			throw ResourceRefusal::Because(ChangeLogReservation::Refusal($class));
		}

		try {
			$oDocument = DocumentFetch::Fetch($class, (int)$id, $att_code);
		} catch (MCPDocumentException $e) {
			throw ResourceRefusal::Because($e->getMessage());
		}

		// Resource contents, never the image content a tool answers: a resources/read answer takes no other shape.
		return DocumentAccess::ResourceContentsFor($oDocument, $uri);
	}
}
