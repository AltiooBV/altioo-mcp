<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Server\Session\StatelessSessionStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

// iTop's own runner bootstraps with unittestautoload.php, which cannot
// autoload this module's test Support classes; this fills that gap and is a
// no-op when phpunit.xml.dist already loaded it.
require_once __DIR__.'/../bootstrap.php';

class StatelessSessionStoreTest extends TestCase
{
	private StatelessSessionStore $oStore;

	protected function setUp(): void
	{
		parent::setUp();
		$this->oStore = new StatelessSessionStore();
	}

	private function uuid(): Uuid
	{
		return Uuid::v4();
	}

	/**
	 * The store is deliberately permissive: the MCP transport hands it a client
	 * supplied session id and the extension does not pin sessions to a server.
	 */
	public function testExistsAcceptsAnyId(): void
	{
		$this->assertTrue($this->oStore->exists($this->uuid()));
		$this->assertTrue($this->oStore->exists($this->uuid()));
	}

	public function testReadReturnsFalseForUnknownId(): void
	{
		$this->assertFalse($this->oStore->read($this->uuid()));
	}

	/**
	 * The one fact this class exists to guarantee. Not "writes are discarded
	 * eventually" but "a write is not observable at all", which is what lets
	 * exists() accept any id without that being a way to reach somebody else's
	 * state.
	 */
	public function testAWriteIsNotObservable(): void
	{
		$oId = $this->uuid();

		$this->assertTrue($this->oStore->write($oId, 'payload'));
		$this->assertFalse(
			$this->oStore->read($oId),
			'the store kept a write: while exists() accepts any id, keeping anything makes a guessed id worth something'
		);
	}

	/**
	 * The SDK saves once per request and logs a failure when the store says
	 * no. Nothing is failing - there is simply nowhere for it to go - so an
	 * honest "false" here would be a warning on every single request.
	 */
	public function testWriteReportsSuccessSoTheSdkDoesNotLogAFailedSave(): void
	{
		$this->assertTrue($this->oStore->write($this->uuid(), 'payload'));
		$this->assertTrue($this->oStore->write($this->uuid(), ''));
	}

	public function testDestroyIsIdempotentBecauseThereIsNothingToDestroy(): void
	{
		$oId = $this->uuid();

		$this->assertTrue($this->oStore->destroy($oId));
		$this->assertTrue($this->oStore->destroy($oId));
		$this->assertFalse($this->oStore->read($oId));
	}

	public function testGcReturnsNothingToCollect(): void
	{
		$this->oStore->write($this->uuid(), 'payload');

		$this->assertSame([], $this->oStore->gc());
	}

	/**
	 * Two instances cannot share what neither of them holds. The store used to
	 * back onto a private static array, which made this test about sharing;
	 * it is now about there being nothing to share.
	 */
	public function testASecondInstanceSeesNothingEither(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'payload');

		$this->assertFalse((new StatelessSessionStore())->read($oId));
	}

	// ------------------------------------------------------------------
	// Why exists() saying yes to everything is not an authentication bypass
	//
	// It is safe for one reason and one reason only: nothing is stored, so an
	// id a caller invents matches no state belonging to anybody, and the
	// request is authenticated on its own regardless. The day this becomes a
	// real store - a file, a table, iTop's PHP session - that same exists()
	// hands the caller whatever sits under an id they guessed.
	//
	// These tests hold the invariant, not the behaviour, so that whoever makes
	// that change is stopped by a red suite rather than by a reviewer noticing.
	// ------------------------------------------------------------------

	/**
	 * The class holds no state of any kind. A property added here - an array,
	 * a path, a PDO handle, a session key - is the change this is watching
	 * for, whether or not it survives the request.
	 */
	public function testTheStoreKeepsNothingAtAll(): void
	{
		$aProperties = (new ReflectionClass(StatelessSessionStore::class))->getProperties();

		$this->assertSame(
			[],
			$aProperties,
			'StatelessSessionStore grew a property: if anything is now kept, exists() accepting any id is an authentication bypass'
		);
	}

	/**
	 * exists() must not consult any storage. It is not an accident of the
	 * current implementation but the whole contract: a client that keeps
	 * sending back an Mcp-Session-Id is never contradicted, and never
	 * remembered either.
	 */
	public function testExistsNeverConsultsAnyStorage(): void
	{
		$oId = $this->uuid();

		// Never written.
		$this->assertTrue($this->oStore->exists($oId));

		$this->oStore->write($oId, 'payload');
		$this->assertTrue($this->oStore->exists($oId));

		// Written and then destroyed - still yes, because it was never looked up.
		$this->oStore->destroy($oId);
		$this->assertTrue($this->oStore->exists($oId));
	}

	/**
	 * What a second request sees, which is nothing - and now for a reason that
	 * does not depend on the SAPI. The store used to rely on a new PHP process
	 * clearing a static, which is not what a persistent worker (FrankenPHP,
	 * RoadRunner) does.
	 */
	public function testNothingSurvivesIntoTheNextRequestWhateverTheSapi(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'payload');

		$this->assertFalse(
			(new StatelessSessionStore())->read($oId),
			'session data outlived the request: every request must re-authenticate on its own'
		);
	}
}
