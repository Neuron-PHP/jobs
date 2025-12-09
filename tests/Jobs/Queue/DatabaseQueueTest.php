<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\DatabaseQueue;
use Neuron\Jobs\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/**
 * Test job for DatabaseQueue testing.
 */
class DatabaseQueueTestJob implements IJob
{
	public function getName(): string
	{
		return 'DatabaseQueueTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

/**
 * Comprehensive tests for DatabaseQueue class.
 *
 * Tests database-backed queue operations using SQLite in-memory database.
 */
class DatabaseQueueTest extends TestCase
{
	private \PDO $pdo;
	private DatabaseQueue $queue;

	protected function setUp(): void
	{
		parent::setUp();

		// Create in-memory SQLite database
		$this->pdo = new \PDO('sqlite::memory:');
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

		// Create tables
		$this->createTables();

		// Create queue with explicit PDO connection
		$config = [
			'adapter' => 'sqlite',
			'name' => ':memory:'
		];

		$this->queue = new DatabaseQueue($config);

		// Use reflection to inject our PDO instance
		$reflection = new \ReflectionClass($this->queue);
		$property = $reflection->getProperty('_connection');
		$property->setAccessible(true);
		$property->setValue($this->queue, $this->pdo);
	}

	private function createTables(): void
	{
		// Jobs table
		$this->pdo->exec("
			CREATE TABLE jobs (
				id TEXT PRIMARY KEY,
				queue TEXT NOT NULL,
				payload TEXT NOT NULL,
				attempts INTEGER DEFAULT 0,
				reserved_at INTEGER NULL,
				available_at INTEGER NOT NULL,
				created_at INTEGER NOT NULL
			)
		");

		// Failed jobs table
		$this->pdo->exec("
			CREATE TABLE failed_jobs (
				id TEXT PRIMARY KEY,
				queue TEXT NOT NULL,
				payload TEXT NOT NULL,
				exception TEXT NOT NULL,
				failed_at INTEGER NOT NULL
			)
		");
	}

	public function testConstructorCreatesDatabaseConnection(): void
	{
		$config = [
			'adapter' => 'sqlite',
			'name' => ':memory:'
		];

		$queue = new DatabaseQueue($config);

		$this->assertInstanceOf(DatabaseQueue::class, $queue);
	}

	public function testConstructorWithInvalidAdapterThrowsException(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unsupported database adapter: invalid');

		$config = [
			'adapter' => 'invalid',
			'name' => 'test'
		];

		new DatabaseQueue($config);
	}

	public function testPushInsertsJobIntoDatabase(): void
	{
		$job = new DatabaseQueueTestJob();

		$jobId = $this->queue->push($job, ['arg1' => 'value1'], 'test-queue');

		$this->assertNotEmpty($jobId);

		// Verify job was inserted
		$stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertNotFalse($row);
		$this->assertEquals($jobId, $row['id']);
		$this->assertEquals('test-queue', $row['queue']);
		$this->assertEquals(0, $row['attempts']);
	}

	public function testPushCreatesValidPayload(): void
	{
		$job = new DatabaseQueueTestJob();

		$jobId = $this->queue->push($job, ['key' => 'value'], 'default');

		$stmt = $this->pdo->prepare("SELECT payload FROM jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$payload = json_decode($row['payload'], true);
		$this->assertIsArray($payload);
		$this->assertEquals(DatabaseQueueTestJob::class, $payload['class']);
		$this->assertEquals(['key' => 'value'], $payload['args']);
	}

	public function testPopReturnsNullWhenQueueIsEmpty(): void
	{
		$job = $this->queue->pop('empty-queue');

		$this->assertNull($job);
	}

	public function testPopReturnsJobWhenAvailable(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, ['test' => 'data'], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		$this->assertInstanceOf(QueuedJob::class, $queuedJob);
		$this->assertEquals($jobId, $queuedJob->getId());
		$this->assertEquals('default', $queuedJob->getQueue());
	}

	public function testPopIncrementsAttempts(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		$this->assertEquals(1, $queuedJob->getAttempts());

		// Verify in database
		$stmt = $this->pdo->prepare("SELECT attempts FROM jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertEquals(1, $row['attempts']);
	}

	public function testPopSetsReservedAt(): void
	{
		$job = new DatabaseQueueTestJob();
		$this->queue->push($job, [], 'default', 0);

		$beforeTime = time();
		$queuedJob = $this->queue->pop('default');
		$afterTime = time();

		$this->assertNotNull($queuedJob->getReservedAt());
		$this->assertGreaterThanOrEqual($beforeTime, $queuedJob->getReservedAt());
		$this->assertLessThanOrEqual($afterTime, $queuedJob->getReservedAt());
	}

	public function testPopSkipsReservedJobs(): void
	{
		$job = new DatabaseQueueTestJob();

		// Insert job directly into database with reserved_at set
		$stmt = $this->pdo->prepare("
			INSERT INTO jobs (id, queue, payload, attempts, reserved_at, available_at, created_at)
			VALUES (:id, :queue, :payload, 0, :reserved_at, :available_at, :created_at)
		");

		$stmt->execute([
			'id' => 'reserved_job',
			'queue' => 'default',
			'payload' => json_encode(['class' => DatabaseQueueTestJob::class, 'args' => []]),
			'reserved_at' => time(),
			'available_at' => time(),
			'created_at' => time()
		]);

		// Pop should return null because job is reserved
		$queuedJob = $this->queue->pop('default');

		$this->assertNull($queuedJob);
	}

	public function testPopSkipsJobsWithFutureAvailableAt(): void
	{
		$job = new DatabaseQueueTestJob();

		$this->queue->push($job, ['future'], 'default', 999);
		$jobId2 = $this->queue->push($job, ['now'], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		// Should get job available now, not future job
		$this->assertEquals($jobId2, $queuedJob->getId());
	}

	public function testReleaseUpdatesJobAvailability(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->assertNotNull($queuedJob->getReservedAt());

		$this->queue->release($queuedJob, 30);

		// Verify in database
		$stmt = $this->pdo->prepare("SELECT reserved_at, available_at FROM jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertNull($row['reserved_at']);
		$this->assertGreaterThan(time(), $row['available_at']);
	}

	public function testDeleteRemovesJobFromDatabase(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->delete($queuedJob);

		// Verify job was deleted
		$stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertEquals(0, $row['count']);
	}

	public function testFailedCreatesFailedJobRecord(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, ['data'], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$exception = new \RuntimeException('Job failed');

		$this->queue->failed($queuedJob, $exception);

		// Verify failed job record
		$stmt = $this->pdo->prepare("SELECT * FROM failed_jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertNotFalse($row);
		$this->assertEquals($jobId, $row['id']);
		$this->assertStringContainsString('RuntimeException', $row['exception']);
		$this->assertStringContainsString('Job failed', $row['exception']);
	}

	public function testFailedRemovesJobFromQueue(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		// Verify job removed from jobs table
		$stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertEquals(0, $row['count']);
	}

	public function testSizeReturnsCorrectCount(): void
	{
		$this->assertEquals(0, $this->queue->size('test-queue'));

		$job = new DatabaseQueueTestJob();
		$this->queue->push($job, [], 'test-queue', 0);
		$this->assertEquals(1, $this->queue->size('test-queue'));

		$this->queue->push($job, [], 'test-queue', 0);
		$this->assertEquals(2, $this->queue->size('test-queue'));

		$this->queue->push($job, [], 'test-queue', 0);
		$this->assertEquals(3, $this->queue->size('test-queue'));
	}

	public function testSizeExcludesReservedJobs(): void
	{
		$job = new DatabaseQueueTestJob();
		$this->queue->push($job, [], 'default', 0);
		$this->queue->push($job, [], 'default', 0);

		$this->assertEquals(2, $this->queue->size('default'));

		// Reserve one job
		$this->queue->pop('default');

		// Size should only count unreserved jobs
		$this->assertEquals(1, $this->queue->size('default'));
	}

	public function testClearRemovesAllJobsFromQueue(): void
	{
		$job = new DatabaseQueueTestJob();
		$this->queue->push($job, [], 'clear-test', 0);
		$this->queue->push($job, [], 'clear-test', 0);
		$this->queue->push($job, [], 'clear-test', 0);

		$this->assertEquals(3, $this->queue->size('clear-test'));

		$count = $this->queue->clear('clear-test');

		$this->assertEquals(3, $count);
		$this->assertEquals(0, $this->queue->size('clear-test'));
	}

	public function testClearReturnsZeroForEmptyQueue(): void
	{
		$count = $this->queue->clear('nonexistent');

		$this->assertEquals(0, $count);
	}

	public function testGetFailedJobsReturnsEmptyArrayWhenNoFailedJobs(): void
	{
		$failedJobs = $this->queue->getFailedJobs();

		$this->assertIsArray($failedJobs);
		$this->assertEmpty($failedJobs);
	}

	public function testGetFailedJobsReturnsAllFailedJobs(): void
	{
		$job = new DatabaseQueueTestJob();

		$jobId1 = $this->queue->push($job, ['first'], 'default', 0);
		$queuedJob1 = $this->queue->pop('default');
		$this->queue->failed($queuedJob1, new \RuntimeException('First failure'));

		$jobId2 = $this->queue->push($job, ['second'], 'default', 0);
		$queuedJob2 = $this->queue->pop('default');
		$this->queue->failed($queuedJob2, new \RuntimeException('Second failure'));

		$failedJobs = $this->queue->getFailedJobs();

		$this->assertCount(2, $failedJobs);
		$this->assertArrayHasKey('id', $failedJobs[0]);
		$this->assertArrayHasKey('exception', $failedJobs[0]);
		$this->assertArrayHasKey('failed_at', $failedJobs[0]);
	}

	public function testRetryFailedJobCreatesNewJob(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, ['retry-test'], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		$result = $this->queue->retryFailedJob($jobId);

		$this->assertTrue($result);
		$this->assertGreaterThan(0, $this->queue->size('default'));
	}

	public function testRetryFailedJobRemovesFailedJobRecord(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		$this->queue->retryFailedJob($jobId);

		$stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM failed_jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertEquals(0, $row['count']);
	}

	public function testRetryFailedJobReturnsFalseForNonExistentJob(): void
	{
		$result = $this->queue->retryFailedJob('nonexistent-job-id');

		$this->assertFalse($result);
	}

	public function testForgetFailedJobRemovesRecord(): void
	{
		$job = new DatabaseQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		$result = $this->queue->forgetFailedJob($jobId);

		$this->assertTrue($result);

		$stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM failed_jobs WHERE id = :id");
		$stmt->execute(['id' => $jobId]);
		$row = $stmt->fetch();

		$this->assertEquals(0, $row['count']);
	}

	public function testForgetFailedJobReturnsFalseForNonExistentJob(): void
	{
		$result = $this->queue->forgetFailedJob('nonexistent-job-id');

		$this->assertFalse($result);
	}

	public function testClearFailedJobsRemovesAllFailedJobs(): void
	{
		$job = new DatabaseQueueTestJob();

		// Create 3 failed jobs
		for ($i = 0; $i < 3; $i++) {
			$jobId = $this->queue->push($job, [], 'default', 0);
			$queuedJob = $this->queue->pop('default');
			$this->queue->failed($queuedJob, new \RuntimeException("Failure $i"));
		}

		$this->assertCount(3, $this->queue->getFailedJobs());

		$count = $this->queue->clearFailedJobs();

		$this->assertEquals(3, $count);
		$this->assertEmpty($this->queue->getFailedJobs());
	}

	public function testMultipleQueuesAreIndependent(): void
	{
		$job = new DatabaseQueueTestJob();

		$this->queue->push($job, ['queue1'], 'queue1', 0);
		$this->queue->push($job, ['queue2'], 'queue2', 0);

		$this->assertEquals(1, $this->queue->size('queue1'));
		$this->assertEquals(1, $this->queue->size('queue2'));

		$job1 = $this->queue->pop('queue1');
		$job2 = $this->queue->pop('queue2');

		$this->assertEquals('queue1', $job1->getQueue());
		$this->assertEquals('queue2', $job2->getQueue());
	}

	public function testJobLifecycleComplete(): void
	{
		$job = new DatabaseQueueTestJob();

		// 1. Push job
		$jobId = $this->queue->push($job, ['test' => 'data'], 'lifecycle', 0);
		$this->assertEquals(1, $this->queue->size('lifecycle'));

		// 2. Pop job
		$queuedJob = $this->queue->pop('lifecycle');
		$this->assertEquals($jobId, $queuedJob->getId());
		$this->assertEquals(1, $queuedJob->getAttempts());

		// 3. Delete job (successful completion)
		$this->queue->delete($queuedJob);
		$this->assertEquals(0, $this->queue->size('lifecycle'));
	}

	public function testJobLifecycleWithRetry(): void
	{
		$job = new DatabaseQueueTestJob();

		// 1. Push job
		$jobId = $this->queue->push($job, ['test'], 'retry', 0);

		// 2. Pop and fail
		$queuedJob1 = $this->queue->pop('retry');
		$this->assertEquals(1, $queuedJob1->getAttempts());

		// 3. Release for retry
		$this->queue->release($queuedJob1, 0);

		// 4. Pop again
		$queuedJob2 = $this->queue->pop('retry');
		$this->assertEquals($jobId, $queuedJob2->getId());
		$this->assertEquals(2, $queuedJob2->getAttempts());

		// 5. Delete
		$this->queue->delete($queuedJob2);
		$this->assertEquals(0, $this->queue->size('retry'));
	}
}
