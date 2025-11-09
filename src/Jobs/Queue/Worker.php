<?php

namespace Neuron\Jobs\Queue;

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
	private QueueManager $_QueueManager;
	private bool $_ShouldQuit = false;
	private int $_Sleep = 3; // seconds to sleep when queue is empty
	private int $_MaxJobs = 0; // 0 = unlimited
	private int $_JobsProcessed = 0;
	private int $_Timeout = 60; // seconds
	private bool $_StopWhenEmpty = false;

	/**
	 * @param QueueManager $queueManager
	 */
	public function __construct( QueueManager $queueManager )
	{
		$this->_QueueManager = $queueManager;
		$this->registerSignalHandlers();
	}

	/**
	 * Register signal handlers for graceful shutdown
	 *
	 * @return void
	 */
	private function registerSignalHandlers(): void
	{
		if( !function_exists( 'pcntl_signal' ) )
		{
			return; // PCNTL extension not available
		}

		pcntl_async_signals( true );

		pcntl_signal( SIGTERM, function() {
			$this->stop();
		});

		pcntl_signal( SIGINT, function() {
			$this->stop();
		});
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

		Log::info( "Worker started for queues: " . implode( ', ', $queues ) );

		if( $once )
		{
			$this->_StopWhenEmpty = true;
		}

		while( !$this->_ShouldQuit )
		{
			$processed = false;

			foreach( $queues as $queue )
			{
				if( $this->processNextJob( $queue ) )
				{
					$processed = true;
					$this->_JobsProcessed++;

					// Check if we've hit max jobs
					if( $this->_MaxJobs > 0 && $this->_JobsProcessed >= $this->_MaxJobs )
					{
						Log::info( "Worker processed {$this->_JobsProcessed} jobs, shutting down" );
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
				if( $this->_StopWhenEmpty )
				{
					Log::info( "Queue is empty, exiting" );
					break;
				}

				// Sleep before checking again
				Log::debug( "Queue is empty, sleeping for {$this->_Sleep} seconds" );
				sleep( $this->_Sleep );
			}
		}

		Log::info( "Worker stopped. Total jobs processed: {$this->_JobsProcessed}" );
	}

	/**
	 * Process the next job from the queue
	 *
	 * @param string $queue Queue name
	 * @return bool True if a job was processed, false if queue was empty
	 */
	private function processNextJob( string $queue ): bool
	{
		return $this->_QueueManager->processNextJob( $queue );
	}

	/**
	 * Stop the worker gracefully
	 *
	 * @return void
	 */
	public function stop(): void
	{
		Log::info( "Worker received stop signal, shutting down gracefully..." );
		$this->_ShouldQuit = true;
	}

	/**
	 * Set sleep time when queue is empty
	 *
	 * @param int $seconds
	 * @return self
	 */
	public function setSleep( int $seconds ): self
	{
		$this->_Sleep = $seconds;
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
		$this->_MaxJobs = $maxJobs;
		return $this;
	}

	/**
	 * Set job timeout in seconds
	 *
	 * @param int $timeout
	 * @return self
	 */
	public function setTimeout( int $timeout ): self
	{
		$this->_Timeout = $timeout;
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
		$this->_StopWhenEmpty = $stop;
		return $this;
	}

	/**
	 * Get number of jobs processed
	 *
	 * @return int
	 */
	public function getJobsProcessed(): int
	{
		return $this->_JobsProcessed;
	}

	/**
	 * Get sleep time
	 *
	 * @return int
	 */
	public function getSleep(): int
	{
		return $this->_Sleep;
	}

	/**
	 * Get max jobs
	 *
	 * @return int
	 */
	public function getMaxJobs(): int
	{
		return $this->_MaxJobs;
	}

	/**
	 * Get timeout
	 *
	 * @return int
	 */
	public function getTimeout(): int
	{
		return $this->_Timeout;
	}
}
