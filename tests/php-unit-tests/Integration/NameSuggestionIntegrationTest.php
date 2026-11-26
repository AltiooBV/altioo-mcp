<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Integration;

use Altioo\iTop\Extension\MCP\Api\MCPModule;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectSearchByClass;
use Altioo\iTop\Extension\MCP\Helper\ClassRefusal;
use Altioo\iTop\Extension\MCP\Service\Read\DatamodelReader;
use Altioo\iTop\Extension\MCP\Service\Read\NameSuggestion;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionSettings;
use Altioo\iTop\Extension\MCP\Service\Server\RequestScope;
use Altioo\iTop\Extension\MCP\Service\Server\ServedElements;
use Altioo\iTop\Extension\MCP\Test\Support\ItopDataTestCaseAlias;
use Mcp\Server\Exception\ToolCallException;
use MetaModel;

require_once __DIR__.'/../bootstrap.php';

/**
 * Review 2026-11-23, C12 on an instance: a refusal of a class or an attribute that does not exist names the nearest served
 * ones, and what the operator excluded does not show in it, however near it is. And the obsolescence condition is OQL.
 */
class NameSuggestionIntegrationTest extends ItopDataTestCaseAlias
{
	protected function setUp(): void
	{
		parent::setUp();
		ServedElements::RememberServed();
		$this->exclude([], []);
	}

	protected function tearDown(): void
	{
		$this->exclude([], []);
		RequestScope::Close();
		parent::tearDown();
	}

