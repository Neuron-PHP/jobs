<?php

namespace Neuron\Jobs\Queue;

/**
 * Optional per-job queue settings.
 *
 * Jobs implementing this alongside IJob can override the queue-wide
 * configuration for themselves. Every getter may return null to mean "use
 * the configured default", so a job can override one value without having
 * to restate the rest. Plain IJob classes are unaffected.
 *
 * @package Neuron\Jobs\Queue
 */
interface IQueueable
{
	/**
	 * Queue to dispatch onto when the caller does not name one.
	 *
	 * @return string|null
	 */
	public function getQueue(): ?string;

	/**
	 * Attempts before the job is moved to failed storage.
	 *
	 * @return int|null
	 */
	public function getMaxAttempts(): ?int;

	/**
	 * Base backoff in seconds between retries (doubles each attempt).
	 *
	 * @return int|null
	 */
	public function getBackoff(): ?int;

	/**
	 * Seconds the job may run before it is failed with JobTimeoutException.
	 * Overrides the worker's --timeout. 0 disables the timeout for this job.
	 *
	 * @return int|null
	 */
	public function getTimeout(): ?int;

	/**
	 * Called once when the job has exhausted its attempts, after the driver
	 * has stored it as failed. Exceptions thrown here are logged, not retried.
	 *
	 * @param \Throwable $exception The exception from the final attempt
	 * @return void
	 */
	public function failed( \Throwable $exception ): void;
}
