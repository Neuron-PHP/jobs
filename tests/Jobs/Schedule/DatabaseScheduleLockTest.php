<?php

namespace Tests\Jobs\Schedule;

use Neuron\Jobs\Schedule\DatabaseScheduleLock;
use Neuron\Jobs\Schedule\IScheduleLock;
use PHPUnit\Framework\TestCase;

class DatabaseScheduleLockTest extends TestCase
{
	private \PDO $pdo;

	protected function setUp(): void
	{
		parent::setUp();
		$this->pdo = new \PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	}

	public function testImplementsInterfaceAndCreatesTableOnFirstUse(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo );

		$this->assertInstanceOf( IScheduleLock::class, $lock );
		$this->assertTrue( $lock->acquire( 'cleanup', new \DateTimeImmutable( '2026-10-20 03:00' ) ) );

		$tables = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type='table'" )->fetchAll( \PDO::FETCH_COLUMN );
		$this->assertContains( DatabaseScheduleLock::DEFAULT_TABLE, $tables );
	}

	public function testSecondClaimForSameRunIsRefused(): void
	{
		$due = new \DateTimeImmutable( '2026-10-20 03:00' );

		// Two schedulers sharing one database.
		$first = new DatabaseScheduleLock( $this->pdo );
		$second = new DatabaseScheduleLock( $this->pdo );

		$this->assertTrue( $first->acquire( 'cleanup', $due ) );
		$this->assertFalse( $second->acquire( 'cleanup', $due ) );
		$this->assertFalse( $first->acquire( 'cleanup', $due ), 'Not even the owner may claim the same run twice' );
	}

	public function testDifferentJobsOrMinutesAreIndependent(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo );
		$due = new \DateTimeImmutable( '2026-10-20 03:00' );

		$this->assertTrue( $lock->acquire( 'cleanup', $due ) );
		$this->assertTrue( $lock->acquire( 'reports', $due ) );
		$this->assertTrue( $lock->acquire( 'cleanup', $due->modify( '+1 minute' ) ) );
	}

	public function testSecondsWithinTheMinuteDoNotMatter(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo );

		$this->assertTrue( $lock->acquire( 'cleanup', new \DateTimeImmutable( '2026-10-20 03:00:05' ) ) );
		$this->assertFalse( $lock->acquire( 'cleanup', new \DateTimeImmutable( '2026-10-20 03:00:42' ) ) );
	}

	public function testPruneRemovesOldClaims(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo, 'locks', 3600 );
		$lock->acquire( 'cleanup', new \DateTimeImmutable() );

		$this->pdo->exec( "UPDATE locks SET claimed_at = " . ( time() - 7200 ) . " WHERE name = 'cleanup'" );
		$lock->acquire( 'fresh', new \DateTimeImmutable() );

		$this->assertEquals( 1, $lock->prune() );
		$this->assertEquals( 1, (int)$this->pdo->query( 'SELECT COUNT(*) FROM locks' )->fetchColumn() );
	}

	public function testCustomTableName(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo, 'my_locks' );

		$this->assertEquals( 'my_locks', $lock->getTable() );
		$this->assertTrue( $lock->acquire( 'x', new \DateTimeImmutable() ) );
		$this->assertEquals( 1, (int)$this->pdo->query( 'SELECT COUNT(*) FROM my_locks' )->fetchColumn() );
	}

	public function testGetLastClaimsReportsLatestClaimPerEntry(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo, 'locks' );

		$this->assertSame( [], $lock->getLastClaims(), 'empty before any claim' );

		$lock->acquire( 'cleanup', new \DateTimeImmutable( '2026-01-01 10:00:00' ) );
		$lock->acquire( 'cleanup', new \DateTimeImmutable( '2026-01-01 10:05:00' ) );
		$lock->acquire( 'digest', new \DateTimeImmutable( '2026-01-01 10:00:00' ) );

		$this->pdo->exec( "UPDATE locks SET claimed_at = 1000 WHERE name = 'cleanup' AND due_minute = '202601011000'" );
		$this->pdo->exec( "UPDATE locks SET claimed_at = 2000 WHERE name = 'cleanup' AND due_minute = '202601011005'" );
		$this->pdo->exec( "UPDATE locks SET claimed_at = 1500 WHERE name = 'digest'" );

		$this->assertSame( [ 'cleanup' => 2000, 'digest' => 1500 ], $lock->getLastClaims() );
	}

	public function testGetLastClaimsReturnsEmptyOnDatabaseError(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo );
		$lock->acquire( 'warm', new \DateTimeImmutable() );

		$this->pdo->exec( 'DROP TABLE ' . DatabaseScheduleLock::DEFAULT_TABLE );
		$this->pdo->exec( 'CREATE TABLE ' . DatabaseScheduleLock::DEFAULT_TABLE . ' (wrong INTEGER)' );

		$this->assertSame( [], $lock->getLastClaims() );
	}

	public function testFailsOpenOnUnexpectedDatabaseError(): void
	{
		$lock = new DatabaseScheduleLock( $this->pdo );
		$lock->acquire( 'warm', new \DateTimeImmutable() ); // creates table

		// Break the table so the INSERT fails with something other than a
		// duplicate key; the lock must let the schedule proceed.
		$this->pdo->exec( 'DROP TABLE ' . DatabaseScheduleLock::DEFAULT_TABLE );
		$this->pdo->exec( 'CREATE TABLE ' . DatabaseScheduleLock::DEFAULT_TABLE . ' (wrong INTEGER)' );

		$this->assertTrue( $lock->acquire( 'cleanup', new \DateTimeImmutable() ) );
	}
}
