<?php

namespace Neuron\Jobs\Queue;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Jobs\Exceptions\JobReportedFailureException;
use Neuron\Jobs\Exceptions\JobTimeoutException;
use Neuron\Data\Database\ConnectionFactory;
use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\Heartbeat\ArrayWorkerHeartbeat;
use Neuron\Jobs\Queue\Heartbeat\DatabaseWorkerHeartbeat;
use Neuron\Jobs\Queue\Heartbeat\IWorkerHeartbeat;
use Neuron\Jobs\Queue\History\ArrayJobHistory;
use Neuron\Jobs\Queue\History\DatabaseJobHistory;
use Neuron\Jobs\Queue\History\IJobHistory;
use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Log\Log;
use Neuron\Patterns\Container\IContainer;
use Neuron\Patterns\Registry;

/**
 * Queue manager facade for job queue operations.
 *
 * Provides a unified interface for dispatching jobs, managing workers,
 * and handling failed jobs. Manages queue driver instantiation and configuration.
 *
 * @package Neuron\Jobs\Queue
 */
class QueueManager
{
	/**
	 * Registry key under which the shared QueueManager instance is stored.
	 */
	public const REGISTRY_KEY = 'queue.manager';

	private IQueue $_driver;
	private array $_config;
	private int $_maxAttempts = 3;
	private int $_backoff = 0;
	private ?IJobHistory $_history = null;
	private ?IWorkerHeartbeat $_heartbeat = null;

	/**
	 * @param ISettingSource|null $settings Settings source for configuration
	 * @param array|null $config Direct configuration array (overrides settings)
	 * @param \PDO|null $connection Existing connection for the database driver.
	 *        When given, the driver reuses it instead of opening its own, so a
	 *        process shares one connection between its application code and the
	 *        queue (important for SQLite, where every extra writer contends).
	 */
	public function __construct( ?ISettingSource $settings = null, ?array $config = null, ?\PDO $connection = null )
	{
		$this->_config = $config ?? $this->loadConfig( $settings );
		$this->_maxAttempts = $this->_config['max_attempts'] ?? 3;
		$this->_backoff = $this->_config['backoff'] ?? 0;
		$this->_driver = $this->createDriver( $this->_config, $connection );
		$this->_history = $this->createHistory( $this->_config, $connection );
		$this->_heartbeat = $this->createHeartbeat( $this->_config, $connection );
	}

	/**
	 * Resolve the shared QueueManager from the Registry, creating it from the
	 * application settings when it does not exist yet.
	 *
	 * This is the single resolution path used by the helper functions, the CLI
	 * commands and the scheduler, so every process in a deployment (web,
	 * scheduler, worker) reads the same queue configuration. Settings are read
	 * from RegistryKeys::SETTINGS, where the application stores them.
	 *
	 * @return static
	 */
	public static function fromRegistry(): static
	{
		$registry = Registry::getInstance();

		$manager = $registry->get( self::REGISTRY_KEY );

		if( $manager instanceof self )
		{
			return $manager;
		}

		$settings = $registry->get( RegistryKeys::SETTINGS );

		if( !$settings instanceof ISettingSource )
		{
			$settings = null;
		}

		if( $settings === null )
		{
			Log::warning( 'QueueManager: no application settings in the Registry; defaulting to the sync driver.' );
		}

		$manager = new static( $settings );

		$registry->set( self::REGISTRY_KEY, $manager );

		return $manager;
	}

