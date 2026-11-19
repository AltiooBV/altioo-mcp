<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectApplyStimulus;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Access\ObjectAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalAccess;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalPolicy;
use Altioo\iTop\Extension\MCP\Service\Read\LinkedRows;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Service\Read\ValueCeilings;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Write\EditPolicy;
use Altioo\iTop\Extension\MCP\Service\Write\WriteOutcome;
use Altioo\iTop\Extension\MCP\Service\Write\WriteValues;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * The rows of a link set in portal mode are the portal's rows (RT-9), and a write
 * of one keeps the rows the portal hides (RT-10).
 *
 * The values need a portal and a datamodel, and are held by the integration suite
 * (`PortalLinkRowsTest`). This is ADR 0014's half: the two checks live in shared
 * code - one place every route that serialises a link set passes through, one
 * place every value a caller sends is converted - so each is taught to the
 * contract tests by name, and its body is pinned here.
 */
class LinkedRowsContractTest extends TestCase
{
	protected function tearDown(): void
	{
		LinkedRows::Forget();
		PortalMode::Forget();
	}

	/**
	 * Every route that reports a link set - a read, a portal list, a write's `applied`
	 * and `changes`, a dry run - goes through ObjectSerializer::Value(), so that is
	 * where the portal is asked, and it is asked before the datamodel's own rendering.
	 */
	public function testEveryLinkSetIsServedThroughTheOnePlaceThatAsksThePortal(): void
	{
		$oValue = SourceCalls::OfClass(ObjectSerializer::class);
		$this->assertTrue($oValue->HasCall(LinkedRows::class.'::Of', 'Value'), 'ObjectSerializer::Value() no longer asks which rows of a link set are shown');
		$this->assertTrue(
			$oValue->CallsInOrder([LinkedRows::class.'::Of', '->GetForJSON'], 'Value'),
			'the portal is asked before the datamodel renders the set'
		);
		// The rows come from the access, whatever the ceiling: output_fields never decides which rows may be read.
		$this->assertStringContainsString('$bClip?ValueCeilings::MAX_LINKS:null', $oValue->Code('Value'));
	}

