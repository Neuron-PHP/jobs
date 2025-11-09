<?php

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;

if( !function_exists( 'dispatch' ) )
{
	/**
	 * Dispatch a job to the queue for background processing
	 *
	 * @param IJob $job Job instance to queue
	 * @param array $args Arguments to pass to the job
	 * @param string|null $queue Queue name (null = default)
	 * @param int $delay Delay in seconds before job is available
	 * @return string Job ID
	 *
	 * @example
	 * // Basic usage
	 * dispatch(new SendEmailJob(), ['to' => 'user@example.com']);
	 *
	 * // With specific queue
	 * dispatch(new ProcessImageJob(), ['path' => '/tmp/image.jpg'], 'images');
	 *
	 * // Delayed job (run in 1 hour)
	 * dispatch(new SendReminderJob(), ['order_id' => 123], 'default', 3600);
	 */
	function dispatch( IJob $job, array $args = [], ?string $queue = null, int $delay = 0 ): string
	{
		$queueManager = getQueueManager();

		return $queueManager->dispatch( $job, $args, $queue, $delay );
	}
}

if( !function_exists( 'dispatchNow' ) )
{
	/**
	 * Execute a job immediately (synchronously), bypassing the queue
	 *
	 * Useful for testing or when you want to ensure a job runs
	 * in the current process without queueing.
	 *
	 * @param IJob $job Job instance to execute
	 * @param array $args Arguments to pass to the job
	 * @return mixed Job result
	 *
	 * @example
	 * $result = dispatchNow(new ProcessDataJob(), ['data' => $data]);
	 */
	function dispatchNow( IJob $job, array $args = [] ): mixed
	{
		$queueManager = getQueueManager();

		return $queueManager->dispatchNow( $job, $args );
	}
}

if( !function_exists( 'getQueueManager' ) )
{
	/**
	 * Get the queue manager instance from registry
	 *
	 * Creates a new instance if one doesn't exist.
	 *
	 * @return QueueManager
	 */
	function getQueueManager(): QueueManager
	{
		$registry = Registry::getInstance();

		$queueManager = $registry->get( 'queue.manager' );

		if( !$queueManager )
		{
			// Try to get settings from registry
			$settings = $registry->get( 'settings' );

			$queueManager = new QueueManager( $settings );
			$registry->set( 'queue.manager', $queueManager );
		}

		return $queueManager;
	}
}

if( !function_exists( 'queueSize' ) )
{
	/**
	 * Get the size of a queue
	 *
	 * @param string|null $queue Queue name (null = default)
	 * @return int Number of jobs in queue
	 *
	 * @example
	 * $size = queueSize(); // default queue
	 * $emailQueueSize = queueSize('emails');
	 */
	function queueSize( ?string $queue = null ): int
	{
		$queueManager = getQueueManager();

		return $queueManager->size( $queue );
	}
}

if( !function_exists( 'clearQueue' ) )
{
	/**
	 * Clear all jobs from a queue
	 *
	 * @param string|null $queue Queue name (null = default)
	 * @return int Number of jobs cleared
	 *
	 * @example
	 * $cleared = clearQueue('emails');
	 */
	function clearQueue( ?string $queue = null ): int
	{
		$queueManager = getQueueManager();

		return $queueManager->clear( $queue );
	}
}
