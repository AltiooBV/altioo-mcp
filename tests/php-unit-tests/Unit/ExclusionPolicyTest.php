<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Test\Support\SourceCalls;
use PHPUnit\Framework\TestCase;

/**
 * The settings of doc/adr/0012, read without a datamodel: a value that cannot
 * be honoured is a problem, never an empty list.
 */
class ExclusionPolicyTest extends TestCase
{
	public function testNothingIsExcludedByDefault(): void
	{
		$aParsed = ExclusionSettings::Parse([], []);

		$this->assertNull($aParsed['problem']);
		$this->assertSame([], $aParsed['classes']);
		$this->assertSame([], $aParsed['attributes']);
	}

	public function testAListIsReadByName(): void
	{
		$aParsed = ExclusionSettings::Parse(['Person', ' Team '], ['Person' => ['phone', 'mobile_phone']]);

		$this->assertNull($aParsed['problem']);
		$this->assertSame(['person' => 'Person', 'team' => 'Team'], $aParsed['classes']);
		$this->assertSame(['person' => ['phone' => 'phone', 'mobile_phone' => 'mobile_phone']], $aParsed['attributes']);
	}

	/**
	 * @dataProvider malformedProvider
	 */
	public function testAValueThatCannotBeHonouredIsAProblemAndNeverEmpty(mixed $mClasses, mixed $mAttributes): void
	{
		$aParsed = ExclusionSettings::Parse($mClasses, $mAttributes);

		$this->assertNotNull($aParsed['problem'], 'a setting that cannot be honoured read as empty, which is the spelling of "nothing is excluded"');
		$this->assertSame([], $aParsed['classes']);
		$this->assertSame([], $aParsed['attributes']);
	}

	/**
	 * The dashboard groups the module's own audit rows and nothing else: a dashlet that grouped object data
	 * would be a route round the exclusions (doc/adr/0012), and is the one this keeps from being added unseen.
	 */
	public function testNoDashletGroupsOnObjectData(): void
	{
		$oXml = simplexml_load_file(dirname(__DIR__, 3).'/datamodel.altioo-mcp.xml');
		$this->assertNotFalse($oXml);

		$aQueries = $oXml->xpath('//dashlet/query') ?: [];
		$this->assertNotSame([], $aQueries, 'the scan found no dashlet, so it is not working');
		foreach ($aQueries as $oQuery) {
			$this->assertMatchesRegularExpression('/^\s*SELECT\s+AltiooEventMCPService\b/', (string)$oQuery, 'a dashlet reads something other than the audit trail');
		}
	}

	/**
	 * doc/adr/0012, Amendment 3: a key or a link set typed on the parent of an excluded class holds rows of it, so the
	 * question asked about the target is "does a query on it reach an excluded class", never "is it excluded".
	 * Asked of the target alone, `lnkContactToTicket.contact_id` (to Contact) served an excluded Person. The behaviour is
	 * in ExclusionParentTypedTest; this keeps the one call that decides it from being put back.
	 */
	public function testAKeyAndALinkSetAskWhetherTheirTargetHoldsAnExcludedClass(): void
	{
		$oSource = SourceCalls::OfClass(ExclusionPolicy::class);
		$sOwn = ExclusionPolicy::class;

		$this->assertTrue($oSource->HasCall($sOwn.'::holdsExcluded', 'excludes'), 'excludes() no longer asks what a target holds');
		$this->assertFalse($oSource->HasCall($sOwn.'::ExcludesClass', 'excludes'), 'excludes() asks about the declared class alone');
		$this->assertTrue($oSource->HasCall($sOwn.'::ExcludesClass', 'holdsExcluded'), 'a class that is itself excluded holds one');
		$this->assertTrue($oSource->HasCall($sOwn.'::lineage', 'holdsExcluded'), 'the classes above an excluded one are not walked');
	}

	/**
	 * The friendly name is derived, never listed: the settings keep refusing an attribute the operator names that is
	 * part of it, and a name that reaches an excluded attribute by derivation is absent without refusing anything.
	 */
	public function testTheFriendlyNameIsDerivedFromItsPartsAndItsPartsAreReadOnce(): void
	{
		$oPolicy = SourceCalls::OfClass(ExclusionPolicy::class);
		$sOwn = ExclusionPolicy::class;
		$this->assertTrue($oPolicy->HasCall($sOwn.'::nameReachesExcluded', 'excludes'));
		$this->assertTrue($oPolicy->HasCall(ExclusionSettings::class.'::NameAttributes', 'nameReachesExcluded'));
		$this->assertTrue($oPolicy->HasCall($sOwn.'::excludes', 'nameReachesExcluded'), 'a part of the name is asked about as any attribute is');

		$this->assertTrue(SourceCalls::OfClass(ExclusionSettings::class)->HasCall(ExclusionSettings::class.'::NameAttributes', 'datamodelProblem'), 'the settings check reads the name another way than the policy does');
	}

	public function testTheExemptProfilesAreReadAsNames(): void
	{
		$this->assertSame(['profiles' => [], 'problem' => null], ExclusionSettings::ParseExempt([]));
		$this->assertSame(['profiles' => ['MCP Exempt'], 'problem' => null], ExclusionSettings::ParseExempt([' MCP Exempt ', 'MCP Exempt']));
	}

	/**
	 * @dataProvider malformedExemptProvider
	 */
	public function testExemptProfilesThatCannotBeHonouredAreAProblemAndNeverAnExemption(mixed $mValue): void
	{
		$aParsed = ExclusionSettings::ParseExempt($mValue);

		$this->assertNotNull($aParsed['problem']);
		$this->assertSame([], $aParsed['profiles']);
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformedExemptProvider(): array
	{
		return ['a string' => ['MCP Exempt'], 'a number' => [[1]], 'an empty name' => [['']], 'a boolean' => [true], 'nested' => [[['x']]]];
	}

	/** @return array<string, array{0: mixed, 1: mixed}> */
	public static function malformedProvider(): array
	{
		return [
			'classes as a string'              => ['Person', []],
			'classes holding a number'         => [[3], []],
			'classes holding a bad name'       => [['Per son'], []],
			'attributes as a string'           => [[], 'Person'],
			'attributes of a class as a string' => [[], ['Person' => 'phone']],
			'attributes of a class, empty'     => [[], ['Person' => []]],
			'attributes keyed by a number'     => [[], [0 => ['phone']]],
			'an attribute that is no code'     => [[], ['Person' => ['pho ne']]],
			'the id'                           => [[], ['Person' => ['id']]],
			'the final class'                  => [[], ['Person' => ['finalclass']]],
			'the friendly name'                => [[], ['Person' => ['friendlyname']]],
		];
	}
}
