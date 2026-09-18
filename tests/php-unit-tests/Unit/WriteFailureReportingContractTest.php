<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectBulkCreate;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCreate;
use Altioo\iTop\Extension\MCP\Helper\WritePlan;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * A write that reports failure must not have written.
 *
 * Observed: core_object_create created a UserRequest and answered "Error while
 * executing tool". The row was there; the caller was told it was not. Creating
 * is not idempotent and nothing in the protocol says a failed write may have
 * written, so the reasonable next move - retry - is a second object.
 *
 * Two causes, both pinned here. An Error is not an Exception, so every catch
 * written as \Exception let one past into the SDK's own handler, which logs
 * "Unhandled error during tool execution" and answers with a fixed string: no
 * class, no message, and no reference to find it by, which is what makes the
 * endpoint's own audit event useless for the failures that need it most. And
 * DBInsert() commits before it returns - it reloads external values afterwards
 * - so catching around it is not the same as knowing nothing was written.
 */
class WriteFailureReportingContractTest extends TestCase
{
	// tests/php-unit-tests/Unit -> module root
	private const SRC = __DIR__.'/../../../src';

	/**
	 * \Exception does not catch \Error, and the difference is only ever
	 * noticed in production.
	 */
	public function testNothingInTheSourceCatchesExceptionRatherThanThrowable(): void
	{
		$aOffenders = [];

		foreach ($this->phpFiles() as $sPath) {
			if (str_contains((string) file_get_contents($sPath), 'catch (\Exception')) {
				$aOffenders[] = substr($sPath, strlen(self::SRC) + 1);
			}
		}

		$this->assertSame([], $aOffenders, sprintf(
			'These catch \Exception, so an Error passes them and reaches the SDK as an unhandled '
			.'failure - a fixed string with no reference: %s',
			implode(', ', $aOffenders)
		));
	}

	/**
	 * The committed row is reported, not swallowed by the failure.
	 */
	public function testACommittedCreateIsReportedAsOneDespiteTheFailure(): void
	{
		$sBody = $this->methodBody(ObjectCreate::class, 'execute');
		$iCatch = strpos($sBody, 'catch (\Throwable');
		$this->assertNotFalse($iCatch, 'The write has to be wrapped to be reported.');
		$sHandler = substr($sBody, $iCatch);

		$this->assertStringContainsString('CommittedId', $sHandler, 'The handler must ask whether the row exists.');
		$this->assertStringContainsString('ToolOutput::Structured', $sHandler, 'A committed row is answered as a result.');
		$this->assertStringContainsString("'warning'", $sHandler, 'The failure is attached, not discarded.');
	}

	/**
	 * Only when the row really is not there.
	 */
	public function testAFailedCreateStillFails(): void
	{
		$sHandler = substr(
			$this->methodBody(ObjectCreate::class, 'execute'),
			(int) strpos($this->methodBody(ObjectCreate::class, 'execute'), 'catch (\Throwable')
		);

		$this->assertMatchesRegularExpression(
			'/if\s*\(\$iCommittedId === null\)\s*\{[^}]*throw new ToolCallException/s',
			$sHandler,
			'Nothing written is still an error; only a committed row changes the answer.'
		);
	}

	/**
	 * iTop gives an unsaved object a negative temporary key on purpose. Reading
	 * that as an id would report every failed create as a success.
	 */
	public function testATemporaryKeyIsNotMistakenForACommittedOne(): void
	{
		$this->assertMatchesRegularExpression(
			'/\(int\)\s*\$mId\s*>\s*0\s*\?/',
			$this->methodBody(WritePlan::class, 'AsId'),
			'A temporary key is negative; treating it as an id reports failures as successes.'
		);
		$this->assertStringContainsString(
			'catch (Throwable',
			$this->methodBody(WritePlan::class, 'CommittedId'),
			'This runs on the failure path and must not replace the failure with one of its own.'
		);
	}

