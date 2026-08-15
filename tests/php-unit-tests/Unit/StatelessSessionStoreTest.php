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
		// The backing array is private *static*: without resetting it, state
		// leaks between test methods.
		$oProperty = (new ReflectionClass(StatelessSessionStore::class))->getProperty('sessions');
		$oProperty->setValue(null, []);

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

	public function testWriteThenRead(): void
	{
		$oId = $this->uuid();

		$this->assertTrue($this->oStore->write($oId, 'payload'));
		$this->assertSame('payload', $this->oStore->read($oId));
	}

	public function testWriteOverwrites(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'first');
		$this->oStore->write($oId, 'second');

		$this->assertSame('second', $this->oStore->read($oId));
	}

	public function testEntriesAreKeyedByIdNotByInstance(): void
	{
		$oId = $this->uuid();
		$oOther = $this->uuid();
		$this->oStore->write($oId, 'mine');

		$this->assertSame('mine', $this->oStore->read($oId));
		$this->assertFalse($this->oStore->read($oOther));
	}

	public function testDestroyRemovesTheEntry(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'payload');

		$this->assertTrue($this->oStore->destroy($oId));
		$this->assertFalse($this->oStore->read($oId));
	}

	public function testDestroyIsIdempotent(): void
	{
		$this->assertTrue($this->oStore->destroy($this->uuid()));
	}

	public function testGcReturnsNothingToCollect(): void
	{
		$this->oStore->write($this->uuid(), 'payload');

		$this->assertSame([], $this->oStore->gc());
	}

	/**
	 * Storage is static, so two instances share it. That is what makes the
	 * store usable across the several reads the transport performs within one
	 * request - and why nothing survives into the next one.
	 */
	public function testStorageIsSharedBetweenInstances(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'payload');

		$this->assertSame('payload', (new StatelessSessionStore())->read($oId));
	}

	// ------------------------------------------------------------------
	// Why exists() saying yes to everything is not an authentication bypass
	//
	// It is safe for one reason and one reason only: nothing is stored across
	// requests, so an id a caller invents matches no state belonging to
	// anybody, and the request is authenticated on its own regardless. The day
	// this becomes a real store - a file, a table, iTop's PHP session - that
	// same exists() hands the caller whatever sits under an id they guessed.
	//
	// These tests hold the invariant, not the behaviour, so that whoever makes
	// that change is stopped by a red suite rather than by a reviewer noticing.
	// ------------------------------------------------------------------

	/**
	 * The storage is one static array and nothing else. A property added here
	 * that outlives the process - a path, a PDO handle, a session key - is the
	 * change this is watching for.
	 */
	public function testTheStoreKeepsNothingButOneProcessLocalArray(): void
	{
		$oClass = new ReflectionClass(StatelessSessionStore::class);
		$aProperties = $oClass->getProperties();

		$this->assertCount(
			1,
			$aProperties,
			'StatelessSessionStore grew a property: if anything now outlives the request, exists() accepting any id is an authentication bypass'
		);

		$oProperty = $aProperties[0];
		$this->assertSame('sessions', $oProperty->getName());
		$this->assertTrue($oProperty->isStatic());
		$this->assertTrue($oProperty->isPrivate());
	}

	/**
	 * exists() must not consult the storage. It is not an accident of the
	 * current implementation but the whole contract: a client that keeps
	 * sending back an Mcp-Session-Id is never contradicted, and never
	 * remembered either.
	 */
	public function testExistsNeverConsultsTheStorage(): void
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
	 * What a second request sees, which is nothing. Resetting the static is
	 * what a new PHP process does on its own; if a future implementation makes
	 * this survive, that is the point at which a guessed session id starts
	 * being worth something.
	 */
	public function testNothingSurvivesIntoTheNextRequest(): void
	{
		$oId = $this->uuid();
		$this->oStore->write($oId, 'payload');

		(new ReflectionClass(StatelessSessionStore::class))->getProperty('sessions')->setValue(null, []);

		$this->assertFalse(
			(new StatelessSessionStore())->read($oId),
			'session data outlived the request: every request must re-authenticate on its own'
		);
	}
}
