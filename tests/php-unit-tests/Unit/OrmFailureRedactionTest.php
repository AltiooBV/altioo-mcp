<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a caller is told when the ORM is what failed.
 *
 * The rule is provenance. A message this module composed - a CheckToWrite()
 * issue, "Unknown attribute 'x'", a validation refusal - is written for
 * whoever is on the other end and is what lets a model correct its own call,
 * so it goes back unchanged. A message that came out of iTop's ORM is written
 * for whoever maintains iTop: it routinely carries SQL, table names and class
 * internals, and a caller can do nothing with any of it.
 *
 * The narrow catches - ToolCallException, OQLException, MCPDocumentException -
 * are the first kind and keep their messages. The broad ones, catch (\Exception)
 * and catch (Throwable), are where the ORM's own words arrive, and this holds
 * them to the log.
 *
 * It is a source scan rather than a behavioural test because there is no way
 * to make DBInsert() fail from a unit test that never boots iTop, and because
 * the thing being protected is a habit: the next tool written in this style
 * will reach for $e->getMessage() exactly as the last twelve did.
 */
class OrmFailureRedactionTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const SOURCE_DIR = __DIR__.'/../../../src';

	/**
	 * A bad external key is answered, not deferred.
	 *
	 * org_id = 999999 on an instance with no such organisation came back as
	 * "iTop refused it, the reason is in the log under reference X, quote that
	 * reference" - opaque about a reason that is knowable and harmless, and
	 * pointing at a log nothing on this server reads. Every other refusal on
	 * this surface names what is valid in that context; this one named a step
	 * the caller could not take.
	 *
	 * Asked before the ORM sees the value, so the plain refusal replaces the
	 * opaque one rather than decorating it. Missing and unreadable share a
	 * sentence: telling them apart would let a caller enumerate what it may
	 * not see.
	 */
	public function testABadExternalKeyIsAnsweredInItsOwnWords(): void
	{
		$sBody = $this->methodBody(
			\Altioo\iTop\Extension\MCP\Helper\WritePlan::class,
			'RefusalForExternalKey'
		);

		$this->assertStringContainsString('IsExternalKey', $sBody, 'the check fires on attributes that are not keys');
		$this->assertStringContainsString('GetTargetClass', $sBody, 'the refusal does not name the class to search');
		$this->assertStringContainsString('core_object_find_by_name', $sBody, 'the refusal names no way forward');
		$this->assertStringNotContainsString('OpaqueFailure', $sBody, 'the plain reason is wrapped in the opaque one');

		foreach ([
			'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\ObjectCreate',
			'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\ObjectUpdate',
			'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\ObjectApplyStimulus',
			'Altioo\\iTop\\Extension\\MCP\\Abstract\\AbstractBulkTool',
		] as $sClass) {
			$sSource = (string) file_get_contents((new \ReflectionClass($sClass))->getFileName());
			$this->assertStringContainsString(
				'WritePlan::RefusalForExternalKey(',
				$sSource,
				"{$sClass} still defers a bad id to the ORM"
			);
		}
	}

	/**
	 * The opaque refusal points at something the caller can actually do.
	 *
	 * It used to say "quote that reference", and the reference is an index
	 * into iTop's log - which no tool on this server reads. A model was being
	 * sent to look for a capability that does not exist.
	 */
	public function testTheOpaqueRefusalDoesNotSendTheCallerToALogItCannotRead(): void
	{
		$sBody = $this->methodBody(\Altioo\iTop\Extension\MCP\Helper\MCPHelper::class, 'RejectedValue');

		$this->assertStringContainsString('core_class_schema', $sBody, 'the caller is told nothing it can check');
		$this->assertStringContainsString('No tool here reads that log', $sBody, 'the dead end is not named as one');
		$this->assertStringNotContainsString('quote that reference', $sBody);
	}

	/**
	 * The broad catches. Anything narrower names a type this module or the SDK
	 * defines, and those carry messages meant to be read.
	 */
	private const BROAD_TYPES = ['Exception', 'Throwable'];

	/**
	 * Where what the ORM said is allowed to go: the server log, or the helper
	 * that writes it to the server log and hands back a reference.
	 */
	private const ALLOWED_SINKS = ['Log', 'LogError', 'OpaqueFailure'];

	/**
	 * The one file this does not apply to, and why.
	 *
	 * ElementContract is a contract checker a tool pack runs from its own
	 * PHPUnit suite against its own elements. It never runs inside a request,
	 * so it has no caller to keep anything from - and what it catches is a
	 * refusal MCPRegistry composed for whoever is writing the element, which
	 * is the entire output of the helper. Redacting it would leave a pack
	 * author with "refused:" and a reference to a log they are not reading.
	 *
	 * Listed by path rather than by directory so that a second file appearing
	 * under src/Testing/ is scanned like everything else.
	 */
	private const NOT_ON_A_REQUEST_PATH = ['src/Testing/ElementContract.php'];

	public function testTheHelperTellsTheCallerTheReferenceAndNothingElse(): void
	{
		$oOrmFailure = new RuntimeException(
			'Error in SQL query: SELECT `id` FROM `priv_ticket_request` WHERE `org_id` = 42 -- duplicate entry'
		);

		$sMessage = MCPHelper::OpaqueFailure('Failed to create the object', $oOrmFailure);

		$this->assertStringNotContainsString('SELECT', $sMessage, 'the ORM message reached the caller');
		$this->assertStringNotContainsString('priv_ticket_request', $sMessage, 'a table name reached the caller');
		$this->assertStringContainsString('Failed to create the object', $sMessage, 'the caller is not told what failed');
		$this->assertMatchesRegularExpression('/\b[0-9a-f]{16}\b/', $sMessage, 'the caller has no reference to quote');
	}

	/**
	 * The value-rejection twin. Same redaction, different advice: the caller
	 * chose this value and can choose another, so it must not be told to stop
	 * retrying - and must still not be told what the database said.
	 *
	 * The message used here is the shape RestUtils::MakeValue() actually
	 * produces on the external-key path, where the caller's string is run as
	 * OQL and a database error comes back with the SQL folded into it by
	 * CoreException.
	 */
	public function testTheValueRejectionKeepsTheDatabaseOutOfIt(): void
	{
		$oOrmFailure = new RuntimeException(
			'org_id: Failed to issue SQL query: query -> SELECT `id` FROM `priv_organization` '
			.'WHERE `name` = 0x27, mysql_error -> You have an error in your SQL syntax'
		);

		$sMessage = MCPHelper::RejectedValue("Invalid value for attribute 'org_id'", $oOrmFailure);

		$this->assertStringNotContainsString('SELECT', $sMessage, 'the ORM message reached the caller');
		$this->assertStringNotContainsString('priv_organization', $sMessage, 'a table name reached the caller');
		$this->assertStringNotContainsString('mysql', $sMessage, 'the database error reached the caller');
		$this->assertStringContainsString("attribute 'org_id'", $sMessage, 'the caller is not told which value was refused');
		$this->assertMatchesRegularExpression('/\b[0-9a-f]{16}\b/', $sMessage, 'the caller has no reference to quote');
		$this->assertStringNotContainsString(
			'rather than retrying',
			$sMessage,
			'a value the caller can change must not be answered as unfixable'
		);
	}

	/**
	 * Two failures must not correlate to the same log entry, or the reference
	 * answers the wrong question.
	 */
	public function testEachFailureGetsItsOwnReference(): void
	{
		$this->assertNotSame(MCPHelper::NewErrorReference(), MCPHelper::NewErrorReference());
	}

	/**
	 * The exclusion has to name a file that exists, or it is silently
	 * protecting nothing while reading as though it were.
	 */
	public function testTheExcludedFileIsStillThere(): void
	{
		foreach (self::NOT_ON_A_REQUEST_PATH as $sRelative) {
			$this->assertFileExists(
				self::SOURCE_DIR.'/..'.'/'.$sRelative,
				'NOT_ON_A_REQUEST_PATH names a file that has moved or gone; drop the entry or repoint it'
			);
		}
	}

	/**
	 * @dataProvider sourceFileProvider
	 */
	public function testNoBroadCatchHandsTheCallerWhatItCaught(string $sPath): void
	{
		if (in_array(self::relative($sPath), self::NOT_ON_A_REQUEST_PATH, true)) {
			$this->addToAssertionCount(1);

			return;
		}

		$aOffences = $this->broadCatchesLeakingTheMessage(file_get_contents($sPath));

		$this->assertSame(
			[],
			$aOffences,
			sprintf(
				"%s passes a caught exception's own message on from a broad catch.\n"
				.'Lines: %s.'."\n"
				.'Wrap it in MCPHelper::OpaqueFailure() for a server-side failure, or '
				.'MCPHelper::RejectedValue() for a value the caller supplied - both log what was thrown '
				."and return a reference.\n"
				.'A narrower catch - ToolCallException, OQLException, MCPDocumentException - keeps its message and belongs in its own clause.',
				self::relative($sPath),
				implode(', ', $aOffences)
			)
		);
	}

	/**
	 * The scan has to survive an interpolated string, because every message it
	 * is looking for contains one.
	 *
	 * This is the shape it read as clean for as long as the brace matcher
	 * counted only plain '{' tokens: the '}' closing "{$sAttCode}" balanced the
	 * one that opened the catch, so the block appeared to end mid-line and the
	 * getMessage() after it was never reached.
	 */
	public function testAnInterpolatedStringDoesNotEndTheCatchBlockEarly(): void
	{
		$sSource = <<<'PHP'
		<?php
		try {
			$x = f();
		} catch (\Exception $e) {
			$aIssues[$sAttCode] = "Invalid value for attribute '{$sAttCode}': ".$e->getMessage();
		}
		PHP;

		$this->assertSame(
			[5],
			$this->broadCatchesLeakingTheMessage($sSource),
			'the scan stops at the first interpolated string and misses what follows it'
		);
	}

	/**
	 * The counterpart: a sink still shields what it wraps, interpolation or not.
	 */
	public function testASinkInsideAnInterpolatingCatchIsStillAllowed(): void
	{
		$sSource = <<<'PHP'
		<?php
		try {
			$x = f();
		} catch (\Exception $e) {
			$aIssues[$sAttCode] = "Invalid value for attribute '{$sAttCode}'.";
			MCPHelper::LogError('failed: '.$e->getMessage());
		}
		PHP;

		$this->assertSame([], $this->broadCatchesLeakingTheMessage($sSource));
	}

	/**
	 * Line numbers of every $e->getMessage() that sits inside a broad catch
	 * and outside an allowed sink.
	 *
	 * Takes source rather than a path so that the scan can be held to a known
	 * snippet, which is the only way to notice it has stopped finding things.
	 *
	 * @return array<int, int>
	 */
	private function broadCatchesLeakingTheMessage(string $sSource): array
	{
		$aTokens = token_get_all($sSource);
		$aOffences = [];

		foreach ($aTokens as $i => $mToken) {
			if (!is_array($mToken) || $mToken[0] !== T_CATCH) {
				continue;
			}

			[$aTypes, $iOpenBrace] = $this->catchHeader($aTokens, $i);
			if (array_intersect($aTypes, self::BROAD_TYPES) === [] || $iOpenBrace === null) {
				continue;
			}

			$iCloseBrace = $this->matching($aTokens, $iOpenBrace, '{', '}');
			$aSinks = $this->sinkSpans($aTokens, $iOpenBrace, $iCloseBrace);

			for ($j = $iOpenBrace; $j < $iCloseBrace; $j++) {
				if (!is_array($aTokens[$j]) || $aTokens[$j][0] !== T_STRING || $aTokens[$j][1] !== 'getMessage') {
					continue;
				}

				foreach ($aSinks as [$iFrom, $iTo]) {
					if ($j > $iFrom && $j < $iTo) {
						continue 2;
					}
				}

				$aOffences[] = $aTokens[$j][2];
			}
		}

		return $aOffences;
	}

	/**
	 * The types a catch clause names, and the index of the { that opens it.
	 *
	 * Names arrive as one token or as several depending on how they are
	 * written: PHP 8 lexes "\\Exception" as a single T_NAME_FULLY_QUALIFIED
	 * while a bare "Throwable" stays a T_STRING. Both spellings appear in this
	 * codebase, and reading only one of them is a scan that quietly passes -
	 * which is how this method was written the first time.
	 *
	 * Compared on the last segment, so "\\Exception", "Exception" and a
	 * "use Throwable" import all grade the same.
	 *
	 * @return array{0: array<int, string>, 1: int|null}
	 */
	private function catchHeader(array $aTokens, int $iCatch): array
	{
		$aNameTokens = [T_STRING];
		foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE'] as $sConstant) {
			if (defined($sConstant)) {
				$aNameTokens[] = constant($sConstant);
			}
		}

		$aTypes = [];

		for ($i = $iCatch; $i < count($aTokens); $i++) {
			$mToken = $aTokens[$i];

			if ($mToken === '{') {
				return [$aTypes, $i];
			}

			if (is_array($mToken) && in_array($mToken[0], $aNameTokens, true)) {
				$aSegments = explode('\\', $mToken[1]);
				$aTypes[] = end($aSegments);
			}
		}

		return [$aTypes, null];
	}

	/**
	 * Index ranges covering each call to an allowed sink, so a getMessage()
	 * nested inside one is not counted.
	 *
	 * @return array<int, array{0: int, 1: int}>
	 */
	private function sinkSpans(array $aTokens, int $iFrom, int $iTo): array
	{
		$aSpans = [];

		for ($i = $iFrom; $i < $iTo; $i++) {
			if (!is_array($aTokens[$i]) || $aTokens[$i][0] !== T_STRING || !in_array($aTokens[$i][1], self::ALLOWED_SINKS, true)) {
				continue;
			}

			$iParen = $this->nextSignificant($aTokens, $i + 1);
			if ($iParen === null || $aTokens[$iParen] !== '(') {
				continue;
			}

			$aSpans[] = [$iParen, $this->matching($aTokens, $iParen, '(', ')')];
		}

		return $aSpans;
	}

	private function nextSignificant(array $aTokens, int $iFrom): ?int
	{
		for ($i = $iFrom; $i < count($aTokens); $i++) {
			if (is_array($aTokens[$i]) && in_array($aTokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * The index of the delimiter closing the one opened at $iOpen.
	 *
	 * Braces are not only punctuation. Inside a double-quoted string, "{$x}"
	 * opens with T_CURLY_OPEN - an *array* token carrying '{' - and closes with
	 * a plain '}'. Counting only the plain forms therefore sees one more close
	 * than open, drops the depth to zero at the first interpolated string, and
	 * ends the catch block there. Every getMessage() after it goes unread, and
	 * the test reports no offence: this scan passed on seven of them, in
	 * messages that all began "Invalid value for attribute '{$sAttCode}': ".
	 *
	 * So the interpolation openers count too. Parenthesis matching is
	 * unaffected and shares this method, which is why the token forms are
	 * consulted only when looking for a brace.
	 */
	private function matching(array $aTokens, int $iOpen, string $sOpen, string $sClose): int
	{
		$aAlsoOpens = $sOpen === '{' ? self::interpolationOpeners() : [];

		$iDepth = 0;
		for ($i = $iOpen; $i < count($aTokens); $i++) {
			$mToken = $aTokens[$i];

			if ($mToken === $sOpen
				|| (is_array($mToken) && in_array($mToken[0], $aAlsoOpens, true))) {
				$iDepth++;
			} elseif ($mToken === $sClose) {
				$iDepth--;
				if ($iDepth === 0) {
					return $i;
				}
			}
		}

		return count($aTokens) - 1;
	}

	/**
	 * The token ids that open a brace inside a string literal.
	 *
	 * Resolved through defined() because the set has changed across PHP
	 * versions - T_DOLLAR_OPEN_CURLY_BRACES, the "${x}" form, is deprecated
	 * and on its way out - and an undefined constant here would be a fatal
	 * rather than one fewer opener.
	 *
	 * @return array<int, int>
	 */
	private static function interpolationOpeners(): array
	{
		$aIds = [];

		foreach (['T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'] as $sConstant) {
			if (defined($sConstant)) {
				$aIds[] = constant($sConstant);
			}
		}

		return $aIds;
	}

	/** @return array<string, array{0: string}> */
	public static function sourceFileProvider(): array
	{
		$aCases = [];
		$oIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SOURCE_DIR));
		foreach ($oIt as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sPath = $oFile->getPathname();
			$aCases[self::relative($sPath)] = [$sPath];
		}
		ksort($aCases);

		return $aCases;
	}

	private static function relative(string $sPath): string
	{
		$sReal = realpath($sPath) ?: $sPath;
		$sRoot = realpath(self::SOURCE_DIR.'/..') ?: '';

		// A prefix strip, not a search-and-replace: str_replace would also
		// eat any later occurrence of the root inside the path.
		return ltrim(substr($sReal, strlen($sRoot)), '/');
	}

	/**
	 * The body of a method, comments stripped.
	 *
	 * Stripped because these assertions are about what the code does, and a
	 * comment explaining why a phrase was removed contains that phrase - which
	 * is exactly how this test first failed against the fix it was written for.
	 */
	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new \ReflectionMethod($sClass, $sMethod);
		$aLines = file($oMethod->getFileName());

		$aBody = array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		);

		return implode('', array_filter(
			$aBody,
			static fn (string $sLine): bool => !str_starts_with(ltrim($sLine), '//')
		));
	}
}
