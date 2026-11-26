<?php
/**
 * iTop's ItopDataTestCase, less the objects iTop's own insert leaves behind.
 *
 * DBObject::DBInsertNoReload() starts MetaModel's reentrance protection while the object still has its temporary
 * key (a negative number), sets the key to null, and only then stops it: the stop looks under null, finds nothing,
 * and the object stays in MetaModel::$m_aReentranceProtection under its temporary key for the life of the process.
 * Seen on iTop 3.2.3 and 3.3.0. A web request ends and takes it with it; a test suite is one process, so every
 * object any test inserts (each CMDBChange and CMDBChangeOp included) stays reachable to the end. On iTop 3.3 that
 * took the integration suite past the 512 MB the CI allows (2026-11-26, PR #96).
 *
 * So after each test the entries under a temporary key are dropped. No write is in progress between two tests, and
 * an entry under a real key - which iTop does stop - is left alone, so this hides nothing a test should see.
 *
 * Loaded by tests/php-unit-tests/bootstrap.php only when iTop's harness is present.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use Combodo\iTop\Test\UnitTest\ItopDataTestCase;

/**
 * @psalm-suppress UndefinedClass Psalm runs with no iTop checkout (psalm.xml).
 */
abstract class ReentranceSafeItopDataTestCase extends ItopDataTestCase
{
	protected function tearDown(): void
	{
		parent::tearDown();
		self::DropLeakedReentranceEntries();
		$this->ForgetCreatedObjects();
		// iTop's tearDown logs the user off, and iTop asks for this when the user changes. Its cache of preferences
		// otherwise holds an object whose insert the test's rollback undid; an update of it was silently skipped while
		// the entry above protected it, and is refused once that entry is gone.
		if (class_exists('appUserPreferences')) {
			\appUserPreferences::ResetPreferences();
		}
	}

	/**
	 * Empties ItopDataTestCase's list of the objects this test created, once its tearDown has deleted them (or rolled
	 * them back). iTop never empties it, and PHPUnit keeps every test instance until the run ends, so each object
	 * any test created stayed reachable through it.
	 */
	public function ForgetCreatedObjects(): void
	{
		if (!property_exists(ItopDataTestCase::class, 'aCreatedObjects')) {
			return;
		}
		$oProperty = new \ReflectionProperty(ItopDataTestCase::class, 'aCreatedObjects');
		$oProperty->setAccessible(true);
		$oProperty->setValue($this, []);
	}

	/**
	 * Drops what an insert left under a temporary key, and answers how many were dropped.
	 */
	public static function DropLeakedReentranceEntries(): int
	{
		if (!property_exists('MetaModel', 'm_aReentranceProtection')) {
			return 0;
		}
		$oProperty = new \ReflectionProperty('MetaModel', 'm_aReentranceProtection');
		$oProperty->setAccessible(true);
		$aProtected = (array)$oProperty->getValue();

		$iDropped = 0;
		foreach ($aProtected as $sClass => $aObjects) {
			foreach (array_keys((array)$aObjects) as $mKey) {
				if (is_int($mKey) && $mKey < 0) {
					unset($aProtected[$sClass][$mKey]);
					$iDropped++;
				}
			}
			if ($aProtected[$sClass] === []) {
				unset($aProtected[$sClass]);
			}
		}
		$oProperty->setValue(null, $aProtected);

		return $iDropped;
	}
}
