<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use PHPUnit\Framework\TestCase;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * How much of the surface one caller is served.
 *
 * This is a permission boundary, so the cases that matter are the ones where
 * two sources disagree, and the empty ones - where "nothing named" has to keep
 * meaning "all of them" on both axes, since the other reading is a server that
 * serves nothing at all and looks broken rather than restricted.
 *
 * The policy is a value object on purpose: every rule below is decided without
 * iTop, a request, or a configuration file.
 */
class AccessPolicyTest extends TestCase
{
	public function testAnUnrestrictedPolicyServesEverything(): void
	{
		$oPolicy = AccessPolicy::Unrestricted();

		$this->assertTrue($oPolicy->allowsToolset('anything-at-all'));
		$this->assertTrue($oPolicy->allowsCapability(AccessPolicy::CAPABILITY_DELETE));
		$this->assertTrue($oPolicy->allowsTool(null, null));
	}

	// --- grading a tool by what it declares -------------------------------

	/**
	 * @dataProvider annotationProvider
	 */
	public function testAToolIsGradedByItsAnnotations(?bool $bReadOnly, ?bool $bDestructive, string $sExpected): void
	{
		$this->assertSame($sExpected, AccessPolicy::CapabilityOf($bReadOnly, $bDestructive));
	}

	/** @return array<string, array{0: bool|null, 1: bool|null, 2: string}> */
	public static function annotationProvider(): array
	{
		return [
			'read-only'                => [true, false, AccessPolicy::CAPABILITY_READ],
			'read-only and destructive'=> [true, true, AccessPolicy::CAPABILITY_READ],
			'writes'                   => [false, false, AccessPolicy::CAPABILITY_WRITE],
			'destroys'                 => [false, true, AccessPolicy::CAPABILITY_DELETE],
			// The tool whose author never considered the question.
			'claims nothing'           => [null, null, AccessPolicy::CAPABILITY_DELETE],
		];
	}