	/**
	 * The same question, asked by the bulk path.
	 *
	 * Observed on a live instance: two bulk-created lnkContactToTicket rows
	 * that duplicated one another came back "succeeded: 0, failed: 2", and one
	 * of the two was in the database. The second row's refusal was the
	 * uniqueness rule, which runs in DoCheckToWrite() before any insert and
	 * named itself properly; what threw *after* the first row committed is
	 * recorded only in that instance's log. DBInsert() commits and then
	 * reloads, so the second half of it has always been able to fail with the
	 * row written - which reason it was does not change what the report has to
	 * say. A caller reading "failed" for a row that exists either retries,
	 * writing a second one, or tells a user nothing was created.
	 *
	 * Pinned per tool rather than centrally because each write path decides for
	 * itself what "did this one write" means: a creation can answer from the
	 * key, and an update cannot, which is why only the two create paths carry
	 * this.
	 */
	public function testACommittedBulkRowIsReportedAsOneDespiteTheFailure(): void
	{
		$sBody = $this->methodBody(ObjectBulkCreate::class, 'createOne');
		$iCatch = strpos($sBody, 'catch (\Throwable');
		$this->assertNotFalse($iCatch, 'The write has to be wrapped to be reported.');
		$sHandler = substr($sBody, $iCatch);

		$this->assertStringContainsString('WritePlan::CommittedId', $sHandler, 'The handler must ask whether the row exists.');
		$this->assertMatchesRegularExpression(
			'/if\s*\(\$iCommittedId !== null\)\s*\{.*self::outcome\(\$iCommittedId, \$iRow, true/s',
			$sHandler,
			'A committed row is an entry that succeeded, carrying its id.'
		);
		$this->assertStringContainsString("'warning'", $sHandler, 'The failure is attached, not discarded.');
	}

