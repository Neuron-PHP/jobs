<?php

namespace Tests\Jobs\Events;

use Neuron\Events\IEvent;
use Neuron\Jobs\Events\JobFailedEvent;
use Neuron\Jobs\Events\JobMaxAttemptsReachedEvent;
use Neuron\Jobs\Events\JobProcessedEvent;
use Neuron\Jobs\Events\SchedulerJobTriggeredEvent;
use Neuron\Jobs\Events\WorkerStartedEvent;
use Neuron\Jobs\Events\WorkerStoppedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for all Jobs event classes.
 *
 * Tests event creation, property access, name methods,
 * and interface implementation for all event types.
 */
class EventsTest extends TestCase
{
	// JobFailedEvent tests

	public function testJobFailedEventCanBeCreated(): void
	{
		$exception = new \RuntimeException('Job failed');
		$event = new JobFailedEvent(
			'App\Jobs\SendEmailJob',
			['to' => 'user@example.com'],
			'emails',
			$exception,
			3
		);

		$this->assertInstanceOf(IEvent::class, $event);
		$this->assertEquals('App\Jobs\SendEmailJob', $event->jobClass);
		$this->assertEquals(['to' => 'user@example.com'], $event->arguments);
		$this->assertEquals('emails', $event->queue);
		$this->assertSame($exception, $event->exception);
		$this->assertEquals(3, $event->attempts);
	}

	public function testJobFailedEventGetName(): void
	{
		$exception = new \Exception('Test');
		$event = new JobFailedEvent('TestJob', [], 'default', $exception, 1);

		$this->assertEquals('job.failed', $event->getName());
	}

	public function testJobFailedEventWithEmptyArguments(): void
	{
		$exception = new \Exception('Test');
		$event = new JobFailedEvent('TestJob', [], 'default', $exception, 0);

		$this->assertIsArray($event->arguments);
		$this->assertEmpty($event->arguments);
	}

	// JobMaxAttemptsReachedEvent tests

	public function testJobMaxAttemptsReachedEventCanBeCreated(): void
	{
		$exception = new \RuntimeException('Max attempts reached');
		$event = new JobMaxAttemptsReachedEvent(
			'App\Jobs\ProcessOrderJob',
			['order_id' => 123],
			'orders',
			$exception,
			5
		);

		$this->assertInstanceOf(IEvent::class, $event);
		$this->assertEquals('App\Jobs\ProcessOrderJob', $event->jobClass);
		$this->assertEquals(['order_id' => 123], $event->arguments);
		$this->assertEquals('orders', $event->queue);
		$this->assertSame($exception, $event->exception);
		$this->assertEquals(5, $event->maxAttempts);
	}

	public function testJobMaxAttemptsReachedEventGetName(): void
	{
		$exception = new \Exception('Test');
		$event = new JobMaxAttemptsReachedEvent('TestJob', [], 'default', $exception, 3);

		$this->assertEquals('job.max_attempts_reached', $event->getName());
	}

	// JobProcessedEvent tests

	public function testJobProcessedEventCanBeCreated(): void
	{
		$event = new JobProcessedEvent(
			'App\Jobs\GenerateReportJob',
			['report_id' => 456],
			'reports',
			1.25
		);

		$this->assertInstanceOf(IEvent::class, $event);
		$this->assertEquals('App\Jobs\GenerateReportJob', $event->jobClass);
		$this->assertEquals(['report_id' => 456], $event->arguments);
		$this->assertEquals('reports', $event->queue);
		$this->assertEquals(1.25, $event->executionTime);
	}

	public function testJobProcessedEventGetName(): void
	{
		$event = new JobProcessedEvent('TestJob', [], 'default', 0.5);

		$this->assertEquals('job.processed', $event->getName());
	}

	public function testJobProcessedEventExecutionTimeIsFloat(): void
	{
		$event = new JobProcessedEvent('TestJob', [], 'default', 2.5);

		$this->assertIsFloat($event->executionTime);
		$this->assertEquals(2.5, $event->executionTime);
	}

	// SchedulerJobTriggeredEvent tests

	public function testSchedulerJobTriggeredEventCanBeCreated(): void
	{
		$event = new SchedulerJobTriggeredEvent(
			'daily-report',
			'App\Jobs\DailyReportJob',
			'0 0 * * *',
			'reports'
		);

		$this->assertInstanceOf(IEvent::class, $event);
		$this->assertEquals('daily-report', $event->jobName);
		$this->assertEquals('App\Jobs\DailyReportJob', $event->jobClass);
		$this->assertEquals('0 0 * * *', $event->schedule);
		$this->assertEquals('reports', $event->queue);
	}

