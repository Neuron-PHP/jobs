<?php

namespace Neuron\Jobs\Queue;

/**
 * Optional, richer per-queue statistics for drivers that can provide them.
 *
 * Kept separate from IQueue so third-party drivers are not broken by the
 * addition; `jobs:stats` uses it when the driver implements it and falls back
 * to size() otherwise.
 *
 * @package Neuron\Jobs\Queue
 */
interface IQueueStats
{
	/**
	 * Statistics for one queue.
	 *
	 * Keys:
	 *  - pending   int       jobs waiting and available to run now
	 *  - delayed   int       jobs waiting whose available_at is in the future
	 *  - reserved  int       jobs currently held by a worker
	 *  - oldest_pending_age  int|null  seconds the longest-waiting available job has waited, null if none
	 *
	 * @param string $queue
	 * @return array{pending:int,delayed:int,reserved:int,oldest_pending_age:?int}
	 */
	public function getStats( string $queue = 'default' ): array;

	/**
	 * Names of the queues that currently hold jobs (pending, delayed or
	 * reserved), so tooling can report on what is actually there rather than
	 * on a configured list. Sorted; empty when the store is empty.
	 *
	 * @return string[]
	 */
	public function getQueueNames(): array;
}
