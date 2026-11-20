<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectCount;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByOQL;
use Altioo\iTop\Extension\MCP\Service\Read\ObjectSerializer;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, RT-3: an attribute the endpoint masks on output (a password, a token) could still be
 * filtered, sorted and LIKE-matched, so a steered agent read a secret back one character at a time:
 * `token LIKE "SECRET-A%"` answered yes or no while `core_object_get` showed asterisks. core_object_count
 * already refused such an attribute; the two search tools did not.
 *
 * Every route is asked once about a masked attribute and once about a name the datamodel does not have, and
 * must answer both in the same words.
 */
class MaskedAttributeSearchTest extends ItopDataTestCaseAlias
{
	private string $sClass = '';
	private string $sAtt = '';

	protected function setUp(): void
	{
		parent::setUp();
		// A class and a masked attribute that exist on this iTop, whichever it is.
		foreach (['OAuthClient', 'Oauth2Client', 'UserLocal', 'RemoteiTopConnectionToken', 'PersonalToken'] as $sClass) {
			if (!MetaModel::IsValidClass($sClass) || MetaModel::IsAbstract($sClass)) {
				continue;
			}
			foreach (MetaModel::ListAttributeDefs($sClass) as $sAtt => $oDef) {
				if (ObjectSerializer::IsSensitive($oDef, $sClass) && MetaModel::IsValidFilterCode($sClass, $sAtt)) {
					$this->sClass = $sClass;
					$this->sAtt = $sAtt;
					break 2;
				}
			}
		}
		if ($this->sClass === '') {
			$this->markTestSkipped('This iTop has no class with a masked, searchable attribute.');
		}
	}

	private function outcome(callable $fCall, string $sName): string
	{
		try {
			$fCall($sName);
			$sOut = 'ok';
		} catch (ToolCallException $e) {
			$sOut = 'refused '.$e->getMessage();
		}

		return str_replace($sName, '#', $sOut);
	}

	/** @param callable(string): mixed $fRoute */
	private function assertAbsent(callable $fRoute, string $sRoute): void
	{
		$sUnknown = $this->sAtt.'zz';
		$sAbsent = $this->outcome($fRoute, $sUnknown);
		$sMasked = $this->outcome($fRoute, $this->sAtt);

		$this->assertStringStartsWith('refused', $sMasked, "{$sRoute}: the masked attribute '{$this->sClass}.{$this->sAtt}' was searchable");
		$this->assertSame(str_replace($sUnknown, '#', $sAbsent), $sMasked, "{$sRoute}: the masked attribute was told apart from one that does not exist");
	}

	public function testAMaskedAttributeIsUnknownToEveryWayOfSearching(): void
	{
		$sClass = $this->sClass;
		$aRoutes = [
			'search, filter' => fn (string $a) => ObjectSearchByClass::execute($sClass, [$a => 'x']),
			'search, order' => fn (string $a) => ObjectSearchByClass::execute($sClass, [], 50, 0, $a),
			'oql, where' => fn (string $a) => ObjectSearchByOQL::execute("SELECT {$sClass} WHERE {$a} = 'x'"),
			'oql, like' => fn (string $a) => ObjectSearchByOQL::execute("SELECT {$sClass} WHERE {$a} LIKE 'x%'"),
			'oql, order' => fn (string $a) => ObjectSearchByOQL::execute("SELECT {$sClass}", 50, 0, $a),
			'count, filter' => fn (string $a) => ObjectCount::execute($sClass, [$a => 'x']),
			'count, oql' => fn (string $a) => ObjectCount::execute('', [], "SELECT {$sClass} WHERE {$a} LIKE 'x%'"),
		];
		foreach ($aRoutes as $sRoute => $fRoute) {
			$this->assertAbsent($fRoute, $sRoute);
		}
	}

	public function testAMaskedAttributeInASubQueryIsRefusedToo(): void
	{
		$sClass = $this->sClass;
		try {
			ObjectSearchByOQL::execute("SELECT {$sClass} WHERE id IN (SELECT {$sClass} WHERE {$this->sAtt} LIKE 'x%')");
			$this->fail('a sub-query read the masked attribute');
		} catch (ToolCallException $e) {
			$this->assertStringStartsWith('Invalid OQL query.', $e->getMessage());
		}
	}

	public function testTheClassStaysSearchableOnItsOtherAttributes(): void
	{
		// The control: the same routes answer for what is not masked, and a default order that names a masked attribute is dropped.
		$aTotal = ToolOutput::Decode(ObjectSearchByClass::execute($this->sClass));
		$this->assertIsArray($aTotal);
		$this->assertSame($this->sClass, $aTotal['class']);
		$aOql = ToolOutput::Decode(ObjectSearchByOQL::execute("SELECT {$this->sClass} WHERE id = 99999999"));
		$this->assertSame(0, $aOql['total']);
	}
}
