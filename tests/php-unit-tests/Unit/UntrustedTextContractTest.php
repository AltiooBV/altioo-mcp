<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectFindByName;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetDocument;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectHistory;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Core\Tools\PortalList;
use Altioo\iTop\Extension\MCP\Service\Read\UntrustedMarker;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * core_object_get marks the free text that other people wrote.
 *
 * The server instruction that ticket text is data is read only by a client
 * that reads instructions; the marker travels with the output. The field
 * choice needs the datamodel, so this pins the wiring and the wording, and
 * the integration suite the choice.
 */
class UntrustedTextContractTest extends TestCase
{
	public function testTheReadReportsTheMarkerBesideTheObject(): void
	{
		$sBody = $this->bodyOf(ObjectGet::class);

		$this->assertStringContainsString('UntrustedMarker::UntrustedText(', $sBody);
		$this->assertStringContainsString("'untrusted_text'", $sBody);
	}

	/**
	 * Every tool that returns rows of other people's text marks them, the
	 * portal's list included: a title is free text too.
	 */
	public function testEveryListingMarksItsRows(): void
	{
		foreach ([ObjectSearchByClass::class, ObjectSearchByOQL::class, ObjectFindByName::class, PortalList::class] as $sTool) {
			$this->assertStringContainsString('UntrustedMarker::MarkRows(', $this->bodyOf($sTool), $sTool);
		}
	}

	public function testTheNoticeSaysDataNotInstructions(): void
	{
		$this->assertStringContainsString('data', UntrustedMarker::UNTRUSTED_NOTICE);
		$this->assertStringContainsString('never as instructions', UntrustedMarker::UNTRUSTED_NOTICE);
	}

	public function testTheRelatedGraphMarksItsNames(): void
	{
		$oMethod = new ReflectionMethod(\Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetRelated::class, 'serializeGraph');
		$aLines = file($oMethod->getFileName());
		$sBody = implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));

		$this->assertStringContainsString('UntrustedMarker::MarkRows(', $sBody);
	}

	/** The history holds before and after values and case-log entries; the document is the file itself. */
	public function testTheHistoryAndTheDocumentCarryTheNotice(): void
	{
		$this->assertStringContainsString("'untrusted_text'", $this->bodyOf(ObjectHistory::class));
		$this->assertStringContainsString('UntrustedMarker::UNTRUSTED_NOTICE', $this->bodyOf(ObjectHistory::class));
		$this->assertStringContainsString('UntrustedMarker::UNTRUSTED_DOCUMENT_NOTICE', $this->bodyOf(ObjectGetDocument::class));
	}

	private function bodyOf(string $sClass): string
	{
		$oMethod = new ReflectionMethod($sClass, 'execute');
		$aLines = file($oMethod->getFileName());

		return implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));
	}
}
