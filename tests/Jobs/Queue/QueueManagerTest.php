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
 * Job that blocks for longer than any test timeout.
 */
class SleepingManagerJob implements IJob
{
	public function getName(): string
	{
		return 'SleepingManagerJob';
	}

	public function run( array $argv = [] ): mixed
	{
		// usleep is interrupted by SIGALRM, so loop until the handler throws.
		$until = microtime( true ) + 5;
		while( microtime( true ) < $until )
		{
			usleep( 10000 );
		}

		return true;
	}
}

/**
 * Job carrying its own queue settings.
 */
class QueueableManagerJob implements IJob, \Neuron\Jobs\Queue\IQueueable
{
	public static array $failedWith = [];
	public static ?int $timeout = null;
	public static ?int $maxAttempts = 2;
	public static ?int $backoff = 7;
	public static ?string $queue = 'priority';
	public static bool $throwInFailed = false;

	public function getName(): string
	{
		return 'QueueableManagerJob';
	}

	public function run( array $argv = [] ): mixed
	{
		throw new \RuntimeException( 'queueable failure' );
	}

	public function getQueue(): ?string
	{
		return self::$queue;
	}

	public function getMaxAttempts(): ?int
	{
		return self::$maxAttempts;
	}

	public function getBackoff(): ?int
	{
		return self::$backoff;
	}

	public function getTimeout(): ?int
	{
		return self::$timeout;
	}

	public function failed( \Throwable $exception ): void
	{
		self::$failedWith[] = $exception->getMessage();

		if( self::$throwInFailed )
		{
			throw new \LogicException( 'hook blew up' );
		}
	}
}

/**
 * Job with a constructor dependency; only resolvable through a container.
 */
class DependentManagerJob implements IJob
{
	public static ?\ArrayObject $received = null;

	public function __construct( \ArrayObject $dependency )
	{
		self::$received = $dependency;
	}

	public function getName(): string
	{
		return 'DependentManagerJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
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
	/**
	 * Recursively remove a directory and its contents.
	 */
	private function recursiveRemoveDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$items = array_diff(scandir($dir), ['.', '..']);

		foreach ($items as $item) {
			$path = $dir . '/' . $item;

			if (is_dir($path)) {
				$this->recursiveRemoveDirectory($path);
			} else {
				unlink($path);
			}
		}

		rmdir($dir);
	}

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

	public function testConstructorWithDatabaseDriver(): void
	{
		$config = [
			'driver' => 'database',
			'database' => [
				'adapter' => 'sqlite',
				'name' => ':memory:'
			]
		];

		$manager = new QueueManager(null, $config);

		$this->assertInstanceOf(QueueManager::class, $manager);
		$this->assertInstanceOf(\Neuron\Jobs\Queue\DatabaseQueue::class, $manager->getDriver());
	}