	/**
	 * Load configuration from settings source
	 *
	 * @param ISettingSource|null $settings
	 * @return array
	 */
	private function loadConfig( ?ISettingSource $settings ): array
	{
		if( !$settings )
		{
			return $this->getDefaultConfig();
		}

		$config = [];

		// Load queue settings
		$config['driver'] = $settings->get( 'queue', 'driver' ) ?? 'database';
		$config['default_queue'] = $settings->get( 'queue', 'default' ) ?? 'default';
		$config['retry_after'] = (int)( $settings->get( 'queue', 'retry_after' ) ?? 90 );
		$config['max_attempts'] = (int)( $settings->get( 'queue', 'max_attempts' ) ?? 3 );
		$config['backoff'] = (int)( $settings->get( 'queue', 'backoff' ) ?? 0 );
		$config['history'] = $settings->get( 'queue', 'history' );
		$config['history_retention'] = (int)( $settings->get( 'queue', 'history_retention' ) ?? DatabaseJobHistory::DEFAULT_RETENTION );
		$config['heartbeat'] = $settings->get( 'queue', 'heartbeat' );

		// Load database settings if using database driver, or if history /
		// heartbeat need a PDO while the queue itself is file/redis/array.
		// The whole section is taken as-is (including `url`) and interpreted
		// by Neuron\Data\Database\ConnectionFactory, so the queue accepts
		// exactly the same database configuration as the rest of the
		// application.
		//
		// `queue.connection` names that section, `database` unless set, so the
		// queue can live in a different database from the application's own -
		// one the application only reads, for instance.
		$needsDatabase = $config['driver'] === 'database'
			|| $config['history'] === 'database'
			|| $config['heartbeat'] === 'database';

		if( $needsDatabase )
		{
			$section = $settings->get( 'queue', 'connection' );
			$section = is_string( $section ) && $section !== '' ? $section : 'database';

			$config['database'] = $settings->getSection( $section ) ?? [];

			// DATABASE_URL describes the `database` section. Applied to a named
			// connection it would silently move the queue back to that database.
			if( $section !== 'database' )
			{
				$config['database']['use_environment'] = false;
			}

			foreach( [ 'jobs_table', 'failed_jobs_table' ] as $key )
			{
				$table = $settings->get( 'queue', $key );

				if( is_string( $table ) && $table !== '' )
				{
					$config['database'][ $key ] = $table;
				}
			}
		}

		// Load file settings if using file driver
		if( $config['driver'] === 'file' )
		{
			$config['file'] = [
				'path' => $settings->get( 'queue', 'file_path' ) ?? 'storage/queue'
			];
		}

		// Redis settings live in their own section so they can hold a url or
		// host/port/password without cluttering `queue:`.
		if( $config['driver'] === 'redis' )
		{
			$redis = $settings->get( 'queue', 'redis' );
			$config['redis'] = is_array( $redis ) ? $redis : [];
		}

		return $config;
	}

	/**
	 * Get default configuration
	 *
	 * @return array
	 */
	private function getDefaultConfig(): array
	{
		return [
			'driver' => 'sync',
			'default_queue' => 'default',
			'retry_after' => 90,
			'max_attempts' => 3,
			'backoff' => 0,
			'history' => null,
			'history_retention' => DatabaseJobHistory::DEFAULT_RETENTION,
			'heartbeat' => null,
		];
	}

	/**
	 * Create queue driver instance
	 *
	 * @param array $config
	 * @param \PDO|null $connection Existing connection for the database driver
	 * @return IQueue
	 */
	private function createDriver( array $config, ?\PDO $connection = null ): IQueue
	{
		$driver = $config['driver'] ?? 'database';

		// retry_after is a queue-level setting; the persistent drivers need it to
		// decide when a reserved job has gone stale.
		$retryAfter = (int)( $config['retry_after'] ?? 90 );

		return match( $driver )
		{
			'database' => new DatabaseQueue( ( $config['database'] ?? [] ) + [ 'retry_after' => $retryAfter ], $connection ),
			'file' => new FileQueue( ( $config['file'] ?? [] ) + [ 'retry_after' => $retryAfter ] ),
			'array' => new ArrayQueue( [ 'retry_after' => $retryAfter ] ),
			'redis' => new RedisQueue( ( $config['redis'] ?? [] ) + [ 'retry_after' => $retryAfter ] ),
			'sync' => new SyncQueue(),
			default => throw new \RuntimeException( "Unsupported queue driver: {$driver}" )
		};
	}

