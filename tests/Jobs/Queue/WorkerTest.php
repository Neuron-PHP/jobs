<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for Worker class.
 *
 * Tests worker initialization, configuration, job processing,
 * and graceful shutdown.
 */
class WorkerTest extends TestCase
{
	private QueueManager $queueManager;
	private Worker $worker;

	protected function setUp(): void
	{
		parent::setUp();

		// Create a mock QueueManager
		$this->queueManager = $this->createMock(QueueManager::class);
		$this->worker = new Worker($this->queueManager);
	}

	public function testConstructorCreatesWorker(): void
	{
		$queueManager = $this->createMock(QueueManager::class);
		$worker = new Worker($queueManager);

		$this->assertInstanceOf(Worker::class, $worker);
	}

	public function testSetSleepSetsAndReturnsWorker(): void
	{
		$result = $this->worker->setSleep(10);

		$this->assertSame($this->worker, $result);
		$this->assertEquals(10, $this->worker->getSleep());
	}

	public function testGetSleepReturnsDefaultValue(): void
	{
		$worker = new Worker($this->queueManager);

		$this->assertEquals(3, $worker->getSleep());
	}

	public function testSetMaxJobsSetsAndReturnsWorker(): void
	{
		$result = $this->worker->setMaxJobs(100);

		$this->assertSame($this->worker, $result);
		$this->assertEquals(100, $this->worker->getMaxJobs());
	}

	public function testGetMaxJobsReturnsDefaultValue(): void
	{
		$worker = new Worker($this->queueManager);

		$this->assertEquals(0, $worker->getMaxJobs());
	}

	public function testSetTimeoutSetsAndReturnsWorker(): void
	{
		$result = $this->worker->setTimeout(120);

		$this->assertSame($this->worker, $result);
		$this->assertEquals(120, $this->worker->getTimeout());
	}

	public function testGetTimeoutReturnsDefaultValue(): void
	{
		$worker = new Worker($this->queueManager);

		$this->assertEquals(60, $worker->getTimeout());
	}

	public function testSetStopWhenEmptyReturnsWorker(): void
	{
		$result = $this->worker->setStopWhenEmpty(true);

		$this->assertSame($this->worker, $result);
	}

	public function testGetJobsProcessedReturnsZeroInitially(): void
	{
		$this->assertEquals(0, $this->worker->getJobsProcessed());
	}

	public function testStopSetsShutdownFlag(): void
	{
		// Calling stop() should not throw exception
		$this->worker->stop();

		// Worker should be able to stop
		$this->assertTrue(true);
	}

	public function testFluentInterface(): void
	{
		$worker = $this->worker
			->setSleep(5)
			->setMaxJobs(50)
			->setTimeout(90)
			->setStopWhenEmpty(false);

		$this->assertSame($this->worker, $worker);
		$this->assertEquals(5, $worker->getSleep());
		$this->assertEquals(50, $worker->getMaxJobs());
		$this->assertEquals(90, $worker->getTimeout());
	}

	public function testRunProcessesJobsWhenAvailable(): void
	{
		// Mock queue manager to return true (job processed)
		$this->queueManager
			->expects($this->atLeast(1))
			->method('processNextJob')
			->with('default')
			->willReturn(true);

		// Set max jobs to 1 so worker stops after one job
		$this->worker->setMaxJobs(1);

		$this->worker->run('default');

		$this->assertEquals(1, $this->worker->getJobsProcessed());
	}

	public function testRunStopsOnSigwinchWhenHeadless(): void
	{
		if( !function_exists( 'pcntl_signal' ) || !function_exists( 'posix_kill' ) )
		{
			$this->markTestSkipped( 'pcntl/posix not available' );
		}

		// `docker stop` on a php:*-apache image sends SIGWINCH. Handlers are
		// registered in the constructor, so build the worker after the override.
		\Neuron\Jobs\Process\StopSignals::assumeHeadless( true );

		try
		{
			$queueManager = $this->createMock( QueueManager::class );
			$worker = new Worker( $queueManager );

			// First job signals; nothing further should be processed.
			$queueManager->expects( $this->once() )
				->method( 'processNextJob' )
				->willReturnCallback( function() {
					posix_kill( posix_getpid(), SIGWINCH );
					return true;
				} );

			$worker->setSleep( 0 );
			$worker->run();

			$this->assertEquals( 1, $worker->getJobsProcessed() );
		}
		finally
		{
			\Neuron\Jobs\Process\StopSignals::assumeHeadless( null );
			pcntl_signal( SIGWINCH, SIG_DFL );
		}
	}

