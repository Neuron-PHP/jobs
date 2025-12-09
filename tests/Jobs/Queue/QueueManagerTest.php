<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\IQueue;
use Neuron\Jobs\Queue\QueuedJob;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\SyncQueue;
use PHPUnit\Framework\TestCase;

/**
 * Test job for QueueManager testing.
 */
class ManagerTestJob implements IJob
{
	public bool $executed = false;
	public array $receivedArgs = [];

	public function getName(): string
	{
		return 'ManagerTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		$this->executed = true;
		$this->receivedArgs = $argv;
		return true;
	}
}

/**
 * Failing test job for QueueManager testing.
 */
class FailingManagerJob implements IJob
{
	public function getName(): string
	{
		return 'FailingManagerJob';
	}

	public function run( array $argv = [] ): mixed
	{
		throw new \RuntimeException('Manager test job failed');
	}
}

/**
 * Comprehensive tests for QueueManager class.
 *
 * Tests job dispatching, processing, retries, failed job handling,
 * and queue driver management.
 */
class QueueManagerTest extends TestCase
{
	public function testConstructorWithNoParametersUsesDefaultConfig(): void
	{
		$manager = new QueueManager();

		$this->assertInstanceOf(QueueManager::class, $manager);
		$this->assertInstanceOf(SyncQueue::class, $manager->getDriver());

		$config = $manager->getConfig();
		$this->assertEquals('sync', $config['driver']);
		$this->assertEquals('default', $config['default_queue']);
		$this->assertEquals(90, $config['retry_after']);
		$this->assertEquals(3, $config['max_attempts']);
		$this->assertEquals(0, $config['backoff']);
	}

	public function testConstructorWithConfigArray(): void
	{
		$config = [
			'driver' => 'sync',
			'default_queue' => 'custom',
			'retry_after' => 120,
			'max_attempts' => 5,
			'backoff' => 10
		];

		$manager = new QueueManager(null, $config);

		$this->assertEquals($config, $manager->getConfig());
		$this->assertInstanceOf(SyncQueue::class, $manager->getDriver());
	}

	public function testConstructorCreatesSyncDriver(): void
	{
		$config = ['driver' => 'sync'];

		$manager = new QueueManager(null, $config);

		$this->assertInstanceOf(SyncQueue::class, $manager->getDriver());
	}

	public function testConstructorWithInvalidDriverThrowsException(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unsupported queue driver: invalid');

		$config = ['driver' => 'invalid'];

		new QueueManager(null, $config);
	}

	public function testDispatchReturnsJobId(): void
	{
		$manager = new QueueManager();
		$job = new ManagerTestJob();

		$jobId = $manager->dispatch($job, ['arg1' => 'value1'], 'emails', 0);

		$this->assertNotEmpty($jobId);
		$this->assertIsString($jobId);
	}

	public function testDispatchExecutesJobWithSyncQueue(): void
	{
		$manager = new QueueManager();
		$job = new ManagerTestJob();

		$this->assertFalse($job->executed);

		$manager->dispatch($job, ['test' => 'data']);

		// SyncQueue executes immediately
		$this->assertTrue($job->executed);
		$this->assertEquals(['test' => 'data'], $job->receivedArgs);
	}

	public function testDispatchUsesDefaultQueue(): void
	{
		$config = ['driver' => 'sync', 'default_queue' => 'custom-default'];
		$manager = new QueueManager(null, $config);
		$job = new ManagerTestJob();

		// Should use default queue when null is passed
		$jobId = $manager->dispatch($job, [], null);

		$this->assertNotEmpty($jobId);
	}

	public function testDispatchNowExecutesJobImmediately(): void
	{
		$manager = new QueueManager();
		$job = new ManagerTestJob();
		$args = ['key' => 'value'];

		$this->assertFalse($job->executed);

		$result = $manager->dispatchNow($job, $args);

		$this->assertTrue($job->executed);
		$this->assertEquals($args, $job->receivedArgs);
		$this->assertTrue($result);
	}

	public function testProcessNextJobReturnsFalseWhenQueueIsEmpty(): void
	{
		// SyncQueue always returns false from processNextJob since it executes immediately
		$manager = new QueueManager();

		$result = $manager->processNextJob();

		$this->assertFalse($result);
	}

