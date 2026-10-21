<?php

namespace Neuron\Jobs\Queue;

/**
 * Optional read and housekeeping access to the jobs a store holds.
 *
 * IQueue moves jobs through their lifecycle; it cannot show what is waiting.
 * This interface adds enumeration and the two safe operator actions on
 * waiting jobs (drop, run now), plus paginated access to failed storage.
 * It is separate from IQueue, like IQueueStats, so third-party drivers keep
 * working without it; QueueManager::supportsInspection() tells callers
 * whether the configured driver provides it.
 *
 * Reserved jobs are read-only: a worker holds them. One whose reservation
 * has outlived `retry_after` is reclaimed by the next pop(), so no action is
 * offered for it here either.
 *
 * @package Neuron\Jobs\Queue
 */
interface IQueueInspector
{
	/**
	 * Jobs in one state on one queue, in the order a worker would take them
	 * (Pending and Delayed by available_at then id, Reserved by reserved_at).
	 *
	 * @param string $queue
	 * @param JobState $state
	 * @param int $limit
	 * @param int $offset
	 * @return list<QueuedJob>
	 */
	public function listJobs( string $queue, JobState $state, int $limit = 50, int $offset = 0 ): array;

	/**
	 * @param string $queue
	 * @param JobState $state
	 * @return int
	 */
	public function countJobs( string $queue, JobState $state ): int;

	/**
	 * A job by id, whatever queue or state it is in. Null when it is not in
	 * the store (completed jobs are deleted; failed ones are in failed storage).
	 *
	 * @param string $id
	 * @return QueuedJob|null
	 */
	public function findJob( string $id ): ?QueuedJob;

	/**
	 * Remove a pending or delayed job. False when it does not exist or a
	 * worker has it reserved.
	 *
	 * @param string $id
	 * @return bool
	 */
	public function deleteJob( string $id ): bool;

	/**
	 * Make a delayed job runnable now. True for a job that is already
	 * pending; false when it does not exist or is reserved.
	 *
	 * @param string $id
	 * @return bool
	 */
	public function releaseNow( string $id ): bool;

	/**
	 * Failed jobs, most recent first, in the row shape getFailedJobs() uses
	 * (id, queue, payload, exception, failed_at).
	 *
	 * @param int $limit
	 * @param int $offset
	 * @param string|null $queue Restrict to one queue
	 * @return list<array>
	 */
	public function listFailedJobs( int $limit = 50, int $offset = 0, ?string $queue = null ): array;

	/**
	 * @param string|null $queue Restrict to one queue
	 * @return int
	 */
	public function countFailedJobs( ?string $queue = null ): int;

	/**
	 * One failed job row, or null.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public function findFailedJob( string $id ): ?array;
}
