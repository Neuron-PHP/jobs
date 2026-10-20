<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\Process\StopSignals;
use Neuron\Log\Log;

/**
 * Worker for processing queued jobs.
 *
 * Polls the queue for jobs and processes them. Supports daemon mode
 * for continuous processing and graceful shutdown on SIGTERM/SIGINT.
 *
 * @package Neuron\Jobs\Queue
 */
class Worker
{
	private QueueManager $_queueManager;
	private bool $_shouldQuit = false;
	private int $_sleep = 3; // seconds to sleep when queue is empty
	private int $_maxJobs = 0; // 0 = unlimited
	private int $_jobsProcessed = 0;
	private int $_timeout = 60; // seconds; 0 = no limit
	private int $_memoryLimit = 0; // megabytes; 0 = no limit
	private int $_maxTime = 0; // seconds the worker may live; 0 = no limit
	private int $_startedAt = 0;
	private ?string $_restartFile = null;
	private bool $_stopWhenEmpty = false;

	/**
	 * @param QueueManager $queueManager
	 */
	public function __construct( QueueManager $queueManager )
	{
		$this->_queueManager = $queueManager;
		$this->registerSignalHandlers();
	}

	/**
	 * Register signal handlers for graceful shutdown
	 *
	 * @return void
	 */
	private function registerSignalHandlers(): void
	{
		// SIGTERM, SIGINT and, when no terminal is attached, SIGWINCH - the
		// stop signal of the php:*-apache Docker image. See StopSignals.
		StopSignals::register( function() {
			$this->stop();
		} );
	}

	/**
	 * Run the worker
	 *
	 * @param string|array $queues Queue name(s) to process
	 * @param bool $once Process one job then exit
	 * @return void
	 */
	public function run( string|array $queues = 'default', bool $once = false ): void
	{
		$queues = is_array( $queues ) ? $queues : [ $queues ];

		$workerId = uniqid( 'worker_', true );
		$this->_startedAt = time();

		// Emit worker started event
		\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Jobs\Events\WorkerStartedEvent(
			$workerId,
			$queues
		) );

		Log::info( "Worker started for queues: " . implode( ', ', $queues ) );

		if( $once )
		{
			$this->_stopWhenEmpty = true;
		}

		while( !$this->_shouldQuit )
		{
			$processed = false;

			foreach( $queues as $queue )
			{
				if( $this->processNextJob( $queue ) )
				{
					$processed = true;
					$this->_jobsProcessed++;

					// Check if we've hit max jobs
					if( $this->_maxJobs > 0 && $this->_jobsProcessed >= $this->_maxJobs )
					{
						Log::info( "Worker processed {$this->_jobsProcessed} jobs, shutting down" );
						$this->stop();
						break 2;
					}

					if( $this->shouldRestart() )
					{
						$this->stop();
						break 2;
					}

					// Don't sleep if we processed a job
					continue 2;
				}
			}

			// No jobs were processed
			if( !$processed )
			{
				if( $this->_stopWhenEmpty )
				{
					Log::info( "Queue is empty, exiting" );
					break;
				}

				if( $this->shouldRestart() )
				{
					$this->stop();
					break;
				}

				// Sleep before checking again. Not logged: an idle worker would
				// otherwise write a line every few seconds.
				sleep( $this->_sleep );
			}
		}

		Log::info( "Worker stopped. Total jobs processed: {$this->_jobsProcessed}" );

