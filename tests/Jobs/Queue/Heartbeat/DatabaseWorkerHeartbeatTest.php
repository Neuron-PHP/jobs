<?php

namespace Tests\Jobs\Queue\Heartbeat;

use Neuron\Jobs\Queue\Heartbeat\DatabaseWorkerHeartbeat;
use PHPUnit\Framework\TestCase;

class DatabaseWorkerHeartbeatTest extends TestCase
{
	private DatabaseWorkerHeartbeat $_beats;

	protected function setUp(): void
	{
		parent::setUp();

		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->_beats = new DatabaseWorkerHeartbeat( $pdo );
	}

	public function testTouchCreatesAndUpdates(): void
	{
		$started = time() - 5;
		$this->_beats->touch( 'w1', [ 'default', 'mail' ], 2, $started );

		$workers = $this->_beats->listWorkers();

		$this->assertCount( 1, $workers );
		$this->assertSame( 'w1', $workers[0]['id'] );
		$this->assertSame( [ 'default', 'mail' ], $workers[0]['queues'] );
		$this->assertSame( 2, $workers[0]['jobs_processed'] );
		$this->assertSame( $started, $workers[0]['started_at'] );

		$this->_beats->touch( 'w1', [ 'mail' ], 4, $started );
		$again = $this->_beats->listWorkers();

		$this->assertCount( 1, $again );
		$this->assertSame( [ 'mail' ], $again[0]['queues'] );
		$this->assertSame( 4, $again[0]['jobs_processed'] );
	}

	public function testPruneRemovesStaleWorkers(): void
	{
		$this->_beats->touch( 'w1', [ 'default' ], 0, time() );
		$this->assertSame( 1, $this->_beats->prune( time() + 1 ) );
		$this->assertCount( 0, $this->_beats->listWorkers() );
	}
}
