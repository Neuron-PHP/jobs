<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\QueuedJob;
use PHPUnit\Framework\TestCase;

/**
 * Test job class for QueuedJob testing.
 */
class TestQueueJob implements IJob
{
	public function getName(): string
	{
		return 'TestQueueJob';
	}

	public function run( array $argv = [] ): mixed
	{
		// Test job does nothing
		return true;
	}
}

/**
 * Invalid class that doesn't implement IJob.
 */
class InvalidJob
{
	// Does not implement IJob
}

/**
 * Comprehensive tests for QueuedJob class.
 *
 * Tests job creation, factory methods, state management,
 * serialization, and validation.
 */
class QueuedJobTest extends TestCase
{
	public function testConstructorCreatesQueuedJob(): void
	{
		$job = new QueuedJob(
			'job_123',
			'default',
			TestQueueJob::class,
			['arg1' => 'value1'],
			2,
			1234567890,
			1234567800,
			1234567700
		);

		$this->assertEquals('job_123', $job->getId());
		$this->assertEquals('default', $job->getQueue());
		$this->assertEquals(TestQueueJob::class, $job->getJobClass());
		$this->assertEquals(['arg1' => 'value1'], $job->getArguments());
		$this->assertEquals(2, $job->getAttempts());
		$this->assertEquals(1234567890, $job->getReservedAt());
		$this->assertEquals(1234567800, $job->getAvailableAt());
		$this->assertEquals(1234567700, $job->getCreatedAt());
	}

	public function testConstructorWithDefaultArguments(): void
	{
		$beforeTime = time();

		$job = new QueuedJob(
			'job_456',
			'emails',
			TestQueueJob::class
		);

		$afterTime = time();

		// Default values
		$this->assertEquals([], $job->getArguments());
		$this->assertEquals(0, $job->getAttempts());
		$this->assertNull($job->getReservedAt());

		// availableAt and createdAt should be set to current time
		$this->assertGreaterThanOrEqual($beforeTime, $job->getAvailableAt());
		$this->assertLessThanOrEqual($afterTime, $job->getAvailableAt());
		$this->assertGreaterThanOrEqual($beforeTime, $job->getCreatedAt());
		$this->assertLessThanOrEqual($afterTime, $job->getCreatedAt());
	}

	public function testConstructorWithExplicitTimestamps(): void
	{
		$job = new QueuedJob(
			'job_789',
			'default',
			TestQueueJob::class,
			[],
			0,
			null,
			1000000000,
			1000000000
		);

		// Explicit timestamps should be used
		$this->assertEquals(1000000000, $job->getAvailableAt());
		$this->assertEquals(1000000000, $job->getCreatedAt());
	}

	public function testFromJobCreatesQueuedJobFromIJobInstance(): void
	{
		$testJob = new TestQueueJob();
		$beforeTime = time();

		$queuedJob = QueuedJob::fromJob(
			$testJob,
			['param' => 'test'],
			'custom-queue'
		);

		$afterTime = time();

		$this->assertInstanceOf(QueuedJob::class, $queuedJob);
		$this->assertStringStartsWith('job_', $queuedJob->getId());
		$this->assertEquals('custom-queue', $queuedJob->getQueue());
		$this->assertEquals(TestQueueJob::class, $queuedJob->getJobClass());
		$this->assertEquals(['param' => 'test'], $queuedJob->getArguments());
		$this->assertEquals(0, $queuedJob->getAttempts());
		$this->assertNull($queuedJob->getReservedAt());
		$this->assertGreaterThanOrEqual($beforeTime, $queuedJob->getAvailableAt());
		$this->assertLessThanOrEqual($afterTime, $queuedJob->getAvailableAt());
		$this->assertGreaterThanOrEqual($beforeTime, $queuedJob->getCreatedAt());
		$this->assertLessThanOrEqual($afterTime, $queuedJob->getCreatedAt());
	}

	public function testFromJobWithDefaultQueue(): void
	{
		$testJob = new TestQueueJob();

		$queuedJob = QueuedJob::fromJob($testJob);

		$this->assertEquals('default', $queuedJob->getQueue());
		$this->assertEquals([], $queuedJob->getArguments());
	}

	public function testFromJobWithDelay(): void
	{
		$testJob = new TestQueueJob();
		$beforeTime = time();

		$queuedJob = QueuedJob::fromJob($testJob, [], 'default', 300);

		$afterTime = time();

		// availableAt should be in the future
		$this->assertGreaterThanOrEqual($beforeTime + 300, $queuedJob->getAvailableAt());
		$this->assertLessThanOrEqual($afterTime + 300, $queuedJob->getAvailableAt());
	}

