<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\ISettingSource;
use Neuron\Application\Base;
use Neuron\Jobs\Bootstrap\MinimalApplication;
use Neuron\Jobs\Bootstrap\WorkerBootstrapper;
use Neuron\Jobs\Queue\DatabaseQueue;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Schedule\DatabaseScheduleLock;
use Neuron\Jobs\Schedule\IScheduleLock;
use Neuron\Jobs\Scheduler;
use Neuron\Data\Settings\SettingManagerFactory;
use Neuron\Data\Objects\Version;

/**
 * CLI command for running the job scheduler.
 * Replaces the standalone schedule script with integrated CLI command.
 */
class ScheduleCommand extends Command
{
	private ?Scheduler $scheduler = null;
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
		return 'jobs:schedule';
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Run the job scheduler for executing scheduled tasks';
	}
	
	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		$this->addOption( 'poll', 'p', false, 'Perform a single poll and exit' );
		$this->addOption( 'interval', 'i', true, 'Set polling interval in seconds (default: 60)' );
		$this->addOption( 'config', 'c', true, 'Path to configuration directory' );
		$this->addOption( 'config-file', 'f', true, 'Schedule configuration filename (default: schedule.yaml)' );
		$this->addOption( 'debug', 'd', false, 'Enable debug mode (single poll then exit)' );
		$this->addOption( 'no-bootstrap', null, false, 'Skip booting the application (jobs run without container/database context)' );
	}
	
	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Get configuration path
		$configPath = $this->input->getOption( 'config', $this->findConfigPath() );
		
		if( !$configPath || !is_dir( $configPath ) )
		{
			$this->output->error( 'Configuration directory not found: ' . ($configPath ?: 'none specified') );
			$this->output->info( 'Use --config to specify the configuration directory' );
			return 1;
		}

		// Boot the application context so jobs that run inline in the
		// scheduler process, and the dispatch() calls for queued ones, see the
		// same container, database and listeners a request would.
		$bootstrapper = null;
		$app          = null;

		if( !$this->input->hasOption( 'no-bootstrap' ) )
		{
			$bootstrapper = $this->bootstrapper ?? new WorkerBootstrapper();
			$app = $bootstrapper->boot( 'scheduler', $configPath );

			if( $app )
			{
				$this->output->info( 'Application: ' . get_class( $app ) );
			}
			else
			{
				$this->output->warning( 'Running without application context' );
			}
		}

		try
		{
			return $this->runScheduler( $configPath, $app );
		}
		finally
		{
			$bootstrapper?->shutdown();
		}
	}

	/**
	 * Initialise and run the scheduler.
	 *
	 * @param string $configPath
	 * @param Base|null $context The application context booted for this process, if any
	 * @return int Exit code
	 */
	private function runScheduler( string $configPath, ?Base $context = null ): int
	{
		// Initialize the scheduler
		try
		{
			$this->scheduler = $this->initializeScheduler( $configPath );
			
			if( !$this->scheduler )
			{
				$this->output->error( 'Failed to initialize scheduler' );
				return 1;
			}

			// The scheduler is an application too, and starting it loads the
			// event listeners and runs the initializers. A context booted
			// above has done that already; doing it again registered every
			// listener twice, so each event was handled twice. A
			// MinimalApplication loads the listeners but runs no initializers,
			// so the scheduler still runs those.
			if( $context !== null )
			{
				$this->scheduler->setStartedByContext( true, !$context instanceof MinimalApplication );
			}
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error initializing scheduler: ' . $e->getMessage() );
			
			if( $this->input->hasOption( 'verbose' ) || $this->input->hasOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}
			
			return 1;
		}
		
		// Set options
		if( $this->input->hasOption( 'interval' ) )
		{
			$interval = (int) $this->input->getOption( 'interval' );
			if( $interval > 0 )
			{
				$this->scheduler->setInterval( $interval );
				$this->output->info( "Polling interval set to {$interval} seconds" );
			}
		}
		
		if( $this->input->hasOption( 'config-file' ) )
		{
			$configFile = $this->input->getOption( 'config-file' );
			$this->scheduler->setConfigFile( $configFile );
		}
		
		if( $this->input->hasOption( 'debug' ) )
		{
			$this->scheduler->setDebug( true );
			$this->output->info( 'Debug mode enabled' );
		}
		
		// Run the scheduler
		try
		{
			// Store current output for scheduler to use
			\Neuron\Patterns\Registry::getInstance()->set( RegistryKeys::CLI_OUTPUT_LEGACY, $this->output );
			
			// Build argv array for scheduler
			$argv = ['schedule'];
			
			if( $this->input->hasOption( 'poll' ) )
			{
				$argv[] = '--poll';
			}
			
			if( $this->input->hasOption( 'interval' ) )
			{
				$argv[] = '--interval';
				$argv[] = $this->input->getOption( 'interval' );
			}
			
			// Run the scheduler
			$this->scheduler->run( $argv );
			
			return 0;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error running scheduler: ' . $e->getMessage() );
			
			if( $this->input->hasOption( 'verbose' ) || $this->input->hasOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}
			
			return 1;
		}
	}
	
	/**
	 * Initialize the scheduler with configuration
	 * 
	 * @param string $configPath
	 * @return Scheduler|null
	 */
	private function initializeScheduler( string $configPath ): ?Scheduler
	{
		// Load settings: every file layer is optional, the environment is the
		// fallback. Only consulted when bin/neuron has not already put the
		// settings in the Registry (Base::initSettings() prefers those).
		$settings = null;

		try
		{
			$settings = SettingManagerFactory::create( null, $configPath );

			if( file_exists( $configPath . '/neuron.yaml' ) )
			{
				$this->output->info( 'Loaded configuration from: ' . $configPath . '/neuron.yaml' );
			}
		}
		catch( \Exception $e )
		{
			$this->output->warning( 'Could not load configuration: ' . $e->getMessage() );
		}
		
		// Load version information
		$versionFile = dirname( __DIR__, 4 ) . '/.version.json';

		try
		{
			$version = \Neuron\Data\Factories\Version::fromFile( $versionFile );
		}
		catch( \Exception $e )
		{
			$version = new Version();
			$version->setMajor( 0 )->setMinor( 1 )->setPatch( 0 );
		}

		// Create scheduler instance
		$scheduler = new Scheduler( $version->getAsString(), $settings );
		
		// Set the base path for the scheduler to find schedule.yaml
		$scheduler->setBasePath( dirname( $configPath ) );

		$scheduler->setLock( $this->createLock( $settings ) );
		
		return $scheduler;
	}

	/**
	 * Build the schedule lock named by `queue.schedule_lock`, if any.
	 *
	 * `database` shares the queue's PDO connection, so it only applies when the
	 * queue driver is `database` too. Anything else (or unset) means no lock:
	 * a single scheduler needs none.
	 *
	 * @param ISettingSource|null $settings
	 * @return IScheduleLock|null
	 */
	private function createLock( ?ISettingSource $settings ): ?IScheduleLock
	{
		$mode = $settings?->get( 'queue', 'schedule_lock' );

		if( !is_string( $mode ) || $mode === '' || $mode === 'none' )
		{
			return null;
		}

		if( $mode !== 'database' )
		{
			$this->output->warning( "Unknown queue.schedule_lock '{$mode}'; running without a schedule lock" );
			return null;
		}

		$driver = QueueManager::fromRegistry()->getDriver();

		if( !$driver instanceof DatabaseQueue )
		{
			$this->output->warning( 'queue.schedule_lock: database needs queue.driver: database; running without a schedule lock' );
			return null;
		}

		$this->output->info( 'Schedule lock: database' );

		return new DatabaseScheduleLock( $driver->getConnection() );
	}
	
	/**
	 * Try to find the configuration directory
	 * 
	 * @return string|null
	 */
	private function findConfigPath(): ?string
	{
		// Try common locations
		$locations = [
			getcwd() . '/config',
			dirname( getcwd() ) . '/config',
			dirname( getcwd(), 2 ) . '/config',
			dirname( __DIR__, 4 ) . '/config',
			dirname( __DIR__, 5 ) . '/config',
		];
		
		foreach( $locations as $location )
		{
			if( is_dir( $location ) )
			{
				return $location;
			}
		}
		
		return null;
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
		$help .= "  # Run scheduler in infinite loop (default)\n";
		$help .= "  neuron jobs:schedule\n\n";
		$help .= "  # Run a single poll and exit\n";
		$help .= "  neuron jobs:schedule --poll\n\n";
		$help .= "  # Set custom polling interval (30 seconds)\n";
		$help .= "  neuron jobs:schedule --interval=30\n\n";
		$help .= "  # Use specific configuration directory\n";
		$help .= "  neuron jobs:schedule --config=/path/to/config\n\n";
		$help .= "  # Use custom schedule file\n";
		$help .= "  neuron jobs:schedule --config-file=custom-schedule.yaml\n\n";
		$help .= "  # Debug mode (single poll then exit)\n";
		$help .= "  neuron jobs:schedule --debug\n";
		
		return $help;
	}
}
