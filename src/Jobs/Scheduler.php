<?php

namespace Neuron\Jobs;

use Cron\CronExpression;
use Neuron\Application\CommandLineBase;
use Neuron\Log\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Advanced job scheduling and execution engine for the Neuron framework.
 * 
 * This scheduler provides comprehensive job management with cron-like scheduling,
 * daemon operation, and robust error handling. It supports both one-time job
 * execution and continuous polling for scheduled jobs.
 * 
 * Key features:
 * - YAML-based job configuration with cron expressions
 * - Daemon mode with configurable polling intervals
 * - Job lifecycle management (creation, execution, cleanup)
 * - Comprehensive logging and error handling
 * - Debug mode for development and troubleshooting
 * - Integration with the Neuron logging system
 * 
 * Configuration format (schedule.yaml):
 * ```yaml
 * jobs:
 *   email_cleanup:
 *     schedule: "0 2 * * *"  # Daily at 2 AM
 *     class: "App\\Jobs\\EmailCleanup"
 *   backup_database:
 *     schedule: "0 1 * * 0"  # Weekly on Sunday at 1 AM
 *     class: "App\\Jobs\\BackupDatabase"
 * ```
 * 
 * @package Neuron\Jobs
 * 
 * @example
 * ```php
 * // Basic usage
 * $scheduler = new Scheduler();
 * $scheduler->setConfigFile('config/schedule.yaml')
 *          ->setDebug(true)
 *          ->loadSchedule()
 *          ->run();
 * 
 * // Daemon mode
 * $scheduler->infinitePoll(60); // Poll every 60 seconds
 * ```
 */

class Scheduler extends CommandLineBase
{
	private bool $_poll = false;
	private int $_interval = 60;
	private array $_jobs = [];
	private string $_configFile =  'schedule.yaml';
	private bool $_debug = false;
	private ?string $_basePath = null;


	/**
	 * @param bool $debug
	 * @return Scheduler
	 */

	public function setDebug( bool $debug ): Scheduler
	{
		$this->_debug = $debug;
		return $this;
	}


	/**
	 * @return string
	 */

	public function getConfigFile(): string
	{
		return $this->_configFile;
	}

	/**
	 * @param string $configFile
	 * @return Scheduler
	 */

	public function setConfigFile( string $configFile ): Scheduler
	{
		$this->_configFile = $configFile;
		return $this;
	}

	/**
	 * @return string
	 */

	public function getDescription(): string
	{
		return "Neuron scheduler for running jobs at specific times.\n".
			"Jobs are defined in the config/schedule.yaml file.\n".
			"Running with no parameters will run the scheduler in an infinite loop.";
	}

	/**
	 * @param int $interval
	 * @return Scheduler
	 */

	public function setInterval( int $interval ): Scheduler
	{
		$this->_interval = $interval;
		return $this;
	}

	/**
	 * @return int
	 */

	public function getInterval(): int
	{
		return $this->_interval;
	}

	/**
	 * Set the base path for configuration files
	 * 
	 * @param string $basePath
	 * @return Scheduler
	 */
	public function setBasePath( string $basePath ): Scheduler
	{
		$this->_basePath = $basePath . '/config';
		return $this;
	}

	/**
	 * Add a job to the scheduler
	 *
	 * @param string $name Job name
	 * @param string $cron Cron expression
	 * @param IJob $job Job instance
	 * @param array $arguments Job arguments
	 * @param string|null $queue Queue name (null = run directly, not queued)
	 */

	public function addJob( string $name, string $cron, IJob $job, array $arguments = [], ?string $queue = null ): void
	{
		Log::debug( "Adding job: {$job->getName()} $cron" . ( $queue ? " [queue: $queue]" : " [direct]" ) );

		$this->_jobs[] = [
			'name' => $name,
			'cron' => new CronExpression( $cron ),
			'job'  => $job,
			'args' => $arguments,
			'queue' => $queue
		];
	}

	/**
	 * @return array
	 */

	public function getJobs(): array
	{
		return $this->_jobs;
	}

	/**
	 * Schedule jobs from configuration
	 *
	 * @param array $schedule Schedule configuration array
	 * @return void
	 */

