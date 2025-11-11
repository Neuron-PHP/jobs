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
			$size = $queueManager->size( $queue );
			$totalJobs += $size;

			$this->output->info( "Queue: {$queue}" );
			$this->output->info( "  Pending jobs: {$size}" );
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
}