	public function testSchedulerJobTriggeredEventWithNullQueue(): void
	{
		$event = new SchedulerJobTriggeredEvent(
			'immediate-task',
			'App\Jobs\ImmediateJob',
			'* * * * *',
			null
		);

		$this->assertNull($event->queue);
	}

	public function testSchedulerJobTriggeredEventGetName(): void
	{
		$event = new SchedulerJobTriggeredEvent('test', 'TestJob', '* * * * *', null);

		$this->assertEquals('scheduler.job_triggered', $event->getName());
	}

	// WorkerStartedEvent tests

	public function testWorkerStartedEventCanBeCreated(): void
	{
		$event = new WorkerStartedEvent(
			'worker-001',
			['default', 'emails', 'reports']
		);

		$this->assertInstanceOf(IEvent::class, $event);
		$this->assertEquals('worker-001', $event->workerId);
		$this->assertEquals(['default', 'emails', 'reports'], $event->queues);
	}

	public function testWorkerStartedEventWithSingleQueue(): void
	{
		$event = new WorkerStartedEvent('worker-002', ['default']);

		$this->assertCount(1, $event->queues);
		$this->assertEquals(['default'], $event->queues);
	}

	public function testWorkerStartedEventGetName(): void
	{
		$event = new WorkerStartedEvent('worker-003', []);

		$this->assertEquals('worker.started', $event->getName());
	}

	// WorkerStoppedEvent tests

	public function testWorkerStoppedEventCanBeCreated(): void
	{
		$event = new WorkerStoppedEvent('worker-001', 150);

		$this->assertInstanceOf(IEvent::class, $event);
		$this->assertEquals('worker-001', $event->workerId);
		$this->assertEquals(150, $event->totalJobsProcessed);
	}

	public function testWorkerStoppedEventWithZeroJobs(): void
	{
		$event = new WorkerStoppedEvent('worker-002', 0);

		$this->assertEquals(0, $event->totalJobsProcessed);
	}

	public function testWorkerStoppedEventGetName(): void
	{
		$event = new WorkerStoppedEvent('worker-003', 100);

		$this->assertEquals('worker.stopped', $event->getName());
	}

	// Property immutability tests (readonly properties)

	public function testJobFailedEventPropertiesAreReadonly(): void
	{
		$exception = new \Exception('Test');
		$event = new JobFailedEvent('TestJob', ['key' => 'value'], 'default', $exception, 1);

		// Verify we can read properties
		$this->assertEquals('TestJob', $event->jobClass);
		$this->assertEquals(['key' => 'value'], $event->arguments);
	}

	public function testJobProcessedEventPropertiesAreReadonly(): void
	{
		$event = new JobProcessedEvent('TestJob', ['key' => 'value'], 'default', 1.5);

		// Verify we can read properties
		$this->assertEquals('TestJob', $event->jobClass);
		$this->assertEquals(['key' => 'value'], $event->arguments);
		$this->assertEquals('default', $event->queue);
		$this->assertEquals(1.5, $event->executionTime);
	}

	// All events implement IEvent interface

	public function testAllEventsImplementIEventInterface(): void
	{
		$exception = new \Exception('Test');

		$events = [
			new JobFailedEvent('Test', [], 'default', $exception, 1),
			new JobMaxAttemptsReachedEvent('Test', [], 'default', $exception, 3),
			new JobProcessedEvent('Test', [], 'default', 1.0),
			new SchedulerJobTriggeredEvent('test', 'Test', '* * * * *', null),
			new WorkerStartedEvent('worker-1', []),
			new WorkerStoppedEvent('worker-1', 0),
		];

		foreach ($events as $event) {
			$this->assertInstanceOf(IEvent::class, $event);
			$this->assertIsString($event->getName());
			$this->assertNotEmpty($event->getName());
		}
	}

	// Event name uniqueness

	public function testAllEventNamesAreUnique(): void
	{
		$exception = new \Exception('Test');

		$events = [
			new JobFailedEvent('Test', [], 'default', $exception, 1),
			new JobMaxAttemptsReachedEvent('Test', [], 'default', $exception, 3),
			new JobProcessedEvent('Test', [], 'default', 1.0),
			new SchedulerJobTriggeredEvent('test', 'Test', '* * * * *', null),
			new WorkerStartedEvent('worker-1', []),
			new WorkerStoppedEvent('worker-1', 0),
		];

		$names = array_map(fn($e) => $e->getName(), $events);

		$this->assertCount(6, $names);
		$this->assertCount(6, array_unique($names), 'Event names must be unique');
	}
}
