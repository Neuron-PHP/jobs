<?php

namespace Neuron\Jobs\Queue\History;

/**
 * Store of finished (and failed) job runs for inspection and re-dispatch.
 *
 * Opt-in via `queue.history: database`. Implementations must tolerate a
 * missing table and create it on first use.
 *
 * @package Neuron\Jobs\Queue\History
 */
interface IJobHistory
{
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED = 'failed';

	/**
	 * Persist one run. Keys: id, job_id, job_class, queue, payload, status,
	 * exception, started_at, finished_at, duration_ms, attempts.
	 *
	 * @param array $run
	 * @return void
	 */
	public function record( array $run ): void;

	/**
	 * @param array{queue?:?string,class?:?string,status?:?string,since?:?int,until?:?int} $filters
	 * @return list<array>
	 */
	public function listRuns( array $filters = [], int $limit = 50, int $offset = 0 ): array;

	/**
	 * @param array{queue?:?string,class?:?string,status?:?string,since?:?int,until?:?int} $filters
	 */
	public function countRuns( array $filters = [] ): int;

	public function findRun( string $id ): ?array;

	/**
	 * Duration percentiles in milliseconds for completed runs.
	 *
	 * @param array{queue?:?string,class?:?string,since?:?int,until?:?int} $filters
	 * @return array{p50:?int,p95:?int,count:int}
	 */
	public function durationPercentiles( array $filters = [] ): array;

	/**
	 * @param array{queue?:?string,class?:?string,since?:?int,until?:?int} $filters
	 * @return array{completed:int,failed:int}
	 */
	public function countsByStatus( array $filters = [] ): array;

	/**
	 * Distinct job classes that have runs matching the filters, for the
	 * history page dropdown.
	 *
	 * @param array{queue?:?string,since?:?int,until?:?int} $filters
	 * @return list<string>
	 */
	public function classes( array $filters = [] ): array;

	/**
	 * Distinct queues that have runs matching the filters.
	 *
	 * @param array{class?:?string,since?:?int,until?:?int} $filters
	 * @return list<string>
	 */
	public function queues( array $filters = [] ): array;

	/**
	 * Remove runs older than the retention window.
	 *
	 * @return int Rows removed
	 */
	public function prune(): int;
}
