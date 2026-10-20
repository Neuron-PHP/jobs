<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;

/**
 * CLI command for showing queue statistics.
 */
class StatsCommand extends Command
{
	use HasQueueManager;

	/**
	 * Get command name.
	 */
	public function getName(): string
	{
		return 'jobs:stats';
	}

	/**
	 * Get command description.
	 */
	public function getDescription(): string
	{
		return 'Show queue statistics';
	}

	/**
	 * Configure command options.
	 */
	public function configure(): void
	{
		$this->addOption( 'queue', 'Q', true, 'Queue to show stats for (comma-separated)', 'default' );
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

		$queueNames = $this->input->getOption( 'queue', 'default' );
		$queues = array_map( 'trim', explode( ',', $queueNames ) );

		$this->output->info( "Queue Statistics" );
		$this->output->info( str_repeat( "═", 60 ) );
		$this->output->write( "\n" );

		$totalJobs = 0;

		foreach( $queues as $queue )
		{
			$stats = $queueManager->getStats( $queue );

			if( !isset( $stats['pending'] ) )
			{
				$stats['pending'] = $queueManager->size( $queue );
			}

			$stats += [ 'delayed' => null, 'reserved' => null, 'oldest_pending_age' => null ];
			$totalJobs += $stats['pending'];

			$this->output->info( "Queue: {$queue}" );
			$this->output->info( "  Pending jobs: {$stats['pending']}" );

			if( $stats['delayed'] !== null )
			{
				$this->output->info( "  Delayed jobs: {$stats['delayed']}" );
			}

			if( $stats['reserved'] !== null )
			{
				$this->output->info( "  In progress:  {$stats['reserved']}" );
			}

			if( $stats['oldest_pending_age'] !== null )
			{
				$this->output->info( "  Oldest pending: " . $this->formatAge( $stats['oldest_pending_age'] ) );
			}

			$this->output->write( "\n" );
		}

		$failedJobs = $queueManager->getFailedJobs();
		$failedCount = count( $failedJobs );

		$this->output->info( "Failed Jobs: {$failedCount}" );
		$this->output->write( "\n" );

		$this->output->info( str_repeat( "═", 60 ) );
		$this->output->info( "Total pending jobs: {$totalJobs}" );
		$this->output->info( "Total failed jobs: {$failedCount}" );

		return 0;
	}

	/**
	 * Human readable duration: "42s", "3m 05s", "2h 14m", "1d 3h".
	 *
	 * @param int $seconds
	 * @return string
	 */
	private function formatAge( int $seconds ): string
	{
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