	/**
	 * A write describes the object it left behind, not the one it was handed.
	 *
	 * `changes` is taken before the write and has to be - DBInsert() clears the
	 * pending values - so it reports what was asked for and what
	 * DoComputeValues() rewrote, and nothing the write itself did: an
	 * AfterInsert hook, an event listener, the ref a ticket is given, the
	 * lifecycle filling a field in. And obsolescence_flag is not a stored
	 * column at all but an expression the database evaluates when the row is
	 * queried, so nothing in memory carries it.
	 *
	 * The case that makes it matter: a status the datamodel counts as obsolete.
	 * The write succeeds, the object leaves every search that account makes,
	 * and the report said nothing - so the agent looks for what it wrote, finds
	 * nothing, and concludes the write failed.
	 *
	 * One read, on the real path only, for both halves.
	 */
	public function testEveryFieldChangingWriteReportsTheObjectItLeftBehind(): void
	{
		foreach (['ObjectCreate', 'ObjectUpdate', 'ObjectApplyStimulus'] as $sTool) {
			$sClass = 'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sTool;
			$sSource = (string) file_get_contents((new ReflectionClass($sClass))->getFileName());

			$this->assertStringContainsString('WritePlan::AfterSchemaProperty()', $sSource, "{$sTool} does not declare it");
			$this->assertStringContainsString('WritePlan::After(', $sSource, "{$sTool} does not report it");
		}

		$sAfter = $this->methodBody(WritePlan::class, 'After');

		$this->assertStringContainsString('MetaModel::GetObject', $sAfter, 'nothing is re-read, so a trigger stays invisible');
		$this->assertStringContainsString('!$bSimulated', $sAfter, 'a dry run reads a row it has not written');
		$this->assertStringContainsString('ObjectSerializer::Serialize', $sAfter, 'values bypass the read rights and the masking');

		// The codes re-read are the caller's, not the write's. On a create
		// `changes` is every attribute of the new object, so handing that list
		// here returned a seventy-attribute object twice in one answer - and
		// the block that answers "what became of what you sent" answered
		// "here is everything".
		foreach (['ObjectCreate', 'ObjectUpdate', 'ObjectApplyStimulus'] as $sTool) {
			$sSource = (string) file_get_contents(
				(new ReflectionClass('Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sTool))->getFileName()
			);
			$this->assertStringContainsString(
				'WritePlan::After($oObject, $class, array_keys($aValidatedValues)',
				$sSource,
				"{$sTool} echoes back the whole object instead of what the caller supplied"
			);
		}
		$this->assertStringContainsString('hidden_from_searches', $sAfter, 'the one consequence a caller cannot see for itself');
		$this->assertStringContainsString('catch (Throwable', $sAfter, 'describing a write that succeeded must not fail it');
	}

	/**
	 * The answer is built where a failure in it can still be caught.
	 *
	 * The same defect as the create one, found again in core_object_attach: the
	 * insert was wrapped and the response was not, so a TypeError while
	 * describing the stored document escaped to the SDK - "Error while
	 * executing tool", no reference, no id - with the attachment already in the
	 * database. A caller told that reasonably attaches the file again.
	 *
	 * DBInsert() and DBUpdate() commit and then return, so everything after
	 * them runs with the row written. A failure there is not a failed write; it
	 * is a write nobody described, and the two must not answer the same way.
	 */
	public function testTheAnswerIsBuiltInsideTheCatchThatCoversTheWrite(): void
	{
		$sSource = (string) file_get_contents(
			(new ReflectionClass('Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\ObjectAttach'))->getFileName()
		);

		// Both branches: the attachment object, and the blob attribute.
		$this->assertSame(
			2,
			preg_match_all('/try \{\s*(?:\$\w+ = )?\$\w+->(?:DBInsert|DBUpdate)\(\);\s*return ToolOutput::Structured/s', $sSource),
			'a write is wrapped and the answer describing it is not'
		);

		$this->assertStringContainsString(
			'WritePlan::CommittedId($oAttachment)',
			$sSource,
			'a committed attachment can still be reported as a failure'
		);
		$this->assertStringContainsString("'warning'", $sSource, 'the failure replaces the success rather than riding with it');
	}

	/**
	 * An id crossing this boundary is taken as iTop reports it.
	 *
	 * DBInsert() answers with the key as a string, so an int parameter on
	 * anything a write path hands it throws under strict_types - after the
	 * commit, which is what makes it expensive. WritePlan::Identity() was
	 * corrected for this once; DocumentAccess::Describe() had the same
	 * signature and was reached by the same call.
	 */
	public function testTheDocumentDescriberTakesTheIdAsITopReportsIt(): void
	{
		$oMethod = new ReflectionMethod(
			'Altioo\\iTop\\Extension\\MCP\\Helper\\DocumentAccess',
			'Describe'
		);
		$sType = (string) $oMethod->getParameters()[2]->getType();

		$this->assertStringContainsString('string', $sType, sprintf(
			'DBInsert() returns the key as a string, so a %s parameter throws once the row is already written.',
			$sType
		));
		$this->assertStringContainsString(
			'WritePlan::AsId',
			$this->methodBody('Altioo\\iTop\\Extension\\MCP\\Helper\\DocumentAccess', 'Describe'),
			'the id is used without going through the one normaliser'
		);
	}

	/**
	 * The TypeError that started this: DBInsert() returns the key as a string,
	 * and a ?int parameter under strict_types rejects it *after* the commit.
	 * Every create path hands this method an id straight out of the ORM.
	 */
	public function testTheIdentityHelperTakesTheIdAsITopReportsIt(): void
	{
		$oMethod = new ReflectionMethod(WritePlan::class, 'Identity');
		$aParams = $oMethod->getParameters();
		$sType = (string) $aParams[1]->getType();

		$this->assertStringContainsString('string', $sType, sprintf(
			'DBInsert() returns the key as a string, so a %s parameter throws a TypeError once the row is already written.',
			$sType
		));
	}

	/**
	 * The same TypeError, one level down and once per row.
	 *
	 * core_object_bulk_create hands DBInsert()'s return value straight to
	 * outcome(), whose id parameter was ?int. Under strict_types that rejected
	 * the string the ORM returns - after the row was committed - so the catch
	 * around the write reported every successful creation as "Created, but the
	 * call failed after the write". The two other bulk tools pass an id
	 * checkIds() already made an int of, which is why only create showed it.
	 */
	public function testTheBulkOutcomeTakesTheIdAsITopReportsIt(): void
	{
		$oMethod = new ReflectionMethod(
			'Altioo\\iTop\\Extension\\MCP\\Abstract\\AbstractBulkTool',
			'outcome'
		);
		$sType = (string) $oMethod->getParameters()[0]->getType();

		$this->assertStringContainsString('string', $sType, sprintf(
			'a bulk create hands this DBInsert()\'s return value, so a %s parameter throws a TypeError once the row is already written.',
			$sType
		));
		$this->assertStringContainsString(
			'WritePlan::AsId',
			$this->methodBody(
				'Altioo\\iTop\\Extension\\MCP\\Abstract\\AbstractBulkTool',
				'outcome'
			),
			'the widened parameter has to be normalised, or the schema\'s integer id becomes a string'
		);
	}

	/**
	 * Nothing between DBInsert() and the entry it produces may narrow the id.
	 *
	 * The signature above is the boundary that was found; a cast written at
	 * the call site would put the TypeError back without changing it.
	 */
	public function testTheBulkCreateRowDoesNotCastTheIdItself(): void
	{
		$sSource = (string) file_get_contents(self::SRC.'/Core/Tools/ObjectBulkCreate.php');

		$this->assertSame(0, preg_match('/outcome\(\s*\(int\)/', $sSource),
			'a cast at the call site is the fix the next bulk tool would be written without');
	}

	/**
	 * A deletion that happened is reported as one.
	 *
	 * The create path asks "is there a row" after a throw, because DBInsert()
	 * commits and then keeps going. DBDelete() has the same shape and the
	 * delete tool asked nothing: it turned every throw into an outright
	 * failure, including the ones that arrive with the row already gone.
	 *
	 * Reported by a red-team pass, where the failing call was the cleanup of
	 * the object staged to demonstrate something else. That is the case that
	 * matters - an operator removing something on purpose is told the removal
	 * did not work, and goes looking for a record that is not there.
	 *
	 * Deliberately the opposite direction from the creation rule, and the test
	 * pins that too: a deletion may only be reported on positive evidence of
	 * absence, because saying a thing is gone when it is not is the worse of
	 * the two mistakes here, and retrying a deletion that worked costs
	 * nothing.
	 */
	public function testACommittedDeletionIsReportedAsOneDespiteTheFailure(): void
	{
		$sSource = (string) file_get_contents(self::SRC.'/Core/Tools/ObjectDelete.php');

		$this->assertStringContainsString('WritePlan::IsGone(', $sSource,
			'the delete tool reports a failure without asking whether the row is still there');
		$this->assertStringContainsString("'warning'", $sSource,
			'a deletion that happened has nowhere to report the failure that followed it');

		$sGone = $this->methodBody(WritePlan::class, 'IsGone');

		$this->assertStringContainsString('=== null', $sGone, 'absence is inferred rather than established');
		$this->assertStringContainsString('catch (Throwable', $sGone,
			'a question asked while an exception is being reported may not raise one of its own');
		$this->assertStringContainsString('return false;', $sGone,
			'a question it cannot answer has to read as "still there", or a failure becomes a deletion report');
	}

	/**
	 * A token pinned to dry runs rehearses whatever the caller passed.
	 *
	 * The trust tier between "may read" and "may write": propose changes, show
	 * a person what they would do, commit nothing. It belongs to the
	 * credential rather than to the call, because a caller that can choose is
	 * not restricted - and it lives on the token rather than in a session,
	 * because this endpoint is stateless and the token is the only thing that
	 * persists between calls.
	 *
	 * Two halves pinned here. The decision itself, which must never turn a
	 * rehearsal into a write; and the fact that every write tool routes its
	 * argument through it, since one that reads `simulate` directly is one the
	 * scope does not reach.
	 */
	public function testAnAdvisoryTokenRehearsesWhateverTheCallerAsked(): void
	{
		AccessPolicy::Forget();

		// No policy remembered: the caller's own answer stands, which is the
		// state this suite runs in and the only safe direction to fail in.
		$this->assertTrue(WritePlan::Simulated(true));
		$this->assertFalse(WritePlan::Simulated(false));

		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write']));
		$this->assertFalse(WritePlan::Simulated(false), 'an ordinary write token was forced to rehearse');

		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']));
		$this->assertTrue(WritePlan::Simulated(false), 'an advisory token was allowed to write');
		$this->assertTrue(WritePlan::Simulated(true));

		// The modifier survives "everything", and narrowing only ever adds it.
		AccessPolicy::Remember(AccessPolicy::FromScopes(['MCP', 'MCP-advisory']));
		$this->assertTrue(WritePlan::Simulated(false), 'a full-scope token shook the modifier off');

		$oOpen = AccessPolicy::FromScopes(['MCP']);
		$this->assertTrue(
			$oOpen->narrowedBy(AccessPolicy::FromScopes(['MCP-advisory']))->isAdvisory(),
			'narrowing dropped the advisory modifier instead of keeping it'
		);

		AccessPolicy::Forget();
	}

	/** Every write tool routes its dry-run argument through the one decision. */
	public function testEveryWriteToolAsksWhetherItIsRehearsing(): void
	{
		$aMissing = [];

		foreach ($this->phpFiles() as $sPath) {
			$sSource = (string) file_get_contents($sPath);
			if (!str_contains($sSource, 'bool    $simulate') && !str_contains($sSource, 'bool $simulate')) {
				continue;
			}
			// The helper that defines it, not a caller of it.
			if (str_contains($sSource, 'public static function Simulated(')) {
				continue;
			}
			if (!str_contains($sSource, 'WritePlan::Simulated($simulate)')) {
				$aMissing[] = substr($sPath, strlen(self::SRC) + 1);
			}
		}
		sort($aMissing);

		$this->assertSame([], $aMissing, sprintf(
			'These take a dry-run argument and read it directly, so the advisory scope does not reach them: %s',
			implode(', ', $aMissing)
		));
	}

	/**
	 * An empty map reaches the tool, which has something useful to say about
	 * it.
	 *
	 * A JSON body is decoded with json_decode($sBody, true), so `{}` and `[]`
	 * both become the empty PHP array - and the SDK's validator converts an
	 * array back to an object only when it is non-empty
	 * (SchemaValidator::convertDataForValidator()). `fields: {}` therefore
	 * reached the validator as a list and was refused against `type: object`
	 * with "Invalid type. Expected `object`, but received `array`": a type
	 * error for a value whose type was right, sending the caller to look for a
	 * shape it had already sent.
	 *
	 * `fields: {}` on an update is a caller with nothing to update - an
	 * ordinary mistake with an ordinary answer. Declaring the map as
	 * MCPHelper::MAP_TYPE lets it through to the tool, which says so.
	 *
	 * Checked against the real validator rather than by reading the schema,
	 * because the bug is in how the validator converts, not in what the schema
	 * says.
	 *
	 * @dataProvider emptyMapPayloadProvider
	 */
	public function testAnEmptyMapIsNotATypeError(string $sTool, string $sJson): void
	{
		if (!class_exists('Mcp\\Capability\\Discovery\\SchemaValidator')) {
			$this->markTestSkipped('the MCP SDK validator is not available here.');
		}

		$sClass = 'Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sTool;
		$oTool = new $sClass();
		$oValidator = new \Mcp\Capability\Discovery\SchemaValidator();

		$aErrors = $oValidator->validateAgainstJsonSchema(json_decode($sJson, true), $oTool->getInputSchema());

		$this->assertSame([], $aErrors ?? [], sprintf(
			'%s refuses an empty map as a type error instead of letting the tool answer: %s',
			$sTool,
			json_encode($aErrors)
		));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function emptyMapPayloadProvider(): array
	{
		return [
			'update with no fields'  => ['ObjectUpdate', '{"class":"UserRequest","id":1,"fields":{}}'],
			'create with no fields'  => ['ObjectCreate', '{"class":"UserRequest","fields":{}}'],
			'search with no filters' => ['ObjectSearchByClass', '{"class":"UserRequest","filters":{}}'],
			'a bulk row with none'   => ['ObjectBulkCreate', '{"class":"UserRequest","objects":[{}]}'],
		];
	}

	/**
	 * Creating a credential says that the credential is not in the answer.
	 *
	 * A token's usable value never exists as an attribute.
	 * AbstractPersonalToken::AfterInsert() generates it, keeps it on the object
	 * as a plain property, stores only its hash in auth_token - overwriting
	 * whatever the caller supplied for that attribute - and hands the plaintext
	 * to the console as a session message, which a stateless endpoint cannot
	 * deliver. So a create leaves a usable row and no usable credential, and
	 * auth_token comes back masked over a salted hash with nothing behind the
	 * mask worth having.
	 *
	 * Reported in review as a credential-provisioning gap, which it is - the
	 * fix is to say so rather than to hand a live portable secret back through
	 * a tool result, where it would land in a model's context, in the transport
	 * and in this endpoint's own audit row.
	 *
	 * Pinned on the source, since the interface that identifies a token class
	 * only exists on an instance.
	 */
	public function testCreatingACredentialSaysTheCredentialIsNotInTheAnswer(): void
	{
		$sPlan = (string) file_get_contents(self::SRC.'/Helper/WritePlan.php');

		$this->assertStringContainsString('AuthentToken', $sPlan,
			'the token classes are named rather than asked for by interface, so a later one is missed');
		$this->assertStringContainsString(
			'auth_token',
			$this->methodBody(WritePlan::class, 'CredentialNote'),
			'the note does not mention the attribute the caller will go looking at'
		);

		$sSource = (string) file_get_contents(self::SRC.'/Core/Tools/ObjectCreate.php');
		$this->assertSame(
			2,
			substr_count($sSource, 'credentialNote($class)'),
			'the note is not on both the dry run and the real create - a caller should learn this before it makes one'
		);

		// Nothing is returned for an ordinary class, on any instance.
		$this->assertNull(WritePlan::CredentialNote('UserRequest'));
	}

	/** Every id that came out of a write goes through the one normaliser. */
	public function testNoCreatePathCastsTheIdItself(): void
	{
		$aOffenders = [];

		foreach ($this->phpFiles() as $sPath) {
			$sSource = (string) file_get_contents($sPath);
			if (preg_match('/Identity\([^,]+,\s*\(int\)/', $sSource) === 1) {
				$aOffenders[] = substr($sPath, strlen(self::SRC) + 1);
			}
		}

		$this->assertSame([], $aOffenders, sprintf(
			'A cast at the call site is the fix the next create tool would be written without: %s',
			implode(', ', $aOffenders)
		));
	}

	/** @return array<int, string> */
	private function phpFiles(): array
	{
		$aFiles = [];
		$oIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC));

		foreach ($oIterator as $oFile) {
			if ($oFile->isFile() && $oFile->getExtension() === 'php') {
				$aFiles[] = $oFile->getPathname();
			}
		}

		sort($aFiles);

		return $aFiles;
	}

	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());

		return implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));
	}
}
