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
use ReflectionProperty;
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
	 * The regression that made the endpoint unusable. The SDK saves the queued
	 * response through the store and reads it back through a second Session
	 * object whose data cache is empty, so a store that discards writes loses
	 * every response: the transport answers 202 with no body and no
	 * Mcp-Session-Id, and the client waits for a reply that was generated and
	 * then thrown away.
	 */
	public function testAWriteIsReadableBackWithinTheRequest(): void
	{
		$oId = $this->uuid();

		$this->assertTrue($this->oStore->write($oId, 'payload'));
		$this->assertSame(
			'payload',
			$this->oStore->read($oId),
			'the store dropped a write: the SDK reads the queued response back through it, so discarding a write discards the response'
		);
	}

	/**
	 * Reading is by id and by nothing else. One request may see more than one
	 * session id - an id a caller invented is one of them - and holding what
	 * this request wrote must not turn into answering for an id it did not.
	 */
	public function testAWriteIsNotReadableUnderAnotherId(): void
	{
		$this->oStore->write($this->uuid(), 'payload');

		$this->assertFalse($this->oStore->read($this->uuid()));
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

	public function testDestroyRemovesTheEntryAndIsIdempotent(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'payload');

		$this->assertTrue($this->oStore->destroy($oId));
		$this->assertFalse($this->oStore->read($oId));

		// Destroying what is already gone is not a failure.
		$this->assertTrue($this->oStore->destroy($oId));
		$this->assertFalse($this->oStore->read($oId));
	}

	public function testGcReturnsNothingToCollect(): void
	{
		$this->oStore->write($this->uuid(), 'payload');

		$this->assertSame([], $this->oStore->gc());
	}

	/**
	 * One store holds what its own request wrote and nothing reaches across to
	 * another. Backed onto a private static array the two would share, which is
	 * what makes a second instance - the next request's store - the thing worth
	 * asserting about.
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
	// It is safe for one reason and one reason only: nothing outlives the
	// request that wrote it, so an id a caller invents matches no state
	// belonging to anybody, and the request is authenticated on its own
	// regardless. The day this becomes a real store - a file, a table, iTop's
	// PHP session, or an array shared across requests by a persistent worker -
	// that same exists() hands the caller whatever sits under an id they
	// guessed.
	//
	// These tests hold the invariant, not the behaviour, so that whoever makes
	// that change is stopped by a red suite rather than by a reviewer noticing.
	// ------------------------------------------------------------------

	/**
	 * The class may hold what this request wrote, and nothing wider than that.
	 * A static property - or any other backing shared between requests: a
	 * path, a PDO handle, a session key - is the change this is watching for.
	 * Under a persistent worker it survives into the next request, and
	 * exists() accepting any id then turns a guessed session id into somebody
	 * else's state.
	 */
	public function testTheStoreKeepsNothingStatic(): void
	{
		$aStatic = array_filter(
			(new ReflectionClass(StatelessSessionStore::class))->getProperties(),
			static fn(ReflectionProperty $oProperty): bool => $oProperty->isStatic()
		);

		$this->assertSame(
			[],
			$aStatic,
			'StatelessSessionStore grew a static property: it outlives the request under a persistent worker, and exists() accepting any id is then an authentication bypass'
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
	 * What a second request sees, which is nothing - for a reason that does not
	 * depend on the SAPI. Relying on a new PHP process to clear a static is not
	 * a reason that holds under a persistent worker (FrankenPHP, RoadRunner).
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
