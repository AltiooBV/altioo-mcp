<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the activity dashboard says it shows, against what the audit row holds.
 *
 * The dashboard is data (iTop's DashboardMenuNode), so a wrong filter is not a
 * failure anywhere: it is a dashlet that quietly counts nothing, or counts
 * something else. These read the datamodel without iTop; what actually renders
 * is proved on an instance (the matrix's smoke and the hand-run in the PR).
 */
class ActivityDashboardContractTest extends TestCase
{
	private const ROOT = __DIR__.'/../../..';

	private static function datamodel(): SimpleXMLElement
	{
		$oXml = simplexml_load_file(self::ROOT.'/datamodel.altioo-mcp.xml');
		self::assertInstanceOf(SimpleXMLElement::class, $oXml);

		return $oXml;
	}

	/** @return array<int, SimpleXMLElement> */
	private static function dashlets(): array
	{
		$aDashlets = self::datamodel()->xpath('//menu[@id="MCPActivity"]//dashlet');

		return is_array($aDashlets) ? $aDashlets : [];
	}

	private static function dictionary(string $sFile): string
	{
		return (string)file_get_contents(self::ROOT.'/datamodel.altioo-mcp.dict.'.$sFile.'.xml');
	}

	public function testBothEntriesAreAdministratorOnlyUnderAdministration(): void
	{
		foreach (['MCPActivity' => 'DashboardMenuNode', 'MCPServiceCalls' => 'OQLMenuNode'] as $sId => $sType) {
			$aMenu = self::datamodel()->xpath('//menu[@id="'.$sId.'"]');
			$this->assertIsArray($aMenu);
			$this->assertCount(1, $aMenu, $sId);
			$this->assertSame($sType, (string)$aMenu[0]->attributes('http://www.w3.org/2001/XMLSchema-instance')['type'], $sId);
			$this->assertSame('AdminTools', (string)$aMenu[0]->parent, $sId.' sits under Administration');
			$this->assertSame('1', (string)$aMenu[0]->enable_admin_only, $sId.' is for administrators only');
		}
	}

	public function testTheListIsTheWholeAuditClass(): void
	{
		$aMenu = self::datamodel()->xpath('//menu[@id="MCPServiceCalls"]');

		$this->assertSame('SELECT AltiooEventMCPService', trim((string)$aMenu[0]->oql));
	}

	public function testEveryDashletLooksAtTheAuditClassOverTheLastSevenDays(): void
	{
		$this->assertGreaterThanOrEqual(8, count(self::dashlets()));
		foreach (self::dashlets() as $oDashlet) {
			$sQuery = (string)$oDashlet->query;
			$this->assertStringStartsWith('SELECT AltiooEventMCPService WHERE date > DATE_SUB(NOW(), INTERVAL 7 DAY)', $sQuery, (string)$oDashlet->title);
		}
	}

	/**
	 * Grouping on the login string must say so: it is not a person, and after
	 * an anonymisation it is no longer even the login the person chose.
	 */
	public function testAnyDashletGroupedOnUserinfoSaysItIsALogin(): void
	{
		$sEn = self::dictionary('en-us');
		$sFr = self::dictionary('fr-fr');
		$iChecked = 0;
		foreach (self::dashlets() as $oDashlet) {
			if ((string)$oDashlet->group_by !== 'userinfo') {
				continue;
			}
			$iChecked++;
			$sKey = (string)$oDashlet->title;
			$this->assertSame(1, preg_match('#id="'.preg_quote($sKey, '#').'"[^>]*><!\[CDATA\[([^\]]*)\]\]>#', $sEn, $aEn), $sKey.' has no English title');
			$this->assertSame(1, preg_match('#id="'.preg_quote($sKey, '#').'"[^>]*><!\[CDATA\[([^\]]*)\]\]>#', $sFr, $aFr), $sKey.' has no French title');
			$this->assertStringContainsString('login', $aEn[1], 'the English title says it is a login');
			$this->assertStringContainsString('identifiant', $aFr[1], 'the French title says it is a login');
		}
		$this->assertGreaterThanOrEqual(3, $iChecked);
	}

	public function testEveryKeyTheDashboardUsesIsTranslatedInBothLanguages(): void
	{
		$aKeys = ['Menu:MCPActivity', 'Menu:MCPActivity+', 'Menu:MCPServiceCalls', 'Menu:MCPServiceCalls+', (string)self::datamodel()->xpath('//menu[@id="MCPActivity"]//definition/title')[0]];
		foreach (self::dashlets() as $oDashlet) {
			$aKeys[] = (string)$oDashlet->title;
		}
		foreach (array_unique($aKeys) as $sKey) {
			foreach (['en-us', 'fr-fr'] as $sLang) {
				$this->assertStringContainsString('<entry id="'.$sKey.'" _delta="force">', self::dictionary($sLang), $sKey.' is missing in '.$sLang);
			}
		}
	}

	public function testEveryGroupedAttributeIsOneTheClassHas(): void
	{
		$aOwn = [];
		foreach (self::datamodel()->xpath('//class[@id="AltiooEventMCPService"]/fields/field') as $oField) {
			$aOwn[] = (string)$oField['id'];
		}
		$aInherited = ['date', 'userinfo', 'message'];
		foreach (self::dashlets() as $oDashlet) {
			$sAttribute = explode(':', (string)$oDashlet->group_by)[0];
			$this->assertContains($sAttribute, array_merge($aOwn, $aInherited), (string)$oDashlet->title.' groups on an attribute the class does not have');
			$sAggregated = (string)$oDashlet->aggregation_attribute;
			if ($sAggregated !== '') {
				$this->assertContains($sAggregated, $aOwn);
			}
		}
	}

	/**
	 * A refused portal login is a row of method "login" and element
	 * "portal_admission", as the controller writes it. A filter that spelt
	 * either differently would be a dashlet that always reads zero.
	 */
	public function testTheRefusedLoginDashletMatchesWhatTheControllerWrites(): void
	{
		// Code(), not text: a comment or a docblock that quotes the call does not write the row.
		$oController = SourceCalls::OfFile(self::ROOT.'/src/Controller/CallLog.php');
		$this->assertTrue($oController->HasCallTo('Set'), 'CallLog sets no column at all');
		$this->assertStringContainsString("->Set('mcp_method','login')", $oController->Code());
		$this->assertStringContainsString("->Set('mcp_name','portal_admission')", $oController->Code());

		$aRefused = self::datamodel()->xpath('//dashlet[title="MCP:Dashboard:RefusedLogins"]');
		$this->assertCount(1, $aRefused);
		$this->assertStringContainsString("mcp_method = 'login' AND mcp_name = 'portal_admission'", (string)$aRefused[0]->query);
	}

	/**
	 * A write is a row that wrote: not a dry run, and carrying its change. It
	 * does not read the change's own line, which carries nothing of the channel
	 * since ADR 0009.
	 */
	public function testTheWriteDashletsUseTheRowsOwnColumns(): void
	{
		foreach (['MCP:Dashboard:WritesByTool', 'MCP:Dashboard:WritesByDay', 'MCP:Dashboard:WritesByLogin'] as $sTitle) {
			$aDashlet = self::datamodel()->xpath('//dashlet[title="'.$sTitle.'"]');
			$sQuery = (string)$aDashlet[0]->query;
			$this->assertStringContainsString("simulate = 'no' AND change_id != 0", $sQuery, $sTitle);
			$this->assertStringNotContainsString('(MCP', $sQuery, $sTitle);
		}
	}

	public function testTheDashboardIsDataAndNotCode(): void
	{
		$this->assertFileDoesNotExist(self::ROOT.'/src/Service/ActivityDashboard.php');
		$this->assertStringNotContainsString('DashletGroupBy', SourceCalls::OfFile(self::ROOT.'/src/Controller/MCPController.php')->Code());
	}
}
