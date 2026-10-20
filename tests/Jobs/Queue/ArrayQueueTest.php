<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\ArrayQueue;
use Neuron\Jobs\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class ArrayQueueTestJob implements IJob
{
	public function getName(): string
	{
		return 'ArrayQueueTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

class ArrayQueueTest extends TestCase
{
	private ArrayQueue $queue;

	protected function setUp(): void
	{
		parent::setUp();
		$this->queue = new ArrayQueue();
	}

	public function testPushStoresJobAndRecordsDispatch(): void
	{
		$id = $this->queue->push( new ArrayQueueTestJob(), [ 'a' => 1 ], 'emails' );

		$this->assertNotEmpty( $id );
		$this->assertEquals( 1, $this->queue->size( 'emails' ) );
		$this->assertEquals( 0, $this->queue->size( 'default' ) );
		$this->assertCount( 1, $this->queue->getDispatched() );
		$this->assertEquals( [ 'emails' ], $this->queue->getQueues() );

		$dispatched = $this->queue->getDispatched( ArrayQueueTestJob::class )[0];
		$this->assertEquals( [ 'a' => 1 ], $dispatched->getArguments() );
		$this->assertEquals( 'emails', $dispatched->getQueue() );
	}

	public function testGetDispatchedFiltersByClassAndToleratesLeadingBackslash(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );

		$this->assertCount( 1, $this->queue->getDispatched( '\\' . ArrayQueueTestJob::class ) );
		$this->assertCount( 0, $this->queue->getDispatched( 'Other\\Job' ) );
	}

	public function testPopReservesAndIncrementsAttempts(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );

		$job = $this->queue->pop();

		$this->assertNotNull( $job );
		$this->assertEquals( 1, $job->getAttempts() );
		$this->assertTrue( $job->isReserved() );
		$this->assertEquals( 0, $this->queue->size(), 'Reserved jobs are not counted as pending' );
		$this->assertNull( $this->queue->pop(), 'A reserved job is not handed out twice' );
	}

	public function testPopReclaimsStaleReservation(): void
	{
		$queue = new ArrayQueue( [ 'retry_after' => 90 ] );
		$queue->push( new ArrayQueueTestJob() );

		$first = $queue->pop();
		$this->assertNotNull( $first );

		// Simulate a worker that died mid-job: the reservation is older than
		// retry_after, so the next pop reclaims it (same rule as the other drivers).
		$first->setReservedAt( time() - 91 );

		$second = $queue->pop();
		$this->assertNotNull( $second );
		$this->assertSame( $first->getId(), $second->getId() );
		$this->assertEquals( 2, $second->getAttempts() );
	}

	public function testPopRespectsDelay(): void
	{
		$this->queue->push( new ArrayQueueTestJob(), [], 'default', 60 );

		$this->assertNull( $this->queue->pop() );
		$this->assertEquals( 1, $this->queue->size() );
	}

	public function testReleaseMakesJobAvailableAgain(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );
		$job = $this->queue->pop();

		$this->queue->release( $job );

		$this->assertEquals( 1, $this->queue->size() );
		$again = $this->queue->pop();
		$this->assertSame( $job->getId(), $again->getId() );
		$this->assertEquals( 2, $again->getAttempts() );
	}

	public function testReleaseWithDelayHoldsJob(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );
		$job = $this->queue->pop();

		$this->queue->release( $job, 120 );

		$this->assertNull( $this->queue->pop() );
	}

	public function testDeleteRemovesJob(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );
		$job = $this->queue->pop();

		$this->queue->delete( $job );

		$this->assertEmpty( $this->queue->getPending() );
		$this->assertCount( 1, $this->queue->getDispatched(), 'Dispatch record survives processing' );
	}

	public function testFailedMovesJobToFailedStorage(): void
	{
		$this->queue->push( new ArrayQueueTestJob(), [ 'x' => 1 ] );
		$job = $this->queue->pop();

		$this->queue->failed( $job, new \RuntimeException( 'boom' ) );

		$this->assertEmpty( $this->queue->getPending() );

		$failed = $this->queue->getFailedJobs();
		$this->assertCount( 1, $failed );
		$this->assertEquals( $job->getId(), $failed[0]['id'] );
		$this->assertEquals( 'default', $failed[0]['queue'] );
		$this->assertStringContainsString( 'boom', $failed[0]['exception'] );
	}

	public function testRetryFailedJobRequeuesWithFreshAttempts(): void
	{
		$this->queue->push( new ArrayQueueTestJob(), [ 'x' => 1 ] );
		$job = $this->queue->pop();
		$this->queue->failed( $job, new \RuntimeException( 'boom' ) );

		$this->assertTrue( $this->queue->retryFailedJob( $job->getId() ) );
		$this->assertFalse( $this->queue->retryFailedJob( $job->getId() ) );
		$this->assertEmpty( $this->queue->getFailedJobs() );

		$retried = $this->queue->pop();
		$this->assertNotNull( $retried );
		$this->assertEquals( 1, $retried->getAttempts() );
		$this->assertEquals( [ 'x' => 1 ], $retried->getArguments() );
	}

	public function testForgetAndClearFailedJobs(): void
	{
		foreach( [ 1, 2 ] as $i )
		{
			$this->queue->push( new ArrayQueueTestJob(), [ 'i' => $i ] );
			$this->queue->failed( $this->queue->pop(), new \RuntimeException( 'boom' ) );
		}

		$ids = array_column( $this->queue->getFailedJobs(), 'id' );

		$this->assertTrue( $this->queue->forgetFailedJob( $ids[0] ) );
		$this->assertFalse( $this->queue->forgetFailedJob( $ids[0] ) );
		$this->assertEquals( 1, $this->queue->clearFailedJobs() );
		$this->assertEquals( 0, $this->queue->clearFailedJobs() );
	}

	public function testClearEmptiesOneQueue(): void
	{
		$this->queue->push( new ArrayQueueTestJob(), [], 'a' );
		$this->queue->push( new ArrayQueueTestJob(), [], 'a' );
		$this->queue->push( new ArrayQueueTestJob(), [], 'b' );

		$this->assertEquals( 2, $this->queue->clear( 'a' ) );
		$this->assertEquals( 0, $this->queue->size( 'a' ) );
		$this->assertEquals( 1, $this->queue->size( 'b' ) );
	}

	public function testResetForgetsEverything(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );
		$this->queue->failed( $this->queue->pop(), new \RuntimeException( 'boom' ) );
		$this->queue->push( new ArrayQueueTestJob() );

		$this->queue->reset();

		$this->assertEmpty( $this->queue->getDispatched() );
		$this->assertEmpty( $this->queue->getFailedJobs() );
		$this->assertEquals( 0, $this->queue->size() );
	}

	public function testGetStatsSplitsPendingDelayedAndReserved(): void
	{
		$this->assertInstanceOf( \Neuron\Jobs\Queue\IQueueStats::class, $this->queue );

		$this->assertEquals(
			[ 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age' => null ],
			$this->queue->getStats()
		);

		$this->queue->push( new ArrayQueueTestJob() );            // pending
		$this->queue->push( new ArrayQueueTestJob() );            // will be reserved
		$this->queue->push( new ArrayQueueTestJob(), [], 'default', 600 ); // delayed
		$this->queue->pop();

		$stats = $this->queue->getStats();

		$this->assertEquals( 1, $stats['pending'] );
		$this->assertEquals( 1, $stats['delayed'] );
		$this->assertEquals( 1, $stats['reserved'] );
		$this->assertIsInt( $stats['oldest_pending_age'] );
		$this->assertLessThanOrEqual( 1, $stats['oldest_pending_age'] );
	}

	public function testGetStatsReportsOldestPendingAge(): void
	{
		$this->queue->push( new ArrayQueueTestJob() );
		$this->queue->push( new ArrayQueueTestJob() );

		// Backdate one job's availability: it has been waiting 90s.
		$this->queue->getPending()[0]->setAvailableAt( time() - 90 );

		$this->assertEqualsWithDelta( 90, $this->queue->getStats()['oldest_pending_age'], 1 );
	}

	public function testQueueManagerSelectsArrayDriver(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array', 'retry_after' => 5 ] );

		$this->assertInstanceOf( ArrayQueue::class, $manager->getDriver() );
	}

	public function testQueueManagerProcessesThroughArrayDriver(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array', 'max_attempts' => 1, 'backoff' => 0 ] );
		$manager->dispatch( new ArrayQueueTestJob(), [ 'k' => 'v' ] );

		$this->assertTrue( $manager->processNextJob() );
		$this->assertFalse( $manager->processNextJob() );
		$this->assertEquals( 0, $manager->size() );
	}
}