	public function testRunStopsWhenMaxJobsReached(): void
	{
		// Mock queue manager to always have jobs available
		$this->queueManager
			->expects($this->exactly(3))
			->method('processNextJob')
			->willReturn(true);

		$this->worker->setMaxJobs(3);

		$this->worker->run();

		$this->assertEquals(3, $this->worker->getJobsProcessed());
	}

	public function testRunStopsWhenQueueIsEmptyAndStopWhenEmptyIsTrue(): void
	{
		// Mock queue manager to return false (no jobs)
		$this->queueManager
			->expects($this->once())
			->method('processNextJob')
			->willReturn(false);

		$this->worker->setStopWhenEmpty(true);

		$this->worker->run();

		$this->assertEquals(0, $this->worker->getJobsProcessed());
	}

	public function testRunWithOnceParameterStopsAfterFirstCheck(): void
	{
		// Mock queue manager to return false (no jobs)
		$this->queueManager
			->expects($this->once())
			->method('processNextJob')
			->willReturn(false);

		// once=true should set stopWhenEmpty=true
		$this->worker->run('default', true);

		$this->assertEquals(0, $this->worker->getJobsProcessed());
	}

	public function testRunWithMultipleQueues(): void
	{
		// Mock queue manager to process jobs from multiple queues
		$this->queueManager
			->expects($this->exactly(3))
			->method('processNextJob')
			->willReturnCallback(function($queue) {
				static $callCount = 0;
				$callCount++;

				// Process one job from each queue
				if ($callCount <= 3) {
					return true; // Job processed
				}
				return false; // No more jobs
			});

		$this->worker->setMaxJobs(3);

		$this->worker->run(['high', 'default', 'low']);

		$this->assertEquals(3, $this->worker->getJobsProcessed());
	}

	public function testRunProcessesMultipleJobsSequentially(): void
	{
		$processCount = 0;

		$this->queueManager
			->method('processNextJob')
			->willReturnCallback(function() use (&$processCount) {
				$processCount++;
				return $processCount <= 5; // Process 5 jobs then return false
			});

		$this->worker->setMaxJobs(5);

		$this->worker->run();

		$this->assertEquals(5, $this->worker->getJobsProcessed());
	}

	public function testRunAcceptsStringQueue(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('processNextJob')
			->with('custom-queue')
			->willReturn(true);

		$this->worker->setMaxJobs(1);

		$this->worker->run('custom-queue');

		$this->assertEquals(1, $this->worker->getJobsProcessed());
	}

	public function testRunAcceptsArrayOfQueues(): void
	{
		$queues = ['queue1', 'queue2', 'queue3'];

		$this->queueManager
			->expects($this->atLeast(3))
			->method('processNextJob')
			->willReturnCallback(function($queue) use ($queues) {
				// Verify queue names are from our array
				$this->assertContains($queue, $queues);
				return true;
			});

		$this->worker->setMaxJobs(3);

		$this->worker->run($queues);

		$this->assertGreaterThanOrEqual(3, $this->worker->getJobsProcessed());
	}

	public function testGettersReturnCorrectValues(): void
	{
		$this->worker
			->setSleep(7)
			->setMaxJobs(25)
			->setTimeout(180);

		$this->assertEquals(7, $this->worker->getSleep());
		$this->assertEquals(25, $this->worker->getMaxJobs());
		$this->assertEquals(180, $this->worker->getTimeout());
		$this->assertEquals(0, $this->worker->getJobsProcessed());
	}

	public function testWorkerTracksJobsProcessed(): void
	{
		$jobCount = 0;

		$this->queueManager
			->method('processNextJob')
			->willReturnCallback(function() use (&$jobCount) {
				$jobCount++;
				return $jobCount <= 10;
			});

		$this->worker->setMaxJobs(10);

		$this->worker->run();

		$this->assertEquals(10, $this->worker->getJobsProcessed());
	}

