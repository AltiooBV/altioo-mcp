<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Api\ToolOutput;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use DBObjectSearch;
use DBObjectSet;
use Mcp\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-18, HA-2: a served class's own friendly name quotes the excluded object it points at - with `Contract`
 * excluded, `lnkCustomerContractToService` read "Customer contract Demo / Computers and peripherals" and could be
 * filtered on it; with `Person` excluded, `lnkPersonToTeam` served "Anna Gavalda / Hardware support"; with `Organization`
 * excluded, dozens of CI classes quote `organization_name`. ADR 0012: "the labels derived from them ... are not served".
 *
 * The HA-1 fix (ADR 0012 Amendment 3) closed it: a class whose friendly name is made of an excluded part serves no
 * friendly name. This test is the proof, by value rather than by the rule: with one class excluded, no class this
 * channel serves puts the name of an object of the excluded class into anything it answers with its friendly name.
 */
class ExclusionNameLeakTest extends ItopDataTestCaseAlias
{
	/** @var array<int, string> */
	private const SCENARIOS = ['Organization', 'Contract', 'Person', 'Location'];
	private const OBJECTS_PER_CLASS = 3;

	protected function tearDown(): void
	{
		$this->exclude([]);
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

	/**
	 * What the excluded class's objects are called, read straight from the ORM (which this module's exclusions do not touch).
	 *
	 * @return array<int, string>
	 */
	private function namesOf(string $sExcluded): array
	{
		$aNames = [];
		$oSet = new DBObjectSet(DBObjectSearch::FromOQL("SELECT {$sExcluded}"));
		$oSet->SetLimit(200);
		while ($oObject = $oSet->Fetch()) {
			$sName = trim((string)$oObject->GetName());
			// Short names collide with ordinary words and numbers, and a leak of one would not be recognised.
			if (mb_strlen($sName) >= 5) {
				$aNames[$sName] = true;
			}
		}

		return array_keys($aNames);
	}

	/** @return array<int, string> */
	private function servedConcreteClasses(string $sExcluded): array
	{
		$aClasses = [];
		foreach (MetaModel::GetClasses() as $sClass) {
			$sClass = (string)$sClass;
			if (MetaModel::IsAbstract($sClass) || ExclusionPolicy::ExcludesClass($sClass) || is_a($sClass, $sExcluded, true)) {
				continue;
			}
			if (in_array($sClass, ['AltiooEventMCPService'], true) || str_starts_with($sClass, 'Event') || str_starts_with($sClass, 'CMDBChange')) {
				continue;
			}
			$aClasses[] = $sClass;
		}

		return $aClasses;
	}

	/** @return array<string, array{0: string}> */
	public static function scenarioProvider(): array
	{
		return array_combine(self::SCENARIOS, array_map(static fn (string $s): array => [$s], self::SCENARIOS));
	}

	/**
	 * @dataProvider scenarioProvider
	 */
	public function testNoServedClassQuotesAnExcludedObjectInItsFriendlyName(string $sExcluded): void
	{
		if (!MetaModel::IsValidClass($sExcluded)) {
			$this->markTestSkipped("Needs {$sExcluded}.");
		}
		$aNames = $this->namesOf($sExcluded);
		if ($aNames === []) {
			$this->markTestSkipped("No {$sExcluded} with a name long enough to recognise in this instance's data.");
		}

		$this->exclude([$sExcluded]);

		$aLeaks = [];
		$iChecked = 0;
		foreach ($this->servedConcreteClasses($sExcluded) as $sClass) {
			if (ExclusionPolicy::ExcludesAttribute($sClass, 'friendlyname')) {
				continue; // not served: the rule did its work, and the next two routes would only say so
			}
			$oSet = new DBObjectSet(DBObjectSearch::FromOQL("SELECT {$sClass}"));
			$oSet->SetLimit(self::OBJECTS_PER_CLASS);
			while ($oObject = $oSet->Fetch()) {
				try {
					// The friendly name itself: the audit block names the user who made a change, which is another thing.
					$sGet = (string)(ToolOutput::Decode(ObjectGet::execute($sClass, (int)$oObject->GetKey(), 'id,friendlyname'))['object']['friendlyname'] ?? '');
				} catch (\Throwable $e) {
					// A class this endpoint cannot read at all (iTop's own lnkTriggerAction, whose key is not `id`) leaks nothing.
					continue;
				}
				$iChecked++;
				foreach ($aNames as $sName) {
					if (str_contains($sGet, $sName)) {
						$aLeaks[] = "{$sClass}#{$oObject->GetKey()} names '{$sName}'";
					}
				}
			}
		}

		$this->assertSame([], array_slice($aLeaks, 0, 10), "A served friendly name quotes an excluded {$sExcluded}");
		$this->addToAssertionCount(1);
		$this->assertGreaterThan(0, $iChecked, 'nothing was read: the test proved nothing');
	}

	public function testALinkClassWhoseNameIsMadeOfAnExcludedObjectCannotBeFilteredByIt(): void
	{
		foreach ([['Contract', 'lnkCustomerContractToService'], ['Person', 'lnkPersonToTeam']] as [$sExcluded, $sLink]) {
			if (!MetaModel::IsValidClass($sExcluded) || !MetaModel::IsValidClass($sLink)) {
				continue;
			}
			$this->exclude([$sExcluded]);

			$this->assertTrue(ExclusionPolicy::ExcludesAttribute($sLink, 'friendlyname'), "{$sLink} serves a name made of an excluded {$sExcluded}");
			try {
				ObjectSearchByClass::execute($sLink, ['friendlyname' => 'x']);
				$this->fail("{$sLink} was filtered on its friendly name");
			} catch (ToolCallException $e) {
				$this->assertSame("Unknown attribute 'friendlyname' on class '{$sLink}'.", $e->getMessage());
			}
		}
	}
}
