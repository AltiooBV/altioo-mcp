<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\ServerInstructions;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassList;
use Altioo\iTop\Extension\MCP\Core\Tools\ClassSchema;
use Altioo\iTop\Extension\MCP\Core\Tools\ObjectGet;
use Altioo\iTop\Extension\MCP\Helper\PortalMode;
use Altioo\iTop\Extension\MCP\Service\Portal\PortalDescription;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\Rights\ServedAccess;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../bootstrap.php';

/**
 * A connection served no tool that changes an object says so in its first lines, says why, and talks no more of
 * writing (review 2026-11-23, C5; decided 2026-11-24: the reviewer's portal session was read-only because of the
 * OAuth authorization scope).
 */
class NoWriteToolServedTest extends TestCase
{
	/** What a write-only vocabulary looks like in the text of a connection that cannot write. */
	private const WRITE_TALK = ['core_object_create', 'core_object_update', 'core_object_apply_stimulus', 'core_object_attach', 'settable_fields', 'may_attach', 'create action'];

	protected function tearDown(): void
	{
		ServedAccess::Forget();
		PortalMode::Forget();
		parent::tearDown();
	}

	private function withheld(?string $sWhy): void
	{
		ServedAccess::Remember(
			static fn (): array => ['toolsets' => [], 'tools' => [], 'capabilities' => [], 'prompts' => []],
			static fn (): array => [],
			static fn (): ?string => $sWhy
		);
	}

	public function testTheScopeIsNamedAsTheReasonAndWhatWouldChangeIt(): void
	{
		$this->withheld(ServedAccess::WRITES_BY_SCOPE);

		foreach (['console' => ServerInstructions::Text(AccessPolicy::Unrestricted(), 'Y-m-d H:i:s', 'Y-m-d'), 'portal' => ServerInstructions::PortalText('itop-portal')] as $sMode => $sText) {
			$iSecurity = strpos($sText, 'Security');
			$iAccess = strpos($sText, "Access\n- No tool that creates or changes an object");
			$this->assertSame(0, $iSecurity, $sMode);
			$this->assertNotFalse($iAccess, $sMode.': no Access block');
			$this->assertLessThan(strpos($sText, 'Session'), $iAccess, $sMode.': the Access block is not among the first lines');
			$this->assertStringContainsString('scope', $sText, $sMode);
			$this->assertStringContainsString('MCP-write', $sText, $sMode.': it does not say what would change it');
			$this->assertStringContainsString('OAuth', $sText, $sMode);
		}
	}

	public function testItPromisesNoRightThePortalDoesNotGive(): void
	{
		$this->withheld(ServedAccess::WRITES_BY_SCOPE);

		$this->assertStringContainsString('what the portal\'s own forms let them do', ServerInstructions::PortalText('itop-portal'));
		$this->assertStringContainsString('what their iTop rights allow', ServerInstructions::Text(AccessPolicy::Unrestricted()));
	}

	public function testTheInstanceSettingsAreNamedWhenTheyAreWhy(): void
	{
		$this->withheld(ServedAccess::WRITES_BY_SETTINGS);
		$sText = ServerInstructions::PortalText('itop-portal');

		$this->assertStringContainsString("server's settings", $sText);
		$this->assertStringNotContainsString('MCP-write', $sText, 'a token scope cannot change an instance setting');
		$this->assertStringNotContainsString('OAuth', $sText);
	}

	public function testAnUnexplainedAbsenceIsNotBlamedOnTheScope(): void
	{
		$this->withheld(ServedAccess::WRITES_BY_OTHER);
		$sText = ServerInstructions::PortalText('itop-portal');

		$this->assertStringContainsString('No tool that creates or changes an object', $sText);
		$this->assertStringNotContainsString('MCP-write', $sText);
		$this->assertStringNotContainsString('settings', $sText);
	}

	public function testThePortalInstructionsTalkOfNoWritingWhenNoneIsServed(): void
	{
		$this->withheld(ServedAccess::WRITES_BY_SCOPE);
		$sText = ServerInstructions::PortalText('itop-portal');

		foreach (self::WRITE_TALK as $sWord) {
			$this->assertStringNotContainsString($sWord, $sText, 'a connection that cannot write is told of: '.$sWord);
		}
		$this->assertStringNotContainsString("Writing\n", $sText);
		// What it can do is still said.
		$this->assertStringContainsString('core_portal_list', $sText);
		$this->assertStringContainsString('core_object_get', $sText);
	}

