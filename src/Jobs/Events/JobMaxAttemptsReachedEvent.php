<?php

namespace Neuron\Jobs\Events;

use Neuron\Events\IEvent;
use Throwable;

/**
 * Event fired when a job exhausts all retry attempts and fails permanently.
 *
 * This event is triggered when a job has failed and reached the maximum
 * number of retry attempts configured in the queue system. The job is
 * moved to the failed jobs table/store and will not be retried automatically.
 *
 * Use cases:
 * - Send critical alerts requiring immediate human intervention
 * - Log permanent failures for post-mortem analysis
 * - Trigger manual review workflows
 * - Create tickets in issue tracking systems
 * - Update business processes that depend on the job
 * - Track job reliability and identify problematic jobs
 *
 * @package Neuron\Jobs\Events
 */
class JobMaxAttemptsReachedEvent implements IEvent
{
	/**
	 * @param string $jobClass Fully qualified class name of the job
	 * @param array $arguments Arguments passed to the job
	 * @param string $queue Queue name where job failed
	 * @param Throwable $exception The final exception that caused permanent failure
	 * @param int $maxAttempts Maximum number of attempts allowed
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly array $arguments,
		public readonly string $queue,
		public readonly Throwable $exception,
		public readonly int $maxAttempts
	)
	{
	}

	public function getName(): string
	{
		return 'job.max_attempts_reached';
	}
}