	public function testFromPayloadCreatesQueuedJobFromJson(): void
	{
		$payload = json_encode([
			'class' => TestQueueJob::class,
			'args' => ['key' => 'value']
		]);

		$queuedJob = QueuedJob::fromPayload(
			'job_payload_123',
			'processing',
			$payload,
			3,
			1234567890,
			1234567800,
			1234567700
		);

		$this->assertEquals('job_payload_123', $queuedJob->getId());
		$this->assertEquals('processing', $queuedJob->getQueue());
		$this->assertEquals(TestQueueJob::class, $queuedJob->getJobClass());
		$this->assertEquals(['key' => 'value'], $queuedJob->getArguments());
		$this->assertEquals(3, $queuedJob->getAttempts());
		$this->assertEquals(1234567890, $queuedJob->getReservedAt());
		$this->assertEquals(1234567800, $queuedJob->getAvailableAt());
		$this->assertEquals(1234567700, $queuedJob->getCreatedAt());
	}

	public function testFromPayloadThrowsExceptionForInvalidJson(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid job payload');

		QueuedJob::fromPayload('job_invalid', 'default', 'invalid json');
	}

	public function testFromPayloadThrowsExceptionForMissingClass(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid job payload');

		$payload = json_encode(['args' => []]);
		QueuedJob::fromPayload('job_invalid', 'default', $payload);
	}

	public function testFromPayloadThrowsExceptionForMissingArgs(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid job payload');

		$payload = json_encode(['class' => TestQueueJob::class]);
		QueuedJob::fromPayload('job_invalid', 'default', $payload);
	}

	public function testGetJobReturnsIJobInstance(): void
	{
		$queuedJob = new QueuedJob(
			'job_test',
			'default',
			TestQueueJob::class
		);

		$job = $queuedJob->getJob();

		$this->assertInstanceOf(IJob::class, $job);
		$this->assertInstanceOf(TestQueueJob::class, $job);
	}

	public function testGetJobThrowsExceptionForNonExistentClass(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Job class not found: NonExistent\Class');

		$queuedJob = new QueuedJob(
			'job_missing',
			'default',
			'NonExistent\Class'
		);

		$queuedJob->getJob();
	}

	public function testGetJobThrowsExceptionForInvalidJobClass(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Job class must implement IJob');

		$queuedJob = new QueuedJob(
			'job_invalid',
			'default',
			InvalidJob::class
		);

		$queuedJob->getJob();
	}

	public function testGetPayloadReturnsJsonEncodedData(): void
	{
		$queuedJob = new QueuedJob(
			'job_payload',
			'default',
			TestQueueJob::class,
			['param1' => 'value1', 'param2' => 123]
		);

		$payload = $queuedJob->getPayload();
		$decoded = json_decode($payload, true);

		$this->assertIsString($payload);
		$this->assertIsArray($decoded);
		$this->assertEquals(TestQueueJob::class, $decoded['class']);
		$this->assertEquals(['param1' => 'value1', 'param2' => 123], $decoded['args']);
	}

	public function testGetPayloadUsesRawPayloadWhenAvailable(): void
	{
		$originalPayload = json_encode([
			'class' => TestQueueJob::class,
			'args' => ['original' => 'data']
		]);

		$queuedJob = QueuedJob::fromPayload(
			'job_raw',
			'default',
			$originalPayload
		);

		// Should return the original payload, not regenerate it
		$this->assertEquals($originalPayload, $queuedJob->getPayload());
	}

	public function testIncrementAttemptsIncreasesCounter(): void
	{
		$queuedJob = new QueuedJob(
			'job_attempts',
			'default',
			TestQueueJob::class,
			[],
			0
		);

		$this->assertEquals(0, $queuedJob->getAttempts());

		$queuedJob->incrementAttempts();
		$this->assertEquals(1, $queuedJob->getAttempts());

		$queuedJob->incrementAttempts();
		$this->assertEquals(2, $queuedJob->getAttempts());

		$queuedJob->incrementAttempts();
		$this->assertEquals(3, $queuedJob->getAttempts());
	}

	public function testMarkAsReservedSetsReservedAt(): void
	{
		$queuedJob = new QueuedJob(
			'job_reserve',
			'default',
			TestQueueJob::class
		);

		$this->assertNull($queuedJob->getReservedAt());
		$this->assertFalse($queuedJob->isReserved());

		$beforeTime = time();
		$queuedJob->markAsReserved();
		$afterTime = time();

		$this->assertNotNull($queuedJob->getReservedAt());
		$this->assertTrue($queuedJob->isReserved());
		$this->assertGreaterThanOrEqual($beforeTime, $queuedJob->getReservedAt());
		$this->assertLessThanOrEqual($afterTime, $queuedJob->getReservedAt());
	}

	public function testIsReservedReturnsFalseWhenNotReserved(): void
	{
		$queuedJob = new QueuedJob(
			'job_not_reserved',
			'default',
			TestQueueJob::class,
			[],
			0,
			null
		);

		$this->assertFalse($queuedJob->isReserved());
	}

	public function testIsReservedReturnsTrueWhenReserved(): void
	{
		$queuedJob = new QueuedJob(
			'job_reserved',
			'default',
			TestQueueJob::class,
			[],
			0,
			1234567890
		);

		$this->assertTrue($queuedJob->isReserved());
	}

