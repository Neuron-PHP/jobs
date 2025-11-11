<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;

/**
 * CLI command for deleting failed jobs.
 */
class ForgetCommand extends Command
{
	use HasQueueManager;
	/**
	 * Get command name.
	 */
	public function getName(): string
	{
		return 'jobs:forget';
	}

	/**
	 * Get command description.
	 */
	public function getDescription(): string
	{
		return 'Delete a failed job';
	}

	/**
	 * Configure command options.
	 */
	public function configure(): void
	{
		// No options needed
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

		$jobId = $parameters[0] ?? null;

		if( !$jobId )
		{
			$this->output->error( "Please provide a job ID" );
			$this->output->info( "Usage: neuron jobs:forget <id>" );
			return 1;
		}

		if( $queueManager->forgetFailedJob( $jobId ) )
		{
			$this->output->success( "Failed job {$jobId} has been deleted" );
			return 0;
		}
		else
		{
			$this->output->error( "Failed job not found: {$jobId}" );
			return 1;
		}
	}
}
