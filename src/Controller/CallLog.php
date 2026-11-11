<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Controller;

use Altioo\iTop\Extension\MCP\Api\ChangeTracking;
use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Helper\ErrorReport;
use Altioo\iTop\Extension\MCP\Helper\MCPMethod;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Helper\Settings;
use Altioo\iTop\Extension\MCP\Model\MCPResult;
use Altioo\iTop\Extension\MCP\Service\Audit\ChangeSummary;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\TokenScopes;
use AltiooEventMCPService;
use CMDBChange;
use CMDBObject;
use MetaModel;
use Throwable;
use UserRights;
use utils;

/**
 * The audit row a call leaves, written once the call is answered.
 *
 * Whether a call is logged at all, what the row says of the credential, the
 * account, the objects read and the change made, and how caller-supplied text
 * is made fit to store. What the request said is CallRequest's; what the call
 * answered is MCPResponse's.
 *
 * @internal
 * @since 2.0.0
 */
final class CallLog
{
	public static function LogIfConfigured(MCPResult $oResult): void
	{
		if (!Settings::LogsCalls()) {
			return;
		}

		// Only log actual MCP method calls, skip the rest of the handshake
		// (notifications/initialized, tools/list, etc.)
		$sMethod = $oResult->mcpMethod;
		if (utils::IsNullOrEmptyString($sMethod)) {
			return;
		}

		if (!in_array($sMethod, Settings::GetAuditedMethods(), true)) {
			return;
		}
		// If the response is successful and the log level is set to error, skip logging to avoid filling the logs with successful calls
		if ($oResult->isSuccess()
			&& !self::isConnection($sMethod)
			&& MetaModel::GetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_LOG_LEVEL) === Settings::LOG_LEVEL_ERROR) {
			return;
		}

