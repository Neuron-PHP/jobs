<?php

namespace Neuron\Jobs\Queue\Heartbeat;

/**
 * In-memory worker heartbeats for tests.
 *
 * @package Neuron\Jobs\Queue\Heartbeat
 */
class ArrayWorkerHeartbeat implements IWorkerHeartbeat
{
	/** @var array<string,array> */
	private array $_workers = [];

	public function touch( string $id, array $queues, int $jobsProcessed, int $startedAt ): void
	{
		$this->_workers[ $id ] = [
			'id' => $id,
			'queues' => array_values( $queues ),
			'jobs_processed' => $jobsProcessed,
			'started_at' => $startedAt,
			'last_seen' => time(),
			'pid' => getmypid() ?: null,
			'hostname' => gethostname() ?: null,
		];
	}

	public function listWorkers(): array
	{
		$workers = array_values( $this->_workers );
		usort( $workers, fn( array $a, array $b ) => $b['last_seen'] <=> $a['last_seen'] );

		return $workers;
	}

	public function prune( int $olderThan ): int
	{
		$removed = 0;

		foreach( $this->_workers as $id => $worker )
		{
			if( $worker['last_seen'] < $olderThan )
			{
				unset( $this->_workers[ $id ] );
				$removed++;
			}
		}

		return $removed;
	}
}