	/**
	 * Dispatch a job to the queue
	 *
	 * @param IJob $job Job instance
	 * @param array $args Arguments for the job
	 * @param string|null $queue Queue name (null = default)
	 * @param int $delay Delay in seconds before job is available
	 * @return string Job ID
	 */
	public function dispatch( IJob $job, array $args = [], ?string $queue = null, int $delay = 0 ): string
	{
		if( $queue === null && $job instanceof IQueueable )
		{
			$queue = $job->getQueue();
		}

		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

		// A unique job already waiting to run is not queued again: the pending
		// one will do the same work. One that is running does not count, so a
		// run that starts after it began still happens.
		if( $job instanceof IUniqueJob && $this->_driver instanceof IUniqueQueue )
		{
			$pending = $this->_driver->findPending( get_class( $job ), $args, $queue );

			if( $pending !== null )
			{
				Log::info( "Not dispatching " . get_class( $job ) . ": the same job is already pending on '$queue' ($pending)" );

				return $pending;
			}
		}

		return $this->_driver->push( $job, $args, $queue, $delay );
	}

	/**
	 * Dispatch a job for immediate execution (bypasses queue)
	 *
	 * @param IJob $job Job instance
	 * @param array $args Arguments for the job
	 * @return mixed Job result
	 */
	public function dispatchNow( IJob $job, array $args = [] ): mixed
	{
		Log::debug( "Executing job immediately: " . get_class( $job ) );

		return $job->run( $args );
	}

	/**
	 * Process the next job from the queue
	 *
	 * @param string|null $queue Queue name (null = default)
	 * @param int $timeout Seconds the job may run before it is failed with a
	 *        JobTimeoutException; 0 disables. Requires the pcntl extension.
	 * @return bool True if a job was processed, false if queue was empty
	 */
	public function processNextJob( ?string $queue = null, int $timeout = 0 ): bool
	{
		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

		$queuedJob = $this->_driver->pop( $queue );

		if( !$queuedJob )
		{
			return false;
		}

		$job = null;
		$startedAt = time();
		$startedHr = microtime( true );

		try
		{
			$job = $queuedJob->getJob( $this->resolveContainer() );

			Log::info( "Processing job: {$queuedJob->getId()} ({$queuedJob->getJobClass()})" );

			if( $job instanceof IQueueable && $job->getTimeout() !== null )
			{
				$timeout = $job->getTimeout();
			}

			$this->armTimeout( $queuedJob->getJobClass(), $timeout );

			try
			{
				$result = $job->run( $queuedJob->getArguments() );
			}
			finally
			{
				$this->disarmTimeout();
			}

			// IJob::run() returns the job's success/failure status: false is a
			// failure and is handled exactly like a thrown exception.
			if( $result === false )
			{
				throw new JobReportedFailureException( $queuedJob->getJobClass() );
			}

			$executionTime = microtime( true ) - $startedHr;

			$this->_driver->delete( $queuedJob );

			$this->recordRun( $queuedJob, IJobHistory::STATUS_COMPLETED, $startedAt, $startedHr, null );

			Log::info( "Job completed: {$queuedJob->getId()}" );

			// Emit job processed event
			\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Jobs\Events\JobProcessedEvent(
				$queuedJob->getJobClass(),
				$queuedJob->getArguments(),
				$queue,
				$executionTime
			) );

			return true;
		}
		catch( \Throwable $e )
		{
			Log::error( "Job failed: {$queuedJob->getId()} - {$e->getMessage()}" );

			// Emit job failed event
			\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Jobs\Events\JobFailedEvent(
				$queuedJob->getJobClass(),
				$queuedJob->getArguments(),
				$queue,
				$e,
				$queuedJob->getAttempts()
			) );

			$this->recordRun( $queuedJob, IJobHistory::STATUS_FAILED, $startedAt, $startedHr, $e );

			$this->handleFailedJob( $queuedJob, $e, $job );

