<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;

/**
 * Queue interface for managing job queues.
 *
 * This interface defines the contract for different queue drivers
 * (database, file, Redis, etc.) to implement job queue functionality.
 *
 * @package Neuron\Jobs\Queue
 */
interface IQueue
{
	/**
	 * Push a job onto the queue
	 *
	 * @param IJob $job Job instance to queue
	 * @param array $args Arguments to pass to the job
	 * @param string $queue Queue name (default: 'default')
	 * @param int $delay Delay in seconds before the job is available
	 * @return string Job ID
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string;

	/**
	 * Pop the next available job from the queue
	 *
	 * @param string $queue Queue name (default: 'default')
	 * @return QueuedJob|null The next job, or null if queue is empty
	 */
	public function pop( string $queue = 'default' ): ?QueuedJob;

	/**
	 * Release a job back to the queue
	 * Used when a job fails and should be retried
	 *
	 * @param QueuedJob $job Job to release
	 * @param int $delay Delay in seconds before job is available again
	 * @return void
	 */
	public function release( QueuedJob $job, int $delay = 0 ): void;

	/**
	 * Delete a job from the queue
	 * Called when a job completes successfully
	 *
	 * @param QueuedJob $job Job to delete
	 * @return void
	 */
	public function delete( QueuedJob $job ): void;

	/**
	 * Mark a job as failed
	 * Moves the job to failed jobs storage
	 *
	 * @param QueuedJob $job Failed job
	 * @param \Throwable $exception Exception that caused the failure
	 * @return void
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void;

	/**
	 * Get the size of the queue
	 *
	 * @param string $queue Queue name (default: 'default')
	 * @return int Number of jobs in the queue
	 */
	public function size( string $queue = 'default' ): int;

	/**
	 * Clear all jobs from the queue
	 *
	 * @param string $queue Queue name (default: 'default')
	 * @return int Number of jobs cleared
	 */
	public function clear( string $queue = 'default' ): int;

	/**
	 * Get all failed jobs
	 *
	 * @return array Array of failed job data
	 */
	public function getFailedJobs(): array;

	/**
	 * Retry a failed job
	 *
	 * @param string $id Failed job ID
	 * @return bool True if job was retried, false if not found
	 */
	public function retryFailedJob( string $id ): bool;

	/**
	 * Delete a failed job
	 *
	 * @param string $id Failed job ID
	 * @return bool True if job was deleted, false if not found
	 */
	public function forgetFailedJob( string $id ): bool;

	/**
	 * Clear all failed jobs
	 *
	 * @return int Number of failed jobs cleared
	 */
	public function clearFailedJobs(): int;
}
