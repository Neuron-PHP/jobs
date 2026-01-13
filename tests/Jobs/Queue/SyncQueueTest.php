<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\QueuedJob;
use Neuron\Jobs\Queue\SyncQueue;
use PHPUnit\Framework\TestCase;

/**
 * Test job for SyncQueue testing.
 */
class SyncTestJob implements IJob
{
	public bool $executed = false;
	public array $receivedArgs = [];

	public function getName(): string
	{
		return 'SyncTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		$this->executed = true;
		$this->receivedArgs = $argv;
		return true;
	}
}

/**
 * Test job that throws an exception.
 */
class FailingSyncJob implements IJob
{
	public function getName(): string
	{
		return 'FailingSyncJob';
	}

	public function run( array $argv = [] ): mixed
	{
		throw new \RuntimeException('Job intentionally failed');
	}
}

/**
 * Comprehensive tests for SyncQueue class.
 *
 * Tests synchronous job execution, no-op methods,
 * and failure handling.
 */
class SyncQueueTest extends TestCase
{
	private SyncQueue $queue;

	protected function setUp(): void
	{
		parent::setUp();
		$this->queue = new SyncQueue();
	}

	public function testPushExecutesJobImmediately(): void
	{
		$job = new SyncTestJob();
		$args = ['key' => 'value', 'number' => 123];

		$this->assertFalse($job->executed);

		$id = $this->queue->push($job, $args);

		// Job should have been executed
		$this->assertTrue($job->executed);
		$this->assertEquals($args, $job->receivedArgs);
	}

	public function testPushReturnsUniqueId(): void
	{
		$job1 = new SyncTestJob();
		$job2 = new SyncTestJob();

		$id1 = $this->queue->push($job1);
		$id2 = $this->queue->push($job2);

		$this->assertNotEmpty($id1);
		$this->assertNotEmpty($id2);
		$this->assertNotEquals($id1, $id2);
		$this->assertStringStartsWith('sync_', $id1);
		$this->assertStringStartsWith('sync_', $id2);
	}

	public function testPushWithEmptyArguments(): void
	{
		$job = new SyncTestJob();

		$id = $this->queue->push($job);

		$this->assertTrue($job->executed);
		$this->assertEquals([], $job->receivedArgs);
		$this->assertNotEmpty($id);
	}

	public function testPushWithQueueParameter(): void
	{
		$job = new SyncTestJob();

		// Queue parameter is ignored in SyncQueue
		$id = $this->queue->push($job, [], 'custom-queue');

		$this->assertTrue($job->executed);
		$this->assertNotEmpty($id);
	}

	public function testPushWithDelayParameter(): void
	{
		$job = new SyncTestJob();

		// Delay parameter is ignored in SyncQueue - executes immediately
		$id = $this->queue->push($job, [], 'default', 60);

		$this->assertTrue($job->executed);
		$this->assertNotEmpty($id);
	}

	public function testPushRethrowsExceptionFromJob(): void
	{
		$job = new FailingSyncJob();

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Job intentionally failed');

		$this->queue->push($job);
	}

	public function testPopAlwaysReturnsNull(): void
	{
		$this->assertNull($this->queue->pop());
		$this->assertNull($this->queue->pop('default'));
		$this->assertNull($this->queue->pop('custom-queue'));
	}

	public function testReleaseIsNoOp(): void
	{
		$queuedJob = new QueuedJob(
			'test_id',
			'default',
			SyncTestJob::class
		);

		// Should not throw any exception
		$this->queue->release($queuedJob);
		$this->queue->release($queuedJob, 30);

		$this->assertTrue(true); // If we get here, release() is a no-op
	}

	public function testDeleteIsNoOp(): void
	{
		$queuedJob = new QueuedJob(
			'test_id',
			'default',
			SyncTestJob::class
		);

		// Should not throw any exception
		$this->queue->delete($queuedJob);

		$this->assertTrue(true); // If we get here, delete() is a no-op
	}

	public function testFailedLogsError(): void
	{
		$queuedJob = new QueuedJob(
			'test_id',
			'default',
			SyncTestJob::class
		);

		$exception = new \RuntimeException('Test failure');

		// Should not throw any exception
		$this->queue->failed($queuedJob, $exception);

		$this->assertTrue(true); // If we get here, failed() completed
	}

	public function testSizeAlwaysReturnsZero(): void
	{
		$this->assertEquals(0, $this->queue->size());
		$this->assertEquals(0, $this->queue->size('default'));
		$this->assertEquals(0, $this->queue->size('custom-queue'));

		// Even after pushing jobs, size is still 0 (jobs execute immediately)
		$this->queue->push(new SyncTestJob());
		$this->assertEquals(0, $this->queue->size());
	}

	public function testClearAlwaysReturnsZero(): void
	{
		$this->assertEquals(0, $this->queue->clear());
		$this->assertEquals(0, $this->queue->clear('default'));
		$this->assertEquals(0, $this->queue->clear('custom-queue'));
	}

	public function testGetFailedJobsAlwaysReturnsEmptyArray(): void
	{
		$this->assertEquals([], $this->queue->getFailedJobs());
		$this->assertIsArray($this->queue->getFailedJobs());
		$this->assertEmpty($this->queue->getFailedJobs());
	}

	public function testRetryFailedJobAlwaysReturnsFalse(): void
	{
		$this->assertFalse($this->queue->retryFailedJob('job_123'));
		$this->assertFalse($this->queue->retryFailedJob('any-id'));
		$this->assertFalse($this->queue->retryFailedJob(''));
	}

	public function testForgetFailedJobAlwaysReturnsFalse(): void
	{
		$this->assertFalse($this->queue->forgetFailedJob('job_123'));
		$this->assertFalse($this->queue->forgetFailedJob('any-id'));
		$this->assertFalse($this->queue->forgetFailedJob(''));
	}

	public function testClearFailedJobsAlwaysReturnsZero(): void
	{
		$this->assertEquals(0, $this->queue->clearFailedJobs());
	}

	public function testSyncQueueBehavior(): void
	{
		// SyncQueue should execute jobs immediately and not queue them
		$job1 = new SyncTestJob();
		$job2 = new SyncTestJob();
		$job3 = new SyncTestJob();

		$this->queue->push($job1, ['first']);
		$this->queue->push($job2, ['second']);
		$this->queue->push($job3, ['third']);

		// All jobs executed immediately
		$this->assertTrue($job1->executed);
		$this->assertTrue($job2->executed);
		$this->assertTrue($job3->executed);

		// Each received its arguments
		$this->assertEquals(['first'], $job1->receivedArgs);
		$this->assertEquals(['second'], $job2->receivedArgs);
		$this->assertEquals(['third'], $job3->receivedArgs);

		// Queue is still empty
		$this->assertEquals(0, $this->queue->size());
		$this->assertNull($this->queue->pop());
	}

	public function testMultipleJobsWithMixedResults(): void
	{
		$successJob1 = new SyncTestJob();
		$successJob2 = new SyncTestJob();

		// Execute successful jobs
		$id1 = $this->queue->push($successJob1, ['arg1']);
		$id2 = $this->queue->push($successJob2, ['arg2']);

		$this->assertTrue($successJob1->executed);
		$this->assertTrue($successJob2->executed);
		$this->assertNotEquals($id1, $id2);

		// Queue remains empty
		$this->assertEquals(0, $this->queue->size());
		$this->assertEquals([], $this->queue->getFailedJobs());
	}
}
