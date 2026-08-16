<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Helper;

use CMDBChange;
use CMDBObject;
use Combodo\iTop\Core\CMDBChange\CMDBChangeOrigin;
use Psr\Http\Message\MessageInterface;
use Throwable;
use UserRights;

/**
 * What the object's own history says about a change this endpoint made.
 *
 * Every write iTop performs is attached to a CMDBChange, and a CMDBChange
 * carries three things: the user, the origin, and a line of free text. Left
 * alone, that line is the user's name and nothing else - which is what the
 * console writes, and which is right there, because the console also shows who
 * clicked what. A write that arrives through this endpoint has no such context:
 * the same name appears in the history whether the person typed the change,
 * asked an assistant to make it, or granted a token to a scheduled agent that
 * has been making the same change nightly for a month.
 *
 * iTop's own REST API answers this with a mandatory `comment` on every write,
 * fed to {@see \CMDBObject::SetTrackInfo()}. This is the same idea, with the
 * two differences the two callers make necessary:
 *
 *  - **The channel is recorded whether or not anyone says anything.** A REST
 *    caller is a script whose author writes the comment once; a tool call is
 *    made by a model that may or may not have been told to explain itself, so
 *    the tool that made the change is filled in from the request rather than
 *    asked for. {@see ForRequest()} does that before any tool runs, which also
 *    means a tool from a pack that has never heard of this class is attributed
 *    exactly like a core one.
 *  - **The user survives it.** SetTrackInfo() *replaces* the line, so a caller
 *    that passes only a comment loses the name from the history - REST included
 *    (`user_id` still holds it, the line the console displays does not). The
 *    composed line here starts with the user, except under impersonation, where
 *    {@see \CMDBObject::GetTrackInfo()} prepends it itself.
 *
 * The origin stays `custom-extension`, the value iTop reserves for changes made
 * by an extension. Adding an `mcp` value to that enum would read better in a
 * filter and would cost an ALTER TABLE on `priv_change` at every setup - the
 * one table in an old instance with tens of millions of rows. The channel is in
 * the text instead, which is free, and which is the field the console shows.
 *
 * @since 1.0.0
 */
final class ChangeTracking
{
	/** `userinfo` is a VARCHAR(255), counted in characters. */
	private const MAX_INFO_CHARS = 255;

	/** How long a tool name may be before it is one by name only. */
	private const MAX_TOOL_CHARS = 64;

	/** What the channel is called in the history, when nothing else is known. */
	private const CHANNEL = 'MCP';

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
			'description' => 'Why '.$sWhatItDoes.', in one short sentence, recorded in the object\'s history next to the user and the tool. '
				.'Pass the reason the user gave, not a restatement of the call: "customer confirmed the laptop was returned" is worth recording, "updating the ticket" is not. Leave it out when there is nothing to add.',
			'maxLength'   => self::MAX_INFO_CHARS,
		];
	}

	/**
	 * Attributes every change this request makes to the tool that made it.
	 *
	 * Called once, before the server runs, so that the attribution does not
	 * depend on the tool remembering to ask for it. A tool that also takes a
	 * `comment` adds the why on top through {@see Explain()}.
	 *
	 * The body is read defensively and put back: it is the same stream the
	 * transport is about to parse, and a request whose name cannot be read is
	 * still a request that must run.
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
	 * Records why, from a tool that asked its caller.
	 *
	 * Safe to call on a dry run - nothing writes, so nothing is attributed -
	 * and safe to call twice: the current change is dropped rather than
	 * amended, so the next write builds one from what was last said. Two tool
	 * calls with two different reasons therefore produce two entries in the
	 * history, which is what they are.
	 *
	 * @since 1.0.0
	 */
	public static function Explain(?string $sComment): void
	{
		self::$sComment = self::oneLine($sComment);

		self::apply();
	}

	/**
	 * The line written to `userinfo`, from its parts.
	 *
	 * Pure, and separated from everything that touches iTop, because the shape
	 * of this string is the whole feature: it is what an auditor reads six
	 * months later, and it is the only part of the mechanism that can be
	 * asserted without a database.
	 *
	 * The comment is what gets cut when the line is too long. The user and the
	 * tool are what the row would be useless without; a reason that stops mid
	 * sentence still says more than no reason at all.
	 *
	 * @param string      $sUser         The name iTop would have written on its own.
	 * @param bool        $bImpersonated Whether iTop will prepend that name itself.
	 * @param string|null $sTool         Qualified tool name, when it is known.
	 * @param string|null $sComment      The caller's reason, when it gave one.
	 *
	 * @since 1.0.0
	 */
	public static function Compose(string $sUser, bool $bImpersonated, ?string $sTool, ?string $sComment): string
	{
		$sChannel = ($sTool === null || $sTool === '')
			? self::CHANNEL
			: self::CHANNEL.': '.$sTool;

		// Under impersonation GetTrackInfo() writes "A (on behalf of B) (<this>)"
		// - prefixing the name here as well would say it twice.
		$sPrefix = $bImpersonated ? '' : trim($sUser).' ';
		$sHead = $sPrefix.'('.$sChannel.')';

		if ($sComment === null || $sComment === '') {
			return mb_substr($sHead, 0, self::MAX_INFO_CHARS);
		}

		$sSeparator = ' - ';
		$iRoom = self::MAX_INFO_CHARS - mb_strlen($sHead) - mb_strlen($sSeparator);

		if ($iRoom < 1) {
			return mb_substr($sHead, 0, self::MAX_INFO_CHARS);
		}

		return $sHead.$sSeparator.mb_substr($sComment, 0, $iRoom);
	}

	/**
	 * Installs what has been said so far, and drops the change built from what
	 * was said before it.
	 *
	 * SetTrackInfo() is read when the change is created and SetTrackOrigin()
	 * documents itself as a no-op once one exists, so setting either without
	 * this would be setting it for the request after next. Dropping an
	 * unwritten change costs nothing: iTop persists it in CMDBChangeOp::OnInsert
	 * and not before, so one that no write ever used leaves no row behind.
	 */
	private static function apply(): void
	{
		try {
			CMDBObject::SetTrackInfo(self::Compose(
				CMDBChange::GetCurrentUserName(),
				UserRights::IsImpersonated(),
				self::$sTool,
				self::$sComment
			));
			CMDBObject::SetTrackOrigin(CMDBChangeOrigin::CUSTOM_EXTENSION);
			CMDBObject::SetCurrentChange(null);
		} catch (Throwable $e) {
			// Saying who made a change is never worth failing the change.
			// Without this the history keeps iTop's own default, which is the
			// user's name - less than was meant, and not wrong.
			MCPHelper::LogError('Could not attribute the change to the MCP caller: '.$e->getMessage());
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
		if (!is_array($aPayload) || ($aPayload['method'] ?? null) !== 'tools/call') {
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