			return true;
		}
	}

	/**
	 * Whether job timeouts can be enforced in this PHP build.
	 *
	 * @return bool
	 */
	public static function canEnforceTimeout(): bool
	{
		return function_exists( 'pcntl_alarm' ) && function_exists( 'pcntl_signal' );
	}

	/**
	 * Start the timeout clock for a job.
	 *
	 * Uses SIGALRM: when it fires, the handler throws JobTimeoutException from
	 * wherever the job is executing, which the caller's catch turns into an
	 * ordinary failure (retry, then failed jobs storage). pcntl_async_signals
	 * is enabled so the signal is delivered promptly rather than at the next
	 * tick.
	 *
	 * @param string $jobClass
	 * @param int $timeout
	 * @return void
	 */
	private function armTimeout( string $jobClass, int $timeout ): void
	{
		if( $timeout <= 0 )
		{
			return;
		}

		if( !self::canEnforceTimeout() )
		{
			static $warned = false;

			if( !$warned )
			{
				Log::warning( 'Job timeout requested but the pcntl extension is unavailable; timeouts will not be enforced.' );
				$warned = true;
			}

			return;
		}

		pcntl_async_signals( true );

		pcntl_signal( SIGALRM, function() use ( $jobClass, $timeout ) {
			throw new JobTimeoutException( $jobClass, $timeout );
		} );

		pcntl_alarm( $timeout );
	}

	/**
	 * Cancel any pending timeout alarm.
	 *
	 * @return void
	 */
	private function disarmTimeout(): void
	{
		if( self::canEnforceTimeout() )
		{
			pcntl_alarm( 0 );
			pcntl_signal( SIGALRM, SIG_DFL );
		}
	}

	/**
	 * The application container, when one has been registered.
	 *
	 * @return IContainer|null
	 */
	private function resolveContainer(): ?IContainer
	{
		$container = Registry::getInstance()->get( RegistryKeys::CONTAINER );

		return $container instanceof IContainer ? $container : null;
	}

	/**
	 * Handle a failed job
	 *
	 * @param QueuedJob $job
	 * @param \Throwable $exception
	 * @param IJob|null $instance The resolved job, when resolution succeeded;
	 *        consulted for IQueueable overrides and the failed() hook
	 * @return void
	 */
	private function handleFailedJob( QueuedJob $job, \Throwable $exception, ?IJob $instance = null ): void
	{
		$queueable = $instance instanceof IQueueable ? $instance : null;

		$maxAttempts = $queueable?->getMaxAttempts() ?? $this->_maxAttempts;
		$backoff = $queueable?->getBackoff() ?? $this->_backoff;

		if( $job->getAttempts() < $maxAttempts )
		{
			// Retry with exponential backoff
			$delay = $this->calculateBackoff( $job->getAttempts(), $backoff );

			Log::info( "Retrying job {$job->getId()} in {$delay} seconds (attempt {$job->getAttempts()} of {$maxAttempts})" );

			$this->_driver->release( $job, $delay );
		}
		else
		{
			// Max attempts reached, mark as failed
			Log::error( "Job {$job->getId()} failed permanently after {$job->getAttempts()} attempts" );

			// Emit job max attempts reached event
			\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Jobs\Events\JobMaxAttemptsReachedEvent(
				$job->getJobClass(),
				$job->getArguments(),
				$job->getQueue(),
				$exception,
				$maxAttempts
			) );

			$this->_driver->failed( $job, $exception );

			if( $queueable !== null )
			{
				try
				{
					$queueable->failed( $exception );
				}
				catch( \Throwable $hookException )
				{
					Log::error( "{$job->getJobClass()}::failed() threw: {$hookException->getMessage()}" );
				}
			}
		}
	}

	/**
	 * Calculate backoff delay for retry
	 *
	 * @param int $attempts Number of attempts made
	 * @param int|null $backoff Base backoff in seconds (defaults to the configured value)
	 * @return int Delay in seconds
	 */
	private function calculateBackoff( int $attempts, ?int $backoff = null ): int
	{
		$backoff = $backoff ?? $this->_backoff;

		if( $backoff <= 0 )
		{
			return 0;
		}

		// Exponential backoff: backoff * (2 ^ (attempts - 1))
		return $backoff * ( 2 ** ( $attempts - 1 ) );
	}

	/**
	 * The queue jobs go to when none is named.
	 *
	 * @return string
	 */
	public function getDefaultQueue(): string
	{
		return $this->_config['default_queue'] ?? 'default';
	}

	/**
	 * Name of the configured driver (`database`, `file`, `redis`, ...).
	 *
	 * @return string
	 */
	public function getDriverName(): string
	{
		return (string)( $this->_config['driver'] ?? 'database' );
	}

	/**
	 * Whether the driver can enumerate and act on waiting jobs
	 * (implements IQueueInspector).
	 *
	 * @return bool
	 */
	public function supportsInspection(): bool
	{
		return $this->_driver instanceof IQueueInspector;
	}

	/**
	 * @see IQueueInspector::listJobs()
	 * @param string|null $queue Queue name (null = default)
	 * @return list<QueuedJob>
	 * @throws \RuntimeException when the driver does not support inspection
	 */
	public function listJobs( ?string $queue, JobState $state, int $limit = 50, int $offset = 0 ): array
	{
		return $this->inspector()->listJobs( $queue ?? $this->getDefaultQueue(), $state, $limit, $offset );
	}

	/**
	 * @see IQueueInspector::countJobs()
	 * @param string|null $queue Queue name (null = default)
	 * @throws \RuntimeException when the driver does not support inspection
	 */
	public function countJobs( ?string $queue, JobState $state ): int
	{
		return $this->inspector()->countJobs( $queue ?? $this->getDefaultQueue(), $state );
	}

	/**
	 * @see IQueueInspector::findJob()
	 * @throws \RuntimeException when the driver does not support inspection
	 */
	public function findJob( string $id ): ?QueuedJob
	{
		return $this->inspector()->findJob( $id );
	}

	/**
	 * @see IQueueInspector::deleteJob()
	 * @throws \RuntimeException when the driver does not support inspection
	 */
	public function deleteJob( string $id ): bool
	{
		return $this->inspector()->deleteJob( $id );
	}

	/**
	 * @see IQueueInspector::releaseNow()
	 * @throws \RuntimeException when the driver does not support inspection
	 */
	public function releaseNow( string $id ): bool
	{
		return $this->inspector()->releaseNow( $id );
	}

	/**
	 * Failed jobs, most recent first. Drivers without IQueueInspector are
	 * paged in memory from getFailedJobs(), so this always works.
	 *
	 * @see IQueueInspector::listFailedJobs()
	 * @return list<array>
	 */
	public function listFailedJobs( int $limit = 50, int $offset = 0, ?string $queue = null ): array
	{
		if( $this->_driver instanceof IQueueInspector )
		{
			return $this->_driver->listFailedJobs( $limit, $offset, $queue );
		}

		return array_slice( $this->failedJobsIn( $queue ), max( 0, $offset ), max( 1, $limit ) );
	}

	/**
	 * @see IQueueInspector::countFailedJobs()
	 */
	public function countFailedJobs( ?string $queue = null ): int
	{
		if( $this->_driver instanceof IQueueInspector )
		{
			return $this->_driver->countFailedJobs( $queue );
		}

		return count( $this->failedJobsIn( $queue ) );
	}

	/**
	 * @see IQueueInspector::findFailedJob()
	 */
	public function findFailedJob( string $id ): ?array
	{
		if( $this->_driver instanceof IQueueInspector )
		{
			return $this->_driver->findFailedJob( $id );
		}

		foreach( $this->_driver->getFailedJobs() as $row )
		{
			if( (string)( $row['id'] ?? '' ) === $id )
			{
				return $row;
			}
		}

		return null;
	}

	/**
	 * The driver as an inspector, or a clear error for callers that did not
	 * check supportsInspection() first.
	 *
	 * @return IQueueInspector
	 * @throws \RuntimeException
	 */
	private function inspector(): IQueueInspector
	{
		if( !$this->_driver instanceof IQueueInspector )
		{
			throw new \RuntimeException(
				"The '{$this->getDriverName()}' queue driver does not support job inspection."
			);
		}

		return $this->_driver;
	}

	/**
	 * getFailedJobs() narrowed to one queue when asked.
	 *
	 * @param string|null $queue
	 * @return list<array>
	 */
	private function failedJobsIn( ?string $queue ): array
	{
		$rows = $this->_driver->getFailedJobs();

		if( $queue === null )
		{
			return array_values( $rows );
		}

		return array_values( array_filter( $rows, fn( array $row ) => ( $row['queue'] ?? null ) === $queue ) );
	}

	/**
	 * Queues that currently hold jobs, when the driver can tell; otherwise
	 * just the default queue.
	 *
	 * @return string[]
	 */
	public function getQueueNames(): array
	{
		if( $this->_driver instanceof IQueueStats )
		{
			return $this->_driver->getQueueNames();
		}

		return [ $this->getDefaultQueue() ];
	}

	/**
	 * Per-queue statistics.
	 *
	 * Drivers implementing IQueueStats report pending, delayed and reserved
	 * counts and the age of the oldest runnable job; others report size() as
	 * pending and null for the rest.
	 *
	 * @param string|null $queue Queue name (null = default)
	 * @return array{pending:int,delayed:?int,reserved:?int,oldest_pending_age:?int}
	 */
	public function getStats( ?string $queue = null ): array
	{
		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

		if( $this->_driver instanceof IQueueStats )
		{
			return $this->_driver->getStats( $queue );
		}

		return [
			'pending' => $this->_driver->size( $queue ),
			'delayed' => null,
			'reserved' => null,
			'oldest_pending_age' => null,
		];
	}

	/**
	 * Get queue size
	 *
	 * @param string|null $queue Queue name (null = default)
	 * @return int Number of jobs in queue
	 */
	public function size( ?string $queue = null ): int
	{
		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

		return $this->_driver->size( $queue );
	}

	/**
	 * Clear all jobs from queue
	 *
	 * @param string|null $queue Queue name (null = default)
	 * @return int Number of jobs cleared
	 */
	public function clear( ?string $queue = null ): int
	{
		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

		return $this->_driver->clear( $queue );
	}

	/**
	 * Get all failed jobs
	 *
	 * @return array
	 */
	public function getFailedJobs(): array
	{
		return $this->_driver->getFailedJobs();
	}

	/**
	 * Retry a failed job
	 *
	 * @param string $id Failed job ID
	 * @return bool True if job was retried, false if not found
	 */
	public function retryFailedJob( string $id ): bool
	{
		return $this->_driver->retryFailedJob( $id );
	}

	/**
	 * Retry all failed jobs
	 *
	 * @return int Number of jobs retried
	 */
	public function retryAllFailedJobs(): int
	{
		$failedJobs = $this->getFailedJobs();
		$count = 0;

		foreach( $failedJobs as $job )
		{
			if( $this->retryFailedJob( $job['id'] ) )
			{
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Forget (delete) a failed job
	 *
	 * @param string $id Failed job ID
	 * @return bool True if job was deleted, false if not found
	 */
	public function forgetFailedJob( string $id ): bool
	{
		return $this->_driver->forgetFailedJob( $id );
	}

	/**
	 * Clear all failed jobs
	 *
	 * @return int Number of failed jobs cleared
	 */
	public function clearFailedJobs(): int
	{
		return $this->_driver->clearFailedJobs();
	}

	/**
	 * Get the queue driver instance
	 *
	 * @return IQueue
	 */
	public function getDriver(): IQueue
	{
		return $this->_driver;
	}

	public function getHistory(): ?IJobHistory
	{
		return $this->_history;
	}

	public function setHistory( ?IJobHistory $history ): self
	{
		$this->_history = $history;
		return $this;
	}

	public function getHeartbeat(): ?IWorkerHeartbeat
	{
		return $this->_heartbeat;
	}

	public function setHeartbeat( ?IWorkerHeartbeat $heartbeat ): self
	{
		$this->_heartbeat = $heartbeat;
		return $this;
	}

	/**
	 * Queue a job from a stored history payload (Dispatch again).
	 *
	 * New id, attempts reset. Unique jobs follow the usual de-duplication.
	 *
	 * @return string|null New job id, or null when the run is missing or unreadable
	 */
	public function dispatchFromHistory( string $runId ): ?string
	{
		$run = $this->_history?->findRun( $runId );

		if( $run === null )
		{
			return null;
		}

		try
		{
			$queued = QueuedJob::fromPayload(
				uniqid( 'job_', true ),
				(string)( $run['queue'] ?? $this->getDefaultQueue() ),
				(string)$run['payload']
			);
			$job = $queued->getJob( $this->resolveContainer() );
		}
		catch( \Throwable $e )
		{
			Log::error( "dispatchFromHistory: {$e->getMessage()}" );
			return null;
		}

		return $this->dispatch( $job, $queued->getArguments(), $queued->getQueue() );
	}

	/**
	 * @param array $config
	 * @param \PDO|null $connection
	 */
	private function createHistory( array $config, ?\PDO $connection ): ?IJobHistory
	{
		$mode = $config['history'] ?? null;

		if( !is_string( $mode ) || $mode === '' || $mode === 'none' )
		{
			return null;
		}

		$retention = (int)( $config['history_retention'] ?? DatabaseJobHistory::DEFAULT_RETENTION );

		if( $mode === 'array' )
		{
			return new ArrayJobHistory( $retention );
		}

		if( $mode !== 'database' )
		{
			Log::warning( "QueueManager: unknown queue.history driver '{$mode}'" );
			return null;
		}

		$pdo = $this->resolveHistoryConnection( $config, $connection );

		if( $pdo === null )
		{
			Log::warning( 'QueueManager: queue.history is database but no PDO connection is available.' );
			return null;
		}

		return new DatabaseJobHistory(
			$pdo,
			(string)( $config['history_table'] ?? DatabaseJobHistory::DEFAULT_TABLE ),
			$retention
		);
	}

	/**
	 * @param array $config
	 * @param \PDO|null $connection
	 */
	private function createHeartbeat( array $config, ?\PDO $connection ): ?IWorkerHeartbeat
	{
		$mode = $config['heartbeat'] ?? null;

		if( !is_string( $mode ) || $mode === '' || $mode === 'none' )
		{
			return null;
		}

		if( $mode === 'array' )
		{
			return new ArrayWorkerHeartbeat();
		}

		if( $mode !== 'database' )
		{
			Log::warning( "QueueManager: unknown queue.heartbeat driver '{$mode}'" );
			return null;
		}

		$pdo = $this->resolveHistoryConnection( $config, $connection );

		if( $pdo === null )
		{
			Log::warning( 'QueueManager: queue.heartbeat is database but no PDO connection is available.' );
			return null;
		}

		return new DatabaseWorkerHeartbeat(
			$pdo,
			(string)( $config['heartbeat_table'] ?? DatabaseWorkerHeartbeat::DEFAULT_TABLE )
		);
	}

	/**
	 * Prefer the injected connection, then the database queue's own PDO,
	 * then open one from `queue.database` / `database` config.
	 *
	 * @param array $config
	 * @param \PDO|null $connection
	 */
	private function resolveHistoryConnection( array $config, ?\PDO $connection ): ?\PDO
	{
		if( $connection instanceof \PDO )
		{
			return $connection;
		}

		if( $this->_driver instanceof DatabaseQueue )
		{
			return $this->_driver->getConnection();
		}

		$db = $config['database'] ?? [];

		if( !is_array( $db ) || $db === [] )
		{
			return null;
		}

		try
		{
			$useEnvironment = (bool)( $db['use_environment'] ?? true );
			unset( $db['jobs_table'], $db['failed_jobs_table'], $db['retry_after'], $db['use_environment'] );

			if( $useEnvironment )
			{
				return ConnectionFactory::createFromConfig( $db );
			}

			$db = ConnectionFactory::normalize( $db, false );

			$pdo = new \PDO(
				ConnectionFactory::buildDsn( $db ),
				$db['user'] ?? null,
				$db['pass'] ?? null,
				[
					\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
					\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
				]
			);

			ConnectionFactory::initializeConnection( $pdo, $db['adapter'] );

			return $pdo;
		}
		catch( \Throwable $e )
		{
			Log::warning( "QueueManager: could not open history connection: {$e->getMessage()}" );
			return null;
		}
	}

	private function recordRun( QueuedJob $job, string $status, int $startedAt, float $startedHr, ?\Throwable $e ): void
	{
		if( $this->_history === null )
		{
			return;
		}

		$this->_history->record([
			'id' => uniqid( 'run_', true ),
			'job_id' => $job->getId(),
			'job_class' => $job->getJobClass(),
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'status' => $status,
			'exception' => $e?->getMessage(),
			'started_at' => $startedAt,
			'finished_at' => time(),
			'duration_ms' => (int)round( ( microtime( true ) - $startedHr ) * 1000 ),
			'attempts' => $job->getAttempts(),
		]);
	}

	/**
	 * Get configuration
	 *
	 * @return array
	 */
	public function getConfig(): array
	{
		return $this->_config;
	}
}
