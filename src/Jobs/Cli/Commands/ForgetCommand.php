<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;

/**
 * CLI command for deleting failed jobs.
 */
class ForgetCommand extends Command
{
	public function getName(): string
	{
		return 'jobs:forget';
	}

	public function getDescription(): string
	{
		return 'Delete a failed job';
	}

	public function configure(): void
	{
		// No options needed
	}

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

	private function getQueueManager(): ?QueueManager
	{
		$registry = Registry::getInstance();
		$queueManager = $registry->get( 'queue.manager' );

		if( !$queueManager )
		{
			$settings = $registry->get( 'settings' );
			$queueManager = new QueueManager( $settings );
			$registry->set( 'queue.manager', $queueManager );
		}

		return $queueManager;
	}
}
