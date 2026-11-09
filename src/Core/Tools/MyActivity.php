<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Core\Tools;

use Altioo\iTop\Extension\MCP\Abstract\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Exception\MCPPortalException;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use Altioo\iTop\Extension\MCP\Helper\ObjectQuery;
use Altioo\iTop\Extension\MCP\Helper\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Helper\ToolOutput;
use Altioo\iTop\Extension\MCP\Service\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\PortalAware;
use Altioo\iTop\Extension\MCP\Service\PortalMode;
use AltiooEventMCPService;
use CMDBChange;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use Throwable;
use UserRights;

/**
 * What this account's credentials did through this endpoint, from the audit
 * trail: "what did my assistant do in my name?".
 *
 * **Own rows only, by key and by nothing else.** The filter is
 * `user_id = <the real user>` (the account that acted, under impersonation as
 * in `CMDBChange`), there is no argument to name another user, and no login is
 * ever matched: a login is a string that is rewritten on anonymisation and can
 * be reused. A row of an account that was deleted holds no key (0), and a row
 * written before the key existed holds none either, so neither is found and
 * `coverage` says so rather than guessing.
 *
 * **The rows are read with `AllowAllData()`**, because no ordinary profile may
 * read the audit class, and that is why everything shown is narrowed here: the
 * caller sees the objects and attribute codes it may see now, and the rest is
 * counted without being named. `request_params` and `refusal_text` are never
 * returned: they hold the raw parameters and what a tool said, which can quote
 * a third party. The reason (`change_comment`) is returned, because it is text
 * this account's own credential sent in that very call; it is marked as text to
 * report and not to follow, since a model wrote it.
 *
 * **Served in portal mode too, as one deliberate exception** to "what the
 * portal offers" (doc/adr/0010): an account's own trail. There an object is
 * named only if the portal shows it now ({@see ObjectAccess::Readable()}), an
 * attribute code only if the portal's view form shows it and the change it
 * belongs to still exists, and coverage says nothing of the instance's
 * configuration.
 *
 * @since 1.3.0
 */
class MyActivity extends AbstractMCPTool implements PortalAware
{
	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT = 100;

	#[\Override]
	public function getNamespace(): string
	{
		return 'core';
	}

	/** Who is calling and what they did, rather than what they can reach. */
	#[\Override]
	public function getToolset(): string
	{
		return 'server';
	}

	#[\Override]
	protected function defaultTitle(): string
	{
		return 'My Activity';
	}

	#[\Override]
	public function getDescription(): ?string
	{
		return 'List what this account\'s credentials did through this endpoint, newest first: when, which tool, whether it was a dry run or failed, the objects it changed or read that you may still see, and the reason given. Only your own calls; there is no way to ask for anyone else\'s. Read "coverage" for what the record cannot show.';
	}

