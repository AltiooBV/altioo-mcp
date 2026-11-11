<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Api;

use Altioo\iTop\Extension\MCP\Helper\MCPMethod;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use CMDBObject;
use Combodo\iTop\Core\CMDBChange\CMDBChangeOrigin;
use Psr\Http\Message\MessageInterface;
use Throwable;

/**
 * What this request says about a change it makes: which tool, and why.
 *
 * A change iTop records carries the user, the origin and a line of free text,
 * and until 1.2 this class wrote the channel into that line: `Jane Doe (MCP:
 * core_object_update) - reason`. That put the tool where the object's history
 * shows it, and it cost something the history was not meant to pay: iTop's own
 * data-subject tool, Combodo's personal data anonymizer, finds the case-log
 * entries of a person by `CMDBChange.userinfo = <their name>`, exactly. A line
 * with a suffix never equals the name, so an entry written through this
 * endpoint kept the person's name after they were anonymised (doc/adr/0009).
 *
 * So the line is left to iTop: the user's name, as the console and REST write
 * it, and with the impersonation text iTop adds on its own. Nothing is
 * composed. What the line used to carry is recorded on this endpoint's audit
 * row instead - the tool is its `mcp_name`, the caller's reason is its
 * `change_comment` - and the row links to the change. The history no longer
 * names the tool: once the audit row is purged, or `log_mcp_service` is off,
 * the tool and the reason are gone.
 *
 * The origin stays `custom-extension`, the value iTop reserves for changes made
 * by an extension. Adding an `mcp` value to that enum would read better in a
 * filter and would cost an ALTER TABLE on `priv_change` at every setup - the
 * one table in an old instance with tens of millions of rows.
 *
 * @api
 * @since 1.0.0
 */
final class ChangeTracking
{
	/** `userinfo` is a VARCHAR(255), counted in characters. */
	private const MAX_INFO_CHARS = 255;

	/** How long a tool name may be before it is one by name only. */
	private const MAX_TOOL_CHARS = 64;

	/** The tool this request is running, as the client named it. */
	private static ?string $sTool = null;

	/** Why, when the caller said. */
	private static ?string $sComment = null;

	/**
	 * The `comment` property, spelled once so that every writing tool spells it
	 * the same way - the counterpart of {@see WritePlan::SimulateSchemaProperty()}.
	 *
	 * Optional, where REST makes it mandatory. A mandatory field is answered by
	 * whoever is asked, and what a model would put there when it has nothing to
	 * say is a sentence restating the call: "Updating the ticket." A history
	 * full of those is worse than one without them, because it reads like a
	 * reason and is not one.
	 *
	 * @param string $sWhatItDoes e.g. 'the change is being made'
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function CommentSchemaProperty(string $sWhatItDoes): array
	{
		return [
			'type'        => 'string',
			'description' => 'Why '.$sWhatItDoes.', in one short sentence, recorded in the audit trail beside the change. The reason the user gave, or nothing.',
			'maxLength'   => self::MAX_INFO_CHARS,
		];
	}

	/**
	 * Remembers the tool this request runs and sets the change up the way iTop
	 * does for any other writer.
	 *
	 * Called once, before the server runs, so that it does not depend on the
	 * tool remembering to ask for it. The body is read defensively and put
	 * back: it is the same stream the transport is about to parse, and a
	 * request whose name cannot be read is still a request that must run.
	 *
	 * @since 1.0.0
	 */
	public static function ForRequest(?MessageInterface $oRequest): void
	{
		self::$sTool = self::toolNameOf($oRequest);
		self::$sComment = null;

		self::apply();
	}

	/**
	 * Records why, from a tool that asked its caller. The reason goes to the
	 * audit row ({@see Comment()}), not to the change.
	 *
	 * Safe to call on a dry run and safe to call twice: the last reason said
	 * is the one kept, and the current change is dropped so that the next write
	 * builds a fresh one.
	 *
	 * @since 1.0.0
	 */
	public static function Explain(?string $sComment): void
	{
		self::$sComment = self::oneLine($sComment);

		self::apply();
	}

	/**
	 * The tool this request runs, as the client named it, cleaned.
	 *
	 * @since 1.3.0
	 */
	public static function Tool(): ?string
	{
		return self::$sTool;
	}

	/**
	 * Why the caller said it was making the change, as one line cut to the audit
	 * column, or null.
	 *
	 * Free text written by the caller, and it may name people.
	 *
	 * @since 1.3.0
	 */
	public static function Comment(): ?string
	{
		return self::$sComment === null ? null : mb_substr(self::$sComment, 0, self::MAX_INFO_CHARS);
	}

	/** For tests, and for a runner that serves more than one request. @since 1.3.0 */
	public static function Forget(): void
	{
		self::$sTool = null;
		self::$sComment = null;
	}

	/**
	 * Gives the next write a fresh change with iTop's default line and this
	 * endpoint's origin.
	 *
	 * SetTrackInfo() is read when the change is created and SetTrackOrigin()
	 * documents itself as a no-op once one exists, so setting either without
	 * dropping the current change would be setting it for the request after
	 * next. Dropping an unwritten change costs nothing: iTop persists it in
	 * CMDBChangeOp::OnInsert and not before.
	 */
	private static function apply(): void
	{
		try {
			// null is "the default": iTop writes the user's name, with its own
			// text under impersonation, as the console and REST do.
			CMDBObject::SetTrackInfo(null);
			CMDBObject::SetTrackOrigin(CMDBChangeOrigin::CUSTOM_EXTENSION);
			CMDBObject::SetCurrentChange(null);
		} catch (Throwable $e) {
			// Never worth failing the change over.
			ErrorReport::Log('Could not set up the change for the MCP caller: '.$e->getMessage());
		}
	}

	/**
	 * The tool a JSON-RPC request is calling, as far as it can be trusted.
	 *
	 * Whatever the client sent, cut to what belongs in a VARCHAR shown in the
	 * console: a tool name is [namespace]_[snake_case] by construction, so
	 * anything outside that character set is either a client that renamed
	 * something or a string that has no business being stored.
	 */
	private static function toolNameOf(?MessageInterface $oRequest): ?string
	{
		if ($oRequest === null) {
			return null;
		}

		try {
			$oBody = $oRequest->getBody();
			if (!$oBody->isSeekable()) {
				// The transport has not read it yet and must be able to.
				return null;
			}

			$oBody->rewind();
			$sBody = $oBody->getContents();
			$oBody->rewind();
		} catch (Throwable $e) {
			return null;
		}

		if ($sBody === '') {
			return null;
		}

		$aPayload = json_decode($sBody, true);
		if (!is_array($aPayload) || ($aPayload['method'] ?? null) !== MCPMethod::TOOLS_CALL) {
			return null;
		}

		$sName = $aPayload['params']['name'] ?? null;
		if (!is_string($sName) || $sName === '') {
			return null;
		}

		$sName = preg_replace('/[^A-Za-z0-9_\-]/', '', $sName);

		return ($sName === null || $sName === '') ? null : mb_substr($sName, 0, self::MAX_TOOL_CHARS);
	}

	/**
	 * A comment as one line of text.
	 *
	 * It lands in a single-line field that the console renders in a list: a
	 * newline or a control character in it is either a model formatting a
	 * paragraph or something trying to make one row look like two.
	 */
	private static function oneLine(?string $sComment): ?string
	{
		if ($sComment === null) {
			return null;
		}

		$sComment = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $sComment);
		if ($sComment === null) {
			return null;
		}

		$sComment = trim(preg_replace('/\s+/u', ' ', $sComment) ?? '');

		return $sComment === '' ? null : $sComment;
	}
}
