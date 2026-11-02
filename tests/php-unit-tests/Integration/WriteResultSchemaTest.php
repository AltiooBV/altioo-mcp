<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Registry\MCPExtensionCollector;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\MCPService;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Http\Discovery\Psr17Factory;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Server\Transport\StreamableHttpTransport;
use MetaModel;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What a writing tool answers, as it leaves the server, against the
 * outputSchema the same tool declares.
 *
 * OutputSchemaContractTest reads the schemas; nothing read a result. A row of
 * core_object_bulk_update answered `overridden: []` where its schema promises
 * an object, and every other test passed - the shape is only visible on the
 * wire, where PHP's empty array becomes a JSON list. From mcp/sdk 0.9.0 the
 * server validates structuredContent itself and replaces a mismatch with
 * `isError: true`, after the write has run.
 *
 * So each call goes through the SDK's tools/call, and the structured half is
 * decoded from the response body without the assoc flag, which keeps `{}` and
 * `[]` apart, then checked with the SDK's own validator. That makes the check
 * independent of whether the SDK in vendor/ validates: on one that does, a
 * mismatch fails the isError assertion first; on one that does not, the
 * validator here fails it.
 *
 * Real writes where the result of one differs from its dry run, dry runs
 * elsewhere. Everything happens inside the test case's transaction.
 */
class WriteResultSchemaTest extends ItopDataTestCaseAlias
{
	/**
	 * Every registered tool that declares an output schema, by the test that
	 * calls it. A tool added with a schema and no call here fails
	 * testEveryToolWithAnOutputSchemaIsCalledHere().
	 */
	private const COVERED = [
		'core_object_create',
		'core_object_update',
		'core_object_delete',
		'core_object_apply_stimulus',
		'core_object_attach',
		'core_object_bulk_create',
		'core_object_bulk_update',
		'core_object_bulk_delete',
		'core_set_obsolete_data',
	];

	private int $iOrg;

	private int $iRequestId = 100;

	protected function setUp(): void
	{
		parent::setUp();

		$this->iOrg = (int)$this->CreateOrganization('WriteResultSchemaTest '.bin2hex(random_bytes(4)))->GetKey();
	}

	public function testEveryToolWithAnOutputSchemaIsCalledHere(): void
	{
		MCPExtensionCollector::CollectAll();

		$aDeclaring = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			if ($oTool->getOutputSchema() !== null) {
				$aDeclaring[] = $sName;
			}
		}

		sort($aDeclaring);
		$aCovered = self::COVERED;
		sort($aCovered);

