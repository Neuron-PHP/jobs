<?php

namespace Neuron\Jobs\Events;

use Neuron\Events\IEvent;
use Throwable;

/**
 * Event fired when a job fails with an exception.
 *
 * This event is triggered when a job's run() method throws an exception.
 * The job may be retried depending on the retry configuration.
 *
 * Use cases:
 * - Alert on job failures for immediate attention
 * - Track error patterns and failure rates
 * - Log detailed exception information for debugging
 * - Trigger fallback or compensation logic
 * - Send notifications to development team
 * - Update monitoring dashboards with failure metrics
 *
 * @package Neuron\Jobs\Events
 */
class JobFailedEvent implements IEvent
{
	/**
	 * @param string $jobClass Fully qualified class name of the job
	 * @param array $arguments Arguments passed to the job
	 * @param string $queue Queue name where job failed
	 * @param Throwable $exception The exception that caused the failure
	 * @param int $attempts Number of attempts made so far
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly array $arguments,
		public readonly string $queue,
		public readonly Throwable $exception,
		public readonly int $attempts
	)
	{
	}

	public function getName(): string
	{
		return 'job.failed';
	}
}
