<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Helper\MCPHelper;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * What the audit trail records when nobody has configured it.
 *
 * An empty list reads as "audit nothing", and a fresh install has
 * log_mcp_service defaulting to true and an AltiooEventMCPService class in the
 * console - so an empty default would leave that trail empty for ever without
 * anything failing. That silence is what makes it worth pinning here rather
 * than leaving to the first operator who goes looking for a call they
 * remember making.
 */
class AuditedMethodsTest extends TestCase
{
	public function testTheDefaultIsNotEmpty(): void
	{
		$this->assertNotEmpty(
			MCPHelper::DEFAULT_LOG_METHODS,
			'An empty default means an instance that was never configured audits nothing at all.'
		);
	}

	/**
	 * The three methods that carry an intent, plus the two that carry an event:
	 * a connection, and a request that died before its method could be read.
	 */
	public function testTheDefaultCoversEveryMethodWorthARow(): void
	{
		foreach (['initialize', 'tools/call', 'resources/read', 'prompts/get', 'exceptions'] as $sMethod) {
			$this->assertContains($sMethod, MCPHelper::DEFAULT_LOG_METHODS);
		}
	}

	/**
	 * MCPController stamps this pseudo-method on a request that failed before
	 * its JSON-RPC method could be read. It is dropped from the trail unless
	 * the list it is filtered against holds it, which is exactly the failure it
	 * exists to record.
	 */
	public function testTheExceptionPseudoMethodIsAudited(): void
	{
		$this->assertContains(MCPHelper::MCP_METHOD_EXCEPTION, MCPHelper::DEFAULT_LOG_METHODS);
	}

	/**
	 * The only record that a client connected at all. An operator asking "is
	 * this token still in use" has nothing else to read.
	 */
	public function testTheConnectionMethodIsAudited(): void
	{
		$this->assertSame('initialize', MCPHelper::MCP_METHOD_INITIALIZE);
		$this->assertContains(MCPHelper::MCP_METHOD_INITIALIZE, MCPHelper::DEFAULT_LOG_METHODS);
	}

	public function testEveryDefaultIsANonEmptyString(): void
	{
		foreach (MCPHelper::DEFAULT_LOG_METHODS as $mMethod) {
			$this->assertIsString($mMethod);
			$this->assertNotSame('', $mMethod);
		}
	}

	/**
	 * The list that decides what an installed instance audits.
	 *
	 * DEFAULT_LOG_METHODS is only the fallback for a configuration that does
	 * not declare log_mcp_method at all, and an installed iTop always declares
	 * it: the setup copies <module_parameters> into config-itop.php. So the
	 * datamodel is the value in force on every install, the constant is the
	 * value in force on none of them, and the two silently disagreeing is how
	 * "initialize" came to be argued for in code and audited nowhere.
	 */
	public function testTheDatamodelDeclaresTheSameListAsTheConstant(): void
	{
		$oXml = simplexml_load_file(__DIR__.'/../../../datamodel.altioo-mcp.xml');
		$this->assertNotFalse($oXml, 'the module datamodel could not be parsed');

		$aDeclared = $oXml->xpath('//module_parameters/parameters[@id="altioo-mcp"]/log_mcp_method/item');
		$this->assertNotEmpty($aDeclared, 'the datamodel declares no log_mcp_method, so an install audits whatever it likes');

		$aDeclared = array_map('strval', $aDeclared);
		sort($aDeclared);

		$aExpected = MCPHelper::DEFAULT_LOG_METHODS;
		sort($aExpected);

		$this->assertSame(
			$aExpected,
			$aDeclared,
			'the shipped log_mcp_method and DEFAULT_LOG_METHODS have drifted apart, and the shipped one is the one that runs'
		);
	}

	/**
	 * The README publishes this list as the value to copy into config-itop.php,
	 * so the two have to say the same thing.
	 */
	public function testTheReadmePublishesTheSameList(): void
	{
		$sReadme = file_get_contents(__DIR__.'/../../../README.md');
		$this->assertIsString($sReadme);

		if (preg_match("/'log_mcp_method'\s*=>\s*array\(([^)]*)\)/", $sReadme, $aMatches) !== 1) {
			$this->fail('The README no longer shows a log_mcp_method sample to compare against.');
		}

		preg_match_all("/'([^']+)'/", $aMatches[1], $aQuoted);
		sort($aQuoted[1]);

		$aExpected = MCPHelper::DEFAULT_LOG_METHODS;
		sort($aExpected);

		$this->assertSame($aExpected, $aQuoted[1], 'The README sample and DEFAULT_LOG_METHODS have drifted apart.');
	}
}
