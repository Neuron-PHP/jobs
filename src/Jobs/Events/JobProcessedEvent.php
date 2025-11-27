<?php

namespace Neuron\Jobs\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when a job completes successfully.
 *
 * This event is triggered after a job's run() method completes without
 * throwing an exception, indicating successful execution.
 *
 * Use cases:
 * - Track job completion times and performance metrics
 * - Calculate job success rates and reliability statistics
 * - Trigger dependent jobs or workflows
 * - Log successful job executions for audit trails
 * - Update job status in monitoring dashboards
 * - Send notifications when critical jobs complete
 *
 * @package Neuron\Jobs\Events
 */
class JobProcessedEvent implements IEvent
{
	/**
	 * @param string $jobClass Fully qualified class name of the job
	 * @param array $arguments Arguments passed to the job
	 * @param string $queue Queue name where job was processed
	 * @param float $executionTime Execution time in seconds
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly array $arguments,
		public readonly string $queue,
		public readonly float $executionTime
	)
	{
	}

	public function getName(): string
	{
		return 'job.processed';
	}
}
