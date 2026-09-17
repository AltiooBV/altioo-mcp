<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Core\CoreExtensions;
use Altioo\iTop\Extension\MCP\Helper\AccessGrants;
use Altioo\iTop\Extension\MCP\Registry\MCPRegistry;
use Altioo\iTop\Extension\MCP\Service\AccessPolicy;
use Altioo\iTop\Extension\MCP\Service\TokenScopes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

/**
 * That the endpoint never writes the things that decide what the endpoint may
 * write.
 *
 * The escalation this stops is quiet. An operator issues an administrator an
 * MCP-write token rather than maintaining a second user account, on the
 * strength of AccessPolicy's promise that a scope only ever narrows. If a tool
 * can write PersonalToken, the assistant holding that token can mint itself
 * one scoped MCP and the promise is gone - with an audit row that says a token
 * was created, which is not a sentence anyone reads as an alarm.
 *
 * Nothing about it is visible at runtime: remove the gate and every other test
 * still passes, every tool still refuses everything iTop refuses, and the only
 * thing that changed is that a credential can now widen itself. So it is
 * pinned twice - the predicate, and the fact that each write path reaches it.
 */
class AccessGrantsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		MCPRegistry::Clear();
		CoreExtensions::RegisterServiceProvider();
	}

	protected function tearDown(): void
	{
		MCPRegistry::Clear();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function grantingClassProvider(): array
	{
		return [
			'the token carrying the scope'   => ['PersonalToken'],
			'the application token'          => ['UserToken'],
			'the account itself'             => ['User'],
			'what a profile grants'          => ['URP_Profiles'],
			'who holds a profile'            => ['URP_UserProfile'],
			'the silo a user sees'           => ['URP_UserOrg'],
			'an action grant'                => ['URP_ActionGrant'],
			'a stimulus grant'               => ['URP_StimulusGrant'],
			'an attribute grant'             => ['URP_AttributeGrant'],
			'a rights dimension'             => ['URP_Dimensions'],
			'the rights family parent'       => ['UserRightsBaseClass'],
			'its console-facing twin'        => ['UserRightsBaseClassGUI'],
		];
	}

	/**
	 * @dataProvider grantingClassProvider
	 */
	public function testAGrantingClassIsRefused(string $sClass): void
	{
		$this->assertTrue(AccessGrants::IsGranting($sClass), "{$sClass} can be written through the endpoint");
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function ordinaryClassProvider(): array
	{
		return [
			'a ticket'              => ['UserRequest'],
			'a person'              => ['Person'],
			'a contact'             => ['Contact'],
			'an organization'       => ['Organization'],
			'a server'              => ['Server'],
			'an attachment'         => ['Attachment'],
			'a team'                => ['Team'],
		];
	}

	/**
	 * The barrier is narrow on purpose. UserRequest starts with the same four
	 * letters as User and is the single most written class on the surface;
	 * a prefix match written one character looser would close the endpoint.
	 *
	 * @dataProvider ordinaryClassProvider
	 */
	public function testAnOrdinaryClassIsUntouched(string $sClass): void
	{
		$this->assertFalse(AccessGrants::IsGranting($sClass), "{$sClass} can no longer be written");
	}

	/**
	 * iTop resolves class names case-insensitively in OQL, so a barrier that
	 * did not would be one lowercase letter away from open.
	 */
	public function testTheMatchIgnoresCase(): void
	{
		$this->assertTrue(AccessGrants::IsGranting('personaltoken'));
		$this->assertTrue(AccessGrants::IsGranting('PERSONALTOKEN'));
		$this->assertTrue(AccessGrants::IsGranting('urp_profiles'));
		$this->assertTrue(AccessGrants::IsGranting('uSeR'));
	}

	/**
	 * A class this module has never heard of, named the way iTop names the
	 * rights family. The listed names cover subclasses; this covers the
	 * sibling a later version adds.
	 */
	public function testAnUnknownMemberOfTheRightsFamilyIsRefused(): void
	{
		$this->assertTrue(AccessGrants::IsGranting('URP_SomethingAddedLater'));
	}

	/**
	 * Descendants, which is the half that cannot be done by name.
	 *
	 * Readable only where a datamodel is loaded - UserLocal is iTop's, not
	 * this module's - so the shape test below carries it when it is not.
	 */
	public function testADescendantIsRefusedWhereTheDatamodelIsLoaded(): void
	{
		if (!class_exists('UserLocal')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so User has no subclasses here.');
		}

		$this->assertTrue(AccessGrants::IsGranting('UserLocal'), 'a subclass of User is writable');
	}

	/**
	 * Why the predicate cannot be a list of names.
	 *
	 * Every root is matched with its descendants, because a datamodel
	 * extension declaring <parent>User</parent> would otherwise walk round the
	 * barrier and nothing would report it. Checked as a shape because the
	 * behaviour needs a datamodel that the unit suite usually runs without.
	 */
	public function testTheBarrierMatchesDescendantsRatherThanNames(): void
	{
		$sBody = $this->body(AccessGrants::class, 'IsNamedGranting');

		$this->assertStringContainsString(
			'is_a(',
			$sBody,
			'AccessGrants::IsGranting() matches names only, so a subclass of PersonalToken is writable'
		);
		$this->assertStringContainsString(
			',true)',
			$sBody,
			'is_a() must be passed $allow_string, or a class name that was never instantiated matches nothing'
		);
	}

	/**
	 * The names are a floor the datamodel can only ever build on.
	 *
	 * Both halves are asked and either one refuses, so nothing iTop says - a
	 * renamed category, a MetaModel that is not there, an attribute definition
	 * that raises - can make a listed class writable. Written the other way
	 * round, as a datamodel answer with the names as a fallback, an instance
	 * whose datamodel could not be read would open every class on this list at
	 * once, which is the failure mode worth designing out rather than testing
	 * for.
	 */
	public function testTheNamesAreAFloorRatherThanADefault(): void
	{
		$sBody = $this->body(AccessGrants::class, 'IsGranting');

		$this->assertStringContainsString(
			'self::IsNamedGranting($sClass)||self::IsDeclaredGranting($sClass)',
			$sBody,
			'the two halves are no longer a union, so the datamodel can narrow what the names refuse'
		);
	}

	/**
	 * Why there is a dynamic half at all.
	 *
	 * This is a part of iTop that moves - personal tokens arrived in 3.1,
	 * application tokens after them - so a barrier made only of names is one
	 * that ages out of correctness silently. Both questions are asked of the
	 * class in front of it rather than of a list: does it declare scopes that
	 * grade this endpoint, and does iTop file it under the rights model.
	 */
	public function testTheDatamodelIsAskedAboutAClassTheNamesDoNotCover(): void
	{
		$sBody = $this->body(AccessGrants::class, 'IsDeclaredGranting');

		$this->assertStringContainsString(
			'TokenScopes::GradesThisEndpoint',
			$sBody,
			'a class that can carry an MCP scope is only refused if someone remembered to name it here'
		);
		$this->assertStringContainsString(
			'MetaModel::GetClasses',
			$sBody,
			'the rights model is matched by spelling alone'
		);
		$this->assertStringContainsString(
			'ListAttributeDefs',
			$sBody,
			'a class holding a credential is only refused if someone remembered to name it here'
		);
	}

	/**
	 * The credential signal, against the datamodel that defines it.
	 *
	 * The two checked here are the ones a caller would actually reach for: the
	 * token it authenticated with, and the account behind it.
	 */
	public function testACredentialBearingClassIsRefusedWhereTheDatamodelIsLoaded(): void
	{
		if (!class_exists('MetaModel') || !class_exists('UserLocal')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so no class declares a password here.');
		}

		$this->assertTrue(AccessGrants::IsGranting('UserLocal'));
		$this->assertTrue(AccessGrants::IsGranting('PersonalToken'));

		// The signal has to stay narrow, or it stops being about credentials:
		// SynchroDataSource is why iTop's own addon/authentication category
		// was not used for this.
		$this->assertFalse(AccessGrants::IsGranting('SynchroDataSource'));
		$this->assertFalse(AccessGrants::IsGranting('UserRequest'));
	}

	/**
	 * The signal is one-way storage, not the word "password", and widening it
	 * to the recoverable types would break the rule it implements.
	 *
	 * AttributeOneWayPassword keeps a salted hash and nothing else, so its
	 * value can only ever be compared against - which is what makes a class
	 * holding one a class that authenticates somebody. Every outbound
	 * credential has to be readable to be used, so it lives in
	 * AttributePassword or AttributeEncryptedString instead: iTop's OAuth
	 * client secret and webhook auth_pwd both do. Those are ordinary object
	 * data - a mailbox password on a mailbox, a login on a CI - and a barrier
	 * that swallowed them would be refusing writes that have nothing to do
	 * with who may call this endpoint.
	 */
	public function testTheRecoverableSecretTypesAreDeliberatelyNotMatched(): void
	{
		$sSource = (string)file_get_contents((new ReflectionClass(AccessGrants::class))->getFileName());
		$sCode = $this->stripComments($sSource);

		foreach (['AttributePassword', 'AttributeEncryptedString', 'AttributeEncryptedPassword'] as $sType) {
			$this->assertStringNotContainsString(
				"'".$sType."'",
				$sCode,
				"{$sType} holds a secret something else has to read back - a mailbox password, an API client secret - so matching it refuses writes that say nothing about who may call this endpoint"
			);
		}

		$this->assertStringContainsString("'AttributeOneWayPassword'", $sCode, 'the credential signal is gone');
	}

	/**
	 * A token class recognised for what it declares rather than for its name.
	 *
	 * Readable only where a datamodel is loaded; the shape test above carries
	 * it when it is not.
	 */
	public function testAClassDeclaringOurScopesIsRefusedWhereTheDatamodelIsLoaded(): void
	{
		if (!class_exists('MetaModel') || !class_exists('PersonalToken')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so nothing declares a scope here.');
		}

		$this->assertTrue(
			TokenScopes::GradesThisEndpoint('PersonalToken'),
			'PersonalToken declares no MCP scope, so the dynamic half of the barrier is answering nothing'
		);
		$this->assertFalse(
			TokenScopes::GradesThisEndpoint('UserRequest'),
			'an ordinary class is being read as one that grades this endpoint'
		);
	}

	/**
	 * The datamodel is asked once per class and the answer kept. Forget()
	 * exists for a test that changes the datamodel underneath, and is the only
	 * way back - a memo nobody can clear is a memo that outlives what it
	 * memoised.
	 */
	public function testTheAnswerIsStableAndForgettable(): void
	{
		$this->assertTrue(AccessGrants::IsGranting('PersonalToken'));
		$this->assertTrue(AccessGrants::IsGranting('PersonalToken'));

		AccessGrants::Forget();

		$this->assertTrue(AccessGrants::IsGranting('PersonalToken'));
		$this->assertFalse(AccessGrants::IsGranting('UserRequest'));
	}

	/** The refusal names the class and says where the change belongs instead. */
	public function testTheRefusalIsUsable(): void
	{
		$sRefusal = sprintf(AccessGrants::GRANT_REFUSAL, 'PersonalToken');

		$this->assertStringContainsString('PersonalToken', $sRefusal);
		$this->assertStringContainsString('console', $sRefusal);
	}

	/**
	 * @return array<int, array{0: string, 1: object}>
	 */
	private function writingTools(): array
	{
		$aTools = [];
		foreach (MCPRegistry::GetTools() as $sName => $oTool) {
			$aHints = $oTool->getAnnotations()->jsonSerialize();
			$sCapability = AccessPolicy::CapabilityOf(
				array_key_exists('readOnlyHint', $aHints) ? (bool)$aHints['readOnlyHint'] : null,
				array_key_exists('destructiveHint', $aHints) ? (bool)$aHints['destructiveHint'] : null
			);

			if ($sCapability !== AccessPolicy::CAPABILITY_READ) {
				$aTools[] = [$sName, $oTool];
			}
		}

		return $aTools;
	}

	public function testThereAreWritingToolsToCheck(): void
	{
		// Guards the test itself: a discovery bug that finds nothing would
		// otherwise make the assertion below vacuously true.
		$this->assertGreaterThanOrEqual(7, count($this->writingTools()));
	}

	/**
	 * Every write tool reaches the barrier, discovered from the registry
	 * rather than listed - so a write tool added later is covered by the same
	 * rule the day it is registered, which is the only way a gate like this
	 * stays applied.
	 *
	 * The bulk tools reach it through AbstractBulkTool::checkBulkAllowed(),
	 * which is where they check every other class-level right too.
	 */
	public function testEveryWritingToolReachesTheBarrier(): void
	{
		foreach ($this->writingTools() as [$sName, $oTool]) {
			$sSource = (string)file_get_contents((new ReflectionClass($oTool))->getFileName());

			$this->assertTrue(
				str_contains($sSource, 'AccessGrants::IsGranting')
				|| str_contains($sSource, 'checkBulkAllowed('),
				"{$sName} can write the classes that decide what it may write"
			);
		}
	}

	/**
	 * The shared gate the bulk tools delegate to, pinned separately: the
	 * assertion above accepts a call to it, so a checkBulkAllowed() that
	 * stopped checking would pass three tools silently.
	 */
	public function testTheSharedBulkGateChecksIt(): void
	{
		$this->assertStringContainsString(
			'AccessGrants::IsGranting',
			$this->body('Altioo\iTop\Extension\MCP\Abstract\AbstractBulkTool', 'checkBulkAllowed'),
			'the bulk tools no longer check the barrier'
		);
	}

	/**
	 * Reading is deliberately not refused, and that asymmetry is a decision
	 * rather than an oversight.
	 *
	 * A caller listing its own tokens learns nothing it did not arrive with -
	 * the secret is not readable once minted - and an assistant that can say
	 * "this token expires on Friday" is worth having. Every read stays gated
	 * by UserRights exactly as before.
	 */
	public function testReadingIsNotRefused(): void
	{
		foreach (['ObjectGet', 'ObjectSearchByOQL', 'ObjectSearchByClass'] as $sTool) {
			$sFile = dirname(__DIR__, 3).'/src/Core/Tools/'.$sTool.'.php';

			$this->assertFileExists($sFile);
			$this->assertStringNotContainsString(
				'AccessGrants',
				(string)file_get_contents($sFile),
				"{$sTool} refuses to read a token, which was never the point of the barrier"
			);
		}
	}

	/**
	 * The body of one method, comments stripped - they discuss the very call
	 * being searched for and would satisfy the search on their own - and
	 * whitespace with them, so that a reindent does not fail the test.
	 *
	 * @param class-string $sClass
	 */
	private function stripComments(string $sSource): string
	{
		$sCode = '';
		foreach (token_get_all($sSource) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}

	private function body(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines  = file($oMethod->getFileName());
		$sBody   = implode('', array_slice(
			$aLines,
			$oMethod->getStartLine() - 1,
			$oMethod->getEndLine() - $oMethod->getStartLine() + 1
		));

		$sCode = '';
		foreach (token_get_all('<?php '.$sBody) as $mToken) {
			if (is_array($mToken) && in_array($mToken[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
				continue;
			}
			$sCode .= is_array($mToken) ? $mToken[1] : $mToken;
		}

		return $sCode;
	}
}
