<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;

/**
 * CLI command for retrying failed jobs.
 */
class RetryCommand extends Command
{
	use HasQueueManager;

	/**
	 * Get command name.
	 */
	public function getName(): string
	{
		return 'jobs:retry';
	}

	/**
	 * Get command description.
	 */
	public function getDescription(): string
	{
		return 'Retry one or more failed jobs';
	}

	/**
	 * Configure command options.
	 */
	public function configure(): void
	{
		$this->addOption( 'all', 'a', false, 'Retry all failed jobs' );
	}

	/**
	 * Execute the command.
	 *
	 * @param array $parameters Command parameters.
	 * @return int Exit code.
	 */
	public function execute( array $parameters = [] ): int
	{
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			return 1;
		}

		// Retry all failed jobs
		if( $this->input->hasOption( 'all' ) )
		{
			$count = $queueManager->retryAllFailedJobs();
			$this->output->success( "Retried {$count} failed job(s)" );
			return 0;
		}

		// Retry specific job
		$jobId = $parameters[0] ?? null;

		if( !$jobId )
		{
			$this->output->error( "Please provide a job ID or use --all" );
			$this->output->info( "Usage: neuron jobs:retry <id>" );
			$this->output->info( "       neuron jobs:retry --all" );
			return 1;
		}

		if( $queueManager->retryFailedJob( $jobId ) )
		{
			$this->output->success( "Job {$jobId} has been retried" );
			return 0;
		}
		else
		{
			$this->output->error( "Failed job not found: {$jobId}" );
			return 1;
		}
	}
}
