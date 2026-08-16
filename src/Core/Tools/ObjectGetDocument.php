<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Exception\MCPDocumentException;
use Altioo\iTop\Extension\MCP\Helper\DocumentAccess;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * One document, named explicitly, per call.
 *
 * The counterpart of the itop://core/document/{class}/{id}/{att_code} resource
 * template, over the same code, for the clients that never fetch a resource -
 * the same reason core_class_schema exists beside itop://core/class/{class}.
 *
 * Deliberately not part of core_object_get. A read fans out: every attribute of
 * an object by default, fifty objects in a search. A file base64-encoded into
 * one of those is a context window spent by nobody's decision, and one that
 * cannot be taken back once it is in the conversation. Asking for one document
 * by name is the decision, and this is where it is made.
 *
 * @since 1.0.0
 */
class ObjectGetDocument extends AbstractMCPTool
{
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
		return 'Get Document';
	}

	public function getDescription(): ?string
	{
		return 'Read one document held by an iTop object - an attachment, a picture, any blob attribute - and return its content. '
			.'core_object_get reports each such attribute as filename, mimetype, size and a uri; this is how that uri is read. '
			.'An image comes back as an image; anything else as an embedded file. Ask for one document at a time, and only when its content is the question: '
			.'a file arrives in full, and a large one fills the context window it arrives in.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Get iTop document',
			true,   // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'class'    => [
					'type'        => 'string',
					'description' => 'iTop class of the object holding the document, e.g. Attachment for a file attached to a ticket.',
				],
				'id'       => [
					'type'        => 'integer',
					'description' => 'The ID of that object.',
					'minimum'     => 1,
				],
				'att_code' => [
					'type'        => 'string',
					'description' => 'Attribute code holding the document, e.g. contents on an Attachment. Call core_class_schema for the attribute codes of the class.',
				],
			],
			'required' => ['class', 'id', 'att_code'],
		];
	}

	/**
	 * @param string $class    The class of the object holding the document
	 * @param int    $id       The ID of that object
	 * @param string $att_code The blob attribute to read
	 *
	 * @return mixed The document, as image content or as an embedded file
	 *
	 * @throws ToolCallException When the object, the attribute or the document cannot be read.
	 */
	public static function execute(
		string $class,
		int    $id,
		string $att_code,
	): mixed {
		try {
			$oDocument = DocumentAccess::Fetch($class, $id, $att_code);
		} catch (MCPDocumentException $e) {
			throw new ToolCallException($e->getMessage());
		}

		return DocumentAccess::ContentFor($oDocument, DocumentAccess::Uri($class, $id, $att_code));
	}
}
