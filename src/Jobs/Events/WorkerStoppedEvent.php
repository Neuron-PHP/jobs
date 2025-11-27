<?php

namespace Neuron\Jobs\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when a queue worker stops processing jobs.
 *
 * This event is triggered when a worker gracefully shuts down or crashes,
 * allowing for cleanup operations and worker pool monitoring.
 *
 * Use cases:
 * - Monitor worker crashes and unexpected shutdowns
 * - Track worker uptime and operational metrics
 * - Alert when worker pool drops below healthy threshold
 * - Log worker shutdown reasons for debugging
 * - Update monitoring dashboards with active worker count
 * - Trigger worker restart or replacement procedures
 *
 * @package Neuron\Jobs\Events
 */
class WorkerStoppedEvent implements IEvent
{
	/**
	 * @param string $workerId Unique identifier for this worker instance
	 * @param int $totalJobsProcessed Total number of jobs processed by this worker
	 */
	public function __construct(
		public readonly string $workerId,
		public readonly int $totalJobsProcessed
	)
	{
	}

	public function getName(): string
	{
		return 'worker.stopped';
	}
}
