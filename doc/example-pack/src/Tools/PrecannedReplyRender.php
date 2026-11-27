<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Tools;

use Altioo\iTop\Extension\MCP\Api\AbstractMCPTool;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Read\UntrustedMarker;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSearches;
use AttributeCaseLog;
use AttributeExternalKey;
use AttributeLinkedSet;
use AttributeLinkedSetIndirect;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use Dict;
use HTMLSanitizer;
use Mcp\Schema\ToolAnnotations;
use MetaModel;
use Throwable;
use UserRights;
use utils;

/**
 * A predefined reply of Combodo's "Predefined response models" extension, filled in for one ticket.
 *
 * The extension (module precanned-replies, class PrecannedReply) keeps reply models whose `body`
 * holds placeholders such as $this->caller_id->first_name$. Its console button fills them in with
 * MetaModel::ApplyParams() and pastes the result into a case log. core_object_get already reads the
 * body and core_object_update already posts to a case log; what neither does is the filling in, the
 * extension's own choice of which replies apply to a ticket (PrecannedReply::FilterApplicableReplies)
 * and of which case logs they go to (its `targets` setting). That is this tool, and only that: it
 * writes nothing, so the agent posts the text with core_object_update, under every check a core write
 * makes (ADR 0006 - writing stays in core).
 *
 * The one thing it must not do is what ApplyParams does: follow any path a reply's author wrote. A
 * placeholder is a read of an attribute, through as many external keys as it names, and of the
 * current user and contact too ($current_user->...$, $current_contact->...$). ApplyParams asks none of
 * the operator's exclusions and no attribute right, so a reply reading $this->caller_id->phone$ would
 * hand an agent the phone number core_object_get leaves out. Every step of every path is asked here,
 * in this file (ADR 0014), as a direct read of it would be: the class served (ADR 0012), the object
 * readable (UserRights, at class and object level), the attribute neither excluded nor unreadable nor a
 * secret, and the classes a link set reaches readable as well (ADR 0023's rule, for a path rather than
 * a join). A path that fails is left as written - which is what iTop itself does with a placeholder
 * naming an attribute that does not exist, so an excluded attribute and a missing one read alike.
 *
 * Not PortalAware: the extension is a console feature, and a portal user never sees this tool.
 */
class PrecannedReplyRender extends AbstractMCPTool
{
	/** The extension's class, module and the attribute holding the text. */
	private const REPLY_CLASS = 'PrecannedReply';
	private const BODY = 'body';
	private const MODULE = 'precanned-replies';

	/** The objects a placeholder may start from, as MetaModel::ApplyParams() names them. */
	private const ROOTS = ['this', 'current_user', 'current_contact'];

	/**
	 * A placeholder that starts from an object, written the two ways ApplyParams() reads: `$this->x$`
	 * (or `$this-&gt;x$` in HTML) and its URL-encoded form in an attribute, `%24this-&gt;x%24`. The
	 * second pattern's `[^%24]` is ApplyParams' own, quirk included, so the same text is matched.
	 */
	private const PLACEHOLDER_PATTERNS = [
		'/(\$)(this|current_user|current_contact)-(>|&gt;)([^\$]+)\$/',
		'/(%24)(this|current_user|current_contact)-(>|&gt;)([^%24]+)%24/',
	];

	public function getNamespace(): string
	{
		return 'acme';
	}

	public function getToolset(): string
	{
		return 'acme-servicedesk';
	}

	protected function defaultTitle(): string
	{
		return 'Fill In a Predefined Reply';
	}

	public function getDescription(): ?string
	{
		return 'Fill in a predefined reply (iTop\'s "Predefined response models") for one ticket: its placeholders are replaced with the ticket\'s values, '
			.'after checking the reply applies to that ticket and which case logs it may go to. It writes nothing. '
			.'To post it, call core_object_update on the ticket with the returned body as the value of one of the returned log_attcodes, after the user has read it. '
			.'Find reply ids with core_object_search_by_oql on PrecannedReply. A placeholder this account may not read is left as written, as is one naming nothing.';
	}

