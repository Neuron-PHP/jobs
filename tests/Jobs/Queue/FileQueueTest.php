<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\FileQueue;
use Neuron\Jobs\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/**
 * Test job for FileQueue testing.
 */
class FileQueueTestJob implements IJob
{
	public function getName(): string
	{
		return 'FileQueueTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

/**
 * Comprehensive tests for FileQueue class.
 *
 * Tests file-based queue operations including push, pop, release,
 * delete, failed job handling, and file locking.
 */
class FileQueueTest extends TestCase
{
	private string $tempPath;
	private FileQueue $queue;

	protected function setUp(): void
	{
		parent::setUp();

		// Create temporary directory for queue files
		$this->tempPath = sys_get_temp_dir() . '/neuron_test_queue_' . uniqid();
		$this->queue = new FileQueue(['path' => $this->tempPath]);
	}

	protected function tearDown(): void
	{
		// Clean up temporary directory
		if (is_dir($this->tempPath)) {
			$this->recursiveRemoveDirectory($this->tempPath);
		}

		parent::tearDown();
	}

	private function recursiveRemoveDirectory(string $directory): void
	{
		if (!is_dir($directory)) {
			return;
		}

		$items = scandir($directory);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $directory . '/' . $item;
			if (is_dir($path)) {
				$this->recursiveRemoveDirectory($path);
			} else {
				unlink($path);
			}
		}

		rmdir($directory);
	}

	public function testConstructorCreatesQueueDirectories(): void
	{
		$this->assertDirectoryExists($this->tempPath);
		$this->assertDirectoryExists($this->tempPath . '/failed');
	}

	public function testConstructorWithDefaultPath(): void
	{
		$defaultPath = 'storage/queue';
		$queue = new FileQueue();

		$this->assertDirectoryExists($defaultPath);
		$this->assertDirectoryExists($defaultPath . '/failed');

		// Clean up
		$this->recursiveRemoveDirectory($defaultPath);
	}

	public function testPushCreatesJobFile(): void
	{
		$job = new FileQueueTestJob();

		$jobId = $this->queue->push($job, ['arg1' => 'value1'], 'test-queue');

		$this->assertNotEmpty($jobId);
		$this->assertStringStartsWith('job_', $jobId);

		// Verify file was created
		$queuePath = $this->tempPath . '/test-queue';
		$this->assertDirectoryExists($queuePath);

		$files = glob($queuePath . '/job_*.json');
		$this->assertCount(1, $files);
	}

	public function testPushCreatesValidJsonFile(): void
	{
		$job = new FileQueueTestJob();

		$jobId = $this->queue->push($job, ['key' => 'value'], 'default', 0);

		$filename = $this->tempPath . '/default/' . $jobId . '.json';
		$this->assertFileExists($filename);

		$data = json_decode(file_get_contents($filename), true);
		$this->assertIsArray($data);
		$this->assertEquals($jobId, $data['id']);
		$this->assertEquals('default', $data['queue']);
		$this->assertEquals(0, $data['attempts']);
		$this->assertNull($data['reserved_at']);
	}

	public function testPushWithDelay(): void
	{
		$job = new FileQueueTestJob();
		$beforeTime = time();

		$jobId = $this->queue->push($job, [], 'default', 60);

		$filename = $this->tempPath . '/default/' . $jobId . '.json';
		$data = json_decode(file_get_contents($filename), true);

		$this->assertGreaterThanOrEqual($beforeTime + 60, $data['available_at']);
	}

	public function testPopReturnsNullWhenQueueIsEmpty(): void
	{
		$job = $this->queue->pop('empty-queue');

		$this->assertNull($job);
	}

	public function testPopReturnsJobWhenAvailable(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, ['test' => 'data'], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		$this->assertInstanceOf(QueuedJob::class, $queuedJob);
		$this->assertEquals($jobId, $queuedJob->getId());
		$this->assertEquals('default', $queuedJob->getQueue());
		$this->assertEquals(['test' => 'data'], $queuedJob->getArguments());
	}

	public function testPopIncrementsAttempts(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		$this->assertEquals(1, $queuedJob->getAttempts());
	}

	public function testPopSetsReservedAt(): void
	{
		$job = new FileQueueTestJob();
		$this->queue->push($job, [], 'default', 0);

		$beforeTime = time();
		$queuedJob = $this->queue->pop('default');
		$afterTime = time();

		$this->assertNotNull($queuedJob->getReservedAt());
		$this->assertGreaterThanOrEqual($beforeTime, $queuedJob->getReservedAt());
		$this->assertLessThanOrEqual($afterTime, $queuedJob->getReservedAt());
	}

	public function testPopSkipsJobsWithFutureAvailableAt(): void
	{
		$job1 = new FileQueueTestJob();
		$job2 = new FileQueueTestJob();

		// Push job with delay (future)
		$this->queue->push($job1, ['future'], 'default', 999);

		// Push job available now
		$jobId2 = $this->queue->push($job2, ['now'], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		// Should get job2, not job1
		$this->assertEquals($jobId2, $queuedJob->getId());
		$this->assertEquals(['now'], $queuedJob->getArguments());
	}

	public function testPopReturnsOldestJobFirst(): void
	{
		$job1 = new FileQueueTestJob();
		$job2 = new FileQueueTestJob();

		$jobId1 = $this->queue->push($job1, ['first'], 'default', 0);
		sleep(1); // Ensure different timestamps
		$this->queue->push($job2, ['second'], 'default', 0);

		$queuedJob = $this->queue->pop('default');

		$this->assertEquals($jobId1, $queuedJob->getId());
		$this->assertEquals(['first'], $queuedJob->getArguments());
	}

	public function testReleaseUpdatesJobAvailability(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->assertNotNull($queuedJob->getReservedAt());

		$this->queue->release($queuedJob, 30);

		// Read file to verify changes
		$filename = $this->tempPath . '/default/' . $jobId . '.json';
		$data = json_decode(file_get_contents($filename), true);

		$this->assertNull($data['reserved_at']);
		$this->assertGreaterThan(time(), $data['available_at']);
	}

	public function testReleaseWithNoDelayMakesJobImmediatelyAvailable(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->release($queuedJob, 0);

		$filename = $this->tempPath . '/default/' . $jobId . '.json';
		$data = json_decode(file_get_contents($filename), true);

		$this->assertLessThanOrEqual(time(), $data['available_at']);
	}

	public function testDeleteRemovesJobFile(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$filename = $this->tempPath . '/default/' . $jobId . '.json';
		$this->assertFileExists($filename);

		$queuedJob = $this->queue->pop('default');
		$this->queue->delete($queuedJob);

		$this->assertFileDoesNotExist($filename);
	}

	public function testDeleteWithNonExistentFileDoesNotThrow(): void
	{
		$queuedJob = new QueuedJob('nonexistent', 'default', FileQueueTestJob::class);

		// Should not throw exception
		$this->queue->delete($queuedJob);

		$this->assertTrue(true);
	}

	public function testFailedCreatesFailedJobFile(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, ['data'], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$exception = new \RuntimeException('Job failed');

		$this->queue->failed($queuedJob, $exception);

		// Verify failed file created
		$failedFile = $this->tempPath . '/failed/failed_' . $jobId . '.json';
		$this->assertFileExists($failedFile);

		$data = json_decode(file_get_contents($failedFile), true);
		$this->assertEquals($jobId, $data['id']);
		$this->assertStringContainsString('RuntimeException', $data['exception']);
		$this->assertStringContainsString('Job failed', $data['exception']);
	}

	public function testFailedRemovesJobFromQueue(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queueFile = $this->tempPath . '/default/' . $jobId . '.json';
		$this->assertFileExists($queueFile);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		// Original file should be deleted
		$this->assertFileDoesNotExist($queueFile);
	}

	public function testSizeReturnsCorrectCount(): void
	{
		$this->assertEquals(0, $this->queue->size('test-queue'));

		$job = new FileQueueTestJob();
		$this->queue->push($job, [], 'test-queue', 0);
		$this->assertEquals(1, $this->queue->size('test-queue'));

		$this->queue->push($job, [], 'test-queue', 0);
		$this->assertEquals(2, $this->queue->size('test-queue'));

		$this->queue->push($job, [], 'test-queue', 0);
		$this->assertEquals(3, $this->queue->size('test-queue'));
	}

	public function testSizeReturnsZeroForNonExistentQueue(): void
	{
		$size = $this->queue->size('nonexistent-queue');

		$this->assertEquals(0, $size);
	}

	public function testClearRemovesAllJobs(): void
	{
		$job = new FileQueueTestJob();
		$this->queue->push($job, [], 'clear-test', 0);
		$this->queue->push($job, [], 'clear-test', 0);
		$this->queue->push($job, [], 'clear-test', 0);

		$this->assertEquals(3, $this->queue->size('clear-test'));

		$count = $this->queue->clear('clear-test');

		$this->assertEquals(3, $count);
		$this->assertEquals(0, $this->queue->size('clear-test'));
	}

	public function testClearReturnsZeroForNonExistentQueue(): void
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
		$job = new FileQueueTestJob();

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

	public function testGetFailedJobsSortsByFailedAtDescending(): void
	{
		$job = new FileQueueTestJob();

		$jobId1 = $this->queue->push($job, [], 'default', 0);
		$queuedJob1 = $this->queue->pop('default');
		$this->queue->failed($queuedJob1, new \RuntimeException('First'));

		sleep(1);

		$jobId2 = $this->queue->push($job, [], 'default', 0);
		$queuedJob2 = $this->queue->pop('default');
		$this->queue->failed($queuedJob2, new \RuntimeException('Second'));

		$failedJobs = $this->queue->getFailedJobs();

		// Most recent failure should be first
		$this->assertEquals($jobId2, $failedJobs[0]['id']);
		$this->assertEquals($jobId1, $failedJobs[1]['id']);
		$this->assertGreaterThan($failedJobs[1]['failed_at'], $failedJobs[0]['failed_at']);
	}

	public function testRetryFailedJobCreatesNewJob(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, ['retry-test'], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		$result = $this->queue->retryFailedJob($jobId);

		$this->assertTrue($result);

		// Should have a new job in the queue
		$this->assertGreaterThan(0, $this->queue->size('default'));

		$newJob = $this->queue->pop('default');
		$this->assertNotNull($newJob);
		$this->assertEquals(['retry-test'], $newJob->getArguments());
		$this->assertEquals(1, $newJob->getAttempts()); // Incremented by pop
	}

	public function testRetryFailedJobRemovesFailedJobFile(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		$failedFile = $this->tempPath . '/failed/failed_' . $jobId . '.json';
		$this->assertFileExists($failedFile);

		$this->queue->retryFailedJob($jobId);

		$this->assertFileDoesNotExist($failedFile);
	}

	public function testRetryFailedJobReturnsFalseForNonExistentJob(): void
	{
		$result = $this->queue->retryFailedJob('nonexistent-job-id');

		$this->assertFalse($result);
	}

	public function testForgetFailedJobRemovesFile(): void
	{
		$job = new FileQueueTestJob();
		$jobId = $this->queue->push($job, [], 'default', 0);

		$queuedJob = $this->queue->pop('default');
		$this->queue->failed($queuedJob, new \RuntimeException('Test'));

		$failedFile = $this->tempPath . '/failed/failed_' . $jobId . '.json';
		$this->assertFileExists($failedFile);

		$result = $this->queue->forgetFailedJob($jobId);

		$this->assertTrue($result);
		$this->assertFileDoesNotExist($failedFile);
	}

	public function testForgetFailedJobReturnsFalseForNonExistentJob(): void
	{
		$result = $this->queue->forgetFailedJob('nonexistent-job-id');

		$this->assertFalse($result);
	}

	public function testClearFailedJobsRemovesAllFailedJobs(): void
	{
		$job = new FileQueueTestJob();

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

	public function testClearFailedJobsReturnsZeroWhenNoFailedJobs(): void
	{
		$count = $this->queue->clearFailedJobs();

		$this->assertEquals(0, $count);
	}

	public function testMultipleQueuesAreIndependent(): void
	{
		$job = new FileQueueTestJob();

		$this->queue->push($job, ['queue1'], 'queue1', 0);
		$this->queue->push($job, ['queue2'], 'queue2', 0);

		$this->assertEquals(1, $this->queue->size('queue1'));
		$this->assertEquals(1, $this->queue->size('queue2'));

		$job1 = $this->queue->pop('queue1');
		$job2 = $this->queue->pop('queue2');

		$this->assertEquals(['queue1'], $job1->getArguments());
		$this->assertEquals(['queue2'], $job2->getArguments());
	}

	public function testJobLifecycleComplete(): void
	{
		$job = new FileQueueTestJob();

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
		$job = new FileQueueTestJob();

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
