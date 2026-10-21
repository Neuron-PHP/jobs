<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\Heartbeat\ArrayWorkerHeartbeat;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\Worker;
use PHPUnit\Framework\TestCase;

class WorkerHeartbeatJob implements IJob
{
	public function getName(): string
	{
		return 'WorkerHeartbeatJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

class WorkerHeartbeatTest extends TestCase
{
	public function testWorkerTouchesHeartbeatWhileIdle(): void
	{
		$manager = new QueueManager( null, [
			'driver' => 'array',
			'heartbeat' => 'array',
		] );

		$worker = new Worker( $manager );
		$worker->setStopWhenEmpty( true );
		$worker->run( [ 'default', 'mail' ] );

		$workers = $manager->getHeartbeat()->listWorkers();

		$this->assertCount( 1, $workers );
		$this->assertSame( [ 'default', 'mail' ], $workers[0]['queues'] );
		$this->assertSame( 0, $workers[0]['jobs_processed'] );
		$this->assertStringStartsWith( 'worker_', $workers[0]['id'] );
	}

	public function testWorkerTouchesHeartbeatAfterProcessing(): void
	{
		$manager = new QueueManager( null, [
			'driver' => 'array',
			'heartbeat' => 'array',
		] );
		$manager->dispatch( new WorkerHeartbeatJob() );

		$worker = new Worker( $manager );
		$worker->setMaxJobs( 1 );
		$worker->setStopWhenEmpty( true );
		$worker->run( 'default' );

		$workers = $manager->getHeartbeat()->listWorkers();

		$this->assertCount( 1, $workers );
		$this->assertSame( 1, $workers[0]['jobs_processed'] );
	}

	public function testMissingHeartbeatIsANoOp(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );
		$this->assertNull( $manager->getHeartbeat() );

		$worker = new Worker( $manager );
		$worker->setStopWhenEmpty( true );
		$worker->run( 'default' );

		$this->assertSame( 0, $worker->getJobsProcessed() );
	}

	public function testSetHeartbeatInjectsStore(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );
		$beats = new ArrayWorkerHeartbeat();
		$manager->setHeartbeat( $beats );

		$worker = new Worker( $manager );
		$worker->setStopWhenEmpty( true );
		$worker->run( 'default' );

		$this->assertCount( 1, $beats->listWorkers() );
	}
}