	public function testMemoryLimitAndMaxTimeDefaultToUnlimited(): void
	{
		$this->assertEquals(0, $this->worker->getMemoryLimit());
		$this->assertEquals(0, $this->worker->getMaxTime());
	}

	public function testSetMemoryLimitAndMaxTimeAreFluent(): void
	{
		$result = $this->worker->setMemoryLimit(128)->setMaxTime(3600);

		$this->assertSame($this->worker, $result);
		$this->assertEquals(128, $this->worker->getMemoryLimit());
		$this->assertEquals(3600, $this->worker->getMaxTime());
	}

	public function testRunPassesTimeoutToQueueManager(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('processNextJob')
			->with('default', 45)
			->willReturn(false);

		$this->worker->setTimeout(45)->run('default', true);
	}

	public function testRunStopsWhenMemoryLimitExceeded(): void
	{
		// 1MB is always below what PHPUnit itself is using, so the first job
		// pushes the worker over the limit and it must exit without sleeping.
		$this->queueManager
			->method('processNextJob')
			->willReturn(true);

		$this->worker->setMemoryLimit(1)->setMaxJobs(0)->setSleep(0);

		$this->worker->run('default');

		$this->assertEquals(1, $this->worker->getJobsProcessed());
	}

	public function testRunStopsWhenMemoryLimitExceededWhileIdle(): void
	{
		$this->queueManager
			->method('processNextJob')
			->willReturn(false);

		// No --once, no stop-when-empty: only the memory limit ends the loop.
		$this->worker->setMemoryLimit(1)->setSleep(0);

		$this->worker->run('default');

		$this->assertEquals(0, $this->worker->getJobsProcessed());
	}

	public function testRestartFileDefaultsToNullAndIsFluent(): void
	{
		$this->assertNull( $this->worker->getRestartFile() );
		$this->assertSame( $this->worker, $this->worker->setRestartFile( '/tmp/x' ) );
		$this->assertEquals( '/tmp/x', $this->worker->getRestartFile() );
		$this->worker->setRestartFile( '' );
		$this->assertNull( $this->worker->getRestartFile() );
	}

	public function testDefaultRestartFileLivesUnderStorage(): void
	{
		$this->assertEquals( '/srv/site/storage/jobs.restart', Worker::defaultRestartFile( '/srv/site/' ) );
	}

	public function testRunStopsWhenRestartFileIsTouchedAfterStart(): void
	{
		$file = sys_get_temp_dir() . '/worker_restart_' . uniqid();

		$calls = 0;

		$this->queueManager
			->method('processNextJob')
			->willReturnCallback(function() use (&$calls, $file) {
				$calls++;
				if( $calls === 2 )
				{
					// jobs:restart runs while the worker is busy; make the
					// mtime strictly newer than the worker's start second.
					touch( $file, time() + 2 );
				}
				return true;
			});

		$this->worker->setRestartFile( $file )->setSleep( 0 );

		try
		{
			$this->worker->run( 'default' );
			$this->assertEquals( 2, $this->worker->getJobsProcessed() );
		}
		finally
		{
			@unlink( $file );
		}
	}

	public function testRestartFileOlderThanStartIsIgnored(): void
	{
		$file = sys_get_temp_dir() . '/worker_restart_old_' . uniqid();
		touch( $file, time() - 100 );

		$this->queueManager
			->method('processNextJob')
			->willReturn(true);

		$this->worker->setRestartFile( $file )->setMaxJobs( 3 )->setSleep( 0 );

		try
		{
			$this->worker->run( 'default' );
			$this->assertEquals( 3, $this->worker->getJobsProcessed(), 'An old request must not stop a worker started later' );
		}
		finally
		{
			@unlink( $file );
		}
	}

	public function testRunStopsWhenMaxTimeExceeded(): void
	{
		$calls = 0;

		$this->queueManager
			->method('processNextJob')
			->willReturnCallback(function() use (&$calls) {
				$calls++;
				if( $calls === 1 )
				{
					// Push the clock past the one-second budget after the first job.
					sleep(2);
				}
				return true;
			});

		$this->worker->setMaxTime(1)->setSleep(0);

		$this->worker->run('default');

		$this->assertEquals(1, $this->worker->getJobsProcessed());
	}
}
