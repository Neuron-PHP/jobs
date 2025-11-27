<?php

namespace Neuron\Jobs\Events;

use Neuron\Events\IEvent;

/**
 * Event fired when a queue worker starts processing jobs.
 *
 * This event is triggered when a worker begins its run loop and starts
 * listening for jobs from the queue.
 *
 * Use cases:
 * - Monitor worker pool health and availability
 * - Track worker uptime and operational status
 * - Send notifications when workers start (deployment verification)
 * - Log worker startup for debugging and audit trails
 * - Update monitoring dashboards with active worker count
 * - Trigger worker registration in service discovery systems
 *
 * @package Neuron\Jobs\Events
 */
class WorkerStartedEvent implements IEvent
{
	/**
	 * @param string $workerId Unique identifier for this worker instance
	 * @param array $queues Array of queue names this worker processes
	 */
	public function __construct(
		public readonly string $workerId,
		public readonly array $queues
	)
	{
	}

	public function getName(): string
	{
		return 'worker.started';
	}
}
