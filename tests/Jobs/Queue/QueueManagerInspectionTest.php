<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\ArrayQueue;
use Neuron\Jobs\Queue\JobState;
use Neuron\Jobs\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class InspectionManagerJob implements IJob
{
	public function getName(): string
	{
		return 'InspectionManagerJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

/**
 * QueueManager's inspection facade: passthroughs for inspecting drivers,
 * in-memory failed-job paging for the rest, and a clear error for
 * operations a driver cannot do.
 */
class QueueManagerInspectionTest extends TestCase
{
	private function arrayManager(): QueueManager
	{
		return new QueueManager( null, [ 'driver' => 'array', 'default_queue' => 'default', 'max_attempts' => 1 ] );
	}

	private function syncManager(): QueueManager
	{
		return new QueueManager( null, [ 'driver' => 'sync', 'default_queue' => 'default' ] );
	}

	public function testSupportsInspectionReflectsDriver(): void
	{
		$this->assertTrue( $this->arrayManager()->supportsInspection() );
		$this->assertFalse( $this->syncManager()->supportsInspection() );
	}

	public function testGetDriverName(): void
	{
		$this->assertSame( 'array', $this->arrayManager()->getDriverName() );
		$this->assertSame( 'sync', $this->syncManager()->getDriverName() );
	}

	public function testPassthroughsUseDefaultQueueWhenNull(): void
	{
		$manager = $this->arrayManager();

		$id = $manager->dispatch( new InspectionManagerJob(), [ 'k' => 'v' ], null, 600 );

		$this->assertSame( 1, $manager->countJobs( null, JobState::Delayed ) );
		$this->assertSame( [ $id ], array_map( fn( $j ) => $j->getId(), $manager->listJobs( null, JobState::Delayed ) ) );
		$this->assertSame( 'default', $manager->findJob( $id )?->getQueue() );

		$this->assertTrue( $manager->releaseNow( $id ) );
		$this->assertSame( 1, $manager->countJobs( null, JobState::Pending ) );

		$this->assertTrue( $manager->deleteJob( $id ) );
		$this->assertNull( $manager->findJob( $id ) );
	}

	public function testInspectionOperationsThrowForNonInspectingDriver(): void
	{
		$manager = $this->syncManager();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( "'sync' queue driver does not support job inspection" );

		$manager->listJobs( null, JobState::Pending );
	}

	public function testFailedJobPagingFallsBackToInMemoryForNonInspectingDriver(): void
	{
		// A driver that stores failed jobs but is not an inspector: fake one
		// around the manager by mocking getFailedJobs() on a bare IQueue.
		$driver = $this->createMock( \Neuron\Jobs\Queue\IQueue::class );
		$driver->method( 'getFailedJobs' )->willReturn( [
			[ 'id' => 'f1', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => 3 ],
			[ 'id' => 'f2', 'queue' => 'emails', 'payload' => '{}', 'exception' => 'y', 'failed_at' => 2 ],
			[ 'id' => 'f3', 'queue' => 'default', 'payload' => '{}', 'exception' => 'z', 'failed_at' => 1 ],
		] );

		$manager = $this->syncManager();

		$property = new \ReflectionProperty( QueueManager::class, '_driver' );
		$property->setValue( $manager, $driver );

		$this->assertFalse( $manager->supportsInspection() );

		$this->assertSame( 3, $manager->countFailedJobs() );
		$this->assertSame( 2, $manager->countFailedJobs( 'default' ) );

		$this->assertSame( [ 'f2' ], array_column( $manager->listFailedJobs( 1, 1 ), 'id' ) );
		$this->assertSame( [ 'f1', 'f3' ], array_column( $manager->listFailedJobs( 10, 0, 'default' ), 'id' ) );

		$this->assertSame( 'emails', $manager->findFailedJob( 'f2' )['queue'] ?? null );
		$this->assertNull( $manager->findFailedJob( 'nope' ) );
	}

	public function testFailedJobPagingDelegatesToInspectingDriver(): void
	{
		$manager = $this->arrayManager();

		$manager->dispatch( new InspectionManagerJob(), [], 'emails' );

		/** @var ArrayQueue $driver */
		$driver = $manager->getDriver();
		$job = $driver->pop( 'emails' );
		$driver->failed( $job, new \RuntimeException( 'nope' ) );

		$this->assertSame( 1, $manager->countFailedJobs() );
		$this->assertSame( 1, $manager->countFailedJobs( 'emails' ) );
		$this->assertSame( 0, $manager->countFailedJobs( 'default' ) );
		$this->assertSame( $job->getId(), $manager->listFailedJobs()[0]['id'] );
		$this->assertSame( 'emails', $manager->findFailedJob( $job->getId() )['queue'] );
	}
}
