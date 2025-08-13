<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Scheduler;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Data\Object\Version;
use Neuron\Log\Log;

/**
 * CLI command for running the job scheduler.
 * Replaces the standalone schedule script with integrated CLI command.
 */
class ScheduleCommand extends Command
{
	private ?Scheduler $scheduler = null;
	
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
		
		// Initialize the scheduler
		try
		{
			$this->scheduler = $this->initializeScheduler( $configPath );
			
			if( !$this->scheduler )
			{
				$this->output->error( 'Failed to initialize scheduler' );
				return 1;
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
			\Neuron\Patterns\Registry::getInstance()->set( 'cli.output', $this->output );
			
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
		// Try to load settings
		$settings = null;
		$configFile = $configPath . '/config.yaml';
		
		if( file_exists( $configFile ) )
		{
			try
			{
				$settings = new Yaml( $configFile );
				$this->output->info( 'Loaded configuration from: ' . $configFile );
			}
			catch( \Exception $e )
			{
				$this->output->warning( 'Could not load config.yaml: ' . $e->getMessage() );
			}
		}
		
		// Load version information
		$version = new Version();
		$versionFile = dirname( __DIR__, 4 ) . '/.version.json';
		
		if( file_exists( $versionFile ) )
		{
			$version->loadFromFile( $versionFile );
		}
		else
		{
			// Use a default version if file not found
			$version->setMajor( 0 )->setMinor( 1 )->setPatch( 0 );
		}
		
		// Create scheduler instance
		$scheduler = new Scheduler( $version->getAsString(), $settings );
		
		// Set the base path for the scheduler to find schedule.yaml
		$scheduler->setBasePath( dirname( $configPath ) );
		
		return $scheduler;
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