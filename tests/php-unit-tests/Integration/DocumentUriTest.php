<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectAttach;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGetDocument;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C18: core_object_get_document takes the uri a read reports, and reaches nothing the three arguments
 * would not: the same exclusion, the same change-log refusal, the same unknown id, whichever way the document is named.
 */
class DocumentUriTest extends ItopDataTestCaseAlias
{
	private int $iAttachment = 0;

	protected function setUp(): void
	{
		parent::setUp();
		if (!MetaModel::IsValidClass('Attachment') || !MetaModel::IsValidClass('UserRequest')) {
			$this->markTestSkipped('Needs the attachments and the request management modules.');
		}
		$this->exclude([]);
		$iOrg = (int)$this->CreateOrganization('DocUri '.bin2hex(random_bytes(4)))->GetKey();
		$iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()])->GetKey();
		$aDone = ToolOutput::Decode(ObjectAttach::execute('UserRequest', $iTicket, 'uri.txt', base64_encode("By uri.\n"), 'text/plain', null, false, 'Because'));
		$this->iAttachment = (int)$aDone['id'];
	}

	protected function tearDown(): void
	{
		$this->exclude([]);
		RequestScope::Close();
		parent::tearDown();
	}

	/** @param array<int, string> $aClasses */
	private function exclude(array $aClasses): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, []);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	private function refusal(callable $f): string
	{
		try {
			$f();
		} catch (ToolCallException $e) {
			return $e->getMessage();
		}
		$this->fail('not refused');
	}

	public function testAUriReadsWhatTheThreeArgumentsRead(): void
	{
		$sUri = 'itop://core/document/Attachment/'.$this->iAttachment.'/contents';

		$aByUri = ObjectGetDocument::execute(uri: $sUri);
		$aByParts = ObjectGetDocument::execute('Attachment', $this->iAttachment, 'contents');

		$this->assertInstanceOf(TextContent::class, $aByUri[0]);
		$this->assertEquals($aByParts, $aByUri, 'the same notice and the same document');
	}

	public function testAnExcludedClassIsRefusedByUriInTheWordsOfTheArguments(): void
	{
		$this->exclude(['Attachment']);

		$sByParts = $this->refusal(fn () => ObjectGetDocument::execute('Attachment', $this->iAttachment, 'contents'));
		$sByUri = $this->refusal(fn () => ObjectGetDocument::execute(uri: 'itop://core/document/Attachment/'.$this->iAttachment.'/contents'));

		$this->assertSame($sByParts, $sByUri);
	}

	public function testTheChangeLogIsRefusedByUri(): void
	{
		$sByParts = $this->refusal(fn () => ObjectGetDocument::execute('CMDBChangeOpSetAttributeBlob', 1, 'prevdata'));
		$sByUri = $this->refusal(fn () => ObjectGetDocument::execute(uri: 'itop://core/document/CMDBChangeOpSetAttributeBlob/1/prevdata'));

		$this->assertSame($sByParts, $sByUri);
		$this->assertStringContainsString('change log', $sByUri);
	}

	public function testAnUnknownIdAndAnUnknownAttributeAnswerAsTheArgumentsDo(): void
	{
		foreach ([[999999, 'contents'], [$this->iAttachment, 'nonesuch']] as [$iId, $sAtt]) {
			$sByParts = $this->refusal(fn () => ObjectGetDocument::execute('Attachment', $iId, $sAtt));
			$sByUri = $this->refusal(fn () => ObjectGetDocument::execute(uri: "itop://core/document/Attachment/{$iId}/{$sAtt}"));
			$this->assertSame($sByParts, $sByUri);
		}
	}

	public function testAUriForAnotherHostOrSchemeIsRefused(): void
	{
		foreach (['itop://elsewhere/document/Attachment/'.$this->iAttachment.'/contents', 'https://core/document/Attachment/'.$this->iAttachment.'/contents', 'file:///etc/passwd'] as $sUri) {
			$this->assertStringContainsString('not a document uri of this server', $this->refusal(fn () => ObjectGetDocument::execute(uri: $sUri)));
		}
	}
}
