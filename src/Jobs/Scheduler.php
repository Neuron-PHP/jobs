<?php

namespace Neuron\Jobs;

use Cron\CronExpression;
use Neuron\Application\CommandLineBase;
use Neuron\Application\CrossCutting\Event;
use Neuron\Jobs\Events\SchedulerJobTriggeredEvent;
use Neuron\Jobs\Process\StopSignals;
use Neuron\Jobs\Schedule\IScheduleLock;
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
	private ?IScheduleLock $_lock = null;
	private bool $_shouldStop = false;
	private bool $_signalsRegistered = false;

	/**
	 * Ask the poll loop to exit once the current poll has finished.
	 *
	 * Called from the SIGTERM/SIGINT handlers; safe to call from anywhere.
	 * A poll in progress completes so every job due at that minute is still
	 * triggered, then infinitePoll() returns instead of sleeping.
	 *
	 * @param int|null $signal The signal that asked, when called from a handler
	 * @return void
	 */
	public function stop( ?int $signal = null ): void
	{
		if( !$this->_shouldStop )
		{
			Log::info( "Scheduler received " . StopSignals::name( $signal ) . ", shutting down gracefully..." );
		}

		$this->_shouldStop = true;
	}

	/**
	 * @return bool
	 */
	public function shouldStop(): bool
	{
		return $this->_shouldStop;
	}

	/**
	 * Route the stop signals (SIGTERM, SIGINT and, headless, SIGWINCH - see
	 * StopSignals) to stop(). No-op without the pcntl extension, in which
	 * case the default action (immediate termination) applies.
	 *
	 * @return void
	 */
	private function registerSignalHandlers(): void
	{
		if( $this->_signalsRegistered )
		{
			return;
		}

		$this->_signalsRegistered = StopSignals::register( function( int $signal ) {
			$this->stop( $signal );
		} );
	}

	/**
	 * Guard triggers with a lock so several schedulers can share a schedule.
	 *
	 * @param IScheduleLock|null $lock
	 * @return Scheduler
	 */

	public function setLock( ?IScheduleLock $lock ): Scheduler
	{
		$this->_lock = $lock;
		return $this;
	}

	/**
	 * @return IScheduleLock|null
	 */

	public function getLock(): ?IScheduleLock
	{
		return $this->_lock;
	}


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

	public function scheduleJobs( array $schedule ): void
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

		$this->registerSignalHandlers();

		while( !$this->_shouldStop )
		{
			$this->poll();

			if( $this->_shouldStop || $this->_debug )
			{
				break;
			}

			// Wake on the interval boundary - the top of the minute, at the default -
			// rather than a fixed interval after the work, which drifts by however
			// long the poll took. Polls that drift step over minutes entirely.
			// A signal interrupts sleep() early; the loop condition then exits.
			if( $this->_interval > 0 )
			{
				sleep( $this->_interval - ( time() % $this->_interval ) );
			}
		}

		if( $this->_shouldStop )
		{
			Log::info( "Scheduler stopped gracefully." );
		}
	}

	/**
	 * Single job poll.
	 * Checks all scheduled jobs and either runs them directly or dispatches to queue.
	 *
	 * Every job is checked against the time the poll started, not the time it is
	 * reached: jobs run one after another, and checked against the clock, a job
	 * behind a slow one was checked after its minute had passed and silently
	 * skipped.
	 *
	 * @param \DateTimeInterface|null $now The poll's time; null for now.
	 * @return int Number of jobs triggered (run or dispatched).
	 */

	public function poll( ?\DateTimeInterface $now = null ): int
	{
		Log::debug( "Polling.." );

		$now   = \DateTimeImmutable::createFromInterface( $now ?? new \DateTimeImmutable() );
		$count = 0;

		foreach( $this->_jobs as $index => $job )
		{
			Log::debug( "Checking job: {$job['name']}" );

			$due = $this->isJobDue( $job, $now );

			$this->_jobs[ $index ][ 'last_poll' ] = $now;

			if( $due && $this->_lock !== null && !$this->_lock->acquire( $job['name'], $this->scheduledMinute( $job, $now ) ) )
			{
				Log::info( "Skipping {$job['name']}: another scheduler claimed this run." );
				continue;
			}

			if( $due )
			{
				// Emit scheduler job triggered event
				Event::emit( new SchedulerJobTriggeredEvent(
					$job['name'],
					get_class( $job['job'] ),
					(string)$job['cron'],
					$job['queue'] ?? null
				) );

				// One job's failure must not take the scheduler down or skip the
				// jobs due after it: it is reported, and the next scheduled run is
				// the retry. Covers a directly-run job that throws, and a dispatch
				// that throws (queue store down, or the sync driver surfacing the
				// job's own exception).
				try
				{
					$this->runOrDispatch( $job );
				}
				catch( \Throwable $e )
				{
					Log::error( "Job {$job['name']} failed: " . $e->getMessage() );
				}

				$count++;
			}
		}

		return $count;
	}

	/**
	 * Hand a due job to its queue, or run it in this process when it has none.
	 *
	 * @param array $job Schedule entry: job, args, name and optional queue
	 * @return void
	 * @throws \Throwable Whatever the dispatch or the job itself throws
	 */
	private function runOrDispatch( array $job ): void
	{
		if( isset( $job[ 'queue' ] ) )
		{
			Log::debug( "Dispatching job to queue: {$job['name']} -> {$job['queue']}" );

			dispatch( $job[ 'job' ], $job[ 'args' ], $job[ 'queue' ] );

			return;
		}

		Log::debug( "Running job directly: {$job['name']}" );

		// No retry here - the next scheduled run is the retry - so a false
		// return (failure, per IJob) is reported and that is all.
		if( $job[ 'job' ]->run( $job[ 'args' ] ) === false )
		{
			Log::error( "Job {$job['name']} reported failure (run() returned false)." );
		}
	}

	/**
	 * Whether a job is due at the given poll.
	 *
	 * Due when one of its scheduled times falls after the previous poll and at or
	 * before this one, so a time a long poll stepped over still runs - late, and
	 * once however many were missed - rather than never. The first poll has no
	 * previous one and runs only what is due at that minute, so a restart does
	 * not replay the schedule.
	 *
	 * @param array $job
	 * @param \DateTimeImmutable $now
	 * @return bool
	 */

	private function isJobDue( array $job, \DateTimeImmutable $now ): bool
	{
		/** @var CronExpression $cron */
		$cron     = $job[ 'cron' ];
		$lastPoll = $job[ 'last_poll' ] ?? null;

		if( $lastPoll === null )
		{
			return $cron->isDue( $now );
		}

		$scheduled = \DateTimeImmutable::createFromMutable( $cron->getPreviousRunDate( $now, 0, true ) );

		// Compared by minute, the resolution of a cron expression.
		if( $scheduled->format( 'YmdHi' ) <= $lastPoll->format( 'YmdHi' ) )
		{
			return false;
		}

		if( $scheduled->format( 'YmdHi' ) < $now->format( 'YmdHi' ) )
		{
			Log::warning( "Running job late: {$job['name']} was due at {$scheduled->format( 'Y-m-d H:i' )}." );
		}

		return true;
	}

	/**
	 * The minute a due job is being fired for: its most recent scheduled time
	 * at or before the poll. Every scheduler computes the same value for the
	 * same run, which is what makes it a usable lock key.
	 *
	 * @param array $job
	 * @param \DateTimeImmutable $now
	 * @return \DateTimeImmutable
	 */

	private function scheduledMinute( array $job, \DateTimeImmutable $now ): \DateTimeImmutable
	{
		/** @var CronExpression $cron */
		$cron = $job[ 'cron' ];

		return \DateTimeImmutable::createFromMutable( $cron->getPreviousRunDate( $now, 0, true ) );
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
