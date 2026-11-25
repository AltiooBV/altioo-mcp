<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Altioo\iTop\Extension\MCP\Test\Support\ReentranceSafeItopDataTestCase;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * The suite's base class drops what iTop's insert leaves in MetaModel's reentrance protection, which took the
 * integration suite past its memory limit on iTop 3.3 (2026-11-26). This holds both halves: iTop still leaves the
 * object behind (so the workaround is still needed, and can go once it does not), and the base class removes it.
 */
class ReentranceLeakTest extends ItopDataTestCaseAlias
{
	/** @return array<string, array<int|string, mixed>> */
	private static function protectedObjects(): array
	{
		$oProperty = new \ReflectionProperty('MetaModel', 'm_aReentranceProtection');
		$oProperty->setAccessible(true);

		return (array)$oProperty->getValue();
	}

	public function testTheSuiteRunsOnTheBaseClassThatDropsTheLeak(): void
	{
		$this->assertInstanceOf(ReentranceSafeItopDataTestCase::class, $this);
	}

	public function testAnInsertLeavesItsObjectUnderItsTemporaryKeyAndTheBaseClassDropsIt(): void
	{
		if (!property_exists('MetaModel', 'm_aReentranceProtection')) {
			$this->markTestSkipped('This iTop has no reentrance protection.');
		}
		ReentranceSafeItopDataTestCase::DropLeakedReentranceEntries();

		$oOrg = $this->CreateOrganization('Reentrance '.bin2hex(random_bytes(3)));

		$aLeft = self::protectedObjects()['Organization'] ?? [];
		$aTemporary = array_filter(array_keys($aLeft), static fn ($mKey): bool => is_int($mKey) && $mKey < 0);
		if ($aTemporary === []) {
			$this->markTestSkipped('iTop no longer leaves an inserted object behind: ReentranceSafeItopDataTestCase can go.');
		}
		$this->assertArrayNotHasKey((int)$oOrg->GetKey(), $aLeft, 'only the temporary key is left, never the real one');

		$this->assertGreaterThan(0, ReentranceSafeItopDataTestCase::DropLeakedReentranceEntries());
		$this->assertArrayNotHasKey('Organization', self::protectedObjects());
		$this->assertTrue(MetaModel::IsValidClass('Organization'));
	}
}
