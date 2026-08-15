<?php
/**
 * Stand-in parent for the integration suite when iTop's own test harness is
 * unavailable.
 *
 * iTop ships ItopDataTestCase in tests/php-unit-tests/ of its *source* tree
 * only; the packaged release archives (the ones an extension is developed
 * against) do not contain it. Rather than let the integration suite fatal on a
 * missing parent class, tests/bootstrap.php aliases it to this, which skips
 * every test with an explanation.
 *
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Support;

use PHPUnit\Framework\TestCase;

abstract class SkippedItopDataTestCase extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->markTestSkipped(
			'iTop test harness not available. Integration tests need Combodo\iTop\Test\UnitTest\ItopDataTestCase, '
			.'which lives in the iTop *source* tree at tests/php-unit-tests/src/BaseTestCase/ and is absent from '
			.'packaged releases. Run them from an iTop checkout with the altioo-mcp module installed, either via '
			.'iTop\'s own runner (its "Extensions" testsuite scans env-production/*/tests/php-unit-tests) or by '
			.'pointing ITOP_ROOT at that checkout.'
		);
	}
}
