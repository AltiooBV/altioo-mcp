<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory as HistoryTool;
use Altioo\iTop\Extension\MCP\Helper\ObjectHistory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * The three gates that make the change log safe to serve.
 *
 * CMDBChangeOp is granted per profile, as a class, and the grant says nothing
 * about the object a row points at nor about the attribute it names: objkey is
 * an integer column. So a caller holding it could otherwise read the history
 * of objects its silo hides, and the former values of attributes it may not
 * read today. Each gate below is the difference between this tool and an OQL
 * query a model could write for itself, which is the reason the tool exists.
 *
 * A source scan for the same reason as CurrentUserContactTest: UserRights and
 * MetaModel do not exist without iTop, and a unit suite that boots neither can
 * still hold the invariant in place.
 */
class ObjectHistoryContractTest extends TestCase
{
	public function testTheLogItselfIsGatedBeforeAnythingIsLookedUp(): void
	{
		$sBody = $this->bodyOf(HistoryTool::class, 'execute');

		$this->assertStringContainsString(
			'IsReadable()',
			$sBody,
			'the class-level grant on the change log is never checked'
		);
		$this->assertLessThan(
			strpos($sBody, 'MetaModel::GetObject'),
			strpos($sBody, 'IsReadable()'),
			'an instance that grants nobody the log must answer the same way whether or not the object exists'
		);
	}

	/**
	 * The object gate, applied with the object set rather than the class alone
	 * - a silo answers per object, and this is where that answer is asked for.
	 */
	public function testTheObjectMustBeOneTheCallerCouldHaveRead(): void
	{
		$sBody = $this->bodyOf(HistoryTool::class, 'execute');

		$this->assertStringContainsString('UR_ACTION_READ, $oSet', $sBody);
		$this->assertStringContainsString('not found.', $sBody, 'a refused object must not be distinguishable from an absent one');
	}

	/**
	 * Today's rights, not the ones in force when the row was written. A
	 * revoked permission that stayed readable through the log would be a
	 * revocation in name only.
	 */
	public function testEveryRowIsFilteredByTodaysAttributeRights(): void
	{
		$this->assertStringContainsString(
			'MayReadAttribute',
			$this->bodyOf(ObjectHistory::class, 'For'),
			'rows naming an attribute are served without asking whether this caller may read it'
		);
	}

	/**
	 * iTop files a row under get_class($this), so a leaf object's history is
	 * recorded under the leaf. Querying by the name the caller happened to ask
	 * about would match nothing and report "no history" for an object with
	 * plenty.
	 */
	public function testTheLogIsQueriedUnderTheObjectsOwnClass(): void
	{
		$this->assertStringContainsString(
			'get_class($oObject)',
			$this->bodyOf(ObjectHistory::class, 'For')
		);
	}

	public function testItIsItsOwnToolsetSoAnOperatorCanWithholdIt(): void
	{
		$this->assertSame('history', (new HistoryTool())->getToolset());
	}

	/**
	 * A ceiling, and a low default. priv_change is the one table an old
	 * instance holds tens of millions of rows in.
	 */
	public function testThePagingIsBoundedOnBothSides(): void
	{
		$aSchema = (new HistoryTool())->getInputSchema();

		$this->assertSame(ObjectHistory::MAX_LIMIT, $aSchema['properties']['limit']['maximum']);
		$this->assertSame(ObjectHistory::DEFAULT_LIMIT, $aSchema['properties']['limit']['default']);
		$this->assertLessThan(ObjectHistory::MAX_LIMIT, ObjectHistory::DEFAULT_LIMIT);
		$this->assertSame(['class', 'id'], $aSchema['required']);
	}

	/**
	 * The body of one method, comments stripped - otherwise a call named only
	 * in a doc comment would satisfy the search.
	 */
	private function bodyOf(string $sClass, string $sMethod): string
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
