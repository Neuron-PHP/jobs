<?php

namespace Neuron\Jobs\Queue;

/**
 * A driver that can find a job already waiting to run, so that IUniqueJob
 * jobs are not queued twice.
 *
 * @package Neuron\Jobs\Queue
 */
interface IUniqueQueue
{
	/**
	 * The id of a pending job - queued and not reserved by a worker - with this
	 * class and these arguments, or null if there is none.
	 *
	 * @param string $jobClass
	 * @param array $args
	 * @param string $queue
	 * @return string|null
	 */
	public function findPending( string $jobClass, array $args, string $queue ): ?string;
}
