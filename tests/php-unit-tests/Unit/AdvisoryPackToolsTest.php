<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectUpdate;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\FixtureTool;
use Altioo\iTop\Extension\MCP\Test\Support\ReadPackTool;
use Altioo\iTop\Extension\MCP\Test\Support\WritePackToolNoDryRun;
use Altioo\iTop\Extension\MCP\Test\Support\WritePackToolSimulating;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../bootstrap.php';

/**
 * ADR 0022 (Guy Couronne, 2026-11-19): an MCP-advisory credential is not served a pack's write-graded tool. The scope was enforced
 * only by DryRun::Simulated() inside each tool, which a pack's author had to know to call and nothing checked (review 2026-11-18,
 * PA-1). Decided where the served set is decided, so the tool is absent from tools/list and from the registry the SDK routes by:
 * called by name it is answered as a tool that does not exist.
 */
class AdvisoryPackToolsTest extends TestCase
{
	private function hidden(object $oElement, AccessPolicy $oPolicy): bool
	{
		return (new ReflectionMethod(ServedElements::class, 'isHidden'))->invoke(null, $oElement->getQualifiedName(), $oElement, [], $oPolicy);
	}

	public function testAnAdvisoryCredentialIsNotServedAPacksWriteTool(): void
	{
		$oAdvisory = AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']);

		$this->assertTrue($this->hidden(new WritePackToolNoDryRun(), $oAdvisory));
		$this->assertTrue($this->hidden(new WritePackToolSimulating(), $oAdvisory), 'a pack that rehearses is withheld too: an opt-in is the path if a real pack needs one (ADR 0022)');
	}

	public function testAnAdvisoryCredentialIsNotServedAPackToolThatClaimsNothing(): void
	{
		$this->assertTrue($this->hidden(new FixtureTool(), AccessPolicy::FromScopes(['MCP', 'MCP-advisory'])), 'no annotations grades delete');
	}

	public function testAnAdvisoryCredentialIsStillServedAPacksReadTool(): void
	{
		$this->assertFalse($this->hidden(new ReadPackTool(), AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory'])));
	}

	public function testThisModulesOwnWriteToolsAreUnchanged(): void
	{
		$oAdvisory = AccessPolicy::FromScopes(['MCP-write', 'MCP-advisory']);

		$this->assertFalse($this->hidden(new ObjectUpdate(), $oAdvisory), 'a core write tool is served and rehearses through DryRun::Simulated()');
		$this->assertFalse($this->hidden(new ObjectGet(), $oAdvisory));
	}

	public function testACredentialThatIsNotAdvisoryIsServedThePackWriteToolAsBefore(): void
	{
		$this->assertFalse($this->hidden(new WritePackToolNoDryRun(), AccessPolicy::FromScopes(['MCP-write'])));
		$this->assertTrue($this->hidden(new WritePackToolNoDryRun(), AccessPolicy::FromScopes(['MCP-read'])), 'the grade filter, as before');
	}
}