	/**
	 * The case a binary read-only switch cannot express, and the reason the
	 * grades exist: an assistant that may open a ticket and add a work note,
	 * but must never delete anything.
	 */
	public function testWriteWithoutDeleteIsExpressible(): void
	{
		$oPolicy = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ, AccessPolicy::CAPABILITY_WRITE], []);

		$this->assertTrue($oPolicy->allowsTool(true, false), 'a search was withheld');
		$this->assertTrue($oPolicy->allowsTool(false, false), 'a create was withheld');
		$this->assertFalse($oPolicy->allowsTool(false, true), 'a delete was served');
	}

	/**
	 * Grading an unannotated tool as harmless is how a pack update quietly
	 * hands a narrowed credential something that writes. The cost of the other
	 * choice is a tool that does not show up until it is annotated - a
	 * complaint someone makes, rather than a breach nobody notices.
	 */
	public function testAToolThatClaimsNothingIsWithheldFromAnyNarrowedPolicy(): void
	{
		$oReadWrite = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ, AccessPolicy::CAPABILITY_WRITE], []);

		$this->assertFalse($oReadWrite->allowsTool(null, null));
	}

	// --- toolsets ---------------------------------------------------------

	public function testAnEmptyToolsetListMeansEveryToolset(): void
	{
		$this->assertTrue(AccessPolicy::Of([], [])->allowsToolset('objects'));
	}

	public function testANamedToolsetListExcludesTheRest(): void
	{
		$oPolicy = AccessPolicy::Of([], ['datamodel']);

		$this->assertTrue($oPolicy->allowsToolset('datamodel'));
		$this->assertFalse($oPolicy->allowsToolset('objects'));
	}

	// --- reading the scopes off a token -----------------------------------

	public function testTheFullScopeGrantsEverything(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP']);

		$this->assertTrue($oPolicy->allowsCapability(AccessPolicy::CAPABILITY_DELETE));
		$this->assertTrue($oPolicy->allowsToolset('objects'));
	}

	public function testScopesOfOtherEndpointsSayNothingHere(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['REST/JSON', 'Export']);

		$this->assertNull($oPolicy->capabilities(), 'a scope of another endpoint was read as a grade');
		$this->assertNull($oPolicy->toolsets(), 'a scope of another endpoint was read as a toolset');
	}

	public function testTheReadScopeWithholdsWritesAndDeletes(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP-read']);

		$this->assertTrue($oPolicy->allowsTool(true, false));
		$this->assertFalse($oPolicy->allowsTool(false, false));
		$this->assertFalse($oPolicy->allowsTool(false, true));
		// Read says nothing about which toolsets: all of them, read.
		$this->assertTrue($oPolicy->allowsToolset('objects'));
	}

	/**
	 * Granting write without read would describe nothing an operator means by
	 * it, and would produce a credential that can modify a ticket it cannot
	 * fetch.
	 */
	public function testTheWriteScopeImpliesRead(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP-write']);

		$this->assertTrue($oPolicy->allowsTool(true, false));
		$this->assertTrue($oPolicy->allowsTool(false, false));
		$this->assertFalse($oPolicy->allowsTool(false, true));
	}

	public function testAToolsetScopeRestrictsToThatToolset(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP-toolset-objects']);

		$this->assertTrue($oPolicy->allowsToolset('objects'));
		$this->assertFalse($oPolicy->allowsToolset('datamodel'));
	}

	/**
	 * A toolset named "write" must not read as the grade of the same name,
	 * which is the whole reason the toolset scopes carry a longer prefix.
	 */
	public function testAToolsetCalledWriteIsNotAGrade(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP-toolset-write']);

		$this->assertTrue($oPolicy->allowsToolset('write'));
		$this->assertNull($oPolicy->capabilities(), 'a toolset name was read as a capability');
	}

	public function testAGradeAndAToolsetCompose(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP-read', 'MCP-toolset-objects']);

		$this->assertTrue($oPolicy->allowsTool(true, false));
		$this->assertFalse($oPolicy->allowsTool(false, false));
		$this->assertTrue($oPolicy->allowsToolset('objects'));
		$this->assertFalse($oPolicy->allowsToolset('datamodel'));
	}

	public function testTheFullScopeBesideANarrowerOneStillMeansEverything(): void
	{
		$oPolicy = AccessPolicy::FromScopes(['MCP', 'MCP-toolset-objects', 'MCP-read']);

		$this->assertTrue($oPolicy->allowsToolset('datamodel'));
		$this->assertTrue($oPolicy->allowsCapability(AccessPolicy::CAPABILITY_DELETE));
	}

	// --- combining the instance and the token -----------------------------

	/**
	 * A token cannot widen what the instance serves. This is the property the
	 * whole arrangement rests on.
	 */
	public function testATokenCannotReachPastTheInstanceConfiguration(): void
	{
		$oInstance = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], ['datamodel']);
		$oToken = AccessPolicy::FromScopes(['MCP']);

		$oEffective = $oInstance->narrowedBy($oToken);

		$this->assertFalse($oEffective->allowsTool(false, false), 'a full-access token re-enabled writes');
		$this->assertFalse($oEffective->allowsToolset('objects'), 'a full-access token reached a withheld toolset');
	}

	public function testTheNarrowerGradeWins(): void
	{
		$oEffective = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ, AccessPolicy::CAPABILITY_WRITE], [])
			->narrowedBy(AccessPolicy::FromScopes(['MCP-read']));

		$this->assertTrue($oEffective->allowsTool(true, false));
		$this->assertFalse($oEffective->allowsTool(false, false));
	}

	/**
	 * Empty means "all of them", so an empty side must contribute nothing to
	 * the intersection rather than emptying it - otherwise an instance that
	 * configures nothing, plus a token scoped to one toolset, would serve
	 * nothing at all.
	 */
	public function testAnEmptySideDoesNotEmptyTheResult(): void
	{
		$oEffective = AccessPolicy::Unrestricted()->narrowedBy(AccessPolicy::Of([], ['objects']));

		$this->assertTrue($oEffective->allowsToolset('objects'));
		$this->assertFalse($oEffective->allowsToolset('datamodel'));
		$this->assertTrue($oEffective->allowsCapability(AccessPolicy::CAPABILITY_DELETE));
	}

	/**
	 * The other half of the rule above, and the one that is easy to get
	 * backwards: two sides that both name something and agree on nothing have
	 * granted nothing. Reading that empty intersection as "nothing named"
	 * would serve everything, so a read-only instance handed a delete-scoped
	 * token would come out wider than either side alone.
	 */
	public function testTwoNamedListsThatAgreeOnNothingGrantNothing(): void
	{
		$oEffective = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ], [])
			->narrowedBy(AccessPolicy::FromScopes(['MCP-delete']));

		$this->assertFalse($oEffective->allowsCapability(AccessPolicy::CAPABILITY_DELETE), 'a delete-scoped token reached past a read-only instance');
		$this->assertFalse($oEffective->allowsCapability(AccessPolicy::CAPABILITY_READ), 'a grade neither side named was served');
		$this->assertFalse($oEffective->allowsCapability(AccessPolicy::CAPABILITY_WRITE), 'a grade neither side named was served');
		$this->assertFalse($oEffective->allowsTool(true, false), 'a search survived a policy that grants nothing');
	}

	/** The same defect from the toolset side. */
	public function testTwoNamedToolsetListsThatAgreeOnNothingServeNothing(): void
	{
		$oEffective = AccessPolicy::Of([], ['objects'])
			->narrowedBy(AccessPolicy::FromScopes(['MCP-toolset-datamodel']));

		$this->assertFalse($oEffective->allowsToolset('objects'));
		$this->assertFalse($oEffective->allowsToolset('datamodel'));
		$this->assertFalse($oEffective->allowsToolset('relations'), 'a toolset neither side named was served');
	}

	/**
	 * A grade nobody here has heard of cannot be honoured, and an endpoint
	 * that serves everything because a line was misspelt is the wrong way to
	 * fail.
	 */
	public function testACapabilityListThatNamesNothingKnownGrantsNothing(): void
	{
		$oPolicy = AccessPolicy::Of(['sudo'], []);

		$this->assertSame([], $oPolicy->capabilities());
		$this->assertFalse($oPolicy->allowsCapability(AccessPolicy::CAPABILITY_READ));
	}

	public function testTwoNamedListsIntersect(): void
	{
		$oEffective = AccessPolicy::Of([], ['datamodel', 'objects'])
			->narrowedBy(AccessPolicy::Of([], ['objects', 'relations']));

		$this->assertTrue($oEffective->allowsToolset('objects'));
		$this->assertFalse($oEffective->allowsToolset('datamodel'));
		$this->assertFalse($oEffective->allowsToolset('relations'));
	}

	/** An unknown grade in the configuration is dropped, not honoured. */
	public function testAnUnknownCapabilityIsIgnored(): void
	{
		$oPolicy = AccessPolicy::Of([AccessPolicy::CAPABILITY_READ, 'sudo'], []);

		$this->assertSame([AccessPolicy::CAPABILITY_READ], $oPolicy->capabilities());
	}
}
