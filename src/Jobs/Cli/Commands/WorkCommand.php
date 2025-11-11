<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Cli\Traits\HasQueueManager;
use Neuron\Jobs\Queue\Worker;

/**
 * CLI command for running the queue worker.
 *
 * Processes jobs from the queue in daemon mode or one-time execution.
 */
class WorkCommand extends Command
{
	use HasQueueManager;
	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'jobs:work';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Process jobs from the queue';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'queue', 'Q', true, 'Queue(s) to process (comma-separated)', 'default' );
		$this->addOption( 'once', null, false, 'Process one job then exit' );
		$this->addOption( 'stop-when-empty', null, false, 'Stop when queue is empty' );
		$this->addOption( 'sleep', 's', true, 'Seconds to sleep when queue is empty', '3' );
		$this->addOption( 'max-jobs', 'm', true, 'Max jobs to process before stopping (0 = unlimited)', '0' );
		$this->addOption( 'timeout', 't', true, 'Job timeout in seconds', '60' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		$this->output->info( "Queue Worker Starting..." );

		// Get queue manager
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			return 1;
		}

		// Get options
		$queueNames = $this->input->getOption( 'queue', 'default' );
		$queues = array_map( 'trim', explode( ',', $queueNames ) );
		$once = $this->input->hasOption( 'once' );
		$stopWhenEmpty = $this->input->hasOption( 'stop-when-empty' );
		$sleep = (int) $this->input->getOption( 'sleep', '3' );
		$maxJobs = (int) $this->input->getOption( 'max-jobs', '0' );
		$timeout = (int) $this->input->getOption( 'timeout', '60' );

		// Display configuration
		$this->output->info( "Configuration:" );
		$this->output->info( "  Queue(s): " . implode( ', ', $queues ) );
		$this->output->info( "  Mode: " . ( $once ? "Single job" : "Daemon" ) );
		$this->output->info( "  Sleep: {$sleep}s" );

		if( $maxJobs > 0 )
		{
			$this->output->info( "  Max jobs: {$maxJobs}" );
		}

		$this->output->info( "  Timeout: {$timeout}s" );
		$this->output->info( "" );

		// Create worker
		$worker = new Worker( $queueManager );
		$worker->setSleep( $sleep )
			->setMaxJobs( $maxJobs )
			->setTimeout( $timeout )
			->setStopWhenEmpty( $stopWhenEmpty );

		try
		{
			$this->output->success( "Worker is ready. Press Ctrl+C to stop." );
			$this->output->info( "" );

			$worker->run( $queues, $once );

			$this->output->info( "" );
			$this->output->success( "Worker stopped gracefully. Jobs processed: {$worker->getJobsProcessed()}" );

			return 0;
		}
		catch( \Exception $e )
		{
			$this->output->error( "Worker error: " . $e->getMessage() );

			if( $this->input->hasOption( 'verbose' ) || $this->input->hasOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}

			return 1;
		}
	}

	/**
	 * Get detailed help for the command
	 *
	 * @return string
	 */
	public function getHelp(): string
	{
		$help = parent::getHelp();

		$help .= "\n\n";
		$help .= "Examples:\n";
		$help .= "  # Run worker in daemon mode on default queue\n";
		$help .= "  neuron jobs:work\n\n";
		$help .= "  # Process one job then exit\n";
		$help .= "  neuron jobs:work --once\n\n";
		$help .= "  # Process specific queue\n";
		$help .= "  neuron jobs:work --queue=emails\n\n";
		$help .= "  # Process multiple queues with priority\n";
		$help .= "  neuron jobs:work --queue=high,default,low\n\n";
		$help .= "  # Set custom sleep time\n";
		$help .= "  neuron jobs:work --sleep=5\n\n";
		$help .= "  # Process max 100 jobs then stop\n";
		$help .= "  neuron jobs:work --max-jobs=100\n\n";
		$help .= "  # Stop when queue is empty\n";
		$help .= "  neuron jobs:work --stop-when-empty\n";

		return $help;
	}
}
