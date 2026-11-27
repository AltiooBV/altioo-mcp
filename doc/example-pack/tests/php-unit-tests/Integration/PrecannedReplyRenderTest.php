<?php
/**
 * @copyright Copyright (C) 2026 Acme
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Acme\iTop\Extension\ServiceDesk\Test\Integration;

use Acme\iTop\Extension\ServiceDesk\Tools\PrecannedReplyRender;
use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Api\ToolRefusal;
use Combodo\iTop\Test\UnitTest\ItopDataTestCase;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use Throwable;
use UserRights;

/**
 * The predefined reply tool on an instance with Combodo's "Predefined response models" (module precanned-replies).
 *
 * Run as iTop runs an extension's tests, from iTop's own tests/php-unit-tests with its bootstrap, against the
 * env-production copy of this directory. Skipped where the module is not installed.
 *
 * Each case runs in a process of its own, so that the operator's exclusions it sets are the ones the base
 * extension reads: a request reads them once, and a test that changed them mid-process would be testing a cache.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PrecannedReplyRenderTest extends ItopDataTestCase
{
	private const PHONE = '+33 1 23 45 67 89';

	private int $iTicket = 0;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('PrecannedReply')) {
			$this->markTestSkipped('Combodo\'s precanned-replies module is not installed on this instance');
		}

		$iOrg = (int)$this->CreateOrganization('Precanned '.bin2hex(random_bytes(4)))->GetKey();
		$oCaller = $this->CreatePerson(1, $iOrg);
		$oCaller->Set('first_name', 'Ada');
		$oCaller->Set('phone', self::PHONE);
		$oCaller->DBUpdate();

		$oTicket = MetaModel::NewObject('UserRequest');
		$oTicket->Set('org_id', $iOrg);
		$oTicket->Set('caller_id', $oCaller->GetKey());
		$oTicket->Set('title', 'Printer');
		$oTicket->Set('description', 'It jams.');
		$oTicket->DBInsert();
		$this->iTicket = (int)$oTicket->GetKey();

		$iProfile = (int)(new DBObjectSet(DBObjectSearch::FromOQL_AllData('SELECT URP_Profiles WHERE name = "Administrator"')))->Fetch()->GetKey();
		$sLogin = 'precanned-'.bin2hex(random_bytes(3));
		$this->CreateUser($sLogin, $iProfile, 'Precanned-'.bin2hex(random_bytes(3)).'-1!', (int)$this->CreatePerson(2, $iOrg)->GetKey());
		UserRights::Login($sLogin);
	}

	private function reply(string $sBody): int
	{
		$oReply = MetaModel::NewObject('PrecannedReply');
		$oReply->Set('name', 'Reply '.bin2hex(random_bytes(3)));
		$oReply->Set('body', $sBody);
		$oReply->DBInsert();

		return (int)$oReply->GetKey();
	}

	/** @param array<string, array<int, string>> $aAttributes */
	private function exclude(array $aClasses, array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, 'mcp_excluded_classes', $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, 'mcp_excluded_attributes', $aAttributes);
	}

	/** @return array<string, mixed> */
	private function render(int $iReply, string $sClass = 'UserRequest', ?int $iTicket = null): array
	{
		return ToolOutput::Decode(PrecannedReplyRender::execute($sClass, $iTicket ?? $this->iTicket, $iReply));
	}

	public function testItIsServedWhereTheModuleIs(): void
	{
		$this->assertTrue((new PrecannedReplyRender())->isAvailable());
	}

	public function testThePlaceholdersAreFilledInAsTheExtensionFillsThem(): void
	{
		$aAnswer = $this->render($this->reply('<p>Hello $this->caller_id->first_name$, about $this-&gt;title$.</p>'));

		$this->assertStringContainsString('Hello Ada, about Printer.', $aAnswer['body']);
		$this->assertSame([], $aAnswer['unresolved_placeholders']);
		$this->assertContains('public_log', $aAnswer['log_attcodes'], 'the extension\'s default targets give UserRequest its public log');
		$this->assertSame('html', $aAnswer['format']);
	}

	/**
	 * The point of the tool's own checks: iTop's ApplyParams() would fill this in, and core_object_get would not
	 * show it. An excluded attribute is left as written - which is what iTop does with an attribute that does not
	 * exist, so the two read alike (ADR 0012).
	 */
	public function testAnExcludedAttributeIsLeftAsWrittenLikeOneThatDoesNotExist(): void
	{
		$this->exclude([], ['Person' => ['phone']]);
		$aAnswer = $this->render($this->reply('<p>$this->caller_id->first_name$ / $this->caller_id->phone$ / $this->caller_id->no_such_code$</p>'));

		$this->assertStringNotContainsString(self::PHONE, $aAnswer['body'], 'an excluded attribute reached the reply');
		$this->assertStringContainsString('Ada / $this-&gt;caller_id-&gt;phone$ / $this-&gt;caller_id-&gt;no_such_code$', $aAnswer['body']);
		// Quoted as the body holds them: iTop stores an HTML text with the arrow escaped.
		$this->assertSame(['$this-&gt;caller_id-&gt;phone$', '$this-&gt;caller_id-&gt;no_such_code$'], $aAnswer['unresolved_placeholders']);
	}

	public function testWithoutTheExclusionTheSamePlaceholderIsFilledIn(): void
	{
		$aAnswer = $this->render($this->reply('<p>$this->caller_id->phone$</p>'));

		$this->assertStringContainsString(self::PHONE, $aAnswer['body'], 'the exclusion test above proves nothing if the value never fills in');
	}

	/** A key to an excluded class is not followed: every placeholder through it stays as written. */
	public function testAKeyToAnExcludedClassIsNotFollowed(): void
	{
		$this->exclude(['Person'], []);
		$aAnswer = $this->render($this->reply('<p>$this->caller_id->first_name$ $this->caller_id_friendlyname$</p>'));

		$this->assertStringNotContainsString('Ada', $aAnswer['body']);
		$this->assertCount(2, $aAnswer['unresolved_placeholders'], 'the key and the field read through it are both the excluded class');
	}

	/** A class the extension's targets do not name is not offered replies, whatever the reply says. */
	public function testAClassTheTargetsDoNotNameIsRefused(): void
	{
		$iOrg = (int)$this->CreateOrganization('Not a ticket '.bin2hex(random_bytes(3)))->GetKey();

		try {
			$this->render($this->reply('<p>x</p>'), 'Organization', $iOrg);
			$this->fail('an Organization was offered a predefined reply');
		} catch (Throwable $e) {
			$this->assertTrue(ToolRefusal::Is($e), get_class($e).': '.$e->getMessage());
			$this->assertStringContainsString('not offered', $e->getMessage());
		}
	}

	public function testAReplyThatDoesNotExistIsRefused(): void
	{
		try {
			$this->render(999999);
			$this->fail('a reply that does not exist was rendered');
		} catch (Throwable $e) {
			$this->assertTrue(ToolRefusal::Is($e), get_class($e).': '.$e->getMessage());
			$this->assertStringContainsString('not found', $e->getMessage());
		}
	}
}