	public function getAnnotations(): ?ToolAnnotations
	{
		return new ToolAnnotations(
			$this->getTitle() ?? 'Fill in a predefined reply',
			true,  // readOnlyHint - and so served to an MCP-read token
			false, // destructiveHint
			true,  // idempotentHint
			false, // openWorldHint
		);
	}

	/**
	 * Absent, not broken, where Combodo's extension is not installed - and where the operator excluded
	 * its class or the text: the tool is then missing as it is on an instance without the module, rather
	 * than listed and refusing in words that would say what was excluded (ADR 0012).
	 */
	public function isAvailable(): bool
	{
		if (!class_exists(MetaModel::class)) {
			// No iTop loaded: nothing can be verified, so nothing is claimed.
			return false;
		}

		return MetaModel::IsValidClass(self::REPLY_CLASS)
			&& MetaModel::IsValidAttCode(self::REPLY_CLASS, self::BODY)
			&& ExclusionPolicy::Serves(self::REPLY_CLASS)
			&& !ExclusionPolicy::ExcludesAttribute(self::REPLY_CLASS, self::BODY);
	}

	public function getInputSchema(): ?array
	{
		return [
			'type'       => 'object',
			'properties' => [
				'ticket_class' => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Class of the ticket the reply is for, e.g. UserRequest.',
				],
				'ticket_id'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Id of the ticket.',
				],
				'reply_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Id of the PrecannedReply to fill in.',
				],
				'log_attcode'  => [
					'type'        => ['string', 'null'],
					'description' => 'The case log the reply is meant for, when the ticket has several (public_log, private_log). Some instances offer different replies per log. Omit it to use the first one this ticket allows.',
				],
			],
			'required'   => ['ticket_class', 'ticket_id', 'reply_id'],
		];
	}

	/**
	 * @param string      $ticket_class Class of the ticket
	 * @param int         $ticket_id    Id of the ticket
	 * @param int         $reply_id     Id of the PrecannedReply
	 * @param string|null $log_attcode  The case log the reply is for; null for the first applicable
	 *
	 * @return mixed The filled-in body, and the case logs it may be posted to
	 *
	 * @throws \Mcp\Server\Exception\ToolCallException (see ToolRefusal) When the ticket or the reply cannot be read, or the reply does not apply to it.
	 */
	public static function execute(
		string  $ticket_class,
		int     $ticket_id,
		int     $reply_id,
		?string $log_attcode = null,
	): mixed
	{
		if ($ticket_id < 1 || $reply_id < 1) {
			throw ToolRefusal::Because('ticket_id and reply_id are ids, 1 or more.');
		}

		// The ticket: an excluded or unreadable class is answered as one that does not exist, and so is an
		// object the account may not read (ADR 0012). Readable() asks UserRights on the object and on the
		// class it actually is, and drops an object of an excluded subclass.
		if (!ExclusionPolicy::Serves($ticket_class) || !UserRights::IsActionAllowed($ticket_class, UR_ACTION_READ)) {
			throw ToolRefusal::Because(ClassRefusal::Unreadable($ticket_class));
		}
		$oAccess = self::portalRefusal(static fn () => ObjectAccess::Current());
		$oTicket = $oAccess->Readable($ticket_class, $ticket_id)
			?? throw ToolRefusal::Because("Object {$ticket_class}::{$ticket_id} not found.");
		$sTicketClass = get_class($oTicket);
		if (!ExclusionPolicy::Serves($sTicketClass)) {
			throw ToolRefusal::Because("Object {$ticket_class}::{$ticket_id} not found.");
		}

		// The case logs the extension offers replies for on this ticket (its `targets` setting), less those
		// the operator excluded: an excluded log is not one a reply may go to, and is not named.
		$aLogs = array_values(array_filter(
			self::configuredLogs($oTicket),
			static fn (string $sAttCode): bool => !ExclusionPolicy::ExcludesAttribute($sTicketClass, $sAttCode)
		));
		if ($aLogs === []) {
			throw ToolRefusal::Because("Predefined replies are not offered for {$sTicketClass} on this instance.");
		}
		if ($log_attcode !== null && $log_attcode !== '' && !in_array($log_attcode, $aLogs, true)) {
			throw ToolRefusal::Because("Predefined replies on this ticket go to ".implode(' or ', $aLogs).", not to '{$log_attcode}'.");
		}
		$sLog = ($log_attcode === null || $log_attcode === '') ? $aLogs[0] : $log_attcode;

		// The reply, as the extension's dialog would offer it: the replies FilterApplicableReplies() leaves
		// for this ticket and this log, of those the account may read. The class and its text are asked like
		// any other read; isAvailable() already hid the tool where either is excluded.
		if (!ExclusionPolicy::Serves(self::REPLY_CLASS)
			|| ExclusionPolicy::ExcludesAttribute(self::REPLY_CLASS, self::BODY)
			|| !UserRights::IsActionAllowed(self::REPLY_CLASS, UR_ACTION_READ)) {
			throw ToolRefusal::Because(ClassRefusal::Unreadable(self::REPLY_CLASS));
		}
		$oReply = self::applicableReply($oTicket, $sLog, $reply_id, $oAccess)
			?? throw ToolRefusal::Because("Predefined reply {$reply_id} not found, or it does not apply to this ticket.");
		$oInstanceSet = null;
		if (!ObjectSerializer::MayReadAttribute($oReply, get_class($oReply), self::BODY, $oInstanceSet)) {
			throw ToolRefusal::Because("Predefined reply {$reply_id} not found, or it does not apply to this ticket.");
		}

		[$sBody, $aUnresolved] = self::render((string)$oReply->Get(self::BODY), [
			'this'            => $oTicket,
			'current_user'    => self::readableOrNull(UserRights::GetUserObject(), $oAccess),
			'current_contact' => self::readableOrNull(UserRights::GetContactObject(), $oAccess),
		]);

		return ToolOutput::Json([
			'ticket_class'            => $sTicketClass,
			'ticket_id'               => $ticket_id,
			'reply_id'                => (int)$oReply->GetKey(),
			'reply_name'              => (string)$oReply->GetName(),
			'log_attcode'             => $sLog,
			'log_attcodes'            => $aLogs,
			'format'                  => 'html',
			'body'                    => $sBody,
			// As the body reads them: a placeholder naming nothing, or something this account may not read.
			'unresolved_placeholders' => $aUnresolved,
			'untrusted_text'          => ['fields' => ['body', 'reply_name'], 'note' => UntrustedMarker::UNTRUSTED_NOTICE],
		]);
	}

	/**
	 * The case log attribute codes the extension's `targets` setting gives this ticket.
	 *
	 * The extension's own answer when it has one (1.4 and later), so that this follows a change to it;
	 * otherwise the same reading of the setting, legacy `target_class` and `target_caselog` included.
	 *
	 * @return array<int, string>
	 */
	private static function configuredLogs(DBObject $oTicket): array
	{
		if (class_exists('PrecannedRepliesPlugIn') && method_exists('PrecannedRepliesPlugIn', 'GetLogAttCodes')) {
			return array_values(array_map('strval', \PrecannedRepliesPlugIn::GetLogAttCodes($oTicket)));
		}

		$aConfig = MetaModel::GetModuleSetting(self::MODULE, 'targets', []);
		$aConfig = is_array($aConfig) ? $aConfig : [];
		$sSingleClass = (string)MetaModel::GetModuleSetting(self::MODULE, 'target_class', '');
		if ($sSingleClass !== '' && !array_key_exists($sSingleClass, $aConfig)) {
			$aConfig[$sSingleClass] = (string)MetaModel::GetModuleSetting(self::MODULE, 'target_caselog', '');
		}

		$sClass = get_class($oTicket);
		$aLogs = [];
		foreach ($aConfig as $sTarget => $sCodes) {
			if (!is_string($sTarget) || !MetaModel::IsValidClass($sTarget) || !MetaModel::IsParentClass($sTarget, $sClass)) {
				continue;
			}
			foreach (array_map('trim', explode(',', (string)$sCodes)) as $sAttCode) {
				if (MetaModel::IsValidAttCode($sClass, $sAttCode) && MetaModel::GetAttributeDef($sClass, $sAttCode) instanceof AttributeCaseLog) {
					$aLogs[] = $sAttCode;
				}
			}
		}

		return array_values(array_unique($aLogs));
	}

	/**
	 * The reply, if FilterApplicableReplies() leaves it for this ticket and log and the account may read it.
	 *
	 * The filter is the extension's hook for an instance's own rules, so it is code this tool did not
	 * write: whatever it did to the search, the reply found is still asked through Readable(), which a
	 * search that a customisation made AllowAllData() would otherwise skip.
	 */
	private static function applicableReply(DBObject $oTicket, string $sLog, int $iReplyId, ObjectAccess $oAccess): ?DBObject
	{
		$oFilter = new DBObjectSearch(self::REPLY_CLASS);
		if (method_exists(self::REPLY_CLASS, 'FilterApplicableReplies')) {
			\PrecannedReply::FilterApplicableReplies($oTicket, $oFilter, $sLog);
		}
		if (!$oFilter instanceof DBObjectSearch || !MetaModel::IsParentClass(self::REPLY_CLASS, $oFilter->GetClass())) {
			return null;
		}
		$oFilter->AddCondition('id', $iReplyId, '=');

		// The rows of an excluded subclass are not in the set (ADR 0012).
		$oSet = new DBObjectSet(ExclusionSearches::Narrowed($oFilter, self::REPLY_CLASS));
		$oFound = $oSet->Fetch();
		if ($oFound === null) {
			return null;
		}

		return $oAccess->Readable(self::REPLY_CLASS, (int)$oFound->GetKey());
	}

	/**
	 * The object, if this account may read it through this channel; null otherwise.
	 */
	private static function readableOrNull(?DBObject $oObject, ObjectAccess $oAccess): ?DBObject
	{
		if ($oObject === null || (int)$oObject->GetKey() < 1) {
			return null;
		}
		$sClass = get_class($oObject);
		if (!ExclusionPolicy::Serves($sClass) || !UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
			return null;
		}

		return $oAccess->Readable($sClass, (int)$oObject->GetKey());
	}

	/**
	 * The body with every placeholder this account may read filled in, and those left as written.
	 *
	 * One pass with strtr() over what was found, so a value that itself contains a placeholder is not
	 * filled in a second time - ApplyParams() runs str_replace() and would. The result is sanitized as
	 * the extension sanitizes it, since a value inserted into HTML is whatever the ticket holds.
	 *
	 * @param array<string, DBObject|null> $aRoots The objects a placeholder starts from; null for one this account may not read.
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function render(string $sBody, array $aRoots): array
	{
		$aReplacements = [];
		$aUnresolved = [];

		foreach (self::PLACEHOLDER_PATTERNS as $sPattern) {
			if (!preg_match_all($sPattern, $sBody, $aMatches, PREG_SET_ORDER)) {
				continue;
			}
			foreach ($aMatches as $aMatch) {
				$sPlaceholder = $aMatch[0];
				if (array_key_exists($sPlaceholder, $aReplacements) || in_array($sPlaceholder, $aUnresolved, true)) {
					continue;
				}
				$oRoot = $aRoots[$aMatch[2]] ?? null;
				$sValue = $oRoot === null ? null : self::resolve($oRoot, $aMatch[4], 0);
				if ($sValue === null) {
					$aUnresolved[] = $sPlaceholder;
				} else {
					$aReplacements[$sPlaceholder] = $sValue;
				}
			}
		}

		// The scalars ApplyParams() adds. None of them is object data.
		foreach ([
			'current_contact_id' => (string)UserRights::GetContactId(),
			'APP_URL'            => utils::GetAbsoluteUrlAppRoot(),
			'MODULES_URL'        => utils::GetAbsoluteUrlModulesRoot(),
		] as $sName => $sValue) {
			$aReplacements['$'.$sName.'$'] = $sValue;
			$aReplacements['%24'.$sName.'%24'] = $sValue;
		}

		return [HTMLSanitizer::Sanitize(strtr($sBody, $aReplacements)), $aUnresolved];
	}

	/**
	 * One placeholder path read from an object, each step asked as a direct read would be; null for a
	 * path that names nothing or that this account may not read, which are not told apart.
	 *
	 * `key->rest` follows an external key; the last step is `id`, `name()`, `hyperlink()`/`url()` (with
	 * an optional portal), or an attribute with an optional verb, `html(description)`, as
	 * DBObject::GetForTemplate() reads them.
	 */
	private static function resolve(DBObject $oObject, string $sPath, int $iDepth): ?string
	{
		// The deepest path a datamodel could mean; a longer one is not a template, it is a loop.
		if ($iDepth > 8) {
			return null;
		}
		$sClass = get_class($oObject);

		if (preg_match('/^([^-]+)-(>|&gt;)(.+)$/', $sPath, $aHop)) {
			$sKey = $aHop[1];
			if (!self::mayRead($oObject, $sClass, $sKey)) {
				return null;
			}
			$oKeyDef = MetaModel::GetAttributeDef($sClass, $sKey);
			if (!$oKeyDef instanceof AttributeExternalKey) {
				return null;
			}
			$sTarget = $oKeyDef->GetTargetClass();
			if (!ExclusionPolicy::Serves($sTarget) || !UserRights::IsActionAllowed($sTarget, UR_ACTION_READ)) {
				return null;
			}
			$iRemote = (int)$oObject->Get($sKey);
			// GetForTemplate() answers an empty key, and a remote object the account may not see, with
			// iTop's own word for it; the second case is the same answer here.
			$oRemote = $iRemote > 0 ? self::portalRefusal(static fn () => ObjectAccess::Current())->Readable($sTarget, $iRemote) : null;
			if ($oRemote === null) {
				return Dict::S('UI:UndefinedObject');
			}

			return self::resolve($oRemote, $aHop[3], $iDepth + 1);
		}

		if ($sPath === 'id') {
			return (string)$oObject->GetKey();
		}

		if (preg_match('/^([^(]+)\((.*)\)$/', $sPath, $aVerb)) {
			[, $sVerb, $sAttCode] = $aVerb;
			if ($sVerb === 'hyperlink' || $sVerb === 'url') {
				// A link to an object already read: it says nothing the id does not.
				return self::template($oObject, $sPath);
			}
			if ($sVerb === 'name' && $sAttCode === '') {
				$sAttCode = 'friendlyname';
			}
		} else {
			$sAttCode = $sPath;
		}

		if (!self::mayRead($oObject, $sClass, $sAttCode)) {
			return null;
		}

		return self::template($oObject, $sPath);
	}

	/**
	 * Whether this account may read this attribute of this object through this channel: it exists, the
	 * operator did not exclude it, UserRights lets it be read, it is not a secret, and a link set's
	 * classes are readable too. What core_object_get would leave out of its answer.
	 */
	private static function mayRead(DBObject $oObject, string $sClass, string $sAttCode): bool
	{
		if (!ObjectSerializer::HasAttribute($sClass, $sAttCode)) {
			return false;
		}
		if (ExclusionPolicy::ExcludesAttribute($sClass, $sAttCode)) {
			return false;
		}
		$oInstanceSet = null;
		if (!ObjectSerializer::MayReadAttribute($oObject, $sClass, $sAttCode, $oInstanceSet)) {
			return false;
		}
		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);
		if (ObjectSerializer::IsSensitive($oAttDef, $sClass)) {
			return false;
		}
		if ($oAttDef instanceof AttributeLinkedSet) {
			$aReached = [$oAttDef->GetLinkedClass()];
			if ($oAttDef instanceof AttributeLinkedSetIndirect) {
				$oRemoteKey = MetaModel::GetAttributeDef($oAttDef->GetLinkedClass(), $oAttDef->GetExtKeyToRemote());
				if ($oRemoteKey instanceof AttributeExternalKey) {
					$aReached[] = $oRemoteKey->GetTargetClass();
				}
			}
			foreach ($aReached as $sReached) {
				if (!ExclusionPolicy::Serves($sReached) || !UserRights::IsActionAllowed($sReached, UR_ACTION_READ)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * iTop's own rendering of one checked step, or null where iTop would leave the placeholder alone.
	 */
	private static function template(DBObject $oObject, string $sPath): ?string
	{
		try {
			$mValue = $oObject->GetForTemplate($sPath);
		} catch (Throwable $e) {
			return null;
		}

		return $mValue === null ? null : (string)$mValue;
	}
}
