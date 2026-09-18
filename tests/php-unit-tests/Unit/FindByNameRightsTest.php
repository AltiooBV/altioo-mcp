<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName;
use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * "Found nothing" and "was not allowed to look" must not be the same answer.
 *
 * searchableClasses() drops every candidate the caller lacks UR_ACTION_BULK_READ
 * on. Dropped silently, a caller with no bulk read anywhere gets total 0 and
 * isError false - an agent reads that as "there is no such object" and reports
 * it to the user as fact, when the truth is that it searched nothing at all.
 *
 * What this must not become is the oracle that §6.2 closes: the count below is
 * the caller's own right on classes, never whether an object exists. The
 * "unknown class" wording that conflates an absent class with an unreadable one
 * stays exactly as it is.
 */
class FindByNameRightsTest extends TestCase
{
	/**
	 * A class dropped for want of the right is counted, not just skipped.
	 */
	public function testWithheldClassesAreCounted(): void
	{
		$sBody = str_replace(' ', '', $this->methodBody(ObjectFindByName::class, 'searchableClasses'));

		$this->assertStringContainsString(
			'UR_ACTION_BULK_READ',
			$sBody,
			'The bulk read check is what separates a searchable class from a readable one.'
		);
		$this->assertStringContainsString(
			'$iWithheld++',
			$sBody,
			'A class dropped for want of bulk read has to be counted, otherwise the'
			.' caller cannot tell an empty result from a refused one.'
		);
	}

	/**
	 * The count reaches the caller.
	 */
	public function testTheCountIsReported(): void
	{
		$this->assertStringContainsString(
			"'classes_withheld'",
			$this->methodBody(ObjectFindByName::class, 'execute'),
			'Counting withheld classes and not returning the count leaves the caller'
			.' exactly where it started.'
		);
	}

	/**
	 * A caller that named a class it may read, and got nothing searchable back,
	 * is refused rather than told there are no matches.
	 */
	public function testANamedUnsearchableClassIsRefused(): void
	{
		$this->assertStringContainsString(
			'Bulk read access denied',
			$this->methodBody(ObjectFindByName::class, 'searchableClasses'),
			'A named class the caller may read one object at a time but not search'
			.' must say so, the way core_object_search_by_class already does.'
		);
	}

	/**
	 * Both tools refuse in the same words.
	 *
	 * Two spellings of one refusal teach a model that they are two different
	 * conditions.
	 */
	public function testBothToolsRefuseBulkReadInTheSameWords(): void
	{
		$sExpected = "Bulk read access denied to class '{\$sClass}'.";
		$sSibling  = "Bulk read access denied to class '{\$class}'.";

		$this->assertStringContainsString(
			$sExpected,
			$this->methodBody(ObjectFindByName::class, 'searchableClasses'),
			'core_object_find_by_name must use the shared wording.'
		);
		$this->assertStringContainsString(
			$sSibling,
			$this->methodBody(ObjectSearchByClass::class, 'execute'),
			'core_object_search_by_class is where that wording comes from; if it'
			.' changed, find_by_name has drifted away from it.'
		);
	}

	/**
	 * The anti-enumeration property is untouched, and is now structural.
	 *
	 * Naming a class the caller may not read still has to be indistinguishable
	 * from naming one that does not exist: telling them apart is a class
	 * enumerator for a datamodel whose class names are themselves information.
	 *
	 * It used to be two identical string literals, kept identical by whoever
	 * edited them. Both paths now call the one helper, so they answer the same
	 * sentence by construction and cannot drift by being written twice - which
	 * is the stronger version of what this test was counting.
	 *
	 * What the helper says changed, and that is not this property: it used to
	 * assert "Unknown class 'X'." flatly, which is false half the time and sent
	 * a reviewer looking for why URP_UserProfile was missing from the datamodel
	 * when it was simply not theirs to read. It names both possibilities now.
	 * A caller still cannot tell which one it hit.
	 */
	public function testAnUnreadableClassIsStillIndistinguishableFromAnAbsentOne(): void
	{
		$sBody = $this->methodBody(ObjectFindByName::class, 'searchableClasses');

		$this->assertSame(
			2,
			substr_count($sBody, 'MCPHelper::UnreadableClassRefusal'),
			'Both the invalid class and the unreadable one must answer with the one helper;'
			.' splitting them hands an unprivileged caller a class enumerator.'
		);

		// And the helper itself must not give the two cases away.
		$sRefusal = MCPHelper::UnreadableClassRefusal('SomeClass');
		$this->assertStringContainsString('either this datamodel has no such class, or this account may not read it', $sRefusal,
			'the refusal settles which of the two happened, which is the oracle this rule closes');
		$this->assertStringNotContainsString('exists', $sRefusal,
			'the refusal confirms the class exists');
	}

	/**
	 * The body of one method, comments stripped - otherwise a phrase appearing
	 * only in a doc comment would satisfy the search.
	 */
	private function methodBody(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file((new ReflectionClass($sClass))->getFileName());
		$sBody = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
