<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Jobs\Bootstrap\WorkerBootstrapper;
use Neuron\Jobs\Cli\Traits\HasQueueManager;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\Worker;

/**
 * CLI command for running the queue worker.
 *
 * Processes jobs from the queue in daemon mode or one-time execution. Before
 * the first job the site's application is booted via `queue.bootstrap` so jobs
 * run with the same container, database connection, events and view paths a
 * web request has.
 */
class WorkCommand extends Command
{
	use HasQueueManager;

	private ?WorkerBootstrapper $bootstrapper = null;

	/**
	 * Inject a bootstrapper (tests); defaults to a new WorkerBootstrapper.
	 *
	 * @param WorkerBootstrapper $bootstrapper
	 * @return void
	 */
	public function setBootstrapper( WorkerBootstrapper $bootstrapper ): void
	{
		$this->bootstrapper = $bootstrapper;
	}

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
		$this->addOption( 'queue', 'Q', true, 'Queue(s) to process (comma-separated)', 'default,emails' );
		$this->addOption( 'once', null, false, 'Process one job then exit' );
		$this->addOption( 'stop-when-empty', null, false, 'Stop when queue is empty' );
		$this->addOption( 'sleep', 's', true, 'Seconds to sleep when queue is empty', '3' );
		$this->addOption( 'max-jobs', 'm', true, 'Max jobs to process before stopping (0 = unlimited)', '0' );
		$this->addOption( 'timeout', 't', true, 'Job timeout in seconds (0 = no limit)', '60' );
		$this->addOption( 'memory', null, true, 'Memory limit in MB; worker exits between jobs once exceeded (0 = no limit)', '0' );
		$this->addOption( 'max-time', null, true, 'Max seconds the worker runs before exiting for restart (0 = no limit)', '0' );
		$this->addOption( 'config', 'c', true, 'Path to configuration directory (located automatically when omitted)' );
		$this->addOption( 'no-bootstrap', null, false, 'Skip booting the application (jobs run without container/database context)' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		$this->output->info( "Queue Worker Starting..." );

		// Boot the application context first so the queue manager, and every
		// job, sees the same settings, container and listeners a request would.
		$bootstrapper = null;

		if( !$this->input->hasOption( 'no-bootstrap' ) )
		{
			$bootstrapper = $this->bootstrapper ?? new WorkerBootstrapper();
			$app = $bootstrapper->boot( 'worker', $this->input->getOption( 'config' ) );

			if( $app )
			{
				$this->output->info( "Application: " . get_class( $app ) );
			}
			else
			{
				$this->output->warning( "Running without application context" );
			}
		}

		// Get queue manager
		$queueManager = $this->getQueueManager();

		if( !$queueManager )
		{
			$this->output->error( "Failed to initialize queue manager" );
			$bootstrapper?->shutdown();
			return 1;
		}

		// Get options
		$queueNames = $this->input->getOption( 'queue', 'default,emails' );
		$queues = array_map( 'trim', explode( ',', $queueNames ) );
		$once = $this->input->hasOption( 'once' );
		$stopWhenEmpty = $this->input->hasOption( 'stop-when-empty' );
		$sleep = (int) $this->input->getOption( 'sleep', '3' );
		$maxJobs = (int) $this->input->getOption( 'max-jobs', '0' );
		$timeout = (int) $this->input->getOption( 'timeout', '60' );
		$memory = (int) $this->input->getOption( 'memory', '0' );
		$maxTime = (int) $this->input->getOption( 'max-time', '0' );

		// Display configuration
		$this->output->info( "Configuration:" );
		$this->output->info( "  Driver: " . ( new \ReflectionClass( $queueManager->getDriver() ) )->getShortName() );
		$this->output->info( "  Queue(s): " . implode( ', ', $queues ) );
		$this->output->info( "  Mode: " . ( $once ? "Single job" : "Daemon" ) );
		$this->output->info( "  Sleep: {$sleep}s" );

		if( $maxJobs > 0 )
		{
			$this->output->info( "  Max jobs: {$maxJobs}" );
		}

		if( $memory > 0 )
		{
			$this->output->info( "  Memory limit: {$memory}MB" );
		}

		if( $maxTime > 0 )
		{
			$this->output->info( "  Max time: {$maxTime}s" );
		}

		$this->output->info( "  Timeout: " . ( $timeout > 0 ? "{$timeout}s" : 'none' ) );

		if( $timeout > 0 && !QueueManager::canEnforceTimeout() )
		{
			$this->output->warning( "  pcntl extension not available; timeouts will not be enforced" );
		}

		$this->output->info( "" );

		$restartFile = RestartCommand::resolveRestartFile( $this->input->getOption( 'config' ) );

		if( $restartFile !== null )
		{
			$this->output->info( "  Restart file: {$restartFile}" );
		}

		// Create worker
		$worker = new Worker( $queueManager );
		$worker->setSleep( $sleep )
			->setMaxJobs( $maxJobs )
			->setTimeout( $timeout )
			->setMemoryLimit( $memory )
			->setMaxTime( $maxTime )
			->setRestartFile( $restartFile )
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
		finally
		{
			$bootstrapper?->shutdown();
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
		$help .= "  neuron jobs:work --stop-when-empty\n\n";
		$help .= "  # Recycle the worker when it grows past 128MB or has run for an hour\n";
		$help .= "  neuron jobs:work --memory=128 --max-time=3600\n\n";
		$help .= "The worker boots the application named by queue.bootstrap in neuron.yaml\n";
		$help .= "before processing, so jobs get the container, database and event listeners.\n";

		return $help;
	}
}
