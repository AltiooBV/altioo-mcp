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
				str_contains($sSource, 'AccessGrants::RefusalFor')
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
			'AccessGrants::RefusalFor',
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
	 * The other half of the barrier: what this endpoint may not hand to
	 * something that writes for it.
	 *
	 * Found by a red-team pass, and it is a gap in what the barrier covered
	 * rather than a hole in the barrier. Every identity class is refused and
	 * stays refused - and a caller that may not write UserLocal could create a
	 * SynchroDataSource whose scope_class is UserLocal, which iTop fills in
	 * with the whole attribute list, update:true, password and profile_list
	 * included. The synchronisation engine applies it later from cron or from
	 * a console button, as a trusted internal process that never passes
	 * through this endpoint.
	 *
	 * So the refusal is about what executes elsewhere, not about what the row
	 * holds - which is why the credential-attribute rule above was never going
	 * to catch it, and says so.
	 *
	 * @dataProvider delegatingClassProvider
	 */
	public function testASynchronisationClassIsRecognised(string $sClass): void
	{
		$this->assertTrue(AccessGrants::IsDelegating($sClass));
		$this->assertTrue(AccessGrants::IsBarred($sClass));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function delegatingClassProvider(): array
	{
		return [
			'the definition'            => ['SynchroDataSource'],
			'the mapping'               => ['SynchroAttribute'],
			'the staged data'           => ['SynchroReplica'],
			'the run log'               => ['SynchroLog'],
			'case is not a way past it' => ['synchrodatasource'],
		];
	}

	/**
	 * The family is four names and a question, not a spelling.
	 *
	 * A prefix match stood here first and was wrong twice: it refused a
	 * customer class called SynchroWidget that stages nothing, and it would
	 * have missed one that stages something without being named for it. What
	 * makes a row part of a definition is that it hangs off a data source, so
	 * that is what is asked - of the datamodel, which is the only thing that
	 * can answer it.
	 */
	public function testTheFamilyIsNotMatchedByItsSpelling(): void
	{
		$this->assertFalse(
			AccessGrants::IsDelegating('SynchroWidget'),
			'a class is in the family for being named like one, so a customer class is read-only by coincidence'
		);

		$sSource = (string) file_get_contents(
			dirname(__DIR__, 3).'/src/Helper/AccessGrants.php'
		);
		$this->assertStringNotContainsString("DELEGATING_PREFIX", $sSource, 'the prefix match is still there');
		$this->assertStringContainsString('IsExternalKey', $sSource, 'nothing asks the datamodel what hangs off a data source');
	}

	/**
	 * A member the names do not list, recognised because of what it points at.
	 *
	 * The datamodel half, and the reason the prefix is not missed: a class
	 * carrying an external key to a SynchroDataSource is part of a definition
	 * the engine will execute, whatever it is called.
	 */
	public function testAClassHangingOffADataSourceIsRecognisedWhereTheDatamodelIsLoaded(): void
	{
		if (!class_exists('MetaModel') || !class_exists('SynchroAttExtKey')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so nothing can be asked what it points at.');
		}

		$this->assertTrue(AccessGrants::IsDelegating('SynchroAttExtKey'));
	}

	/**
	 * A definition that does not say where it lands is refused.
	 *
	 * The target is the whole basis on which this half grades a write, so a
	 * rule that shrugs where it cannot read one is a rule answered by not
	 * writing one. Same stance as the self guard, and the same cost of being
	 * wrong - a refusal an administrator satisfies from the console.
	 */
	public function testASynchroWhoseTargetIsNotSettledIsRefused(): void
	{
		foreach ([[], ['scope_class' => '  ']] as $aFields) {
			$sRefusal = AccessGrants::RefusalGiven(true, 'SynchroDataSource', null, $aFields);

			$this->assertNotNull($sRefusal, 'a definition that names no target was allowed through');
			$this->assertStringContainsString('scope_class', $sRefusal, 'the refusal does not say what would settle it');
		}
	}

	/**
	 * No setting opens a definition pointed at the rights model.
	 *
	 * mcp_allow_access_administration buys administration of other people's
	 * access *through this endpoint*, where every such write is graded against
	 * the caller's own credential on the way past - see the self guard above.
	 * A definition the engine runs later is graded against nothing, so
	 * permitting one pointed at the rights model would hand back exactly the
	 * self-escalation no setting is allowed to lift: stage it instead of
	 * performing it.
	 */
	public function testNoSettingOpensASynchroPointedAtTheRightsModel(): void
	{
		foreach ([false, true] as $bAdministrationAllowed) {
			$sRefusal = AccessGrants::RefusalGiven(
				$bAdministrationAllowed,
				'SynchroDataSource',
				null,
				['scope_class' => 'URP_UserProfile']
			);

			$this->assertNotNull($sRefusal, 'a staged write into the rights model was allowed through');
			$this->assertStringContainsString('URP_UserProfile', $sRefusal);
			$this->assertStringNotContainsString(
				'Turn on mcp_allow_access_administration',
				$sRefusal,
				'the refusal sends an operator to a setting that would not have helped'
			);
		}
	}

	/**
	 * The one the red team actually used, which needs a datamodel to recognise.
	 *
	 * UserLocal is not in the named floor - User is, and UserLocal is refused
	 * for descending from it - so a suite with no iTop cannot resolve the
	 * relationship and this is the same skip the descendant test above takes.
	 */
	public function testNoSettingOpensASynchroPointedAtUserLocal(): void
	{
		if (!class_exists('UserLocal')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so UserLocal is not known to descend from User here.');
		}

		$this->assertNotNull(
			AccessGrants::RefusalGiven(true, 'SynchroDataSource', null, ['scope_class' => 'UserLocal']),
			'a synchro source pointed at UserLocal is the reported escalation, unchanged'
		);
	}

	/**
	 * An ordinary target is graded on the caller's rights over it, not on a
	 * setting.
	 *
	 * The barrier is not a ban on synchronisation, and the first version of it
	 * was: every definition was refused behind mcp_allow_access_administration,
	 * which refused ordinary CMDB work under the name of something else. What
	 * the engine actually removes is the rights check - it runs as a trusted
	 * internal process and consults nobody - so the rights are what this asks
	 * about, once, over the class the definition points at. Staging a write
	 * you could have performed is a scheduling decision. Staging one you were
	 * refused is the bypass.
	 *
	 * All five actions, because a data source does all of them: it creates
	 * what it finds, modifies what it matches and, under its own
	 * delete_policy, deletes what has gone - in bulk, by construction.
	 */
	public function testAnOrdinaryTargetIsGradedOnTheRightsOverIt(): void
	{
		$sRights = $this->body(AccessGrants::class, 'RightsRefusalForTarget');

		foreach (['UR_ACTION_CREATE', 'UR_ACTION_MODIFY', 'UR_ACTION_DELETE', 'UR_ACTION_BULK_MODIFY', 'UR_ACTION_BULK_DELETE'] as $sAction) {
			$this->assertStringContainsString($sAction, $sRights, "a definition is not graded on {$sAction} over its target");
		}

		$this->assertStringNotContainsString(
			'AllowsAccessAdministration',
			$sRights,
			'an ordinary CMDB source is still gated on the access-administration setting'
		);
	}

	/**
	 * And where the rights cannot be asked, it is refused.
	 *
	 * This is the failure mode that matters, and the suite runs in it: no
	 * UserRights, so nothing can be established, so nothing is allowed. Being
	 * wrong this way is a refusal an administrator satisfies; being wrong the
	 * other way is the bypass.
	 */
	public function testAnOrdinaryTargetIsRefusedWhenTheRightsCannotBeAsked(): void
	{
		if (class_exists('UserRights') && class_exists('MetaModel')) {
			$this->markTestSkipped('an iTop is loaded, so the undecidable path is not reachable here.');
		}

		$sRefusal = AccessGrants::RefusalGiven(true, 'SynchroDataSource', null, ['scope_class' => 'Server']);

		$this->assertNotNull($sRefusal, 'a target whose rights could not be read was allowed through');
		$this->assertStringContainsString('Server', $sRefusal);
		$this->assertStringContainsString('could not be established', $sRefusal);
	}

	/**
	 * The refusals name different problems, so they are different sentences.
	 *
	 * Three now: the access classes, a definition pointed at one, and a
	 * definition pointed at a class the caller may not write. Each is fixed by
	 * a different person doing a different thing, and a caller that cannot
	 * tell them apart asks for the wrong one.
	 */
	public function testTheDelegationRefusalsDoNotReadAlike(): void
	{
		$aAll = [
			AccessGrants::GRANT_REFUSAL,
			AccessGrants::SELF_REFUSAL,
			AccessGrants::DELEGATION_REFUSAL,
			AccessGrants::DELEGATED_TARGET_REFUSAL,
			AccessGrants::DELEGATED_RIGHTS_REFUSAL,
		];

		$this->assertSame(count($aAll), count(array_unique($aAll)), 'two refusals say the same thing');
		$this->assertStringContainsString('scope_class', AccessGrants::DELEGATION_REFUSAL);
		$this->assertStringContainsString('decides who may reach this endpoint', AccessGrants::DELEGATED_TARGET_REFUSAL);
		$this->assertStringContainsString('you could write yourself', AccessGrants::DELEGATED_RIGHTS_REFUSAL);
	}

	/**
	 * Reads are untouched here too.
	 *
	 * The barrier is about what a write sets in motion. Reporting that a
	 * source last ran on Tuesday sets nothing in motion, and the read tools
	 * are checked as a whole by testReadingIsNotRefused() above.
	 */
	public function testASynchronisationClassIsStillReadable(): void
	{
		$sBlock = $this->body('Altioo\\iTop\\Extension\\MCP\\Helper\\DatamodelReader', 'narrowedByTheBarrier');

		$this->assertStringContainsString(
			'Readingisunaffected',
			str_replace(' ', '', $sBlock),
			'the schema does not tell a caller that reading a synchro class is still open'
		);
	}

	/**
	 * The third family: a standing instruction that makes iTop act by itself.
	 *
	 * Reported by the same red-team pass, independently exploitable, and it
	 * needs nothing outside the classes this endpoint already wrote. A
	 * RemoteApplicationConnection whose url is a plain text attribute with no
	 * scheme or host check; an ActioniTopWebhook pointed at it; a Trigger
	 * linked to that action by lnkTriggerAction. Enabled, it fires on every
	 * matching change made by anyone, from inside iTop's own request handling,
	 * for as long as nobody notices - so one burst of write access becomes a
	 * standing exfiltration or SSRF channel that outlives the session.
	 *
	 * Refused wholesale, unlike the synchronisation family, and the difference
	 * is the whole argument. A synchro delegates writing objects of a class,
	 * which this endpoint does grant and can therefore grade against the
	 * caller. A trigger delegates sending mail, calling a URL and invoking a
	 * static method by name - none of which any tool here grants anybody. The
	 * question "could the caller have done this itself" has one answer for
	 * every caller, and a rule whose answer never varies is a refusal.
	 *
	 * @dataProvider automationClassProvider
	 */
	public function testAnAutomationClassIsRefusedWithoutItsOwnSetting(string $sClass): void
	{
		$this->assertTrue(AccessGrants::IsAutomation($sClass));
		$this->assertTrue(AccessGrants::IsBarred($sClass));

		$sRefusal = AccessGrants::RefusalGiven(true, $sClass, 1, [], false);

		$this->assertNotNull($sRefusal, "{$sClass} makes the instance act on its own and was allowed through");
		$this->assertStringContainsString('mcp_allow_automation_administration', $sRefusal);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function automationClassProvider(): array
	{
		return [
			'the trigger root'        => ['Trigger'],
			'the action root'         => ['Action'],
			// Listed by name, not left to descent: its datamodel parent is
			// cmdbAbstractObject, and only its php_parent reaches Action.
			'the webhook action root' => ['ActionWebhook'],
			'the connection'          => ['RemoteApplicationConnection'],
			'case is not a way past'  => ['trigger'],
		];
	}

	/**
	 * The kinds of trigger and action, which need a datamodel to recognise.
	 *
	 * TriggerOnObjectCreate and ActioniTopWebhook are not in the named roots
	 * and do not need to be - Trigger and Action are, and these descend from
	 * them - so a suite with no iTop cannot resolve the relationship. Same
	 * skip as the UserLocal case above, kept separate rather than weakening
	 * the cases that run everywhere.
	 *
	 * @dataProvider automationDescendantProvider
	 */
	public function testAKindOfTriggerOrActionIsRefusedWhereTheDatamodelIsLoaded(string $sClass): void
	{
		if (!class_exists($sClass)) {
			$this->markTestSkipped("no iTop datamodel is loaded, so {$sClass} is not known to descend from its root here.");
		}

		$this->assertTrue(AccessGrants::IsAutomation($sClass));
		$this->assertNotNull(AccessGrants::RefusalGiven(true, $sClass, 1, [], false));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function automationDescendantProvider(): array
	{
		return [
			'a trigger on creation' => ['TriggerOnObjectCreate'],
			'the webhook action'    => ['ActioniTopWebhook'],
			'the mail action'       => ['ActionEmail'],
		];
	}

	/**
	 * The access setting does not reach it, and its own setting does.
	 *
	 * Two settings because they answer two questions. "May an assistant
	 * administer other people's access" and "may an assistant leave a standing
	 * instruction that makes this instance call out on its own" are decisions
	 * an operator can reasonably take separately - and folding them together
	 * would be refusing one thing under the name of another, which is the
	 * mistake the synchronisation gate made first time round.
	 */
	public function testTheTwoAdministrationSettingsAreIndependent(): void
	{
		// Access administration on, automation off: still refused.
		$this->assertNotNull(AccessGrants::RefusalGiven(true, 'Action', 1, [], false));
		// Automation on: allowed, whatever the access setting says.
		$this->assertNull(AccessGrants::RefusalGiven(false, 'Action', 1, [], true));
		$this->assertNull(AccessGrants::RefusalGiven(true, 'Action', 1, [], true));
	}

	/**
	 * The link between a trigger and an action, found by what it points at.
	 *
	 * lnkTriggerAction is not in the named roots and does not need to be: a
	 * class carrying an external key to a Trigger or an Action is part of
	 * wiring one to the other, whatever it is called. Same rule that finds a
	 * synchronisation mapping, asked with a different target.
	 */
	public function testTheTriggerActionLinkIsFoundByWhatItPointsAt(): void
	{
		if (!class_exists('MetaModel') || !class_exists('lnkTriggerAction')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so nothing can be asked what it points at.');
		}

		$this->assertTrue(AccessGrants::IsAutomation('lnkTriggerAction'));
		$this->assertNotNull(AccessGrants::RefusalGiven(true, 'lnkTriggerAction', 1, [], false));
	}

	/**
	 * Somebody else's preferences are not this caller's to write.
	 *
	 * The mirror of the self guard, and the only refusal here that is about
	 * another person's row rather than your own. appUserPreferences carries a
	 * userid, iTop's own API for it only ever touches the current account, and
	 * the console offers no way to edit another person's - but the object
	 * tools did, because a preference row is an ordinary DBObject with an
	 * ordinary id and UserRights has nothing to say about it. Small on its
	 * own; one of those preferences decides whether obsolete objects are
	 * visible, so rewriting an administrator's row changes what they see
	 * without changing anything they would look at to find out why.
	 */
	public function testAnotherAccountsPreferencesAreRefused(): void
	{
		$this->assertTrue(AccessGrants::IsPersonal('appUserPreferences'));
		$this->assertTrue(AccessGrants::IsBarred('appUserPreferences'));

		// A row named by id, with no UserRights to establish whose it is.
		$sRefusal = AccessGrants::RefusalGiven(true, 'appUserPreferences', 1, [], true);

		$this->assertNotNull($sRefusal, "another account's preference row was allowed through");
		$this->assertStringContainsString('not yours', $sRefusal);
	}

	/**
	 * And the caller's own row still goes through.
	 *
	 * core_set_obsolete_data goes through appUserPreferences::SetPref(), which
	 * writes the row of the account it is called by - so a call naming neither
	 * an id nor a userid is this module's own tool, not a caller reaching for
	 * somebody else. Refusing that would have broken the one legitimate use
	 * while fixing the illegitimate one.
	 */
	public function testYourOwnPreferencesAreNotRefused(): void
	{
		$this->assertNull(
			AccessGrants::RefusalGiven(false, 'appUserPreferences', null, [], false),
			'the tool that stores your own preference was refused by the rule meant for other people\'s'
		);
	}

	/**
	 * Every class this module declares has been put to the barrier.
	 *
	 * The failure this test exists for, in the words of the reviewer who found
	 * it: the gate is "an allowlist of blocked classes, not a security
	 * property... it\'s writable because nobody added it to the blocklist, not
	 * because it was evaluated and judged safe". AltiooEventMCPService is the
	 * case in point - this module\'s own audit trail, one row per inbound
	 * request, added as a feature and never once held up against the barrier
	 * protecting everything else. A caller could delete the evidence of what
	 * it had just done, then the row recording that.
	 *
	 * A test cannot decide whether an arbitrary iTop class is dangerous. It
	 * can insist that every class **this module puts into the datamodel** has
	 * been looked at, which is the specific thing that did not happen. A class
	 * added here is either barred, or named below with a reason - and adding
	 * one without doing either fails, at the moment it is added, rather than
	 * at the next red-team pass.
	 */
	public function testEveryClassThisModuleDeclaresHasBeenConsidered(): void
	{
		// Classes this module declares that are deliberately ordinary. Each
		// entry is a decision someone made on purpose, not a gap.
		$aDeliberatelyOrdinary = [];

		$sDatamodel = dirname(__DIR__, 3).'/datamodel.altioo-mcp.xml';
		$this->assertFileExists($sDatamodel);

		$oDoc = new \DOMDocument();
		$this->assertTrue($oDoc->load($sDatamodel), 'the module datamodel does not parse');

		// The declared parent, not the compiled one: this suite boots no iTop,
		// so is_a() cannot resolve AltiooEventMCPService to Event - and the
		// datamodel says so in as many words. Asking the file is what makes
		// this test bite where it is useful, which is the moment a class is
		// added rather than the next red-team pass.
		$oXPath = new \DOMXPath($oDoc);
		$aDeclared = [];
		foreach ($oXPath->query('//class[@id]') as $oClass) {
			$oParent = $oXPath->query('parent', $oClass)->item(0);
			$aDeclared[$oClass->getAttribute('id')] = $oParent === null ? '' : trim($oParent->textContent);
		}

		$this->assertNotEmpty($aDeclared, 'no class found in the module datamodel - the scan is looking in the wrong place');

		$aUnconsidered = [];
		foreach ($aDeclared as $sClass => $sParent) {
			if (in_array($sClass, $aDeliberatelyOrdinary, true)) {
				continue;
			}

			// The class itself, then up the chain the file declares, then the
			// parent it stops at - which is iTop's, and is where a root like
			// Event is matched by name.
			$bConsidered = AccessGrants::IsBarred($sClass);
			$sUp = $sParent;
			$iGuard = 0;
			while (!$bConsidered && $sUp !== '' && $iGuard++ < 20) {
				$bConsidered = AccessGrants::IsBarred($sUp);
				$sUp = $aDeclared[$sUp] ?? '';
			}

			if (!$bConsidered) {
				$aUnconsidered[] = $sClass.($sParent === '' ? '' : " (parent {$sParent})");
			}
		}
		sort($aUnconsidered);

		$this->assertSame([], $aUnconsidered, sprintf(
			"This module declares %s and the barrier has nothing to say about it. "
			."Either it belongs behind one of the rules in AccessGrants, or it is ordinary and belongs in this test's own list with a reason. "
			."Deciding nothing is how AltiooEventMCPService stayed writable by the sessions it was recording.",
			implode(', ', $aUnconsidered)
		));
	}

	/**
	 * The audit trail this endpoint writes cannot be edited through it.
	 *
	 * The class the test above would have caught, pinned directly - and pinned
	 * on the parent, because that is where the rule lives: AltiooEventMCPService
	 * declares <parent>Event</parent>, as do iTop\'s own EventNotification,
	 * EventIssue, EventWebService, EventRestService and EventLoginUsage.
	 *
	 * No setting reaches it, and that is the whole point: an audit trail the
	 * audited party may edit with the operator\'s permission is an audit trail
	 * the audited party may edit.
	 */
	public function testTheEndpointsOwnAuditTrailIsNotWritableThroughIt(): void
	{
		$this->assertTrue(AccessGrants::IsRecording('Event'));
		$this->assertTrue(AccessGrants::IsBarred('Event'));

		foreach ([[false, false], [true, true]] as [$bAdministration, $bAutomation]) {
			$sRefusal = AccessGrants::RefusalGiven($bAdministration, 'Event', 1, [], $bAutomation);

			$this->assertNotNull($sRefusal, 'a setting was allowed to open the record of what happened');
			$this->assertStringNotContainsString('mcp_allow', $sRefusal,
				'the refusal names a setting, so a caller goes and asks an operator to turn it on');
		}
	}

	/**
	 * And this module\'s own class comes with the parent, on an instance.
	 */
	public function testThisModulesAuditClassIsRecordingWhereTheDatamodelIsLoaded(): void
	{
		if (!class_exists('AltiooEventMCPService')) {
			$this->markTestSkipped('no iTop datamodel is loaded, so the module class is not known to descend from Event here.');
		}

		$this->assertTrue(AccessGrants::IsRecording('AltiooEventMCPService'));
		$this->assertNotNull(AccessGrants::RefusalGiven(true, 'AltiooEventMCPService', 1, [], true));
	}

	/**
	 * The mail queue is the primitive, so the queue is what is gated.
	 *
	 * AsyncSendEmail extends AsyncTask and is the outbound queue iTop\'s cron
	 * drains, with free-text to, subject and message and a status of
	 * "planned". One create puts a real email into it, sent from the
	 * instance\'s own configured identity to any address - no trigger, no
	 * action, no connection object. Gated at AsyncTask because anything else
	 * landing in that queue is executed the same way by the same cron.
	 */
	public function testTheDeferredWorkQueueIsGated(): void
	{
		$this->assertTrue(AccessGrants::IsAutomation('AsyncTask'));
		$this->assertNotNull(AccessGrants::RefusalGiven(true, 'AsyncTask', null, [], false));
		$this->assertNull(AccessGrants::RefusalGiven(false, 'AsyncTask', null, [], true));
	}

	/**
	 * The credentials the instance authenticates outward with.
	 *
	 * Left out of the barrier once, on the reasoning at CREDENTIAL_ATTRIBUTE
	 * that a recoverable secret is the object\'s own data. That covers a device
	 * password; it does not cover a token this instance authenticates to a
	 * third party with, which a caller can replace with its own or read back
	 * through something else it wrote. Oauth2Client carries client_secret,
	 * refresh_token and access_token as AttributeEncryptedPassword, and its
	 * five subclasses come with it by descent.
	 */
	public function testOutboundCredentialStoresAreGated(): void
	{
		foreach (['Oauth2Client', 'OAuthClient'] as $sClass) {
			$this->assertTrue(AccessGrants::IsAutomation($sClass), "{$sClass} holds outbound credentials and is ungated");
			$this->assertNotNull(AccessGrants::RefusalGiven(true, $sClass, 1, [], false));
		}
	}

	/**
	 * The rules that decide what a person is shown as wrong.
	 *
	 * Not an escalation - nothing here grants access to anything - but the
	 * other half of covering your tracks: delete the rule and the mess looks
	 * like the data. Behind the automation setting rather than refused
	 * outright, because managing data-quality rules is ordinary work an
	 * operator may delegate, unlike the record of what already happened.
	 */
	public function testTheDataQualityAuditIsGated(): void
	{
		foreach (['AuditRule', 'AuditCategory', 'AuditDomain'] as $sClass) {
			$this->assertTrue(AccessGrants::IsDetection($sClass));
			$this->assertNotNull(AccessGrants::RefusalGiven(true, $sClass, 1, [], false));
			$this->assertNull(AccessGrants::RefusalGiven(false, $sClass, 1, [], true));
		}
	}

	/**
	 * An ordinary class is nobody's business here, whatever the setting says.
	 */
	public function testAnOrdinaryClassIsNeverRefusedByThisRule(): void
	{
		$this->assertNull(AccessGrants::RefusalGiven(false, 'UserRequest', 12));
		$this->assertNull(AccessGrants::RefusalGiven(true, 'UserRequest', 12));
	}

	/**
	 * The default, and the answer for almost every instance: refused outright,
	 * naming the setting that would change it.
	 *
	 * The refusal names mcp_allow_access_administration because a model told
	 * only "no" retries a variation of the same call, while one told which
	 * switch is off reports it and stops.
	 */
	public function testWithoutTheSettingAGrantingClassIsRefusedOutright(): void
	{
		$sRefusal = AccessGrants::RefusalGiven(false, 'PersonalToken', 7);

		$this->assertNotNull($sRefusal);
		$this->assertStringContainsString('PersonalToken', $sRefusal);
		$this->assertStringContainsString('mcp_allow_access_administration', $sRefusal);
	}

	/**
	 * The two refusals are different sentences, and have to stay that way.
	 *
	 * One is an instance that has not opted in and can; the other is a rule no
	 * configuration lifts. A caller that cannot tell them apart asks an
	 * operator to turn on a setting that would not have helped.
	 */
	public function testTheTwoRefusalsDoNotReadAlike(): void
	{
		$this->assertNotSame(AccessGrants::GRANT_REFUSAL, AccessGrants::SELF_REFUSAL);
		$this->assertStringNotContainsString('mcp_allow_access_administration', AccessGrants::SELF_REFUSAL);
		$this->assertStringContainsString('yourself', AccessGrants::SELF_REFUSAL);
	}

	/**
	 * With the setting on and nothing to decide with, the answer is still no.
	 *
	 * This is the failure mode that matters. No MetaModel, no login, an object
	 * that will not load: every one of those has to read as "this is your own
	 * access", because being wrong the other way is the escalation the whole
	 * barrier exists to prevent, and being wrong this way is a refusal an
	 * administrator satisfies from the console. The unit suite runs without an
	 * iTop, so this is that path exactly.
	 */
	public function testTheSelfGuardFailsClosed(): void
	{
		if (class_exists('MetaModel') && class_exists('UserRights')) {
			$this->markTestSkipped('an iTop is loaded, so the undecidable path is not reachable here.');
		}

		$sRefusal = AccessGrants::RefusalGiven(true, 'PersonalToken', 7);

		$this->assertNotNull($sRefusal, 'a write was allowed while nothing could establish whose access it was');
		$this->assertStringContainsString('yourself', $sRefusal);
	}

	/**
	 * The setting cannot reach the self-guard.
	 *
	 * That is the property that makes the setting safe to offer at all:
	 * opting in buys administration of other people's access and nothing
	 * whatever about your own. Checked as a shape because the behaviour needs
	 * a datamodel, and because the way it would break is somebody threading
	 * the flag into the guard as an early return.
	 */
	public function testTheSettingCannotReachTheSelfGuard(): void
	{
		$sDecision = $this->body(AccessGrants::class, 'RefusalGiven');
		$sGuard = $this->body(AccessGrants::class, 'ReachesTheCaller');

		$this->assertStringContainsString('ReachesTheCaller', $sDecision, 'the self-guard is no longer consulted');
		$this->assertStringContainsString('SELF_REFUSAL', $sDecision);
		$this->assertStringNotContainsString(
			'AllowsAccessAdministration',
			$sGuard,
			'the self-guard reads the setting, so an instance can switch off the one rule that has no switch'
		);
		$this->assertStringNotContainsString(
			'$bAdministrationAllowed',
			$sGuard,
			'the self-guard was handed the setting, which is the same thing one argument later'
		);
	}

	/**
	 * Minting is the cheap escalation, so the guard is asked of the row rather
	 * than of the verb.
	 *
	 * Editing the token in your hand is the obvious move and the easily
	 * blocked one; creating a second token that is wider reaches the same
	 * place without touching the row you authenticated with. So the create
	 * path passes the values being written, and a create naming no owner is
	 * read as a create for the caller - which is what iTop's own controller
	 * does when it fills user_id in.
	 */
	public function testTheGuardIsAskedOfTheRowIncludingOnACreate(): void
	{
		$sGuard = $this->body(AccessGrants::class, 'ReachesTheCaller');

		$this->assertStringContainsString('user_id', $sGuard, 'the owner of a token is not consulted');
		$this->assertStringContainsString('userid', $sGuard, 'the user named by a rights link is not consulted');
		$this->assertStringContainsString('$aFields', $sGuard, 'a create has only the values to go on and they are not read');
		$this->assertStringContainsString('ProfileOf', $sGuard, 'the profile a row decides about is not consulted');
	}

	/**
	 * Every bulk row is asked, not just the class.
	 *
	 * checkBulkAllowed() sees a class and a verb; whether a row is the
	 * caller's own token is a property of the row. A bulk create of thirty
	 * tokens, one of them for the caller, has to fail that one entry - so the
	 * three bulk tools each ask again per row, the way they already check
	 * per-object rights.
	 */
	public function testEveryBulkRowIsAskedAndNotOnlyTheClass(): void
	{
		foreach (['ObjectBulkCreate', 'ObjectBulkUpdate', 'ObjectBulkDelete'] as $sTool) {
			$sFile = dirname(__DIR__, 3).'/src/Core/Tools/'.$sTool.'.php';

			$this->assertStringContainsString(
				'AccessGrants::RefusalFor',
				(string)file_get_contents($sFile),
				"{$sTool} checks the class and never the rows, so one row naming the caller goes through with the batch"
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