		// Emit worker stopped event
		\Neuron\Application\CrossCutting\Event::emit( new \Neuron\Jobs\Events\WorkerStoppedEvent(
			$workerId ?? uniqid( 'worker_', true ),
			$this->_jobsProcessed
		) );
	}

	/**
	 * Process the next job from the queue
	 *
	 * @param string $queue Queue name
	 * @return bool True if a job was processed, false if queue was empty
	 */
	private function processNextJob( string $queue ): bool
	{
		return $this->_queueManager->processNextJob( $queue, $this->_timeout );
	}

	/**
	 * Whether the worker has exceeded its memory or lifetime budget.
	 *
	 * Both are checked between jobs, never mid-job, so a job is always allowed
	 * to finish. The process supervisor (systemd, supervisor, jobs:run) is
	 * expected to start a fresh worker when this one exits.
	 *
	 * @return bool
	 */
	private function shouldRestart(): bool
	{
		if( $this->_memoryLimit > 0 )
		{
			$usedMb = (int)( memory_get_usage( true ) / 1048576 );

			if( $usedMb >= $this->_memoryLimit )
			{
				Log::info( "Worker memory {$usedMb}MB reached limit of {$this->_memoryLimit}MB, shutting down for restart" );
				return true;
			}
		}

		if( $this->_maxTime > 0 && ( time() - $this->_startedAt ) >= $this->_maxTime )
		{
			Log::info( "Worker ran for {$this->_maxTime} seconds, shutting down for restart" );
			return true;
		}

		if( $this->restartRequested() )
		{
			Log::info( "Restart requested via {$this->_restartFile}, shutting down for restart" );
			return true;
		}

		return false;
	}

	/**
	 * Whether `jobs:restart` has been run since this worker started.
	 *
	 * The restart file's mtime is the time of the request; a worker started
	 * after it ignores it, so one request restarts each running worker once.
	 *
	 * @return bool
	 */
	private function restartRequested(): bool
	{
		if( $this->_restartFile === null )
		{
			return false;
		}

		clearstatcache( true, $this->_restartFile );

		$requestedAt = @filemtime( $this->_restartFile );

		return $requestedAt !== false && $requestedAt > $this->_startedAt;
	}

	/**
	 * File whose modification time signals a restart request (see jobs:restart).
	 *
	 * @param string|null $path
	 * @return Worker
	 */
	public function setRestartFile( ?string $path ): Worker
	{
		$this->_restartFile = $path !== null && $path !== '' ? $path : null;
		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getRestartFile(): ?string
	{
		return $this->_restartFile;
	}

	/**
	 * Default restart file for a site: storage/jobs.restart under the base path.
	 *
	 * @param string $basePath
	 * @return string
	 */
	public static function defaultRestartFile( string $basePath ): string
	{
		return rtrim( $basePath, '/' ) . '/storage/jobs.restart';
	}

	/**
	 * Stop the worker gracefully
	 *
	 * @return void
	 */
	public function stop(): void
	{
		Log::info( "Worker received stop signal, shutting down gracefully..." );
		$this->_shouldQuit = true;
	}

	/**
	 * Set sleep time when queue is empty
	 *
	 * @param int $seconds
	 * @return self
	 */
	public function setSleep( int $seconds ): self
	{
		$this->_sleep = $seconds;
		return $this;
	}

	/**
	 * Set maximum number of jobs to process before stopping
	 *
	 * @param int $maxJobs
	 * @return self
	 */
	public function setMaxJobs( int $maxJobs ): self
	{
		$this->_maxJobs = $maxJobs;
		return $this;
	}

	/**
	 * Set job timeout in seconds (0 = no limit)
	 *
	 * @param int $timeout
	 * @return self
	 */
	public function setTimeout( int $timeout ): self
	{
		$this->_timeout = $timeout;
		return $this;
	}

	/**
	 * Set the memory ceiling in megabytes; the worker exits between jobs once
	 * exceeded (0 = no limit)
	 *
	 * @param int $megabytes
	 * @return self
	 */
	public function setMemoryLimit( int $megabytes ): self
	{
		$this->_memoryLimit = $megabytes;
		return $this;
	}

	/**
	 * Set the maximum lifetime in seconds; the worker exits between jobs once
	 * exceeded (0 = no limit)
	 *
	 * @param int $seconds
	 * @return self
	 */
	public function setMaxTime( int $seconds ): self
	{
		$this->_maxTime = $seconds;
		return $this;
	}

	/**
	 * Set whether to stop when queue is empty
	 *
	 * @param bool $stop
	 * @return self
	 */
	public function setStopWhenEmpty( bool $stop ): self
	{
		$this->_stopWhenEmpty = $stop;
		return $this;
	}

	/**
	 * Get number of jobs processed
	 *
	 * @return int
	 */
	public function getJobsProcessed(): int
	{
		return $this->_jobsProcessed;
	}

	/**
	 * Get sleep time
	 *
	 * @return int
	 */
	public function getSleep(): int
	{
		return $this->_sleep;
	}

	/**
	 * Get max jobs
	 *
	 * @return int
	 */
	public function getMaxJobs(): int
	{
		return $this->_maxJobs;
	}

	/**
	 * Get timeout
	 *
	 * @return int
	 */
	public function getTimeout(): int
	{
		return $this->_timeout;
	}

	/**
	 * Get memory limit in megabytes
	 *
	 * @return int
	 */
	public function getMemoryLimit(): int
	{
		return $this->_memoryLimit;
	}

	/**
	 * Get maximum lifetime in seconds
	 *
	 * @return int
	 */
	public function getMaxTime(): int
	{
		return $this->_maxTime;
	}
}
