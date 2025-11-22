<?php

namespace Neuron\Jobs\Cli\Commands\Generate;

use Neuron\Cli\Commands\Command;
use Symfony\Component\Yaml\Yaml;
use Cron\CronExpression;

/**
 * CLI command for generating job classes.
 * Optionally adds jobs to schedule.yaml when --cron is provided.
 */
class JobCommand extends Command
{
	private string $_ProjectPath;
	private string $_StubPath;

	public function __construct()
	{
		$this->_ProjectPath = getcwd();
		$this->_StubPath = __DIR__ . '/stubs';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'job:generate';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Generate a scheduled job class';
	}

	/**
	 * Configure the command
	 */
	public function configure(): void
	{
		$this->addArgument( 'name', true, 'Job name (e.g., SendEmailReminders)' );
		$this->addOption( 'namespace', null, true, 'Job namespace', 'App\\Jobs' );
		$this->addOption( 'cron', null, true, 'Cron expression (e.g., "0 9 * * *") - automatically adds to schedule.yaml' );
		$this->addOption( 'force', 'f', false, 'Overwrite existing files' );
	}

	/**
	 * Execute the command
	 */
	public function execute(): int
	{
		$this->output->info( "\n╔═══════════════════════════════════════╗" );
		$this->output->info( "║  Job Generator                        ║" );
		$this->output->info( "╚═══════════════════════════════════════╝\n" );

		// Get job name
		$jobName = $this->input->getArgument( 'name' );
		if( empty( $jobName ) )
		{
			$this->output->error( 'Job name is required' );
			return 1;
		}

		// Get namespace
		$namespace = $this->input->getOption( 'namespace', 'App\\Jobs' );
		$namespace = rtrim( $namespace, '\\' );

		// Validate cron expression if provided
		$cronExpression = $this->input->getOption( 'cron' );
		if( $cronExpression )
		{
			if( !$this->validateCronExpression( $cronExpression ) )
			{
				return 1;
			}
		}

		// Generate job
		if( !$this->generateJob( $jobName, $namespace ) )
		{
			return 1;
		}

		// Add to schedule if cron provided
		if( $cronExpression )
		{
			if( !$this->addToSchedule( $jobName, $namespace, $cronExpression ) )
			{
				$this->output->warning( 'Job created but failed to update schedule.yaml' );
				$this->output->info( 'You may need to add it manually to config/schedule.yaml' );
			}
		}

		$this->output->newLine();
		$this->output->success( 'Job generated successfully!' );

		if( $cronExpression )
		{
			$this->output->info( "\nJob scheduled with cron expression: {$cronExpression}" );
			$this->output->info( "Run scheduler: php neuron jobs:schedule" );
		}
		else
		{
			$this->output->info( "\nTo schedule this job, add it to config/schedule.yaml or regenerate with --cron option" );
		}

		return 0;
	}

	/**
	 * Validate cron expression
	 */
	private function validateCronExpression( string $expression ): bool
	{
		try
		{
			$cron = new CronExpression( $expression );

			// Show next run times for validation feedback
			$this->output->info( "Cron expression validated: {$expression}" );

			$next = $cron->getNextRunDate();
			$this->output->info( "Next run: " . $next->format( 'Y-m-d H:i:s' ) );

			return true;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Invalid cron expression: ' . $e->getMessage() );
			$this->output->info( 'Examples:' );
			$this->output->info( '  "0 9 * * *"      - Daily at 9:00 AM' );
			$this->output->info( '  "*/15 * * * *"   - Every 15 minutes' );
			$this->output->info( '  "0 */6 * * *"    - Every 6 hours' );
			$this->output->info( '  "0 0 * * 0"      - Weekly on Sunday at midnight' );
			$this->output->info( '  "0 2 1 * *"      - Monthly on the 1st at 2:00 AM' );
			return false;
		}
	}

