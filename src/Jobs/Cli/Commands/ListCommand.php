<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;
use Neuron\Jobs\Queue\JobState;
use Neuron\Jobs\Queue\QueuedJob;

/**
 * CLI command listing the jobs waiting on a queue.
 *
 * Shows pending, delayed or reserved jobs with class, attempts, timing and
 * an arguments preview. Needs a driver that implements IQueueInspector.
 */
class ListCommand extends Command
{
	use HasQueueManager;

	private const ARGS_PREVIEW_LENGTH = 60;

	/**
	 * Get command name.
	 */
	public function getName(): string
	{
		return 'jobs:list';
	}

	/**
	 * Get command description.
	 */
	public function getDescription(): string
	{
		return 'List jobs waiting on a queue';
	}

	/**
	 * Configure command options.
	 */
	public function configure(): void
	{
		$this->addOption( 'queue', 'Q', true, 'Queue to list (default: the configured default queue)' );
		$this->addOption( 'state', 's', true, 'pending, delayed or reserved (default: pending)' );
		$this->addOption( 'limit', 'l', true, 'Maximum jobs to show (default: 50)' );
		$this->addOption( 'offset', 'o', true, 'Jobs to skip (default: 0)' );
	}

	/**
	 * Execute the command.
	 *
	 * @return int Exit code.
	 */
	public function execute(): int
	{
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			return 1;
		}

		if( !$queueManager->supportsInspection() )
		{
			$this->output->error( "The '{$queueManager->getDriverName()}' queue driver cannot list jobs." );
			return 1;
		}

		$stateOption = (string)( $this->input->getOption( 'state' ) ?? 'pending' );
		$state = JobState::tryFromInput( $stateOption );

		if( $state === null )
		{
			$this->output->error( "Unknown state '{$stateOption}'. Use pending, delayed or reserved." );
			return 1;
		}

		$queueOption = $this->input->getOption( 'queue' );
		$queue = is_string( $queueOption ) && trim( $queueOption ) !== '' ? trim( $queueOption ) : $queueManager->getDefaultQueue();
		$limit = max( 1, (int)( $this->input->getOption( 'limit' ) ?? 50 ) );
		$offset = max( 0, (int)( $this->input->getOption( 'offset' ) ?? 0 ) );

		$total = $queueManager->countJobs( $queue, $state );
		$jobs = $queueManager->listJobs( $queue, $state, $limit, $offset );

		$this->output->info( ucfirst( $state->value ) . " jobs on '{$queue}': {$total}" );

		if( $jobs === [] )
		{
			return 0;
		}

		$this->output->info( str_repeat( "─", 80 ) );

		$now = time();

		foreach( $jobs as $job )
		{
			$this->output->write( "\n" );
			$this->output->info( "ID:       " . $job->getId() );
			$this->output->info( "Job:      " . $job->getJobClass() );
			$this->output->info( "Attempts: " . $job->getAttempts() );
			$this->output->info( "Timing:   " . $this->describeTiming( $job, $state, $now ) );
			$this->output->info( "Args:     " . $this->previewArguments( $job ) );
		}

		$this->output->write( "\n" );
		$this->output->info( str_repeat( "─", 80 ) );

		$shownTo = $offset + count( $jobs );

		if( $shownTo < $total )
		{
			$this->output->info( "Showing " . ( $offset + 1 ) . "-{$shownTo} of {$total}. Next: --offset={$shownTo}" );
		}

		return 0;
	}

	/**
	 * One-line timing for the job in its state.
	 *
	 * @param QueuedJob $job
	 * @param JobState $state
	 * @param int $now
	 * @return string
	 */
	private function describeTiming( QueuedJob $job, JobState $state, int $now ): string
	{
		return match( $state )
		{
			JobState::Pending  => 'waiting ' . $this->formatAge( $now - $job->getAvailableAt() ),
			JobState::Delayed  => 'runs in ' . $this->formatAge( $job->getAvailableAt() - $now ),
			JobState::Reserved => 'reserved for ' . $this->formatAge( $now - (int)$job->getReservedAt() ),
		};
	}

	/**
	 * Arguments as compact JSON, truncated for display.
	 *
	 * @param QueuedJob $job
	 * @return string
	 */
	private function previewArguments( QueuedJob $job ): string
	{
		$args = $job->getArguments();

		if( $args === [] )
		{
			return '-';
		}

		$json = json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '';

		if( strlen( $json ) <= self::ARGS_PREVIEW_LENGTH )
		{
			return $json;
		}

		return substr( $json, 0, self::ARGS_PREVIEW_LENGTH - 1 ) . '…';
	}

	/**
	 * Human readable duration: "42s", "3m 05s", "2h 14m", "1d 3h".
	 *
	 * @param int $seconds
	 * @return string
	 */
	private function formatAge( int $seconds ): string
	{
		$seconds = max( 0, $seconds );

		if( $seconds < 60 )
		{
			return "{$seconds}s";
		}

		if( $seconds < 3600 )
		{
			return sprintf( '%dm %02ds', intdiv( $seconds, 60 ), $seconds % 60 );
		}

		if( $seconds < 86400 )
		{
			return sprintf( '%dh %02dm', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
		}

		return sprintf( '%dd %dh', intdiv( $seconds, 86400 ), intdiv( $seconds % 86400, 3600 ) );
	}
}