	public function testSizeReturnsZeroForSyncQueue(): void
	{
		$manager = new QueueManager();

		$size = $manager->size();

		$this->assertEquals(0, $size);
	}

	public function testSizeUsesDefaultQueue(): void
	{
		$config = ['driver' => 'sync', 'default_queue' => 'my-default'];
		$manager = new QueueManager(null, $config);

		$size = $manager->size();

		$this->assertEquals(0, $size);
	}

	public function testSizeAcceptsQueueName(): void
	{
		$manager = new QueueManager();

		$size = $manager->size('custom-queue');

		$this->assertEquals(0, $size);
	}

	public function testClearReturnsZeroForSyncQueue(): void
	{
		$manager = new QueueManager();

		$cleared = $manager->clear();

		$this->assertEquals(0, $cleared);
	}

	public function testClearAcceptsQueueName(): void
	{
		$manager = new QueueManager();

		$cleared = $manager->clear('test-queue');

		$this->assertEquals(0, $cleared);
	}

	public function testGetFailedJobsReturnsEmptyArrayForSyncQueue(): void
	{
		$manager = new QueueManager();

		$failedJobs = $manager->getFailedJobs();

		$this->assertEquals([], $failedJobs);
		$this->assertIsArray($failedJobs);
	}

	public function testRetryFailedJobReturnsFalseForSyncQueue(): void
	{
		$manager = new QueueManager();

		$result = $manager->retryFailedJob('job_123');

		$this->assertFalse($result);
	}

	public function testRetryAllFailedJobsReturnsZeroWhenNoFailedJobs(): void
	{
		$manager = new QueueManager();

		$count = $manager->retryAllFailedJobs();

		$this->assertEquals(0, $count);
	}

	public function testForgetFailedJobReturnsFalseForSyncQueue(): void
	{
		$manager = new QueueManager();

		$result = $manager->forgetFailedJob('job_456');

		$this->assertFalse($result);
	}

	public function testClearFailedJobsReturnsZeroForSyncQueue(): void
	{
		$manager = new QueueManager();

		$count = $manager->clearFailedJobs();

		$this->assertEquals(0, $count);
	}

	public function testGetDriverReturnsDriverInstance(): void
	{
		$manager = new QueueManager();

		$driver = $manager->getDriver();

		$this->assertInstanceOf(IQueue::class, $driver);
		$this->assertInstanceOf(SyncQueue::class, $driver);
	}

	public function testGetConfigReturnsConfiguration(): void
	{
		$config = [
			'driver' => 'sync',
			'default_queue' => 'test',
			'max_attempts' => 5,
			'retry_after' => 120,
			'backoff' => 10
		];

		$manager = new QueueManager(null, $config);

		$result = $manager->getConfig();

		$this->assertEquals($config, $result);
	}

	public function testConfigurationDefaults(): void
	{
		$config = ['driver' => 'sync'];

		$manager = new QueueManager(null, $config);
		$actualConfig = $manager->getConfig();

		$this->assertEquals('sync', $actualConfig['driver']);
	}

	public function testDispatchWithDelay(): void
	{
		$manager = new QueueManager();
		$job = new ManagerTestJob();

		$jobId = $manager->dispatch($job, [], 'default', 60);

		$this->assertNotEmpty($jobId);
		// SyncQueue executes immediately regardless of delay
		$this->assertTrue($job->executed);
	}

	public function testMultipleDispatchCalls(): void
	{
		$manager = new QueueManager();

		$job1 = new ManagerTestJob();
		$job2 = new ManagerTestJob();
		$job3 = new ManagerTestJob();

		$id1 = $manager->dispatch($job1, ['first']);
		$id2 = $manager->dispatch($job2, ['second']);
		$id3 = $manager->dispatch($job3, ['third']);

		$this->assertNotEquals($id1, $id2);
		$this->assertNotEquals($id2, $id3);
		$this->assertNotEquals($id1, $id3);

		// All should be executed (SyncQueue)
		$this->assertTrue($job1->executed);
		$this->assertTrue($job2->executed);
		$this->assertTrue($job3->executed);
	}

	public function testDispatchNowWithNoArguments(): void
	{
		$manager = new QueueManager();
		$job = new ManagerTestJob();

		$result = $manager->dispatchNow($job);

		$this->assertTrue($job->executed);
		$this->assertEquals([], $job->receivedArgs);
	}
}