	/**
	 * Generate job class
	 */
	private function generateJob( string $jobName, string $namespace ): bool
	{
		// Determine file path
		$namespacePath = str_replace( '\\', '/', str_replace( 'App\\', '', $namespace ) );
		$jobDir = $this->_ProjectPath . '/app/' . $namespacePath;
		$jobFile = $jobDir . '/' . $jobName . '.php';

		// Check if exists
		if( file_exists( $jobFile ) && !$this->input->hasOption( 'force' ) )
		{
			$this->output->error( "Job already exists: {$jobFile}" );
			$this->output->info( 'Use --force to overwrite' );
			return false;
		}

		// Create directory
		if( !is_dir( $jobDir ) )
		{
			if( !mkdir( $jobDir, 0755, true ) )
			{
				$this->output->error( "Could not create directory: {$jobDir}" );
				return false;
			}
		}

		// Load stub
		$stub = $this->loadStub( 'job.stub' );
		if( !$stub )
		{
			$this->output->error( 'Could not load job stub' );
			return false;
		}

		// Generate snake_case job name
		$jobNameSnake = $this->toSnakeCase( $jobName );

		// Replace placeholders
		$content = str_replace(
			[ '{{namespace}}', '{{class}}', '{{jobName}}' ],
			[ $namespace, $jobName, $jobNameSnake ],
			$stub
		);

		// Write file
		if( file_put_contents( $jobFile, $content ) === false )
		{
			$this->output->error( 'Could not write job file' );
			return false;
		}

		$this->output->success( "Created job: {$jobFile}" );
		return true;
	}

	/**
	 * Add job to schedule.yaml
	 */
	private function addToSchedule( string $jobName, string $namespace, string $cronExpression ): bool
	{
		$configDir = $this->_ProjectPath . '/config';
		$scheduleFile = $configDir . '/schedule.yaml';

		// Ensure config directory exists
		if( !is_dir( $configDir ) )
		{
			if( !mkdir( $configDir, 0755, true ) )
			{
				$this->output->error( "Could not create config directory: {$configDir}" );
				return false;
			}
		}

		// Load existing schedule or create new
		$config = [ 'schedule' => [] ];

		if( file_exists( $scheduleFile ) )
		{
			try
			{
				$existingConfig = Yaml::parseFile( $scheduleFile );
				if( isset( $existingConfig['schedule'] ) )
				{
					$config = $existingConfig;
				}
			}
			catch( \Exception $e )
			{
				$this->output->error( 'Could not parse schedule.yaml: ' . $e->getMessage() );
				return false;
			}
		}

		// Generate schedule key (camelCase version of job name)
		$scheduleKey = lcfirst( $jobName );

		// Check if job already scheduled
		if( isset( $config['schedule'][$scheduleKey] ) )
		{
			if( !$this->input->hasOption( 'force' ) )
			{
				$this->output->warning( "Job already scheduled with key: {$scheduleKey}" );
				$this->output->info( 'Use --force to overwrite' );
				return false;
			}
			$this->output->info( "Updating existing schedule entry: {$scheduleKey}" );
		}

		// Add job to schedule
		$config['schedule'][$scheduleKey] = [
			'class' => $namespace . '\\' . $jobName,
			'cron' => $cronExpression,
			'args' => []
		];

		// Write updated config
		try
		{
			$yaml = Yaml::dump( $config, 4, 2 );
			if( file_put_contents( $scheduleFile, $yaml ) === false )
			{
				$this->output->error( 'Could not write schedule.yaml' );
				return false;
			}

			$this->output->success( "Updated: {$scheduleFile}" );
			return true;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Could not generate YAML: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Convert string to snake_case
	 */
	private function toSnakeCase( string $string ): string
	{
		// Insert underscores before uppercase letters and convert to lowercase
		$snake = preg_replace( '/(?<!^)[A-Z]/', '_$0', $string );
		return strtolower( $snake ?? '' );
	}

	/**
	 * Load a stub file
	 */
	private function loadStub( string $name ): ?string
	{
		$stubFile = $this->_StubPath . '/' . $name;

		if( !file_exists( $stubFile ) )
		{
			return null;
		}

		return file_get_contents( $stubFile );
	}
}
