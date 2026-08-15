<?php
/**
 * @copyright Copyright (C) 2026 Altioo
 * @license   http://opensource.org/licenses/AGPL-3.0
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Test\Unit;

use Altioo\iTop\Extension\MCP\Server\Session\StatelessSessionStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Uid\Uuid;

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
}