		$this->assertSame($aCovered, $aDeclaring, 'a tool declares an output schema this test never calls, or the list names one that is gone');
	}

	public function testCreateUpdateAndDelete(): void
	{
		$sName = 'WriteResultSchemaTest '.bin2hex(random_bytes(4));

		$this->assertConforms('core_object_create', ['class' => 'Organization', 'fields' => ['name' => $sName], 'simulate' => true]);
		$oCreated = $this->assertConforms('core_object_create', ['class' => 'Organization', 'fields' => ['name' => $sName], 'simulate' => false]);
		$iId = (int)$oCreated->id;

		$this->assertConforms('core_object_update', ['class' => 'Organization', 'id' => $iId, 'fields' => ['code' => 'WRS1'], 'simulate' => false]);

		$this->assertConforms('core_object_delete', ['class' => 'Organization', 'id' => $iId, 'simulate' => true]);
		$this->assertConforms('core_object_delete', ['class' => 'Organization', 'id' => $iId, 'simulate' => false]);
	}

	/**
	 * Real writes, because a row that was written is the case the bug was in.
	 * Nothing is overridden on either row, which is what made `overridden`
	 * empty; the second sets a value already stored, which empties `changes`.
	 */
	public function testBulkWrites(): void
	{
		$iFirst = (int)$this->CreateOrganization('WriteResultSchemaTest bulk '.bin2hex(random_bytes(4)))->GetKey();

		$this->assertConforms('core_object_bulk_create', ['class' => 'Organization', 'objects' => [['name' => 'WriteResultSchemaTest '.bin2hex(random_bytes(4))]], 'simulate' => false]);
		$this->assertConforms('core_object_bulk_update', ['class' => 'Organization', 'ids' => [$iFirst, $this->iOrg], 'fields' => ['code' => 'WRS2'], 'simulate' => false]);
		$this->assertConforms('core_object_bulk_update', ['class' => 'Organization', 'ids' => [$iFirst], 'fields' => ['code' => 'WRS2'], 'simulate' => false]);
		$this->assertConforms('core_object_bulk_delete', ['class' => 'Organization', 'ids' => [$iFirst], 'simulate' => true]);
	}

	public function testAttach(): void
	{
		$this->assertConforms('core_object_attach', [
			'class'          => 'Organization',
			'id'             => $this->iOrg,
			'filename'       => 'note.txt',
			'content_base64' => base64_encode('hello'),
			'simulate'       => true,
		]);
	}

	public function testApplyStimulus(): void
	{
		if (!MetaModel::IsValidClass('UserRequest') || !isset(MetaModel::EnumTransitions('UserRequest', 'new')['ev_timeout'])) {
			$this->markTestSkipped('Needs the ITIL request management module, whose UserRequest has ev_timeout from new.');
		}

		$oCaller = $this->CreatePerson(1, $this->iOrg);
		$oTicket = $this->CreateUserRequest(1, ['org_id' => $this->iOrg, 'caller_id' => $oCaller->GetKey()]);

		$this->assertConforms('core_object_apply_stimulus', ['class' => 'UserRequest', 'id' => (int)$oTicket->GetKey(), 'stimulus' => 'ev_timeout', 'simulate' => true]);
	}

	public function testSetObsoleteData(): void
	{
		$this->assertConforms('core_set_obsolete_data', ['visible' => true, 'simulate' => true]);
	}

	/**
	 * @param array<string, mixed> $aArguments
	 *
	 * @return \stdClass The structured half, as it was on the wire.
	 */
	private function assertConforms(string $sTool, array $aArguments): \stdClass
	{
		$oResult = $this->call($sTool, $aArguments);
		$sText = $oResult->content[0]->text ?? '';

		$this->assertFalse($oResult->isError ?? false, "{$sTool} answered an error: {$sText}");
		$this->assertInstanceOf(\stdClass::class, $oResult->structuredContent ?? null, "{$sTool} declares an output schema and sent no structuredContent");

		MCPExtensionCollector::CollectAll();
		$aErrors = (new SchemaValidator())->validateAgainstJsonSchema(
			$oResult->structuredContent,
			MCPRegistry::GetTools()[$sTool]->getOutputSchema()
		);

		$this->assertSame([], $aErrors, sprintf('%s answered outside its own output schema: %s', $sTool, json_encode($aErrors)));

		return $oResult->structuredContent;
	}

	/**
	 * @param array<string, mixed> $aArguments
	 */
	private function call(string $sTool, array $aArguments): \stdClass
	{
		$oResponse = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => '2025-06-18',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => ['name' => 'altioo-mcp-tests', 'version' => '1.0.0'],
			],
		]);

		$oAnswer = $this->ask([
			'jsonrpc' => '2.0',
			'id'      => ++$this->iRequestId,
			'method'  => 'tools/call',
			'params'  => ['name' => $sTool, 'arguments' => (object)$aArguments],
		], $oResponse['session']);

		$this->assertInstanceOf(\stdClass::class, $oAnswer['body']->result ?? null, "{$sTool} was answered with no result: ".json_encode($oAnswer['body']));

		return $oAnswer['body']->result;
	}

	/**
	 * @param array<string, mixed> $aPayload
	 *
	 * @return array{session: string, body: \stdClass}
	 */
	private function ask(array $aPayload, ?string $sSessionId = null): array
	{
		MCPExtensionCollector::CollectAll();
		$oServer = (new ReflectionMethod(MCPService::class, 'createServer'))->invoke(null, AccessPolicy::Unrestricted());

		$oFactory = new Psr17Factory();
		$oRequest = $oFactory
			->createServerRequest('POST', 'https://itop.example.com/extensions/altioo-mcp/index.php')
			->withHeader('Host', 'itop.example.com')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withBody($oFactory->createStream((string)json_encode($aPayload)));

		if ($sSessionId !== null && $sSessionId !== '') {
			$oRequest = $oRequest->withHeader('Mcp-Session-Id', $sSessionId);
		}

		$oResponse = $oServer->run(new StreamableHttpTransport($oRequest, $oFactory, middleware: []));

		$oBody = $oResponse->getBody();
		if ($oBody->isSeekable()) {
			$oBody->rewind();
		}

		// Not decoded to arrays: `{}` and `[]` are the difference under test.
		$oDecoded = json_decode((string)$oBody);
		$this->assertInstanceOf(\stdClass::class, $oDecoded, 'the server answered something that is not a JSON object');

		return ['session' => $oResponse->getHeaderLine('Mcp-Session-Id'), 'body' => $oDecoded];
	}
}
