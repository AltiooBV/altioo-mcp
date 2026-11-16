<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Server\ClassCompletion;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/** The class names a `class` variable completes to: a prefix, case-insensitive, sorted, capped. */
class ClassCompletionTest extends TestCase
{
	public function testAPrefixMatchIsCaseInsensitiveAndSorted(): void
	{
		$aNames = ['UserRequest', 'Person', 'Organization', 'Server', 'UserLocal'];

		$this->assertSame(['UserLocal', 'UserRequest'], ClassCompletion::matching($aNames, 'user'));
		$this->assertSame(['Person'], ClassCompletion::matching($aNames, 'Pers'));
		$this->assertSame([], ClassCompletion::matching($aNames, 'zz'));
	}

	public function testNothingTypedYetOffersTheListCapped(): void
	{
		$aNames = array_map(static fn (int $i): string => sprintf('Class%03d', $i), range(1, 250));

		$aMatching = ClassCompletion::matching($aNames, '');

		$this->assertCount(ClassCompletion::MAX, $aMatching);
		$this->assertSame('Class001', $aMatching[0]);
	}

	public function testAContainedValueIsNotAPrefixMatch(): void
	{
		$this->assertSame([], ClassCompletion::matching(['UserRequest'], 'Request'));
	}
}
