<?php

namespace Neuron\Jobs\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when the scheduler determines a scheduled job is due to run.
 *
 * This event is triggered when the scheduler's cron expression evaluates
 * to true and the job is either executed directly or dispatched to a queue.
 *
 * Use cases:
 * - Audit trail of scheduled job executions
 * - Track when scheduled jobs run for compliance
 * - Monitor scheduler health and reliability
 * - Verify scheduled jobs are running on expected schedule
 * - Send notifications when critical scheduled jobs trigger
 * - Debug scheduling issues and cron expressions
 *
 * @package Neuron\Jobs\Events
 */
class SchedulerJobTriggeredEvent implements IEvent
{
	/**
	 * @param string $jobName Name of the scheduled job
	 * @param string $jobClass Fully qualified class name of the job
	 * @param string $schedule Cron expression for this job
	 * @param string|null $queue Queue name if job is queued, null if run directly
	 */
	public function __construct(
		public readonly string $jobName,
		public readonly string $jobClass,
		public readonly string $schedule,
		public readonly ?string $queue
	)
	{
	}

	public function getName(): string
	{
		return 'scheduler.job_triggered';
	}
}