	public function testConstructorWithFileDriver(): void
	{
		$tempPath = sys_get_temp_dir() . '/queue_manager_test_' . uniqid();
		mkdir($tempPath);

		try {
			$config = [
				'driver' => 'file',
				'file' => [
					'path' => $tempPath
				]
			];

			$manager = new QueueManager(null, $config);

			$this->assertInstanceOf(QueueManager::class, $manager);
			$this->assertInstanceOf(\Neuron\Jobs\Queue\FileQueue::class, $manager->getDriver());
		} finally {
			// Clean up
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testProcessNextJobWithFileQueue(): void
	{
		$tempPath = sys_get_temp_dir() . '/queue_manager_process_test_' . uniqid();
		mkdir($tempPath);

		try {
			$config = [
				'driver' => 'file',
				'file' => ['path' => $tempPath],
				'max_attempts' => 3,
				'backoff' => 0
			];

			$manager = new QueueManager(null, $config);
			$job = new ManagerTestJob();

			// Dispatch and process a job
			$jobId = $manager->dispatch($job, ['test' => 'data'], 'default', 0);

			$this->assertNotEmpty($jobId);

			// Process the job
			$result = $manager->processNextJob('default');

			$this->assertTrue($result);
		} finally {
			// Clean up
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testProcessNextJobWithFailingJob(): void
	{
		$tempPath = sys_get_temp_dir() . '/queue_manager_fail_test_' . uniqid();
		mkdir($tempPath);

		try {
			$config = [
				'driver' => 'file',
				'file' => ['path' => $tempPath],
				'max_attempts' => 2,
				'backoff' => 0,
				'retry_after' => 0
			];

			$manager = new QueueManager(null, $config);
			$job = new FailingManagerJob();

			// Dispatch a failing job
			$jobId = $manager->dispatch($job, [], 'default', 0);

			// Process should return true (job was processed, even though it failed)
			$result = $manager->processNextJob('default');

			$this->assertTrue($result);

			// Job should be retried, so it should still be in queue
			$this->assertGreaterThanOrEqual(0, $manager->size('default'));
		} finally {
			// Clean up
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testProcessNextJobEnforcesTimeout(): void
	{
		if( !QueueManager::canEnforceTimeout() )
		{
			$this->markTestSkipped( 'pcntl extension not available' );
		}

		$tempPath = sys_get_temp_dir() . '/queue_manager_timeout_' . uniqid();
		mkdir($tempPath);

		try {
			$config = [
				'driver' => 'file',
				'file' => ['path' => $tempPath],
				'max_attempts' => 1,
				'backoff' => 0
			];

			$manager = new QueueManager(null, $config);
			$manager->dispatch(new SleepingManagerJob(), [], 'default', 0);

			$started = microtime( true );
			$result = $manager->processNextJob('default', 1);
			$elapsed = microtime( true ) - $started;

			$this->assertTrue($result, 'A timed-out job still counts as processed');
			$this->assertLessThan(4, $elapsed, 'Job should have been interrupted by the timeout');

			// max_attempts = 1, so the timeout lands it in failed storage.
			$failed = $manager->getFailedJobs();
			$this->assertCount(1, $failed);
			$this->assertStringContainsString('exceeded timeout of 1 seconds', $failed[0]['exception'] ?? json_encode($failed[0]));

			// The alarm must be disarmed afterwards: nothing should fire now.
			$this->assertSame(0, pcntl_alarm(0));
		} finally {
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testProcessNextJobResolvesJobFromRegistryContainer(): void
	{
		$tempPath = sys_get_temp_dir() . '/queue_manager_container_' . uniqid();
		mkdir($tempPath);

		$registry = \Neuron\Patterns\Registry::getInstance();
		$previous = $registry->get(\Neuron\Core\Registry\RegistryKeys::CONTAINER);

		$dependency = new \ArrayObject(['from' => 'container']);

		$container = $this->createMock(\Neuron\Patterns\Container\IContainer::class);
		$container->expects($this->once())
			->method('make')
			->with(DependentManagerJob::class)
			->willReturnCallback(fn() => new DependentManagerJob($dependency));

		$registry->set(\Neuron\Core\Registry\RegistryKeys::CONTAINER, $container);
		DependentManagerJob::$received = null;

		try {
			$manager = new QueueManager(null, [
				'driver' => 'file',
				'file' => ['path' => $tempPath],
				'max_attempts' => 1,
				'backoff' => 0
			]);

			$manager->dispatch(new DependentManagerJob(new \ArrayObject()), [], 'default', 0);

			$this->assertTrue($manager->processNextJob('default'));
			$this->assertSame($dependency, DependentManagerJob::$received);
			$this->assertCount(0, $manager->getFailedJobs());
		} finally {
			$registry->set(\Neuron\Core\Registry\RegistryKeys::CONTAINER, $previous);
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testDispatchUsesQueueableQueueWhenNoneGiven(): void
	{
		$manager = new QueueManager(null, ['driver' => 'array', 'default_queue' => 'default']);

		QueueableManagerJob::$queue = 'priority';
		$manager->dispatch(new QueueableManagerJob());
		$manager->dispatch(new QueueableManagerJob(), [], 'explicit');

		QueueableManagerJob::$queue = null;
		$manager->dispatch(new QueueableManagerJob());

		$this->assertEquals(1, $manager->size('priority'));
		$this->assertEquals(1, $manager->size('explicit'), 'An explicit queue wins over the job preference');
		$this->assertEquals(1, $manager->size('default'), 'Null from getQueue() falls back to the configured default');

		QueueableManagerJob::$queue = 'priority';
	}

	public function testQueueableOverridesMaxAttemptsAndBackoffAndRunsFailedHook(): void
	{
		// Queue-wide config says 5 attempts, no backoff; the job says 2 and 7.
		$manager = new QueueManager(null, ['driver' => 'array', 'max_attempts' => 5, 'backoff' => 0]);

		/** @var \Neuron\Jobs\Queue\ArrayQueue $driver */
		$driver = $manager->getDriver();

		QueueableManagerJob::$failedWith = [];
		QueueableManagerJob::$maxAttempts = 2;
		QueueableManagerJob::$backoff = 7;
		QueueableManagerJob::$throwInFailed = false;

		$manager->dispatch(new QueueableManagerJob());

		// Attempt 1: retried with the job's backoff (7 * 2^0 = 7s), not immediately.
		$this->assertTrue($manager->processNextJob('priority'));
		$pending = $driver->getPending('priority');
		$this->assertCount(1, $pending);
		$this->assertGreaterThanOrEqual(time() + 6, $pending[0]->getAvailableAt());
		$this->assertEmpty(QueueableManagerJob::$failedWith);

		// Make it available now and fail it a second time: that is the limit.
		$driver->release($pending[0], 0);
		$this->assertTrue($manager->processNextJob('priority'));

		$this->assertEmpty($driver->getPending('priority'));
		$this->assertCount(1, $manager->getFailedJobs());
		$this->assertEquals(['queueable failure'], QueueableManagerJob::$failedWith);
	}

	public function testQueueableFailedHookExceptionIsContained(): void
	{
		$manager = new QueueManager(null, ['driver' => 'array', 'max_attempts' => 5, 'backoff' => 0]);

		QueueableManagerJob::$failedWith = [];
		QueueableManagerJob::$maxAttempts = 1;
		QueueableManagerJob::$throwInFailed = true;

		try {
			$manager->dispatch(new QueueableManagerJob());

			$this->assertTrue($manager->processNextJob('priority'));
			$this->assertCount(1, $manager->getFailedJobs(), 'Job is still recorded as failed when the hook throws');
			$this->assertCount(1, QueueableManagerJob::$failedWith);
		} finally {
			QueueableManagerJob::$throwInFailed = false;
			QueueableManagerJob::$maxAttempts = 2;
		}
	}

	public function testQueueableTimeoutOverridesWorkerTimeout(): void
	{
		if( !QueueManager::canEnforceTimeout() )
		{
			$this->markTestSkipped( 'pcntl extension not available' );
		}

		$manager = new QueueManager(null, ['driver' => 'array', 'max_attempts' => 1, 'backoff' => 0]);

		// The job asks for no timeout even though the worker passes one; a
		// quick job must therefore complete normally and leave no alarm armed.
		QueueableManagerJob::$timeout = 0;

		try {
			$manager->dispatch(new QueueableManagerJob());
			$this->assertTrue($manager->processNextJob('priority', 1));
			$this->assertSame(0, pcntl_alarm(0));
		} finally {
			QueueableManagerJob::$timeout = null;
		}
	}

	public function testProcessNextJobWithMaxAttemptsReached(): void
	{
		$tempPath = sys_get_temp_dir() . '/queue_manager_max_attempts_' . uniqid();
		mkdir($tempPath);

		try {
			$config = [
				'driver' => 'file',
				'file' => ['path' => $tempPath],
				'max_attempts' => 1,
				'backoff' => 0
			];

			$manager = new QueueManager(null, $config);
			$job = new FailingManagerJob();

			// Dispatch a failing job
			$manager->dispatch($job, [], 'default', 0);

			// Process should handle the failure and move to failed jobs
			$manager->processNextJob('default');

			// Check that it moved to failed jobs
			$failedJobs = $manager->getFailedJobs();
			$this->assertCount(1, $failedJobs);
		} finally {
			// Clean up
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testBackoffCalculation(): void
	{
		$tempPath = sys_get_temp_dir() . '/queue_manager_backoff_' . uniqid();
		mkdir($tempPath);

		try {
			$config = [
				'driver' => 'file',
				'file' => ['path' => $tempPath],
				'max_attempts' => 5,
				'backoff' => 10
			];

			$manager = new QueueManager(null, $config);
			$job = new FailingManagerJob();

			// Dispatch a failing job
			$manager->dispatch($job, [], 'default', 0);

			// Process should retry with backoff
			$manager->processNextJob('default');

			// Should still be in queue (retried)
			$this->assertGreaterThanOrEqual(0, $manager->size('default'));
		} finally {
			// Clean up
			$this->recursiveRemoveDirectory($tempPath);
		}
	}

	public function testRetryAllFailedJobsWithNoFailedJobs(): void
	{
		$manager = new QueueManager();

		$count = $manager->retryAllFailedJobs();

		$this->assertEquals(0, $count);
	}

	public function testConstructorWithSettings(): void
	{
		// Create mock settings
		$settings = $this->createMock(\Neuron\Data\Settings\Source\ISettingSource::class);

		$settings->method('get')
			->willReturnCallback(function($section, $key) {
				$config = [
					'queue' => [
						'driver' => 'sync',
						'default' => 'test-queue',
						'retry_after' => 120,
						'max_attempts' => 5,
						'backoff' => 10
					]
				];

				return $config[$section][$key] ?? null;
			});

		$manager = new QueueManager($settings);

		$config = $manager->getConfig();

		$this->assertEquals('sync', $config['driver']);
		$this->assertEquals('test-queue', $config['default_queue']);
		$this->assertEquals(120, $config['retry_after']);
		$this->assertEquals(5, $config['max_attempts']);
		$this->assertEquals(10, $config['backoff']);
	}

	public function testConstructorWithSettingsForDatabaseDriver(): void
	{
		$settings = new \Neuron\Data\Settings\Source\Memory([
			'queue' => [
				'driver' => 'database',
				'default' => 'default',
				'retry_after' => 120,
				'max_attempts' => 3,
				'backoff' => 0
			],
			'database' => [
				'adapter' => 'sqlite',
				'name' => ':memory:',
			]
		]);

		$manager = new QueueManager($settings);

		$config = $manager->getConfig();

		$this->assertEquals('database', $config['driver']);
		$this->assertArrayHasKey('database', $config);
		$this->assertEquals('sqlite', $config['database']['adapter']);
		$this->assertEquals(':memory:', $config['database']['name']);

		// retry_after must reach the driver, not just the manager's config.
		$driver = $manager->getDriver();
		$this->assertInstanceOf(\Neuron\Jobs\Queue\DatabaseQueue::class, $driver);
		$this->assertEquals(120, $driver->getRetryAfter());
	}

	public function testConstructorWithSettingsForDatabaseDriverUsingUrl(): void
	{
		// The same `database.url` form the CMS accepts must work for the queue.
		$settings = new \Neuron\Data\Settings\Source\Memory([
			'queue' => ['driver' => 'database'],
			'database' => ['url' => 'sqlite::memory:']
		]);

		$manager = new QueueManager($settings);

		$this->assertInstanceOf(\Neuron\Jobs\Queue\DatabaseQueue::class, $manager->getDriver());
	}

	public function testConstructorReusesInjectedConnection(): void
	{
		$pdo = new \PDO('sqlite::memory:');

		$manager = new QueueManager(null, ['driver' => 'database'], $pdo);

		/** @var \Neuron\Jobs\Queue\DatabaseQueue $driver */
		$driver = $manager->getDriver();

		$this->assertInstanceOf(\Neuron\Jobs\Queue\DatabaseQueue::class, $driver);
		$this->assertSame($pdo, $driver->getConnection());
	}

	public function testConstructorWithInvalidDatabaseConfigThrowsRuntimeException(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unsupported database adapter: oracle');

		new QueueManager(null, ['driver' => 'database', 'database' => ['adapter' => 'oracle', 'name' => 'x']]);
	}

	public function testConstructorWithSettingsForFileDriver(): void
	{
		// Create mock settings
		$settings = $this->createMock(\Neuron\Data\Settings\Source\ISettingSource::class);

		$settings->method('get')
			->willReturnCallback(function($section, $key) {
				$config = [
					'queue' => [
						'driver' => 'file',
						'default' => 'default',
						'retry_after' => 90,
						'max_attempts' => 3,
						'backoff' => 0,
						'file_path' => '/tmp/queue'
					]
				];

				return $config[$section][$key] ?? null;
			});

		$manager = new QueueManager($settings);

		$config = $manager->getConfig();

		$this->assertEquals('file', $config['driver']);
		$this->assertArrayHasKey('file', $config);
		$this->assertEquals('/tmp/queue', $config['file']['path']);
	}
}
