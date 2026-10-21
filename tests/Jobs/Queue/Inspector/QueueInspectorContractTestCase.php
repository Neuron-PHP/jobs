<?php

namespace Tests\Jobs\Queue\Inspector;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\IQueue;
use Neuron\Jobs\Queue\IQueueInspector;
use Neuron\Jobs\Queue\JobState;
use Neuron\Jobs\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/**
 * Job used by the inspector contract tests.
 */
class InspectorTestJob implements IJob
{
	public function getName(): string
	{
		return 'InspectorTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

/**
 * Behaviour every IQueueInspector driver must share. Subclasses supply the
 * driver; the assertions are identical so the dashboard and CLI can treat
 * all stores the same way.
 */
abstract class QueueInspectorContractTestCase extends TestCase
{
	/** @var IQueue&IQueueInspector */
	protected IQueue $queue;

	/**
	 * @return IQueue&IQueueInspector
	 */
	abstract protected function createQueue(): IQueue;

	protected function setUp(): void
	{
		parent::setUp();

		$this->queue = $this->createQueue();

		$this->assertInstanceOf( IQueueInspector::class, $this->queue );
	}

	private function job(): IJob
	{
		return new InspectorTestJob();
	}

	public function testEmptyQueueListsAndCountsNothing(): void
	{
		foreach( JobState::cases() as $state )
		{
			$this->assertSame( [], $this->queue->listJobs( 'default', $state ) );
			$this->assertSame( 0, $this->queue->countJobs( 'default', $state ) );
		}

		$this->assertNull( $this->queue->findJob( 'missing' ) );
		$this->assertFalse( $this->queue->deleteJob( 'missing' ) );
		$this->assertFalse( $this->queue->releaseNow( 'missing' ) );
	}

	public function testClassifiesPendingDelayedAndReserved(): void
	{
		$pending = $this->queue->push( $this->job(), [ 'n' => 1 ] );
		$delayed = $this->queue->push( $this->job(), [ 'n' => 2 ], 'default', 3600 );
		$reservedCandidate = $this->queue->push( $this->job(), [ 'n' => 3 ] );

		$popped = $this->queue->pop();
		$this->assertNotNull( $popped );

		// Whichever pending job was popped is now reserved; the other stays pending.
		$reservedId = $popped->getId();
		$pendingId = $reservedId === $pending ? $reservedCandidate : $pending;

		$this->assertSame( 1, $this->queue->countJobs( 'default', JobState::Pending ) );
		$this->assertSame( 1, $this->queue->countJobs( 'default', JobState::Delayed ) );
		$this->assertSame( 1, $this->queue->countJobs( 'default', JobState::Reserved ) );

		$this->assertSame( [ $pendingId ], $this->ids( $this->queue->listJobs( 'default', JobState::Pending ) ) );
		$this->assertSame( [ $delayed ], $this->ids( $this->queue->listJobs( 'default', JobState::Delayed ) ) );
		$this->assertSame( [ $reservedId ], $this->ids( $this->queue->listJobs( 'default', JobState::Reserved ) ) );
	}

	public function testListedJobsCarryPayloadAndMetadata(): void
	{
		$id = $this->queue->push( $this->job(), [ 'email' => 'a@example.com' ], 'emails', 120 );

		$jobs = $this->queue->listJobs( 'emails', JobState::Delayed );

		$this->assertCount( 1, $jobs );
		$job = $jobs[0];

		$this->assertInstanceOf( QueuedJob::class, $job );
		$this->assertSame( $id, $job->getId() );
		$this->assertSame( 'emails', $job->getQueue() );
		$this->assertSame( InspectorTestJob::class, $job->getJobClass() );
		$this->assertSame( [ 'email' => 'a@example.com' ], $job->getArguments() );
		$this->assertSame( 0, $job->getAttempts() );
		$this->assertNull( $job->getReservedAt() );
		$this->assertGreaterThan( time() + 60, $job->getAvailableAt() );
	}

	public function testListingIsScopedToTheQueue(): void
	{
		$this->queue->push( $this->job(), [], 'a' );
		$this->queue->push( $this->job(), [], 'b' );

		$this->assertSame( 1, $this->queue->countJobs( 'a', JobState::Pending ) );
		$this->assertSame( 1, $this->queue->countJobs( 'b', JobState::Pending ) );
		$this->assertSame( 0, $this->queue->countJobs( 'c', JobState::Pending ) );
	}

	public function testPagination(): void
	{
		$ids = [];

		for( $i = 0; $i < 5; $i++ )
		{
			$ids[] = $this->queue->push( $this->job(), [ 'i' => $i ], 'default', 1000 + $i );
		}

		$this->assertCount( 2, $this->queue->listJobs( 'default', JobState::Delayed, 2, 0 ) );
		$this->assertCount( 2, $this->queue->listJobs( 'default', JobState::Delayed, 2, 2 ) );
		$this->assertCount( 1, $this->queue->listJobs( 'default', JobState::Delayed, 2, 4 ) );
		$this->assertCount( 0, $this->queue->listJobs( 'default', JobState::Delayed, 2, 5 ) );

		// Soonest first, and pages do not overlap.
		$all = $this->ids( $this->queue->listJobs( 'default', JobState::Delayed, 10, 0 ) );
		$this->assertSame( $ids, $all );

		$paged = array_merge(
			$this->ids( $this->queue->listJobs( 'default', JobState::Delayed, 2, 0 ) ),
			$this->ids( $this->queue->listJobs( 'default', JobState::Delayed, 2, 2 ) ),
			$this->ids( $this->queue->listJobs( 'default', JobState::Delayed, 2, 4 ) )
		);
		$this->assertSame( $ids, $paged );
	}

	public function testFindJobAcrossQueuesAndStates(): void
	{
		$a = $this->queue->push( $this->job(), [ 'q' => 'a' ], 'a' );
		$b = $this->queue->push( $this->job(), [ 'q' => 'b' ], 'b', 600 );

		$this->assertSame( 'a', $this->queue->findJob( $a )?->getQueue() );
		$this->assertSame( 'b', $this->queue->findJob( $b )?->getQueue() );

		$this->queue->pop( 'a' );

		$found = $this->queue->findJob( $a );
		$this->assertNotNull( $found );
		$this->assertNotNull( $found->getReservedAt() );
		$this->assertSame( 1, $found->getAttempts() );
	}

	public function testDeleteJobRemovesWaitingJobsOnly(): void
	{
		$pending = $this->queue->push( $this->job(), [ 'n' => 1 ] );
		$delayed = $this->queue->push( $this->job(), [ 'n' => 2 ], 'default', 600 );

		$this->assertTrue( $this->queue->deleteJob( $delayed ) );
		$this->assertNull( $this->queue->findJob( $delayed ) );
		$this->assertSame( 0, $this->queue->countJobs( 'default', JobState::Delayed ) );

		$popped = $this->queue->pop();
		$this->assertSame( $pending, $popped?->getId() );

		$this->assertFalse( $this->queue->deleteJob( $pending ), 'a reserved job cannot be deleted' );
		$this->assertNotNull( $this->queue->findJob( $pending ) );

		$this->assertFalse( $this->queue->deleteJob( $delayed ), 'already gone' );
	}

	public function testReleaseNowMakesADelayedJobPoppable(): void
	{
		$delayed = $this->queue->push( $this->job(), [ 'n' => 1 ], 'default', 3600 );

		$this->assertNull( $this->queue->pop(), 'not due yet' );

		$this->assertTrue( $this->queue->releaseNow( $delayed ) );
		$this->assertSame( 0, $this->queue->countJobs( 'default', JobState::Delayed ) );
		$this->assertSame( 1, $this->queue->countJobs( 'default', JobState::Pending ) );
		$this->assertLessThanOrEqual( time(), $this->queue->findJob( $delayed )?->getAvailableAt() );

		$popped = $this->queue->pop();
		$this->assertSame( $delayed, $popped?->getId() );
	}

	public function testReleaseNowOnPendingIsTrueAndOnReservedIsFalse(): void
	{
		$pending = $this->queue->push( $this->job() );

		$this->assertTrue( $this->queue->releaseNow( $pending ), 'already pending is a no-op success' );

		$this->queue->pop();

		$this->assertFalse( $this->queue->releaseNow( $pending ), 'reserved jobs are left alone' );
	}

	public function testFailedJobPagingAndLookup(): void
	{
		$ids = [];

		foreach( [ 'default', 'emails', 'default' ] as $i => $queue )
		{
			$id = $this->queue->push( $this->job(), [ 'i' => $i ], $queue );
			$job = $this->queue->pop( $queue );
			$this->assertSame( $id, $job?->getId() );
			$this->queue->failed( $job, new \RuntimeException( "boom {$i}" ) );
			$ids[] = $id;
		}

		$this->assertSame( 3, $this->queue->countFailedJobs() );
		$this->assertSame( 2, $this->queue->countFailedJobs( 'default' ) );
		$this->assertSame( 1, $this->queue->countFailedJobs( 'emails' ) );
		$this->assertSame( 0, $this->queue->countFailedJobs( 'nope' ) );

		$this->assertCount( 2, $this->queue->listFailedJobs( 2, 0 ) );
		$this->assertCount( 1, $this->queue->listFailedJobs( 2, 2 ) );
		$this->assertCount( 0, $this->queue->listFailedJobs( 2, 3 ) );

		$emails = $this->queue->listFailedJobs( 10, 0, 'emails' );
		$this->assertCount( 1, $emails );
		$this->assertSame( $ids[1], $emails[0]['id'] );

		$row = $this->queue->findFailedJob( $ids[2] );
		$this->assertNotNull( $row );
		$this->assertSame( 'default', $row['queue'] );
		$this->assertStringContainsString( 'boom 2', $row['exception'] );
		$this->assertArrayHasKey( 'payload', $row );
		$this->assertArrayHasKey( 'failed_at', $row );

		$this->assertNull( $this->queue->findFailedJob( 'missing' ) );

		// Same rows getFailedJobs() returns, just paged.
		$all = array_column( $this->queue->getFailedJobs(), 'id' );
		$paged = array_merge(
			array_column( $this->queue->listFailedJobs( 2, 0 ), 'id' ),
			array_column( $this->queue->listFailedJobs( 2, 2 ), 'id' )
		);
		$this->assertSame( $all, $paged );
	}

	/**
	 * @param QueuedJob[] $jobs
	 * @return string[]
	 */
	protected function ids( array $jobs ): array
	{
		return array_map( fn( QueuedJob $job ) => $job->getId(), $jobs );
	}
}
