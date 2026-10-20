<?php

namespace Neuron\Jobs\Cli\Traits;

use Neuron\Jobs\Queue\QueueManager;

/**
 * Trait for CLI commands that need access to the QueueManager.
 *
 * Delegates to QueueManager::fromRegistry() so commands share the exact
 * resolution path used by dispatch() and the scheduler.
 */
trait HasQueueManager
{
	/**
	 * Get or initialize the QueueManager instance.
	 *
	 * @return QueueManager|null
	 */
	private function getQueueManager(): ?QueueManager
	{
		return QueueManager::fromRegistry();
	}
}