	public function scheduleJobs( $schedule ): void
	{
		foreach( $schedule as $name => $job )
		{
			$class = $job[ 'class' ];
			if( !class_exists( $class ) )
			{
				Log::error( "Class not found: $class" );
				continue;
			}

			$this->addJob(
				$name,
				$job[ 'cron' ],
				new $class(),
				$job[ 'args' ] ?? [],
				$job[ 'queue' ] ?? null
			);
		}
	}

	/**
	 * @return array
	 */

	public function loadSchedule() : array
	{
		$path = $this->_basePath ?? $this->getBasePath().'/config';

		if( !file_exists( $path . '/'. $this->getConfigFile() ) )
		{
			Log::debug( "schedule.yaml not found." );
			return [];
		}

		try
		{
			$data = Yaml::parseFile( $path . '/'. $this->getConfigFile() );
		}
		catch( ParseException $exception )
		{
			Log::error( "Failed to load schedule: ".$exception->getMessage() );
			return [];
		}

		return $data;
	}

	/**
	 * Schedule the jobs from the schedule.yaml file.
	 * @return void
	 */

	private function initSchedule(): void
	{
		$data = $this->loadSchedule();

		if( empty( $data ) )
		{
			return;
		}

		$this->scheduleJobs( $data[ 'schedule' ] );
	}

	/**
	 * Command line parameter to poll events one time.
	 * @return bool
	 */

	protected function pollCommand(): bool
	{
		$this->_poll = true;

		return true;
	}

	/**
	 * Command line parameter to set the interval between polls.
	 * @param int $interval interval in seconds.
	 * @return bool
	 */

	protected function intervalCommand( int $interval ): bool
	{
		$this->setInterval( $interval );
		Log::debug( "Setting interval to: {$this->getInterval()}" );

		return true;
	}

	/**
	 * Infinite poll loop.
	 * @return void
	 */

	public function infinitePoll(): void
	{
		Log::debug( "Starting infinite poll.." );

		while( true )
		{
			$this->poll();

			sleep( $this->_interval );

			if( $this->_debug )
			{
				break;
			}
		}
	}

	/**
	 * Single job poll.
	 * Checks all scheduled jobs and either runs them directly or dispatches to queue.
	 *
	 * @return int Number of jobs triggered (run or dispatched).
	 */

	public function poll(): int
	{
		Log::debug( "Polling.." );

		$count = 0;

		foreach( $this->_jobs as $job )
		{
			Log::debug( "Checking job: {$job['name']}" );

			if( $job[ 'cron' ]->isDue() )
			{
				// If queue is specified, dispatch to queue instead of running directly
				if( isset( $job['queue'] ) && $job['queue'] !== null )
				{
					Log::debug( "Dispatching job to queue: {$job['name']} -> {$job['queue']}" );

					dispatch(
						$job[ 'job' ],
						$job[ 'args' ],
						$job[ 'queue' ]
					);
				}
				else
				{
					// Run directly in scheduler process (current behavior)
					Log::debug( "Running job directly: {$job['name']}" );

					$job[ 'job' ]->run( $job[ 'args' ] );
				}

				$count++;
			}
		}

		return $count;
	}

	/**
	 * @return bool
	 */

	protected function onStart(): bool
	{
		Log::debug( "Starting scheduler.." );
		$this->addHandler( '--poll', 'Performs a single poll and executes all ready jobs.', 'pollCommand' );
		$this->addHandler( '--interval', 'Set the interval between polls in seconds.', 'intervalCommand', true );

		$this->initSchedule();

		if( count( $this->_jobs ) == 0 )
		{
			Log::error( "No jobs defined." );
			fprintf( STDERR, "No jobs defined.\n" );
			return false;
		}

		return parent::onStart();
	}

	/**
	 * @return void
	 */

	protected function onFinish(): void
	{
		Log::debug( "Shutting down." );
		parent::onFinish();
	}

	/**
	 * @param array $argv
	 */

	protected function onRun( array $argv = [] ): void
	{
		if( $this->_poll )
		{
			$this->poll();
			return;
		}

		$this->infinitePoll();
	}
}
