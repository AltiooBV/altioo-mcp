<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Helper\DatamodelReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The two tools that put the datamodel where every client can reach it.
 *
 * The schema was already served as a resource and a resource template, which a
 * good many clients never fetch. The tools exist so that a model on such a
 * client can still discover attribute codes instead of inventing them - which
 * only holds if the narrowing arguments are ones a client may actually send:
 * a property missing from the input schema is a parameter stuck at its default
 * forever.
 *
 * Reading a schema, a signature and a pure filter needs no iTop, which is what
 * makes this a unit test rather than an integration one.
 */
class SchemaToolsContractTest extends TestCase
{
	/**
	 * A stock datamodel runs to several hundred classes. Narrowing that a
	 * client cannot ask for is narrowing that never happens.
	 */
	public function testListExposesEveryNarrowingArgumentToTheClient(): void
	{
		$aSchema = (new ClassList())->getInputSchema();

		$this->assertArrayHasKey('category', $aSchema['properties']);
		$this->assertSame('string', $aSchema['properties']['category']['type']);
		$this->assertArrayHasKey('filter', $aSchema['properties']);
		$this->assertSame('string', $aSchema['properties']['filter']['type']);
		$this->assertArrayHasKey('may', $aSchema['properties']);
		$this->assertSame('string', $aSchema['properties']['may']['type']);
	}

	/**
	 * The gates a client may send are the gates a rights block reports, plus
	 * the one value that is not a gate.
	 *
	 * Declared as an enum so a client can reject a bad one before the round
	 * trip, and taken from MayValues() so that the schema, the refusal message
	 * and the block itself cannot come apart.
	 */
	public function testTheGatesOfferedAreTheGatesReported(): void
	{
		$aSchema = (new ClassList())->getInputSchema();

		$this->assertSame(DatamodelReader::MayValues(), $aSchema['properties']['may']['enum']);

		// Every offered value is either a key of the block or the sentinel that
		// asks for the block without narrowing. A ninth gate arriving in one
		// list and not the other is what this catches.
		$this->assertSame(
			DatamodelReader::RightsKeys(),
			array_values(array_diff(DatamodelReader::MayValues(), [DatamodelReader::RIGHTS_ALL])),
			'may offers a value that is neither a gate a rights block reports nor the report-them-all sentinel'
		);
		$this->assertNotContains(
			DatamodelReader::RIGHTS_ALL,
			DatamodelReader::RightsKeys(),
			'the report-them-all sentinel has become a gate name, so FilterByRight() would look for it in a rights block'
		);
	}

	/**
	 * Asking for the rights and narrowing on them are two requests, and the
	 * sentinel is what separates them.
	 *
	 * Without it the only way to see the block is to narrow, so the classes a
	 * gate refuses - the ones a caller most wants to know about before it
	 * plans a call - are exactly the ones dropped from the answer.
	 */
	public function testTheRightsBlockCanBeAskedForWithoutNarrowing(): void
	{
		$aClasses = [
			['class' => 'Allowed', 'rights' => ['create' => 'yes', 'delete' => 'yes']],
			['class' => 'Refused', 'rights' => ['create' => 'no', 'delete' => 'yes']],
		];

		$this->assertCount(
			1,
			DatamodelReader::FilterByRight($aClasses, 'create'),
			'a named gate must still drop the classes it refuses'
		);
		$this->assertCount(
			2,
			DatamodelReader::FilterByRight($aClasses, DatamodelReader::RIGHTS_ALL),
			'the sentinel must narrow on nothing: it reports what the caller may do, it does not decide it'
		);
	}