	/**
	 * @param array<int, string>                $aClasses
	 * @param array<string, array<int, string>> $aAttributes
	 */
	private function exclude(array $aClasses, array $aAttributes): void
	{
		$oConfig = MetaModel::GetConfig();
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_CLASSES, $aClasses);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_ATTRIBUTES, $aAttributes);
		$oConfig->SetModuleSetting(MCPModule::MODULE_NAME, ExclusionSettings::SETTING_EXEMPT_PROFILES, []);
		ExclusionPolicy::Forget();
	}

	private function refusal(callable $f): string
	{
		try {
			$f();
		} catch (ToolCallException $e) {
			return $e->getMessage();
		}
		$this->fail('not refused');
	}

	public function testAClassTypedInTheWrongCaseOrWithATypoIsSuggested(): void
	{
		$this->assertStringContainsString("Did you mean 'Organization'? Names are case-sensitive.", $this->refusal(fn () => ObjectGet::execute('organization', 1)));
		$this->assertStringContainsString("Did you mean 'Organization'?", $this->refusal(fn () => ObjectGet::execute('Organizaton', 1)));
		$this->assertStringNotContainsString('Did you mean', $this->refusal(fn () => ObjectGet::execute('NothingLikeAClass', 1)));
	}

	public function testAClassIsStillRefusedInAnotherCase(): void
	{
		$this->assertStringContainsString('Unknown class', $this->refusal(fn () => ObjectGet::execute('organization', 1)), 'a suggestion is not an acceptance');
	}

	public function testAnAttributeIsSuggestedAmongTheOnesTheCallerIsServed(): void
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'status')) {
			$this->markTestSkipped('Needs UserRequest.status.');
		}

		$iOrg = (int)$this->CreateOrganization('Suggest '.bin2hex(random_bytes(3)))->GetKey();
		$iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()])->GetKey();

		$this->assertStringContainsString("Did you mean 'status'", $this->refusal(fn () => ObjectSearchByClass::execute('UserRequest', ['state' => 'new'])));
		$this->assertStringContainsString("Did you mean 'status'", $this->refusal(fn () => ObjectGet::execute('UserRequest', $iTicket, 'state')));
	}

	/** Red pass: an excluded name never shows, near or exact, and leaves what a name that never existed leaves. */
	public function testAnExcludedClassIsNeverSuggestedAndLeavesTheAnswerOfAClassThatDoesNotExist(): void
	{
		$this->exclude(['Organization'], []);

		foreach (['Organization', 'organization', 'Organizaton', 'Organisation'] as $sName) {
			$this->assertStringNotContainsString("'Organization'", NameSuggestion::ForClass($sName), $sName);
		}
		$this->assertStringNotContainsString("Did you mean 'Organization'", $this->refusal(fn () => ObjectGet::execute('Organizaton', 1)));

		// The nearest served names to the string, taken from the served names alone, and nothing else.
		$aServed = [];
		foreach (MetaModel::GetClasses() as $sClass) {
			if (!ExclusionPolicy::ExcludesClass($sClass) && \UserRights::IsActionAllowed($sClass, UR_ACTION_READ)) {
				$aServed[] = $sClass;
			}
		}
		foreach (['Organization', 'Organizaton', 'Orgnization'] as $sName) {
			$this->assertSame(array_slice(NameSuggestion::Closest($sName, $aServed, PHP_INT_MAX), 0, 3), NameSuggestion::NearClasses($sName), $sName);
		}
	}

	public function testAnExcludedAttributeIsNeverSuggested(): void
	{
		if (!MetaModel::IsValidAttCode('UserRequest', 'status') || !MetaModel::IsValidAttCode('UserRequest', 'title')) {
			$this->markTestSkipped('Needs UserRequest.');
		}
		$this->exclude([], ['UserRequest' => ['status']]);

		foreach (['status', 'Status', 'statu', 'state'] as $sCode) {
			$this->assertNotContains('status', NameSuggestion::NearAttributes('UserRequest', $sCode), $sCode);
		}
		$iOrg = (int)$this->CreateOrganization('Suggest '.bin2hex(random_bytes(3)))->GetKey();
		$iTicket = (int)$this->CreateUserRequest(1, ['org_id' => $iOrg, 'caller_id' => $this->CreatePerson(1, $iOrg)->GetKey()])->GetKey();
		$sRefusal = $this->refusal(fn () => ObjectGet::execute('UserRequest', $iTicket, 'state'));
		$this->assertStringNotContainsString("'status'", $sRefusal);
	}

	public function testAnAttributeOfAClassThatIsExcludedIsNotSuggested(): void
	{
		$this->exclude(['UserRequest'], []);

		$this->assertSame([], NameSuggestion::NearAttributes('UserRequest', 'stauts'));
	}

	public function testAServedAttributeIsNotGivenNeighboursAsIfItWereWrong(): void
	{
		$this->assertSame([], NameSuggestion::NearAttributes('Organization', 'name'));
	}

	public function testTheRefusalOfTheClassTouchedByNoRequestStaysAsItWas(): void
	{
		RequestScope::Close();

		$this->assertStringNotContainsString('Did you mean', ClassRefusal::Unreadable('organization'));
	}

	public function testTheObsolescenceConditionIsOqlOrNothingNeverSql(): void
	{
		$aSchema = DatamodelReader::ObsolescenceCondition('Organization');

		$this->assertNotNull($aSchema);
		$this->assertStringNotContainsString('COALESCE', $aSchema);
		$this->assertStringNotContainsString('`', $aSchema);
		$this->assertStringContainsString("status = 'inactive'", $aSchema);
		\Expression::FromOQL($aSchema);

		// Every obsoletable class answers OQL that iTop parses back, or nothing.
		foreach (MetaModel::GetClasses() as $sClass) {
			if (MetaModel::IsAbstract($sClass) || !MetaModel::IsObsoletable($sClass)) {
				continue;
			}
			$sCondition = DatamodelReader::ObsolescenceCondition($sClass);
			if ($sCondition !== null) {
				\Expression::FromOQL($sCondition);
				$this->assertStringNotContainsString('COALESCE(', $sCondition, $sClass);
			}
		}
	}

	public function testTheSchemaReportsItThatWay(): void
	{
		$a = json_decode(ClassSchema::execute('Organization')->text, true);

		$this->assertSame("(status = 'inactive')", $a['obsolescence']['condition'] ?? null);
	}
}