	public function testIsAvailableReturnsTrueWhenAvailableAtIsPast(): void
	{
		$queuedJob = new QueuedJob(
			'job_available',
			'default',
			TestQueueJob::class,
			[],
			0,
			null,
			time() - 100
		);

		$this->assertTrue($queuedJob->isAvailable());
	}

	public function testIsAvailableReturnsTrueWhenAvailableAtIsCurrent(): void
	{
		$queuedJob = new QueuedJob(
			'job_available_now',
			'default',
			TestQueueJob::class,
			[],
			0,
			null,
			time()
		);

		$this->assertTrue($queuedJob->isAvailable());
	}

	public function testIsAvailableReturnsFalseWhenAvailableAtIsFuture(): void
	{
		$queuedJob = new QueuedJob(
			'job_not_available',
			'default',
			TestQueueJob::class,
			[],
			0,
			null,
			time() + 100
		);

		$this->assertFalse($queuedJob->isAvailable());
	}

	public function testSetAvailableAtUpdatesTimestamp(): void
	{
		$queuedJob = new QueuedJob(
			'job_set_available',
			'default',
			TestQueueJob::class
		);

		$newTimestamp = 1600000000;
		$queuedJob->setAvailableAt($newTimestamp);

		$this->assertEquals($newTimestamp, $queuedJob->getAvailableAt());
	}

	public function testSetReservedAtUpdatesTimestamp(): void
	{
		$queuedJob = new QueuedJob(
			'job_set_reserved',
			'default',
			TestQueueJob::class
		);

		$this->assertNull($queuedJob->getReservedAt());

		$newTimestamp = 1600000000;
		$queuedJob->setReservedAt($newTimestamp);

		$this->assertEquals($newTimestamp, $queuedJob->getReservedAt());
		$this->assertTrue($queuedJob->isReserved());
	}

	public function testSetReservedAtCanSetToNull(): void
	{
		$queuedJob = new QueuedJob(
			'job_unreserve',
			'default',
			TestQueueJob::class,
			[],
			0,
			1234567890
		);

		$this->assertTrue($queuedJob->isReserved());

		$queuedJob->setReservedAt(null);

		$this->assertNull($queuedJob->getReservedAt());
		$this->assertFalse($queuedJob->isReserved());
	}

	public function testAllGettersReturnCorrectValues(): void
	{
		$queuedJob = new QueuedJob(
			'job_complete',
			'test-queue',
			TestQueueJob::class,
			['arg1' => 'val1', 'arg2' => 'val2'],
			5,
			1234567890,
			1234567800,
			1234567700
		);

		$this->assertEquals('job_complete', $queuedJob->getId());
		$this->assertEquals('test-queue', $queuedJob->getQueue());
		$this->assertEquals(TestQueueJob::class, $queuedJob->getJobClass());
		$this->assertEquals(['arg1' => 'val1', 'arg2' => 'val2'], $queuedJob->getArguments());
		$this->assertEquals(5, $queuedJob->getAttempts());
		$this->assertEquals(1234567890, $queuedJob->getReservedAt());
		$this->assertEquals(1234567800, $queuedJob->getAvailableAt());
		$this->assertEquals(1234567700, $queuedJob->getCreatedAt());
	}

	public function testGeneratedIdIsUnique(): void
	{
		$job1 = QueuedJob::fromJob(new TestQueueJob());
		$job2 = QueuedJob::fromJob(new TestQueueJob());
		$job3 = QueuedJob::fromJob(new TestQueueJob());

		$this->assertNotEquals($job1->getId(), $job2->getId());
		$this->assertNotEquals($job2->getId(), $job3->getId());
		$this->assertNotEquals($job1->getId(), $job3->getId());
	}

	public function testJobLifecycleSimulation(): void
	{
		// Create a job
		$job = QueuedJob::fromJob(
			new TestQueueJob(),
			['email' => 'test@example.com'],
			'emails',
			0
		);

		// Job should be available immediately
		$this->assertTrue($job->isAvailable());
		$this->assertFalse($job->isReserved());
		$this->assertEquals(0, $job->getAttempts());

		// Reserve the job
		$job->markAsReserved();
		$this->assertTrue($job->isReserved());

		// First attempt
		$job->incrementAttempts();
		$this->assertEquals(1, $job->getAttempts());

		// Job fails, release it and schedule for retry
		$job->setReservedAt(null);
		$job->setAvailableAt(time() + 60);
		$this->assertFalse($job->isReserved());
		$this->assertFalse($job->isAvailable());

		// Second attempt later
		$job->setAvailableAt(time() - 1);
		$this->assertTrue($job->isAvailable());
		$job->markAsReserved();
		$job->incrementAttempts();
		$this->assertEquals(2, $job->getAttempts());
		$this->assertTrue($job->isReserved());
	}
}