	/**
	 * The writable attributes and the ones nobody writes are two blocks.
	 *
	 * A stock UserRequest runs to around a hundred attributes, and most of what
	 * a class with many external keys carries is the `_friendlyname` and
	 * `_obsolescence_flag` companion iTop attaches to each of them. Those are
	 * never an answer to "what can I set", and a reader looking for one had to
	 * scan past all of them to find out.
	 *
	 * Checked on the shape of Describe(), not on a live datamodel: the split
	 * has to be readOnly and nothing else, because that is the datamodel's own
	 * answer and it moves when iTop adds an attribute type. A list of attribute
	 * class names here would be a second answer to keep level with the first.
	 */
	public function testTheSchemaSeparatesWhatCanBeSetFromWhatCannot(): void
	{
		$sBody = $this->methodBody(DatamodelReader::class, 'Describe');

		$this->assertStringContainsString("'attributes'", $sBody);
		$this->assertStringContainsString("'derived'", $sBody);
		$this->assertStringContainsString(
			"'readOnly'",
			$sBody,
			'Describe() must split on the datamodel\'s own IsWritable() answer, carried as readOnly, rather than on a list of attribute class names that goes stale when iTop adds one'
		);

		// Both blocks come out of one enumeration, so an attribute cannot fall
		// between them or land in both.
		$this->assertSame(
			1,
			substr_count($sBody, 'self::attributes('),
			'the two blocks must be filtered from a single pass, or an attribute can be enumerated into neither'
		);
	}

	public function testTheDescriptionSaysWhichBlockHoldsWhat(): void
	{
		// The only string a model reads before it picks a block.
		$sDescription = (new ClassSchema())->getDescription();

		$this->assertStringContainsString('derived', $sDescription);
		$this->assertStringContainsString('_friendlyname', $sDescription);
	}