	/**
	 * AttributeLinkedSet::GetForJSON() renders every row with every scalar attribute. It is called
	 * where the portal has been asked first and nowhere else: a second path that renders a set is
	 * a route the portal's rows do not govern. The row builders of PortalAccess call it on one
	 * attribute of a row, never on a set.
	 */
	public function testNothingRendersALinkSetButTheSerializer(): void
	{
		$aAllowed = [
			'Service/Read/ObjectSerializer.php' => ['Value'],
			'Service/Portal/PortalAccess.php'   => ['addColumn'],
		];

		$sSrc = dirname(__DIR__, 3).'/src';
		$aFound = [];
		$oIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sSrc, \FilesystemIterator::SKIP_DOTS));
		foreach ($oIterator as $oFile) {
			if ($oFile->getExtension() !== 'php') {
				continue;
			}
			$sRelative = substr($oFile->getPathname(), strlen($sSrc) + 1);
			$oSource = SourceCalls::OfFile($oFile->getPathname());
			foreach ($oSource->MethodNames() as $sMethod) {
				if ($oSource->HasCall('->GetForJSON', $sMethod)) {
					$aFound[$sRelative][] = $sMethod;
				}
			}
		}

		// The directory is walked in whatever order the filesystem returns it, which differs between
		// machines (it did on GitHub's runners), so both sides are compared sorted.
		ksort($aAllowed);
		ksort($aFound);
		$this->assertSame($aAllowed, $aFound, 'GetForJSON() is called somewhere new: a link set rendered there is not the portal\'s row');
	}

	public function testThePortalWithNothingToAskServesNoRow(): void
	{
		$oSource = SourceCalls::OfClass(LinkedRows::class);
		$this->assertTrue($oSource->HasCall(PortalMode::class.'::Current', 'Of'));
		$this->assertStringContainsString('if(PortalMode::Current()===null){returnnull;}', $oSource->Code('Of'));
		$this->assertStringContainsString('if(self::$fRows===null){return[[],0];}', $oSource->Code('Of'), 'portal mode with nobody to ask must serve no row, never every row');
	}

	public function testPortalModeIsGivenItsAnswerWhenEnteredAndLosesItWhenClosed(): void
	{
		$this->assertTrue(SourceCalls::OfClass(RequestScope::class)->HasCall(LinkedRows::class.'::Remember', 'EnterPortal'));
		$this->assertTrue(SourceCalls::OfClass(RequestScope::class)->HasCall(LinkedRows::class.'::Forget', 'Close'));
		$this->assertTrue(
			SourceCalls::OfClass(RequestScope::class)->HasCall(ObjectAccess::class.'::Current', 'EnterPortal'),
			'the rows are asked of the access of the request, which is built when asked'
		);
	}

	/**
	 * What decides a row, in the portal's access: the scope on the remote object (one query
	 * for many), the console's questions on it, the operator's exclusions, the form of the
	 * remote class, the portal's own list for the columns, and never a blob.
	 */
	public function testThePortalsRowsAreTheRemoteObjectsTheScopeHoldsWithTheColumnsThePortalLists(): void
	{
		$oAccess = SourceCalls::OfClass(PortalAccess::class);

		$this->assertTrue($oAccess->HasCallTo('ReadableObjects', 'linksOf'), 'a row is shown only when the portal\'s scope holds its remote object');
		$this->assertTrue($oAccess->HasCallTo('consoleReads', 'linksOf'));
		$this->assertTrue($oAccess->HasCall('UserRights::IsActionAllowed', 'consoleReads'));
		$this->assertTrue($oAccess->HasCall(ExclusionPolicy::class.'::ExcludesClass', 'consoleReads'));

		$this->assertTrue($oAccess->HasCallTo('ListColumns', 'directRow'), 'a 1-n row carries the portal\'s list of the class, not every attribute');
		$this->assertTrue($oAccess->HasCallTo('ShownOf', 'directRow'), 'and within the view form of the object');
		$this->assertTrue($oAccess->HasCallTo('ListColumns', 'indirectRow'));
		$this->assertTrue($oAccess->HasCall(ExclusionPolicy::class.'::ExcludesAttribute', 'indirectRow'));

		$sColumn = $oAccess->Code('addColumn');
		$this->assertStringContainsString('instanceof\AttributeBlob', $sColumn, 'a blob is never inlined in a row');
		$this->assertTrue($oAccess->HasCall(ObjectSerializer::class.'::MayReadAttribute', 'addColumn'));
		$this->assertTrue($oAccess->HasCall(ObjectSerializer::class.'::IsSensitive', 'addColumn'));
		$this->assertTrue($oAccess->HasCall(ExclusionPolicy::class.'::ExcludesAttribute', 'addColumn'));

		// A row hidden is not in the total either: the count is of what is shown.
		$this->assertStringContainsString('if(!$aLink[\'shown\']){continue;}', $oAccess->Code('LinkedRows'));
	}

	public function testThePolicyAnswersForManyObjectsThroughItsOwnScopes(): void
	{
		$oPolicy = SourceCalls::OfClass(PortalPolicy::class);
		$this->assertTrue($oPolicy->HasCallTo('ReadSet', 'ReadableObjects'));
		// A child class can carry a narrower scope than its parent, as Readable() asks.
		$this->assertTrue($oPolicy->CallsInSequence(['->ReadSet', 'get_class', '->ReadSet'], 'ReadableObjects'));
	}

	/**
	 * A write replaces the rows the caller was shown: the rows the mode hides are
	 * added to the list they sent before it is compared with the current one, and
	 * before the values are Set(), so that no later check reads them as removed.
	 */
	public function testAWriteKeepsTheRowsTheCallerWasNeverShown(): void
	{
		$oValues = SourceCalls::OfClass(WriteValues::class);
		$this->assertTrue($oValues->HasCall('->KeepHiddenLinks', 'Validated'));
		$this->assertTrue(
			$oValues->CallsInOrder(['->KeepHiddenLinks', EditPolicy::class.'::RefusalForLinkSet'], 'Validated'),
			'the hidden rows are kept before the set is judged: judged without them, they read as removed'
		);

		$oAccess = SourceCalls::OfClass(PortalAccess::class);
		$this->assertTrue($oAccess->HasCallTo('linksOf', 'KeepHiddenLinks'));
		$this->assertTrue($oAccess->HasCall('->AddObject', 'KeepHiddenLinks'));
		$this->assertStringContainsString('if(!$aLink[\'shown\']&&!$aLink[\'link\']->IsNew())', $oAccess->Code('KeepHiddenLinks'));

		// The console serves a set whole: nothing kept, every row.
		$oBase = SourceCalls::OfClass(ObjectAccess::class);
		$this->assertSame('', $oBase->Code('KeepHiddenLinks'));
		$this->assertSame('returnnull;', $oBase->Code('LinkedRows'));
	}

	/** A row the set already held is not the caller's choice; one they added is held to what they could reach. */
	public function testARowTheSetAlreadyHeldIsNotRefusedAsOneTheCallerReached(): void
	{
		$sCode = SourceCalls::OfClass(PortalAccess::class)->Code('outOfReach');

		$this->assertStringContainsString('GetOriginalSet()', $sCode);
		$this->assertStringContainsString('!$oLink->IsNew()&&isset($aStored[(int)$oLink->GetKey()])', $sCode);
		$this->assertStringContainsString('$this->oPolicy->Readable(', $sCode, 'a row the caller added is still refused when the portal does not show it');
	}

	public function testTheWriteToolsSayWhatBecameOfTheRowsTheCallerCannotSee(): void
	{
		foreach ([ObjectUpdate::class, ObjectApplyStimulus::class] as $sTool) {
			$this->assertTrue(SourceCalls::OfClass($sTool)->HasCall(WriteOutcome::class.'::LinksKept', 'execute'), $sTool);
		}
		$this->assertTrue(SourceCalls::OfClass(WriteOutcome::class)->HasCall(PortalMode::class.'::Current', 'LinksKept'));
	}

	public function testALinkSetCutByTheCeilingCountsWhatThePortalShowsAndNothingMore(): void
	{
		$aRows = array_fill(0, ValueCeilings::MAX_LINKS, ['id' => 1]);

		$this->assertSame($aRows, ValueCeilings::firstLinks($aRows), 'a set that fits is served whole');
		$this->assertSame($aRows, ValueCeilings::firstLinks($aRows, ValueCeilings::MAX_LINKS), 'and so when the total says it fits');

		$aCut = ValueCeilings::firstLinks($aRows, ValueCeilings::MAX_LINKS + 30);
		$this->assertSame($aRows, $aCut['links']);
		$this->assertSame(ValueCeilings::MAX_LINKS + 30, $aCut['links_total']);
		$this->assertSame(30, $aCut['links_omitted']);

		$aMore = array_fill(0, ValueCeilings::MAX_LINKS + 5, ['id' => 1]);
		$aCut = ValueCeilings::firstLinks($aMore);
		$this->assertCount(ValueCeilings::MAX_LINKS, $aCut['links']);
		$this->assertSame(ValueCeilings::MAX_LINKS + 5, $aCut['links_total']);
	}
}
