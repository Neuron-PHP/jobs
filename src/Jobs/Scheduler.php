<?php

namespace Neuron\Jobs;

use Cron\CronExpression;
use Neuron\Application\CommandLineBase;
use Neuron\Log\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * CLI application for scheduling jobs.
 * Jobs are defined in the config/schedule.yaml file.
 * Usage:
 * vendor/bin/schedule
 */

class Scheduler extends CommandLineBase
{
	private bool $_Poll = false;
	private int $_Interval = 60;
	private array $_Jobs = [];
	private string $_ConfigFile =  'schedule.yaml';
	private bool $_Debug = false;


	/**
	 * @param bool $Debug
	 * @return Scheduler
	 */

	public function setDebug( bool $Debug ): Scheduler
	{
		$this->_Debug = $Debug;
		return $this;
	}


	/**
	 * @return string
	 */

	public function getConfigFile(): string
	{
		return $this->_ConfigFile;
	}

	/**
	 * @param string $ConfigFile
	 * @return Scheduler
	 */

	public function setConfigFile( string $ConfigFile ): Scheduler
	{
		$this->_ConfigFile = $ConfigFile;
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
	 * @param int $Interval
	 * @return Scheduler
	 */

	public function setInterval( int $Interval ): Scheduler
	{
		$this->_Interval = $Interval;
		return $this;
	}

	/**
	 * @return int
	 */

	public function getInterval(): int
	{
		return $this->_Interval;
	}

	/**
	 * @param string $Name
	 * @param string $Cron
	 * @param IJob $Job
	 * @param array $Arguments
	 */

	public function addJob( string $Name, string $Cron, IJob $Job, array $Arguments = [] ): void
	{
		Log::debug( "Adding job: {$Job->getName()} $Cron" );

		$this->_Jobs[] = [
			'name' => $Name,
			'cron' => new CronExpression( $Cron ),
			'job'  => $Job,
			'args' => $Arguments
		];
	}

	/**
	 * @return array
	 */

	public function getJobs(): array
	{
		return $this->_Jobs;
	}

	/**
	 * @param $Schedule
	 * @return void
	 */

	public function scheduleJobs( $Schedule ): void
	{
		foreach( $Schedule as $Name => $Job )
		{
			$Class = $Job[ 'class' ];
			if( !class_exists( $Class ) )
			{
				Log::error( "Class not found: $Class" );
				continue;
			}

			$this->addJob( $Name, $Job[ 'cron' ], new $Class(), $Job[ 'args' ] ?? [] );
		}
	}

	/**
	 * @return array
	 */

	public function loadSchedule() : array
	{
		$Path = $this->getBasePath().'/config';

		if( !file_exists( $Path . '/'. $this->getConfigFile() ) )
		{
			Log::debug( "schedule.yaml not found." );
			return [];
		}

		try
		{
			$Data = Yaml::parseFile( $Path . '/'. $this->getConfigFile() );
		}
		catch( ParseException $exception )
		{
			Log::error( "Failed to load schedule: ".$exception->getMessage() );
			return [];
		}

		return $Data;
	}

	/**
	 * Schedule the jobs from the schedule.yaml file.
	 * @return void
	 */

	private function initSchedule(): void
	{
		$Data = $this->loadSchedule();

		if( empty( $Data ) )
		{
			return;
		}

		$this->scheduleJobs( $Data[ 'schedule' ] );
	}

	/**
	 * Command line parameter to poll events one time.
	 * @return bool
	 */

	protected function pollCommand(): bool
	{
		$this->_Poll = true;

		return true;
	}

	/**
	 * Command line parameter to set the interval between polls.
	 * @param int $Interval interval in seconds.
	 * @return bool
	 */

	protected function intervalCommand( int $Interval ): bool
	{
		$this->setInterval( $Interval );
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

			sleep( $this->_Interval );

			if( $this->_Debug )
			{
				break;
			}
		}
	}

	/**
	 * Single job poll.
	 * @return int Number of jobs run.
	 */

	public function poll(): int
	{
		Log::debug( "Polling.." );

		$Count = 0;

		foreach( $this->_Jobs as $Job )
		{
			Log::debug( "Checking job: {$Job['name']}" );

			if( $Job[ 'cron' ]->isDue() )
			{
				Log::debug( "Running job: {$Job['name']}" );

				$Job[ 'job' ]->run( $Job[ 'args' ] );
				$Count++;
			}
		}

		return $Count;
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

		if( count( $this->_Jobs ) == 0 )
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
	 * @param array $Argv
	 */

	protected function onRun( array $Argv = [] ): void
	{
		if( $this->_Poll )
		{
			$this->poll();
			return;
		}

		$this->infinitePoll();
	}
}
