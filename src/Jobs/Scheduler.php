<?php

namespace Neuron\Jobs;

use Cron\CronExpression;
use Neuron\Core\Application\CommandLineBase;
use Neuron\Log\Log;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Scheduler extends CommandLineBase
{
	private bool $_Poll = false;
	private int $_Interval = 60;
	private array $_Jobs = [];

	protected function getDescription(): string
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
	 * @param string $Cron
	 * @param IJob $Job
	 * @param ?array $Arguments
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
	 * Schedule the jobs from the schedule.yaml file.
	 * @return void
	 */
	private function initSchedule(): void
	{
		$Path = $this->getBasePath().'/config';

		if( !file_exists( $Path . '/schedule.yaml' ) )
		{
			Log::debug( "schedule.yaml not found." );
			return;
		}

		try
		{
			$Data = Yaml::parseFile( $Path . '/schedule.yaml' );
		}
		catch( ParseException $exception )
		{
			Log::error( "Failed to load schedule: ".$exception->getMessage() );
			return;
		}

		foreach( $Data[ 'schedule' ] as $Name => $Job )
		{
			$Class = $Job[ 'class' ];
			if( !class_exists( $Class ) )
			{
				Log::error( "Class not found: $Class" );
				continue;
			}

			$this->addJob(
				$Name,
				$Job[ 'cron' ],
				new $Class(),
				$Job[ 'args' ] ?? []
			);
		}
	}

	/**
	 * Command line parameter to poll events one time.
	 * @return void
	 */
	protected function pollCommand(): void
	{
		$this->_Poll = true;
	}

	protected function intervalCommand( int $Interval ): void
	{
		$this->setInterval( $Interval );
		Log::debug( "Setting interval to: {$this->getInterval()}" );
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

	protected function onStart(): bool
	{
		$this->addHandler( '--poll', 'Performs a single poll and executes all ready jobs.', 'pollCommand' );
		$this->addHandler( '--interval', 'Set the interval between polls in seconds.', 'intervalCommand', true );

		return parent::onStart();
	}

	/**
	 * @param array $Argv
	 */
	protected function onRun( array $Argv = [] ): void
	{
		$this->initSchedule();

		if( count( $this->_Jobs ) == 0 )
		{
			Log::error( "No jobs defined." );
			fprintf( STDERR, "No jobs defined.\n" );
			return;
		}

		if( $this->_Poll )
		{
			$this->poll();
			return;
		}

		$this->infinitePoll();
	}
}