		try {
			$oLog = new AltiooEventMCPService();
			$oLog->SetTrim('userinfo', UserRights::GetUser());
			$oLog->Set('message', self::AuditLabel($oResult->message, null) ?? '');
			$oLog->Set('mcp_method', $sMethod);
			$oLog->Set('mcp_name', $oResult->mcpName ?? '');
			$oLog->Set('client', $oResult->client ?? '');
			// Which portal served the call: empty for the console's. A portal
			// user allowed into two is served one per connection, and the row
			// is where an administrator learns which (doc/adr/0007).
			$oLog->Set('portal', PortalMode::Current() ?? '');
			$oLog->Set('status', $oResult->isSuccess() ? 'success' : 'error');
			// Whether this was a rehearsal. The parameters themselves are kept
			// only at debug level, so without this a later review of this log -
			// which is the log such a review reads first - cannot tell a dry
			// run from a write that happened.
			$oLog->Set('simulate', self::simulateGrade($oResult->requestParams));
			// Which credential, and what it changed. The row named neither: it
			// said a write by "claude" succeeded, where "claude" is a login
			// string and the write had no subject. Both halves are links, so a
			// reviewer gets from the row to the token and to the change in one
			// click each, and `changed_objects` keeps the readable answer for
			// after the change rows are purged.
			self::describeCredential($oLog);
			self::describeUser($oLog);
			// Whether the exclusions were lifted for this account (doc/adr/0013): every exempt call is listable.
			$oLog->Set('exempt', ExclusionPolicy::IsExempt() ? 'yes' : 'no');
			self::describeChange($oLog);
			self::describeReads($oLog);
			$oLog->Set('change_comment', ChangeTracking::Comment() ?? '');
			// What the row could not answer before: how long the call took, how
			// much it sent back - the two numbers that tell a slow instance from
			// a client filling its context - and which log entry explains it.
			if ($oResult->durationMs !== null) {
				$oLog->Set('duration_ms', $oResult->durationMs);
			}
			if ($oResult->responseBytes !== null) {
				$oLog->Set('response_bytes', $oResult->responseBytes);
			}
			// The controller's own reference first, then every one a tool minted
			// on the way - which used to reach the iTop log and nothing else,
			// leaving this column empty on every error row and the identifier
			// the caller was told to quote resolvable by nobody.
			$aReferences = ErrorReport::MintedReferences();
			if ($oResult->errorReference !== null && $oResult->errorReference !== '') {
				array_unshift($aReferences, $oResult->errorReference);
			}
			$oLog->Set('error_ref', self::truncate(implode(' ', array_unique($aReferences)), 255));
			// Log request parameters only for debug level to avoid filling the logs with too much data in case of errors
			if (MetaModel::GetModuleSetting(MCPModule::MODULE_NAME, Settings::MODULE_SETTING_LOG_LEVEL, Settings::DEFAULT_LOG_LEVEL) === Settings::LOG_LEVEL_DEBUG) {
				$oLog->Set('request_params', self::truncate($oResult->requestParams, 65535));
				// And what the tool said when it refused, which can quote the value it
				// refused: the same standing as the parameters, and cleared with them
				// when the person is anonymised.
				$oLog->Set('refusal_text', self::truncate(self::AuditLabel($oResult->toolText, null), 65535) ?? '');
			}
			$oLog->DBInsertNoReload();
			ReadSummary::Forget();
		} catch (Throwable $e) {
			// Never let audit logging take down a request that already succeeded.
			ErrorReport::Log('Failed to log AltiooEventMCPService: '.$e->getMessage());
		}
	}

	/**
	 * A row for a portal-only user turned away at login, which otherwise left
	 * error.log and nothing an administrator reads in the audit table.
	 *
	 * Only the refusal's own code is recorded, never text from the request.
	 * Written whatever the log level, an error row like any other, unless the
	 * instance does not log calls at all. This is reached after the login, so
	 * there is always a user to name.
	 */
	public static function AuditAdmissionRefusal(string $sRefusal): void
	{
		if (!Settings::LogsCalls()) {
			return;
		}

		try {
			$oLog = new AltiooEventMCPService();
			$oLog->SetTrim('userinfo', UserRights::GetUser());
			$oLog->Set('message', 'Refused at login in portal mode: '.$sRefusal);
			$oLog->Set('mcp_method', 'login');
			$oLog->Set('mcp_name', 'portal_admission');
			$oLog->Set('status', 'error');
			// Nothing has remembered the token yet: the refusal comes first.
			TokenScopes::RememberIdentity();
			self::describeCredential($oLog);
			self::describeUser($oLog);
			$oLog->DBInsertNoReload();
		} catch (Throwable $e) {
			ErrorReport::Log('Failed to log a portal admission refusal: '.$e->getMessage());
		}
	}

	/**
	 * Names the credential on the audit row, in the field that says which kind.
	 *
	 * iTop's two token classes are siblings - `PersonalToken` and `UserToken`
	 * both descend from `cmdbAbstractObject` - so there is no one external key
	 * that could point at either, and the populated field is what answers
	 * "personal or application". Neither set means the request authenticated
	 * some other way, which is itself worth being able to search for.
	 *
	 * The inherited `userinfo` string is set regardless and is not replaced by
	 * this: the keys are reset when a token is deleted, and a rotated
	 * credential must not take the record of what it did with it.
	 *
	 * @since 1.0.0
	 */
	private static function describeCredential(AltiooEventMCPService $oLog): void
	{
		// Remembered during the request, not resolved now. The raw token is
		// dropped by ForgetAuthToken() as soon as the scopes have been read -
		// long before this row is written - so asking for it here answers null
		// however well the request authenticated. That is what shipped first:
		// two external keys that were always empty.
		$aIdentity = TokenScopes::RememberedIdentity();
		if ($aIdentity === null) {
			return;
		}

		$sField = match ($aIdentity['class']) {
			'UserToken'     => 'user_token_id',
			'PersonalToken' => 'personal_token_id',
			default         => null,
		};
		if ($sField === null) {
			// A token class this module has not met. The scopes still gated the
			// call; only the link is missing, and userinfo still names it.
			return;
		}

		$oLog->Set($sField, $aIdentity['key']);
	}

	/**
	 * Links the row to the account the call ran as.
	 *
	 * The real user under impersonation, as `CMDBChange` records it
	 * (`CMDBChange::GetCurrentUserId()`): the account that acted, not the one
	 * it acted as. Left empty when no account is known - the row then has
	 * only `userinfo`, which is the case for a refusal before login.
	 *
	 * @since 1.3.0
	 */
	private static function describeUser(AltiooEventMCPService $oLog): void
	{
		$iUserId = (int)CMDBChange::GetCurrentUserId();
		if ($iUserId > 0) {
			$oLog->Set('user_id', $iUserId);
		}
	}

	/**
	 * Names the objects the call returned, as class and id.
	 *
	 * Cut to a TEXT column like `changed_objects`; {@see ReadSummary} has
	 * already capped what it lists, so the cut is a backstop.
	 *
	 * @since 1.3.0
	 */
	private static function describeReads(AltiooEventMCPService $oLog): void
	{
		$sReads = ReadSummary::Compose();
		if ($sReads !== '') {
			$oLog->Set('read_objects', self::truncate($sReads, 65535));
		}
	}

	/**
	 * Names what the call changed, when it changed anything.
	 *
	 * `GetCurrentChange(false)` - the argument is load-bearing. The default is
	 * `true`, which *creates* a change when there is none, so reading it
	 * without the flag would mint an empty `CMDBChange` on every read this
	 * endpoint serves and make the audit trail claim a write per search.
	 *
	 * Null here is the honest answer for a read and for a dry run, and it is
	 * checkable against `simulate`: a row grading `yes` with a change attached
	 * would be the finding.
	 *
	 * @since 1.0.0
	 */
	private static function describeChange(AltiooEventMCPService $oLog): void
	{
		$oChange = CMDBObject::GetCurrentChange(false);
		if ($oChange === null) {
			return;
		}

		$iChangeId = (int)$oChange->GetKey();
		if ($iChangeId <= 0) {
			return;
		}

		$oLog->Set('change_id', $iChangeId);
		$oLog->Set('changed_objects', self::truncate(ChangeSummary::OfChange($iChangeId), 65535));
	}

	 /**
	  * Truncate a string to fit in a TEXT column (max 65535 bytes).
	  *
	  * Bytes throughout. Deciding in bytes and then cutting in characters is
	  * the bug this avoids: request_params is JSON holding whatever the caller
	  * sent, so one accented or CJK character is two or three bytes, and a cut
	  * to 65532 *characters* can still be three times the column. MySQL then
	  * truncates it itself, or refuses the insert under a strict SQL mode -
	  * and the audit row is either wrong or missing.
	  *
	  * mb_strcut cuts to a byte count without splitting a codepoint, which is
	  * the one thing a plain substr() would get wrong.
	  */
	private static function truncate(?string $sValue, int $iMaxBytes): ?string
	{
		if ($sValue === null) {
			return null;
		}

		if (strlen($sValue) <= $iMaxBytes) {
			return $sValue;
		}

		$sEllipsis = '...';

		return mb_strcut($sValue, 0, $iMaxBytes - strlen($sEllipsis)).$sEllipsis;
	}

	/**
	 * Whether this row is the record that a client connected.
	 *
	 * Kept out of the "successes are not worth a row" rule that the default log
	 * level applies, and deliberately: a successful connection is precisely the
	 * one success an operator needs. Without it the trail answers what was
	 * called and never who arrived - so a token quietly in use by something
	 * nobody remembers issuing it to leaves no trace until it does something,
	 * and an integration that stopped connecting looks exactly like one that
	 * connected and had nothing to do.
	 *
	 * There is one of these per session rather than per call, so it costs a row
	 * per connection.
	 */
	private static function isConnection(string $sMethod): bool
	{
		return $sMethod === MCPMethod::INITIALIZE;
	}

	/**
	 * Whether the call being logged was a dry run, as its own parameters say.
	 *
	 * Three answers, because there are three cases and collapsing them loses
	 * the one that matters. "yes" and "no" are a write tool that was asked to
	 * rehearse or to proceed; "n/a" is everything with no dry run to speak
	 * of - a read, a handshake, a tool that takes no simulate argument - and
	 * is not the same statement as "no".
	 *
	 * Read from the parameters rather than carried down from the tool, so it
	 * stays true for a pack's tool this module has never heard of: any tool
	 * spelling the argument the way the write tools spell it is graded by it.
	 * Absent means "n/a" rather than "no", because a tool that declares the
	 * argument and defaults it to true is rehearsing when the caller says
	 * nothing - and reporting that as a real write would be the lie this is
	 * here to prevent.
	 */
	private static function simulateGrade(?string $sRequestParams): string
	{
		if ($sRequestParams === null || $sRequestParams === '') {
			return 'n/a';
		}

		try {
			$mParams = json_decode($sRequestParams, true, 32, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return 'n/a';
		}

		if (!is_array($mParams)) {
			return 'n/a';
		}

		// tools/call carries the tool's own arguments one level down.
		$aArguments = $mParams['arguments'] ?? $mParams;
		if (!is_array($aArguments) || !array_key_exists('simulate', $aArguments)) {
			return 'n/a';
		}

		$mSimulate = $aArguments['simulate'];

		// A string "false" is false here: a client that sends the flag as text
		// is still saying which of the two it meant, and reading it as truthy
		// would report a real write as a rehearsal.
		if (is_string($mSimulate)) {
			return in_array(strtolower(trim($mSimulate)), ['false', '0', 'no', ''], true) ? 'no' : 'yes';
		}

		return $mSimulate ? 'yes' : 'no';
	}

	/**
	 * Caller-supplied text as one line, fit for an audit row.
	 *
	 * Every source of mcp_name is the caller's own text - a client's
	 * self-description, a tool or prompt name that may match nothing, a
	 * resource URI - and it is recorded before anything checks it. It is also
	 * the class's naming attribute, so it is what every list of those rows
	 * shows. The row's message can carry the same text back: the SDK answers
	 * an unknown tool with its name quoted in the error. A control character
	 * in either is someone making one row read as two, or planting text for
	 * whoever (or whatever model) reviews the trail later; a non-string is a
	 * malformed request, and is recorded as nothing.
	 *
	 * @param int|null $iMaxChars What the column holds, or null for a text
	 *                            column with no limit worth cutting to.
	 */
	public static function AuditLabel(mixed $mValue, ?int $iMaxChars = 255): ?string
	{
		if (!is_string($mValue)) {
			return null;
		}

		// Invalid UTF-8 would make the /u pattern fail outright; what is left
		// after scrubbing still shows the reviewer that something odd arrived.
		$sValue = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($mValue, 'UTF-8')) ?? '');
		if ($sValue === '') {
			return null;
		}

		return $iMaxChars === null ? $sValue : mb_substr($sValue, 0, $iMaxChars);
	}
}
