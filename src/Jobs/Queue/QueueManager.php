<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Log\Log;

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
	private IQueue $_driver;
	private array $_config;
	private int $_maxAttempts = 3;
	private int $_retryAfter = 90;
	private int $_backoff = 0;

	/**
	 * @param ISettingSource|null $settings Settings source for configuration
	 * @param array|null $config Direct configuration array (overrides settings)
	 */
	public function __construct( ?ISettingSource $settings = null, ?array $config = null )
	{
		$this->_config = $config ?? $this->loadConfig( $settings );
		$this->_maxAttempts = $this->_config['max_attempts'] ?? 3;
		$this->_retryAfter = $this->_config['retry_after'] ?? 90;
		$this->_backoff = $this->_config['backoff'] ?? 0;
		$this->_driver = $this->createDriver( $this->_config );
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

		// Load database settings if using database driver
		if( $config['driver'] === 'database' )
		{
			$config['database'] = [
				'adapter' => $settings->get( 'database', 'adapter' ) ?? 'sqlite',
				'name' => $settings->get( 'database', 'name' ),
				'host' => $settings->get( 'database', 'host' ),
				'port' => (int)( $settings->get( 'database', 'port' ) ?? 3306 ),
				'user' => $settings->get( 'database', 'user' ),
				'pass' => $settings->get( 'database', 'pass' ),
				'charset' => $settings->get( 'database', 'charset' ) ?? 'utf8mb4',
			];
		}

		// Load file settings if using file driver
		if( $config['driver'] === 'file' )
		{
			$config['file'] = [
				'path' => $settings->get( 'queue', 'file_path' ) ?? 'storage/queue'
			];
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
			'backoff' => 0
		];
	}

	/**
	 * Create queue driver instance
	 *
	 * @param array $config
	 * @return IQueue
	 */
	private function createDriver( array $config ): IQueue
	{
		$driver = $config['driver'] ?? 'database';

		return match( $driver )
		{
			'database' => new DatabaseQueue( $config['database'] ?? [] ),
			'file' => new FileQueue( $config['file'] ?? [] ),
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
		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

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
	 * @return bool True if a job was processed, false if queue was empty
	 */
	public function processNextJob( ?string $queue = null ): bool
	{
		$queue = $queue ?? $this->_config['default_queue'] ?? 'default';

		$queuedJob = $this->_driver->pop( $queue );

		if( !$queuedJob )
		{
			return false;
		}

		try
		{
			$job = $queuedJob->getJob();

			Log::info( "Processing job: {$queuedJob->getId()} ({$queuedJob->getJobClass()})" );

			$startTime = microtime( true );
			$job->run( $queuedJob->getArguments() );
			$executionTime = microtime( true ) - $startTime;

			$this->_driver->delete( $queuedJob );

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

			$this->handleFailedJob( $queuedJob, $e );

			return true;
		}
	}

	/**
	 * Handle a failed job
	 *
	 * @param QueuedJob $job
	 * @param \Throwable $exception
	 * @return void
	 */
	private function handleFailedJob( QueuedJob $job, \Throwable $exception ): void
	{
		if( $job->getAttempts() < $this->_maxAttempts )
		{
			// Retry with exponential backoff
			$delay = $this->calculateBackoff( $job->getAttempts() );

			Log::info( "Retrying job {$job->getId()} in {$delay} seconds (attempt {$job->getAttempts()} of {$this->_maxAttempts})" );

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
				$this->_maxAttempts
			) );

			$this->_driver->failed( $job, $exception );
		}
	}

	/**
	 * Calculate backoff delay for retry
	 *
	 * @param int $attempts Number of attempts made
	 * @return int Delay in seconds
	 */
	private function calculateBackoff( int $attempts ): int
	{
		if( $this->_backoff === 0 )
		{
			return 0;
		}

		// Exponential backoff: backoff * (2 ^ (attempts - 1))
		return $this->_backoff * ( 2 ** ( $attempts - 1 ) );
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