	public function testTheControlIsUnchangedWhenAWriteToolIsServed(): void
	{
		$this->withheld(null);
		$sText = ServerInstructions::PortalText('itop-portal');

		$this->assertStringNotContainsString("Access\n", $sText);
		foreach (['core_object_create', 'core_object_update', 'settable fields', 'create actions and state changes'] as $sWord) {
			$this->assertStringContainsString($sWord, $sText);
		}
	}

	public function testTheWarningStillSurvivesTheCutWithTheAccessBlock(): void
	{
		foreach ([ServedAccess::WRITES_BY_SCOPE, ServedAccess::WRITES_BY_SETTINGS, ServedAccess::WRITES_BY_OTHER] as $sWhy) {
			$this->withheld($sWhy);
			foreach (['console' => ServerInstructions::Text(AccessPolicy::Unrestricted(), 'Y-m-d H:i:s', 'Y-m-d', ['core_my_open_tickets'], 5, ['Person', 'Team'], true), 'portal' => ServerInstructions::PortalText('a-portal-with-a-longer-id')] as $sMode => $sText) {
				$this->assertSame(0, strpos($sText, ServerInstructions::SECURITY));
				$this->assertLessThan(4096, strlen($sText), $sMode.' '.$sWhy);
				$this->assertLessThan(2000, strpos($sText, 'Access') + 600, $sMode.' '.$sWhy.': the block is too far in to survive the 2,050 cut');
			}
		}
	}

	public function testThePortalDescriptionsNameNoWriteWhenNoneIsServed(): void
	{
		PortalMode::Remember('itop-portal');

		$this->withheld(null);
		$aWith = [(new ObjectGet())->getDescription(), (new ClassList())->getDescription(), (new ClassSchema())->getDescription(), PortalDescription::Reading()];
		$this->assertStringContainsString('settable_fields', $aWith[0]);
		$this->assertStringContainsString('create_chosen_by_portal', $aWith[3]);

		$this->withheld(ServedAccess::WRITES_BY_SCOPE);
		$aWithout = [(new ObjectGet())->getDescription(), (new ClassList())->getDescription(), (new ClassSchema())->getDescription(), PortalDescription::Reading()];
		foreach ($aWithout as $i => $sText) {
			foreach (['core_object_create', 'core_object_update', 'core_object_apply_stimulus', 'settable_fields', 'create action', 'stimul', 'edit'] as $sWord) {
				$this->assertStringNotContainsString($sWord, (string)$sText, 'description '.$i.' names '.$sWord);
			}
		}
	}

	public function testTheConsoleDescriptionsNameNoWriteWhenNoneIsServed(): void
	{
		$this->withheld(ServedAccess::WRITES_BY_SCOPE);

		$this->assertStringNotContainsString('core_object_apply_stimulus', (string)(new ObjectGet())->getDescription());
		$this->assertStringNotContainsString('creating or updating', (string)(new ClassSchema())->getDescription());

		$this->withheld(null);
		$this->assertStringContainsString('core_object_apply_stimulus', (string)(new ObjectGet())->getDescription());
	}

	public function testWithNoServerAskingWritesAreServedAsTheyAlwaysWere(): void
	{
		ServedAccess::Forget();

		$this->assertTrue(ServedAccess::ServesWrites());
		$this->assertNull(ServedAccess::WritesWithheldBy());
	}

	public function testTheAnswerIsAskedOncePerRequest(): void
	{
		$iAsked = 0;
		ServedAccess::Remember(
			static fn (): array => ['toolsets' => [], 'tools' => [], 'capabilities' => [], 'prompts' => []],
			static fn (): array => [],
			static function () use (&$iAsked): ?string {
				$iAsked++;

				return null;
			}
		);

		ServedAccess::ServesWrites();
		ServedAccess::ServesWrites();
		ServedAccess::WritesWithheldBy();

		$this->assertSame(1, $iAsked);
	}
}
