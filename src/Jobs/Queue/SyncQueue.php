<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

/**
 * Synchronous queue implementation.
 *
 * Executes jobs immediately without queueing.
 * Useful for testing and local development.
 *
 * @package Neuron\Jobs\Queue
 */
class SyncQueue implements IQueue
{
	/**
	 * @inheritDoc
	 * Executes job immediately instead of queueing
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string
	{
		$id = uniqid( 'sync_', true );

		Log::debug( "Executing job synchronously: " . get_class( $job ) );

		try
		{
			$job->run( $args );
			Log::info( "Sync job completed: " . get_class( $job ) );
		}
		catch( \Throwable $e )
		{
			Log::error( "Sync job failed: " . get_class( $job ) . " - " . $e->getMessage() );
			throw $e;
		}

		return $id;
	}

	/**
	 * @inheritDoc
	 * Always returns null since jobs execute immediately
	 */
	public function pop( string $queue = 'default' ): ?QueuedJob
	{
		return null;
	}

	/**
	 * @inheritDoc
	 * No-op since jobs execute immediately
	 */
	public function release( QueuedJob $job, int $delay = 0 ): void
	{
		// No-op
	}

	/**
	 * @inheritDoc
	 * No-op since jobs execute immediately
	 */
	public function delete( QueuedJob $job ): void
	{
		// No-op
	}

	/**
	 * @inheritDoc
	 * Logs failure but doesn't store
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void
	{
		Log::error( "Sync job failed: {$job->getJobClass()} - {$exception->getMessage()}" );
	}

	/**
	 * @inheritDoc
	 * Always returns 0 since jobs execute immediately
	 */
	public function size( string $queue = 'default' ): int
	{
		return 0;
	}

	/**
	 * @inheritDoc
	 * Always returns 0 since no jobs are queued
	 */
	public function clear( string $queue = 'default' ): int
	{
		return 0;
	}

	/**
	 * @inheritDoc
	 * Always returns empty array
	 */
	public function getFailedJobs(): array
	{
		return [];
	}

	/**
	 * @inheritDoc
	 * Always returns false since no jobs are stored
	 */
	public function retryFailedJob( string $id ): bool
	{
		return false;
	}

	/**
	 * @inheritDoc
	 * Always returns false since no jobs are stored
	 */
	public function forgetFailedJob( string $id ): bool
	{
		return false;
	}

	/**
	 * @inheritDoc
	 * Always returns 0 since no jobs are stored
	 */
	public function clearFailedJobs(): int
	{
		return 0;
	}
}