	/**
	 * The body of a method, comments stripped, so that a doc comment describing
	 * a split cannot stand in for the split.
	 */
	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file($oMethod->getFileName());
		$sSource = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sSource) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}

	/**
	 * Both narrowing arguments are optional, on both sides: "list everything"
	 * has to stay one call away, and the SDK binds by name off the signature.
	 */
	public function testListNarrowingIsOptionalInBothTheSchemaAndTheSignature(): void
	{
		$aSchema = (new ClassList())->getInputSchema();
		$this->assertSame([], $aSchema['required']);

		foreach ($this->parameters(ClassList::class) as $sName => $oParameter) {
			$this->assertTrue($oParameter->isOptional(), "ClassList::execute() parameter {$sName} has no default");
			$this->assertSame('', $oParameter->getDefaultValue(), "ClassList::execute() parameter {$sName} defaults to something other than 'no narrowing'");
		}

		$this->assertSame(['category', 'filter', 'may'], array_keys($this->parameters(ClassList::class)));
	}

	public function testSchemaToolRequiresTheClassItDescribes(): void
	{
		$aSchema = (new ClassSchema())->getInputSchema();

		$this->assertArrayHasKey('class', $aSchema['properties']);
		$this->assertSame(['class'], $aSchema['required'], 'only the class is mandatory');
		$this->assertSame(
			['class', 'include', 'attributes', 'required_only'],
			array_keys($this->parameters(ClassSchema::class)),
			'the signature and the schema have to offer the same narrowings'
		);
	}

	/**
	 * A narrowed answer says what it was narrowed to.
	 *
	 * "This class has no relations" and "you did not ask for relations" are
	 * different claims, and an absent block cannot tell them apart - which is
	 * the same failure the withheld block in the relation walk exists to
	 * prevent, one surface along.
	 */
	public function testEveryNarrowingIsEchoedBack(): void
	{
		$aDefaults = [];
		foreach ($this->parameters(ClassSchema::class) as $sName => $oParameter) {
			if ($sName !== 'class') {
				$aDefaults[$sName] = $oParameter->getDefaultValue();
			}
		}

		$this->assertSame(
			[
				'include'       => DatamodelReader::BLOCKS_ALL,
				'attributes'    => DatamodelReader::ATTRIBUTES_ALL,
				'required_only' => false,
			],
			$aDefaults,
			'a default that narrows anything makes the unnarrowed call impossible to spell'
		);

		$this->assertStringContainsString(
			"'reported'",
			$this->methodBody(DatamodelReader::class, 'Describe'),
			'the answer does not say what it left out'
		);
	}

	/**
	 * A refused date says which string iTop would have taken.
	 *
	 * Y-m-d H:i:s is a space and no offset, and every other system a model has
	 * met uses RFC 3339, so the ISO form is what a first attempt sends. The
	 * pattern was already in the schema and a refusal still cost a round trip
	 * to go and read it.
	 *
	 * Suggested, never applied: an offset-bearing value is an instant and iTop
	 * stores wall-clock time, so the conversion is shown to the caller rather
	 * than made on its behalf.
	 */
	public function testTheDateHintIsAdviceAndNotAConversion(): void
	{
		$sBody = $this->methodBody(DatamodelReader::class, 'ValueHint');

		$this->assertStringContainsString('GetInternalFormat', $sBody, 'the format has to be read off the branch');
		$this->assertStringContainsString('AttributeDateTime', $sBody, 'only a date attribute gets this hint');
		$this->assertStringContainsString('return \'\';', $sBody, 'anything else has to answer with nothing');

		foreach (['ObjectCreate', 'ObjectUpdate', 'ObjectApplyStimulus'] as $sTool) {
			$sSource = (string) file_get_contents(
				(new \ReflectionClass('Altioo\\iTop\\Extension\\MCP\\Core\\Tools\\'.$sTool))->getFileName()
			);
			$this->assertStringContainsString(
				'DatamodelReader::ValueHint',
				$sSource,
				"{$sTool} refuses a value without saying what would have worked"
			);
		}
	}

	/** Nothing recognised is not the same as nothing wanted. */
	public function testAnUnknownBlockNameDoesNotEmptyTheAnswer(): void
	{
		$oBlocks = new ReflectionMethod(DatamodelReader::class, 'requestedBlocks');

		$this->assertSame(DatamodelReader::BLOCKS, $oBlocks->invoke(null, 'no-such-block'));
		$this->assertSame(DatamodelReader::BLOCKS, $oBlocks->invoke(null, '*'));
		$this->assertSame(['attributes'], $oBlocks->invoke(null, 'attributes'));
		$this->assertSame(
			['rights', 'attributes'],
			$oBlocks->invoke(null, 'attributes, rights, nonsense'),
			'the known ones are kept, in the order the payload writes them'
		);
	}

	/**
	 * Neither tool writes anything; a client that gates non-read-only tools
	 * behind a confirmation relies on the annotation to say so.
	 */
	public function testBothToolsAreAnnotatedReadOnly(): void
	{
		foreach ([new ClassList(), new ClassSchema()] as $oTool) {
			$aAnnotations = $oTool->getAnnotations()->jsonSerialize();

			$this->assertTrue($aAnnotations['readOnlyHint'], get_class($oTool).' is not annotated read-only');
			$this->assertFalse($aAnnotations['destructiveHint'], get_class($oTool).' is annotated destructive');
		}
	}

	/**
	 * Listing then describing is the intended sequence; the descriptions are
	 * the only place a model is told so.
	 */
	public function testTheDescriptionsPointAtEachOther(): void
	{
		$this->assertStringContainsString('core_class_schema', (new ClassList())->getDescription());
		$this->assertStringContainsString('core_class_list', (new ClassSchema())->getDescription());
	}

	public function testFilterMatchesNameLabelAndDescriptionAlike(): void
	{
		$aClasses = [
			['class' => 'UserRequest', 'label' => 'User Request', 'description' => 'A ticket raised by a caller'],
			['class' => 'Server', 'label' => 'Server', 'description' => 'A physical machine'],
		];

		// The class name says nothing about tickets; the description does.
		$this->assertSame(['UserRequest'], $this->names(DatamodelReader::FilterByText($aClasses, 'ticket')));
		$this->assertSame(['Server'], $this->names(DatamodelReader::FilterByText($aClasses, 'Serv')));
		$this->assertSame(['UserRequest'], $this->names(DatamodelReader::FilterByText($aClasses, 'User Req')));
	}

	public function testFilterIsCaseInsensitive(): void
	{
		$aClasses = [['class' => 'UserRequest', 'label' => 'User Request', 'description' => '']];

		$this->assertCount(1, DatamodelReader::FilterByText($aClasses, 'USERREQUEST'));
		$this->assertCount(1, DatamodelReader::FilterByText($aClasses, 'userrequest'));
	}

	/**
	 * No filter means no filtering - not an empty result.
	 */
	public function testAnEmptyFilterKeepsEverything(): void
	{
		$aClasses = [['class' => 'Server', 'label' => 'Server', 'description' => '']];

		$this->assertSame($aClasses, DatamodelReader::FilterByText($aClasses, ''));
	}

	/**
	 * The result is JSON-encoded down the line, where a gapped array turns into
	 * an object keyed by the surviving indices instead of a list.
	 */
	public function testFilteringReturnsAList(): void
	{
		$aClasses = [
			['class' => 'Contact', 'label' => 'Contact', 'description' => ''],
			['class' => 'Server', 'label' => 'Server', 'description' => ''],
		];

		$aFiltered = DatamodelReader::FilterByText($aClasses, 'Server');

		$this->assertSame([0], array_keys($aFiltered));
		$this->assertStringStartsWith('[', json_encode($aFiltered));
	}

	/**
	 * A refusal removes the class; 'depends' does not.
	 *
	 * 'depends' is the addon asking for the object before it answers, so the
	 * class is one the caller may well be able to act on. Dropping it hides
	 * work that can be done, which is the opposite of what narrowing by rights
	 * is for.
	 */
	public function testNarrowingByRightKeepsWhatWasNotRefused(): void
	{
		$aClasses = [
			['class' => 'UserRequest', 'rights' => ['create' => 'yes']],
			['class' => 'Server', 'rights' => ['create' => 'no']],
			['class' => 'Contact', 'rights' => ['create' => 'depends']],
		];

		$this->assertSame(
			['UserRequest', 'Contact'],
			$this->names(DatamodelReader::FilterByRight($aClasses, 'create'))
		);
	}

	/**
	 * One gate at a time: a class refused the gate that was asked about stays
	 * out however generous the others are.
	 */
	public function testNarrowingReadsOnlyTheGateItWasAskedAbout(): void
	{
		$aClasses = [
			['class' => 'Server', 'rights' => ['create' => 'yes', 'delete' => 'no']],
		];

		$this->assertSame(['Server'], $this->names(DatamodelReader::FilterByRight($aClasses, 'create')));
		$this->assertSame([], $this->names(DatamodelReader::FilterByRight($aClasses, 'delete')));
	}

	/**
	 * No gate means no narrowing - not an empty result, which is the reading
	 * that would make an unset argument look like a locked-down instance.
	 */
	public function testAnEmptyGateKeepsEverything(): void
	{
		$aClasses = [['class' => 'Server', 'rights' => ['create' => 'no']]];

		$this->assertSame($aClasses, DatamodelReader::FilterByRight($aClasses, ''));
	}

	/**
	 * Only a refusal removes anything. A summary carrying no rights block says
	 * nothing about the caller, and silence is not a refusal.
	 */
	public function testASummaryWithNoRightsBlockIsKept(): void
	{
		$aClasses = [['class' => 'Server']];

		$this->assertCount(1, DatamodelReader::FilterByRight($aClasses, 'create'));
	}

	/**
	 * Same reason as the text filter: a gapped array JSON-encodes as an object
	 * keyed by the surviving indices instead of a list.
	 */
	public function testNarrowingByRightReturnsAList(): void
	{
		$aClasses = [
			['class' => 'Contact', 'rights' => ['create' => 'no']],
			['class' => 'Server', 'rights' => ['create' => 'yes']],
		];

		$aFiltered = DatamodelReader::FilterByRight($aClasses, 'create');

		$this->assertSame([0], array_keys($aFiltered));
		$this->assertStringStartsWith('[', json_encode($aFiltered));
	}

	/**
	 * RightsKeys() is written out by hand because rights() cannot be called
	 * without iTop. This is what keeps the two level: every key the block
	 * writes is one a caller can narrow on, and no key is offered that the
	 * block never carries.
	 *
	 * A source scan for the same reason as CurrentUserContactTest - the method
	 * needs UserRights, and this suite boots no iTop.
	 */
	public function testTheGateListMatchesTheBlockItDescribes(): void
	{
		$oMethod = new ReflectionMethod(DatamodelReader::class, 'RightsOf');
		$aLines = file($oMethod->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		preg_match_all("/'([A-Za-z]+)'\\s*=>/", $sBody, $aMatches);

		$this->assertSame(
			DatamodelReader::RightsKeys(),
			$aMatches[1],
			'RightsKeys() and the block rights() returns have come apart'
		);
	}

	public function testFilteringToleratesSummariesMissingAField(): void
	{
		$aClasses = [['class' => 'Server']];

		$this->assertCount(1, DatamodelReader::FilterByText($aClasses, 'Server'));
		$this->assertCount(0, DatamodelReader::FilterByText($aClasses, 'ticket'));
	}

	/**
	 * The two formats iTop's own internal formats resolve to. 'Y-m-d' is
	 * RFC 3339 full-date, so AttributeDate can be described by `format` alone;
	 * 'Y-m-d H:i:s' is not RFC 3339 date-time, so the pattern is the only
	 * honest description of it.
	 */
	public function testPatternsForTheFormatsITopActuallyUses(): void
	{
		$this->assertSame('^\d{4}-\d{2}-\d{2}$', DatamodelReader::PatternFromDateFormat('Y-m-d'));
		$this->assertSame(
			'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$',
			DatamodelReader::PatternFromDateFormat('Y-m-d H:i:s')
		);
	}

	public function testThePatternAcceptsITopsValuesAndRejectsRfc3339(): void
	{
		$sPattern = DatamodelReader::PatternFromDateFormat('Y-m-d H:i:s');

		$this->assertSame(1, preg_match('/'.$sPattern.'/', '2026-09-18 07:53:01'));
		// The very string a model told "date-time" would send, and the one
		// AttributeDateTime::MakeRealValue() throws on.
		$this->assertSame(0, preg_match('/'.$sPattern.'/', '2026-09-18T07:53:01Z'));
		$this->assertSame(0, preg_match('/'.$sPattern.'/', 'yesterday'));
	}

	/**
	 * JSON Schema patterns are read as ECMA-262, where "\-" and "\:" are
	 * invalid identity escapes - which is exactly what preg_quote() would emit
	 * for iTop's separators.
	 */
	public function testSeparatorsAreLeftUnescaped(): void
	{
		$sPattern = DatamodelReader::PatternFromDateFormat('Y-m-d H:i:s');

		$this->assertStringNotContainsString('\-', $sPattern);
		$this->assertStringNotContainsString('\:', $sPattern);
	}

	public function testRegexSyntaxCharactersInAFormatAreEscaped(): void
	{
		$this->assertSame('^\d{4}\.\d{2}$', DatamodelReader::PatternFromDateFormat('Y.m'));
	}

	/**
	 * A pattern that rejects valid values is worse than no pattern, so an
	 * untranslatable format yields none.
	 */
	public function testAnUntranslatableFormatYieldsNoPattern(): void
	{
		$this->assertNull(DatamodelReader::PatternFromDateFormat('D, d M Y'), 'D and M are not numeric tokens');
		$this->assertNull(DatamodelReader::PatternFromDateFormat('\\Y-m-d'), 'a backslash escape is not translated');
		$this->assertNull(DatamodelReader::PatternFromDateFormat(''));
	}

	/**
	 * Allowed values keep the code a caller has to send.
	 *
	 * iTop answers code => label, and for most attributes the codes are
	 * strings, so the payload carries an object. A stopwatch sub-item is keyed
	 * [0 => label, 1 => label]: PHP calls that a list, json_encode drops the
	 * keys, and sla_tto_passed reached a caller as ["no","yes"] - the labels,
	 * localised through Dict::S('BooleanLabel:*'), with the 0 and 1 that are
	 * actually stored gone. A search built from that filters on a string the
	 * column never holds.
	 *
	 * Asserted on the encoded form, because the defect was in the encoding: in
	 * PHP both shapes are arrays and look equally fine.
	 */
	public function testAllowedValuesKeepTheirCodesThroughTheEncoding(): void
	{
		$oCodeKeyed = new ReflectionMethod(DatamodelReader::class, 'codeKeyed');

		$this->assertSame(
			'{"0":"no","1":"yes"}',
			json_encode($oCodeKeyed->invoke(null, [0 => 'no', 1 => 'yes'])),
			'a stopwatch sub-item must not answer with its labels alone'
		);
		$this->assertSame(
			'{"open":"Open"}',
			json_encode($oCodeKeyed->invoke(null, ['open' => 'Open'])),
			'an ordinary enumeration encodes exactly as it did'
		);
		$this->assertNull($oCodeKeyed->invoke(null, null), 'no enumeration stays no enumeration');
	}

	/**
	 * @return array<string, \ReflectionParameter>
	 */
	private function parameters(string $sTool): array
	{
		$aParameters = [];
		foreach ((new ReflectionMethod($sTool, 'execute'))->getParameters() as $oParameter) {
			$aParameters[$oParameter->getName()] = $oParameter;
		}

		return $aParameters;
	}

	/**
	 * @param array<int, array<string, mixed>> $aClasses
	 * @return array<int, string>
	 */
	private function names(array $aClasses): array
	{
		return array_column($aClasses, 'class');
	}
}
