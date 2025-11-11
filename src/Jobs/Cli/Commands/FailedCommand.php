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
		// No options needed
	}

	public function execute(): int
	{
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			return 1;
		}

		$failedJobs = $queueManager->getFailedJobs();

		if( empty( $failedJobs ) )
		{
			$this->output->success( "No failed jobs found!" );
			return 0;
		}

		$this->output->info( "Failed Jobs (" . count( $failedJobs ) . "):" );
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
		$this->output->info( "To retry a job: neuron jobs:retry <id>" );
		$this->output->info( "To retry all: neuron jobs:retry --all" );
		$this->output->info( "To forget a job: neuron jobs:forget <id>" );

		return 0;
	}
}
