<?php

namespace Neuron\Jobs\Queue\Heartbeat;

/**
 * Liveness records for running workers.
 *
 * Opt-in via `queue.heartbeat: database`. The worker touches its row
 * between jobs (including idle loops) so the dashboard can tell a live
 * worker from one that died mid-reservation.
 *
 * @package Neuron\Jobs\Queue\Heartbeat
 */
interface IWorkerHeartbeat
{
	/**
	 * Create or refresh this worker's row.
	 *
	 * @param string $id Worker id (stable for the process lifetime)
	 * @param list<string> $queues
	 * @param int $jobsProcessed
	 * @param int $startedAt
	 */
	public function touch( string $id, array $queues, int $jobsProcessed, int $startedAt ): void;

	/**
	 * Current workers, newest last_seen first.
	 *
	 * @return list<array{id:string,queues:list<string>,jobs_processed:int,started_at:int,last_seen:int,pid:?int,hostname:?string}>
	 */
	public function listWorkers(): array;

	/**
	 * Remove rows whose last_seen is older than $olderThan unix time.
	 *
	 * @return int Rows removed
	 */
	public function prune( int $olderThan ): int;
}