	#[\Override]
	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'My activity',
			true,   // readOnlyHint
			false,  // destructiveHint
			true,   // idempotentHint
			false,  // openWorldHint
		);
	}

	#[\Override]
	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'since'  => [
					'type'        => 'string',
					'description' => 'Only calls from this date or date-time on, in the instance\'s time zone, e.g. 2026-11-01 or 2026-11-01 09:30:00. Empty for all.',
					'default'     => '',
				],
				'limit'  => [
					'type'        => 'integer',
					'description' => 'Calls per page.',
					'default'     => self::DEFAULT_LIMIT,
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
				],
				'offset' => [
					'type'        => 'integer',
					'description' => 'Calls to skip; pass next_offset back.',
					'default'     => 0,
					'minimum'     => 0,
				],
			],
			'required'   => [],
		];
	}

	/**
	 * @param string $since  Only calls from this date or date-time on
	 * @param int    $limit  Calls per page
	 * @param int    $offset Calls to skip
	 *
	 * @return mixed The calls, newest first, and what the record cannot show
	 * @throws ToolCallException When the date is not one, or the account has no key to select by.
	 */
	public static function execute(string $since = '', int $limit = self::DEFAULT_LIMIT, int $offset = 0): mixed
	{
		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new ToolCallException('Invalid limit. Please specify a limit between 1 and '.self::MAX_LIMIT.'.');
		}
		if ($offset < 0) {
			throw new ToolCallException('Invalid offset. Please specify a non-negative offset.');
		}

		$sFrom = null;
		if (trim($since) !== '') {
			$iTime = strtotime(trim($since));
			if ($iTime === false) {
				throw new ToolCallException('since is not a date: use 2026-11-01 or 2026-11-01 09:30:00.');
			}
			$sFrom = date('Y-m-d H:i:s', $iTime);
		}

		// The account that acted: the real user under impersonation. Never a
		// value from the request.
		$iUser = (int)CMDBChange::GetCurrentUserId();
		if ($iUser < 1) {
			throw new ToolCallException('This session has no account to look up the activity of.');
		}

		try {
			$sOql = 'SELECT AltiooEventMCPService WHERE user_id = :user'.($sFrom === null ? '' : ' AND date >= :since');
			$aArgs = ['user' => $iUser] + ($sFrom === null ? [] : ['since' => $sFrom]);
			$oSearch = DBObjectSearch::FromOQL($sOql);
			$oSearch->AllowAllData();
			$oSet = new DBObjectSet($oSearch, ['date' => false, 'id' => false], $aArgs, null, $limit, $offset);
			$iTotal = $oSet->Count();

			$aRows = [];
			while ($oRow = $oSet->Fetch()) {
				if ($oRow instanceof AltiooEventMCPService) {
					$aRows[] = self::plain($oRow);
				}
			}
			$aRows = self::narrowed($aRows);

			$iNext = $offset + $limit;

			return ToolOutput::Json([
				'total'       => $iTotal,
				'activity'    => $aRows,
				'has_more'    => $iNext < $iTotal,
				'next_offset' => $iNext < $iTotal ? $iNext : null,
				'coverage'    => self::coverage(),
				'untrusted_text' => ['fields' => ['activity[].reason'], 'note' => ObjectSerializer::UNTRUSTED_NOTICE],
			]);
		} catch (ToolCallException $oException) {
			throw $oException;
		} catch (MCPPortalException $oException) {
			throw new ToolCallException($oException->getMessage());
		} catch (Throwable $oException) {
			throw new ToolCallException(MCPHelper::OpaqueFailure('Failed to read your activity', $oException));
		}
	}

	/**
	 * One row as it is stored, less everything that may not be returned.
	 *
	 * Not here, on purpose: request_params, refusal_text, error_ref, the
	 * message, the user's login and the tokens' ids. What a row says about a
	 * credential is its kind.
	 *
	 * @return array<string, mixed>
	 */
	private static function plain(AltiooEventMCPService $oRow): array
	{
		$sMethod = (string)$oRow->Get('mcp_method');

		return [
			'date'       => (string)$oRow->Get('date'),
			'method'     => $sMethod,
			// For tools/call the tool; for resources/read the resource; for
			// initialize, the client's own label.
			'name'       => (string)$oRow->Get('mcp_name'),
			'status'     => (string)$oRow->Get('status'),
			'dry_run'    => (string)$oRow->Get('simulate'),
			'client'     => (string)$oRow->Get('client'),
			'portal'     => (string)$oRow->Get('portal'),
			'credential' => (int)$oRow->Get('personal_token_id') > 0 ? 'personal token' : ((int)$oRow->Get('user_token_id') > 0 ? 'application token' : null),
			'reason'     => (string)$oRow->Get('change_comment') === '' ? null : (string)$oRow->Get('change_comment'),
			'_change'    => (int)$oRow->Get('change_id'),
			'_changed'   => (string)$oRow->Get('changed_objects'),
			'_read'      => (string)$oRow->Get('read_objects'),
		];
	}

	/**
	 * The objects of each row that this account may see now, and nothing of
	 * the others but how many.
	 *
	 * Asked once per class for the whole page, so a page of rows is a handful
	 * of questions and not one per object. An object that no longer exists
	 * cannot be asked about: one the call deleted is named when its class is
	 * readable, which is the caller's own act; one it only read, and that is
	 * gone, is counted.
	 *
	 * @param array<int, array<string, mixed>> $aRows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function narrowed(array $aRows): array
	{
		$aParsed = [];
		$aWanted = [];
		foreach ($aRows as $i => $aRow) {
			$aChanged = self::parseChanged((string)$aRow['_changed']);
			$aRead = self::parseRead((string)$aRow['_read']);
			$aParsed[$i] = [$aChanged, $aRead];
			foreach ($aChanged as $aObject) {
				if ($aObject['verb'] !== 'deleted') {
					$aWanted[$aObject['class']][$aObject['id']] = true;
				}
			}
			foreach ($aRead as $aObject) {
				$aWanted[$aObject['class']][$aObject['id']] = true;
			}
		}
		$bPortal = PortalMode::Current() !== null;
		$oAccess = ObjectAccess::Current();
		$aObjects = $bPortal ? self::readableInPortal($oAccess, $aWanted) : [];
		$aReadable = $bPortal ? array_map(static fn (array $a): array => array_fill_keys(array_keys($a), true), $aObjects) : self::readable($aWanted);

		foreach ($aRows as $i => $aRow) {
			[$aChanged, $aRead] = $aParsed[$i];
			$aShownChanged = [];
			$iHidden = 0;
			foreach ($aChanged as $aObject) {
				$sClass = $aObject['class'];
				// An object the call deleted cannot be asked about. On the console its class is enough, the
				// act being the caller's own; in portal mode it is counted, since the portal cannot say
				// whether it showed it.
				$bShown = $aObject['verb'] === 'deleted'
					? (!$bPortal && self::classIsReadable($sClass))
					: isset($aReadable[$sClass][$aObject['id']]);
				if (!$bShown) {
					$iHidden++;
					continue;
				}
				$aShownChanged[] = [
					'class'      => $sClass,
					'id'         => $aObject['id'],
					'verb'       => $aObject['verb'],
					'attributes' => self::attributesShown($oAccess, $aObjects[$sClass][$aObject['id']] ?? null, $sClass, $aObject['attributes'], (int)$aRow['_change'] > 0),
				];
			}
			$aShownRead = [];
			foreach ($aRead as $aObject) {
				if (isset($aReadable[$aObject['class']][$aObject['id']])) {
					$aShownRead[] = ['class' => $aObject['class'], 'id' => $aObject['id']];
				} else {
					$iHidden++;
				}
			}
			unset($aRows[$i]['_changed'], $aRows[$i]['_read'], $aRows[$i]['_change']);
			$aRows[$i]['changed'] = $aShownChanged;
			$aRows[$i]['read'] = $aShownRead;
			$aRows[$i]['objects_you_cannot_see'] = $iHidden;
		}

		return array_values($aRows);
	}

	/**
	 * The lines ChangeSummary wrote: `Class::id verb (code, code, +N more)`,
	 * and a last line counting the rest, which names nothing.
	 *
	 * @return array<int, array{class: string, id: int, verb: string, attributes: array<int, string>}>
	 */
	public static function parseChanged(string $sText): array
	{
		$aOut = [];
		foreach (explode("\n", $sText) as $sLine) {
			if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)::(\d+) (created|deleted|updated)(?: \((.*)\))?$/', trim($sLine), $aMatch) !== 1) {
				continue;
			}
			$aAttributes = [];
			foreach (explode(',', $aMatch[4] ?? '') as $sCode) {
				$sCode = trim($sCode);
				if ($sCode !== '' && $sCode[0] !== '+') {
					$aAttributes[] = $sCode;
				}
			}
			$aOut[] = ['class' => $aMatch[1], 'id' => (int)$aMatch[2], 'verb' => $aMatch[3], 'attributes' => $aAttributes];
		}

		return $aOut;
	}

	/**
	 * The line ReadSummary wrote: `Class: 1, 2 (+41 more); Class: 7`.
	 *
	 * @return array<int, array{class: string, id: int}>
	 */
	public static function parseRead(string $sText): array
	{
		$aOut = [];
		foreach (explode(';', $sText) as $sPart) {
			if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*): ([\d, ]+)(?: \(\+\d+ more\))?\s*$/', $sPart, $aMatch) !== 1) {
				continue;
			}
			foreach (explode(',', $aMatch[2]) as $sId) {
				if (trim($sId) !== '' && (int)$sId > 0) {
					$aOut[] = ['class' => $aMatch[1], 'id' => (int)$sId];
				}
			}
		}

		return $aOut;
	}

	private static function classIsReadable(string $sClass): bool
	{
		return MetaModel::IsValidClass($sClass)
			&& !ObjectHistory::IsReserved($sClass)
			&& UserRights::IsActionAllowed($sClass, UR_ACTION_READ);
	}

	/**
	 * Which of the objects this account may read now, by class and id: asked
	 * through the silo and the object-level rights, as a read by id is.
	 *
	 * @param array<string, array<int, true>> $aWanted
	 *
	 * @return array<string, array<int, true>>
	 */
	private static function readable(array $aWanted): array
	{
		$aOut = [];
		foreach ($aWanted as $sClass => $aIds) {
			if (!self::classIsReadable($sClass)) {
				continue;
			}
			foreach (array_chunk(array_keys($aIds), 200) as $aChunk) {
				$oSet = new DBObjectSet(ObjectQuery::ByIds($sClass, $aChunk));
				while ($oObject = $oSet->Fetch()) {
					$aOut[$sClass][(int)$oObject->GetKey()] = true;
				}
			}
		}

		return $aOut;
	}

	/** Objects asked about in one page, in portal mode, where each is asked through the portal. */
	private const MAX_PORTAL_CHECKS = 300;

	/**
	 * Portal mode: the objects the portal shows this user now, each asked the
	 * way a read by id is asked ({@see ObjectAccess::Readable()}: the user's
	 * rights, then the portal's scope). Bounded, since each question is a
	 * handful of queries; what is not asked about is counted, not named.
	 *
	 * @param array<string, array<int, true>> $aWanted
	 *
	 * @return array<string, array<int, DBObject>>
	 */
	private static function readableInPortal(ObjectAccess $oAccess, array $aWanted): array
	{
		$aOut = [];
		$iAsked = 0;
		foreach ($aWanted as $sClass => $aIds) {
			foreach (array_keys($aIds) as $iId) {
				if (++$iAsked > self::MAX_PORTAL_CHECKS) {
					return $aOut;
				}
				$oObject = $oAccess->Readable($sClass, (int)$iId);
				if ($oObject !== null) {
					$aOut[$sClass][(int)$iId] = $oObject;
				}
			}
		}

		return $aOut;
	}

	/**
	 * The attribute codes to list for one changed object.
	 *
	 * The console's rule (see readableAttributes()); in portal mode also only
	 * what the portal's view form shows of the object as it is now, and none
	 * at all once the change they belong to is gone: the code of a field the
	 * form hides, or of one a vanished change cannot vouch for, is not named.
	 *
	 * @param array<int, string> $aCodes
	 *
	 * @return array<int, string>
	 */
	private static function attributesShown(ObjectAccess $oAccess, ?DBObject $oObject, string $sClass, array $aCodes, bool $bChangeExists): array
	{
		$aCodes = self::readableAttributes($sClass, $aCodes);
		if (PortalMode::Current() === null) {
			return $aCodes;
		}
		if (!$bChangeExists || $oObject === null) {
			return [];
		}
		$fShown = $oAccess->ShownOf($oObject);

		return array_values(array_filter($aCodes, static fn (string $sCode): bool => $fShown($sCode)));
	}

	/**
	 * The attribute codes of a change this account may read, whatever the
	 * object: a code names a field, and a field the account cannot read is not
	 * to be learned from a list of what was changed. "Depends" is hidden too:
	 * it cannot be answered for a class.
	 *
	 * @param array<int, string> $aCodes
	 *
	 * @return array<int, string>
	 */
	private static function readableAttributes(string $sClass, array $aCodes): array
	{
		$aOut = [];
		foreach ($aCodes as $sCode) {
			if (MetaModel::IsValidAttCode($sClass, $sCode)
				&& !ObjectSerializer::IsSensitive(MetaModel::GetAttributeDef($sClass, $sCode), $sClass)
				&& UserRights::IsActionAllowedOnAttribute($sClass, $sCode, UR_ACTION_READ) === UR_ALLOWED_YES) {
				$aOut[] = $sCode;
			}
		}

		return $aOut;
	}

	/**
	 * What this record cannot show, as it stands now.
	 *
	 * @return array<string, mixed>
	 */
	private static function coverage(): array
	{
		// A portal user is told that the record is not complete, and nothing of how this
		// instance is set up: no level, no retention, no switch.
		if (PortalMode::Current() !== null) {
			return ['notes' => ['Some earlier calls may not be listed. Objects you can no longer see are counted in objects_you_cannot_see.']];
		}

		$sLevel = (string)MetaModel::GetModuleSetting(MCPHelper::MODULE_NAME, MCPHelper::MODULE_SETTING_LOG_LEVEL, MCPHelper::DEFAULT_LOG_LEVEL);
		// An unset or unknown level records every call, which is what "info" is.
		if (!in_array($sLevel, [MCPHelper::LOG_LEVEL_ERROR, MCPHelper::LOG_LEVEL_DEBUG], true)) {
			$sLevel = 'info';
		}
		$iRetention = MCPHelper::GetAuditRetentionDays();
		$aNotes = [
			'Rows are found by your account\'s key, never by login. A row written before that key existed (iTop MCP 1.2 and earlier), and a row of an account that was deleted, hold no key and are not listed.',
			'A call is listed with the objects it changed or read only up to a cap; the rest is counted, not named. Objects you can no longer see are counted in objects_you_cannot_see.',
		];
		if (!MCPHelper::LogsCalls()) {
			$aNotes[] = 'This instance does not record calls (log_mcp_service is off): nothing new appears here.';
		}
		if ($sLevel === MCPHelper::LOG_LEVEL_ERROR) {
			$aNotes[] = 'This instance records failures and connections only (log_mcp_level is error): successful calls are not listed.';
		}
		if ($iRetention > 0) {
			$aNotes[] = 'Calls older than '.$iRetention.' days are deleted (mcp_audit_retention_days).';
		}

		return [
			'recorded'       => MCPHelper::LogsCalls(),
			'level'          => $sLevel,
			'retention_days' => $iRetention > 0 ? $iRetention : null,
			'notes'          => $aNotes,
		];
	}
}
