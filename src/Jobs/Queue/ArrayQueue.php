<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

/**
 * In-memory queue for tests.
 *
 * Behaves like a real driver (jobs are stored, reserved, released, retried
 * and moved to failed storage) but nothing leaves the process, and every push
 * is also recorded so tests can assert on what was dispatched without
 * processing anything. See Neuron\Jobs\Testing\QueueAssertions.
 *
 * Select with `driver: array` or build directly.
 *
 * @package Neuron\Jobs\Queue
 */
class ArrayQueue implements IQueue, IQueueStats
{
	/** @var array<string, array<string, QueuedJob>> queue name => id => job */
	private array $_jobs = [];

	/** @var array<string, array{job: QueuedJob, exception: \Throwable}> id => record */
	private array $_failed = [];

	/** @var QueuedJob[] every job ever pushed, in order */
	private array $_dispatched = [];

	private int $_retryAfter;

	/**
	 * @param array $config Supports `retry_after` (seconds a reservation lasts)
	 */
	public function __construct( array $config = [] )
	{
		$this->_retryAfter = (int)( $config['retry_after'] ?? 90 );
	}

	/**
	 * @inheritDoc
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string
	{
		$queuedJob = QueuedJob::fromJob( $job, $args, $queue, $delay );

		$this->_jobs[$queue][$queuedJob->getId()] = $queuedJob;
		$this->_dispatched[] = $queuedJob;

		Log::debug( "Job pushed to array queue: {$queuedJob->getId()} ({$queuedJob->getJobClass()})" );

		return $queuedJob->getId();
	}

	/**
	 * @inheritDoc
	 */
	public function pop( string $queue = 'default' ): ?QueuedJob
	{
		$now = time();

		foreach( $this->_jobs[$queue] ?? [] as $job )
		{
			if( $job->getReservedAt() !== null && $job->getReservedAt() >= $now - $this->_retryAfter )
			{
				continue;
			}

			if( $job->getAvailableAt() > $now )
			{
				continue;
			}

			$job->incrementAttempts();
			$job->setReservedAt( $now );

			return $job;
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function release( QueuedJob $job, int $delay = 0 ): void
	{
		$stored = $this->_jobs[$job->getQueue()][$job->getId()] ?? null;

		if( $stored === null )
		{
			return;
		}

		$stored->setReservedAt( null );
		$stored->setAvailableAt( time() + $delay );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( QueuedJob $job ): void
	{
		unset( $this->_jobs[$job->getQueue()][$job->getId()] );
	}

	/**
	 * @inheritDoc
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void
	{
		$this->_failed[$job->getId()] = [ 'job' => $job, 'exception' => $exception ];

		$this->delete( $job );
	}

	/**
	 * @inheritDoc
	 * Reserved jobs are excluded, matching the other drivers.
	 */
	public function size( string $queue = 'default' ): int
	{
		$count = 0;

		foreach( $this->_jobs[$queue] ?? [] as $job )
		{
			if( $job->getReservedAt() === null )
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @inheritDoc
	 */
	public function getStats( string $queue = 'default' ): array
	{
		$stats = [ 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age' => null ];
		$now = time();
		$oldest = null;

		foreach( $this->_jobs[$queue] ?? [] as $job )
		{
			if( $job->getReservedAt() !== null )
			{
				$stats['reserved']++;
			}
			elseif( $job->getAvailableAt() > $now )
			{
				$stats['delayed']++;
			}
			else
			{
				$stats['pending']++;
				$oldest = $oldest === null ? $job->getAvailableAt() : min( $oldest, $job->getAvailableAt() );
			}
		}

		$stats['oldest_pending_age'] = $oldest === null ? null : max( 0, $now - $oldest );

		return $stats;
	}

	/**
	 * @inheritDoc
	 */
	public function clear( string $queue = 'default' ): int
	{
		$count = count( $this->_jobs[$queue] ?? [] );

		unset( $this->_jobs[$queue] );

		return $count;
	}

	/**
	 * @inheritDoc
	 */
	public function getFailedJobs(): array
	{
		$result = [];

		foreach( $this->_failed as $id => $record )
		{
			/** @var QueuedJob $job */
			$job = $record['job'];
			/** @var \Throwable $exception */
			$exception = $record['exception'];

			$result[] = [
				'id' => $id,
				'queue' => $job->getQueue(),
				'payload' => $job->getPayload(),
				'exception' => get_class( $exception ) . ': ' . $exception->getMessage(),
				'failed_at' => time()
			];
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public function retryFailedJob( string $id ): bool
	{
		$record = $this->_failed[$id] ?? null;

		if( $record === null )
		{
			return false;
		}

		/** @var QueuedJob $failed */
		$failed = $record['job'];

		$job = QueuedJob::fromPayload( $failed->getId(), $failed->getQueue(), $failed->getPayload(), 0, null, time(), time() );

		$this->_jobs[$job->getQueue()][$job->getId()] = $job;

		unset( $this->_failed[$id] );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function forgetFailedJob( string $id ): bool
	{
		if( !isset( $this->_failed[$id] ) )
		{
			return false;
		}

		unset( $this->_failed[$id] );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function clearFailedJobs(): int
	{
		$count = count( $this->_failed );

		$this->_failed = [];

		return $count;
	}

	/**
	 * Every job pushed since construction or the last reset(), whether or not
	 * it has since been processed.
	 *
	 * @param string|null $jobClass Restrict to one job class
	 * @return QueuedJob[]
	 */
	public function getDispatched( ?string $jobClass = null ): array
	{
		if( $jobClass === null )
		{
			return $this->_dispatched;
		}

		$jobClass = ltrim( $jobClass, '\\' );

		return array_values( array_filter(
			$this->_dispatched,
			fn( QueuedJob $job ) => $job->getJobClass() === $jobClass
		) );
	}

	/**
	 * Jobs currently waiting in a queue (pushed and not yet deleted/failed).
	 *
	 * @param string $queue
	 * @return QueuedJob[]
	 */
	public function getPending( string $queue = 'default' ): array
	{
		return array_values( $this->_jobs[$queue] ?? [] );
	}

	/**
	 * Names of every queue that has had a job pushed to it.
	 *
	 * @return string[]
	 */
	public function getQueues(): array
	{
		return array_keys( $this->_jobs );
	}

	/**
	 * Forget everything: pending, failed and the dispatch record.
	 *
	 * @return void
	 */
	public function reset(): void
	{
		$this->_jobs = [];
		$this->_failed = [];
		$this->_dispatched = [];
	}
}
