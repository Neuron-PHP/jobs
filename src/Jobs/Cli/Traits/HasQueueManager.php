<?php

namespace Neuron\Jobs\Cli\Traits;

use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;

/**
 * Trait for CLI commands that need access to the QueueManager.
 *
 * Provides a common method to get or initialize the QueueManager instance
 * from the registry.
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
		$registry = Registry::getInstance();
		$queueManager = $registry->get( 'queue.manager' );

		if( !$queueManager )
		{
			$settings = $registry->get( 'settings' );
			$queueManager = new QueueManager( $settings );
			$registry->set( 'queue.manager', $queueManager );
		}

		return $queueManager;
	}
}
