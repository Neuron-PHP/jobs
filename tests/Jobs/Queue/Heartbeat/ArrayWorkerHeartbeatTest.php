<?php

namespace Tests\Jobs\Queue\Heartbeat;

use Neuron\Jobs\Queue\Heartbeat\ArrayWorkerHeartbeat;
use PHPUnit\Framework\TestCase;

class ArrayWorkerHeartbeatTest extends TestCase
{
	public function testTouchListsAndPrunes(): void
	{
		$beats = new ArrayWorkerHeartbeat();
		$beats->touch( 'w1', [ 'default', 'mail' ], 3, time() - 10 );

		$workers = $beats->listWorkers();

		$this->assertCount( 1, $workers );
		$this->assertSame( 'w1', $workers[0]['id'] );
		$this->assertSame( [ 'default', 'mail' ], $workers[0]['queues'] );
		$this->assertSame( 3, $workers[0]['jobs_processed'] );
		$this->assertNotNull( $workers[0]['pid'] );

		$this->assertSame( 1, $beats->prune( time() + 1 ) );
		$this->assertCount( 0, $beats->listWorkers() );
	}

	public function testTouchUpdatesExistingWorker(): void
	{
		$beats = new ArrayWorkerHeartbeat();
		$beats->touch( 'w1', [ 'default' ], 1, 100 );
		$beats->touch( 'w1', [ 'mail' ], 7, 100 );

		$workers = $beats->listWorkers();

		$this->assertCount( 1, $workers );
		$this->assertSame( [ 'mail' ], $workers[0]['queues'] );
		$this->assertSame( 7, $workers[0]['jobs_processed'] );
	}
}
