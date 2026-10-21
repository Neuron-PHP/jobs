<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;

/**
 * CLI command for listing failed jobs.
 */
class FailedCommand extends Command
{
	use HasQueueManager;
	public function getName(): string
	{
		return 'jobs:failed';
	}

	public function getDescription(): string
	{
		return 'List all failed jobs';
	}

	public function configure(): void
	{
		$this->addOption( 'queue', 'Q', true, 'Only failed jobs from this queue' );
		$this->addOption( 'limit', 'l', true, 'Maximum jobs to show (default: 50)' );
		$this->addOption( 'offset', 'o', true, 'Jobs to skip (default: 0)' );
	}

	public function execute(): int
	{
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			return 1;
		}

		$queueOption = $this->input->getOption( 'queue' );
		$queue = is_string( $queueOption ) && trim( $queueOption ) !== '' ? trim( $queueOption ) : null;
		$limit = max( 1, (int)( $this->input->getOption( 'limit' ) ?? 50 ) );
		$offset = max( 0, (int)( $this->input->getOption( 'offset' ) ?? 0 ) );

		$total = $queueManager->countFailedJobs( $queue );

		if( $total === 0 )
		{
			$this->output->success( $queue === null ? "No failed jobs found!" : "No failed jobs on '{$queue}'!" );
			return 0;
		}

		$failedJobs = $queueManager->listFailedJobs( $limit, $offset, $queue );

		$this->output->info( "Failed Jobs (" . $total . "):" );
		$this->output->info( str_repeat( "─", 80 ) );

		foreach( $failedJobs as $job )
		{
			$payload = json_decode( $job['payload'], true );
			$jobClass = $payload['class'] ?? 'Unknown';

			$this->output->write( "\n" );
			$this->output->info( "ID: " . $job['id'] );
			$this->output->info( "Queue: " . $job['queue'] );
			$this->output->info( "Job: " . $jobClass );
			$this->output->info( "Failed: " . date( 'Y-m-d H:i:s', $job['failed_at'] ) );
			$this->output->error( "Exception:\n" . $job['exception'] );
			$this->output->info( str_repeat( "─", 80 ) );
		}

		$this->output->write( "\n" );

		$shownTo = $offset + count( $failedJobs );

		if( $shownTo < $total )
		{
			$this->output->info( "Showing " . ( $offset + 1 ) . "-{$shownTo} of {$total}. Next: --offset={$shownTo}" );
			$this->output->write( "\n" );
		}

		$this->output->info( "To retry a job: neuron jobs:retry <id>" );
		$this->output->info( "To retry all: neuron jobs:retry --all" );
		$this->output->info( "To forget a job: neuron jobs:forget <id>" );

		return 0;
	}
}
