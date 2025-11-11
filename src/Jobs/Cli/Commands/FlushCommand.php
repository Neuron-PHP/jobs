<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;

/**
 * CLI command for flushing queue or failed jobs.
 */
class FlushCommand extends Command
{
	use HasQueueManager;
	public function getName(): string
	{
		return 'jobs:flush';
	}

	public function getDescription(): string
	{
		return 'Flush queue or failed jobs';
	}

	public function configure(): void
	{
		$this->addOption( 'queue', 'Q', true, 'Queue to flush', 'default' );
		$this->addOption( 'failed', 'f', false, 'Flush failed jobs instead' );
	}

	public function execute(): int
	{
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			return 1;
		}

		// Flush failed jobs
		if( $this->input->hasOption( 'failed' ) )
		{
			$count = $queueManager->clearFailedJobs();
			$this->output->success( "Flushed {$count} failed job(s)" );
			return 0;
		}

		// Flush queue
		$queue = $this->input->getOption( 'queue', 'default' );
		$count = $queueManager->clear( $queue );
		$this->output->success( "Flushed {$count} job(s) from queue: {$queue}" );

		return 0;
	}
}
