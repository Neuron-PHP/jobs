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
class ArrayQueue implements IQueue, IQueueStats, IUniqueQueue, IQueueInspector
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
	public function findPending( string $jobClass, array $args, string $queue ): ?string
	{
		foreach( $this->_jobs[$queue] ?? [] as $job )
		{
			if( $job->getReservedAt() === null && $job->getJobClass() === $jobClass && $job->getArguments() === $args )
			{
				return $job->getId();
			}
		}

		return null;
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
	public function getQueueNames(): array
	{
		$names = array_keys( array_filter( $this->_jobs, fn( array $jobs ) => $jobs !== [] ) );

		sort( $names );

		return array_map( 'strval', $names );
	}

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
			$result[] = $this->failedRow( (string)$id, $record );
		}

		return $result;
	}

	/**
	 * @inheritDoc
	 */
	public function listJobs( string $queue, JobState $state, int $limit = 50, int $offset = 0 ): array
	{
		$now = time();

		$matching = array_values( array_filter(
			$this->_jobs[$queue] ?? [],
			fn( QueuedJob $job ) => JobState::of( $job->getReservedAt(), $job->getAvailableAt(), $now ) === $state
		) );

		usort( $matching, function( QueuedJob $a, QueuedJob $b ) use ( $state ) {
			$keyA = $state === JobState::Reserved ? (int)$a->getReservedAt() : $a->getAvailableAt();
			$keyB = $state === JobState::Reserved ? (int)$b->getReservedAt() : $b->getAvailableAt();

			return [ $keyA, $a->getId() ] <=> [ $keyB, $b->getId() ];
		} );

		return array_slice( $matching, max( 0, $offset ), max( 1, $limit ) );
	}

	/**
	 * @inheritDoc
	 */
	public function countJobs( string $queue, JobState $state ): int
	{
		$now = time();
		$count = 0;

		foreach( $this->_jobs[$queue] ?? [] as $job )
		{
			if( JobState::of( $job->getReservedAt(), $job->getAvailableAt(), $now ) === $state )
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * @inheritDoc
	 */
	public function findJob( string $id ): ?QueuedJob
	{
		foreach( $this->_jobs as $jobs )
		{
			if( isset( $jobs[$id] ) )
			{
				return $jobs[$id];
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteJob( string $id ): bool
	{
		$job = $this->findJob( $id );

		if( $job === null || $job->getReservedAt() !== null )
		{
			return false;
		}

		unset( $this->_jobs[$job->getQueue()][$id] );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function releaseNow( string $id ): bool
	{
		$job = $this->findJob( $id );

		if( $job === null || $job->getReservedAt() !== null )
		{
			return false;
		}

		$now = time();

		if( $job->getAvailableAt() > $now )
		{
			$job->setAvailableAt( $now );
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function listFailedJobs( int $limit = 50, int $offset = 0, ?string $queue = null ): array
	{
		$rows = $this->getFailedJobs();

		if( $queue !== null )
		{
			$rows = array_values( array_filter( $rows, fn( array $row ) => $row['queue'] === $queue ) );
		}

		return array_slice( $rows, max( 0, $offset ), max( 1, $limit ) );
	}

	/**
	 * @inheritDoc
	 */
	public function countFailedJobs( ?string $queue = null ): int
	{
		if( $queue === null )
		{
			return count( $this->_failed );
		}

		return count( $this->listFailedJobs( PHP_INT_MAX, 0, $queue ) );
	}

	/**
	 * @inheritDoc
	 */
	public function findFailedJob( string $id ): ?array
	{
		if( !isset( $this->_failed[$id] ) )
		{
			return null;
		}

		return $this->failedRow( $id, $this->_failed[$id] );
	}

	/**
	 * @param string $id
	 * @param array{job: QueuedJob, exception: \Throwable} $record
	 * @return array
	 */
	private function failedRow( string $id, array $record ): array
	{
		$job = $record['job'];
		$exception = $record['exception'];

		return [
			'id' => $id,
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'exception' => get_class( $exception ) . ': ' . $exception->getMessage(),
			'failed_at' => time()
		];
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
