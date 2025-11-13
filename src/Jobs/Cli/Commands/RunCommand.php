<?php

namespace Neuron\Jobs\Cli\Commands;

use Neuron\Cli\Commands\Command;

/**
 * CLI command for running both the scheduler and queue worker together.
 *
 * This command simplifies job system management by running both the scheduler
 * and worker in separate processes, managing their lifecycle together.
 */
class RunCommand extends Command
{
	private array $childProcesses = [];
	private bool $shutdown = false;

	/**
	 * @inheritDoc
	 */
	public function getName(): string
	{
		return 'jobs:run';
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string
	{
		return 'Run both the scheduler and queue worker together';
	}

	/**
	 * @inheritDoc
	 */
	public function configure(): void
	{
		// Scheduler options
		$this->addOption( 'schedule-interval', null, true, 'Scheduler polling interval in seconds', '60' );
		$this->addOption( 'config', 'c', true, 'Path to configuration directory' );
		$this->addOption( 'config-file', 'f', true, 'Schedule configuration filename' );

		// Worker options
		$this->addOption( 'queue', 'Q', true, 'Queue(s) to process (comma-separated)', 'default' );
		$this->addOption( 'worker-sleep', null, true, 'Worker sleep duration when queue is empty', '3' );
		$this->addOption( 'worker-timeout', null, true, 'Worker job timeout in seconds', '60' );
		$this->addOption( 'max-jobs', 'm', true, 'Max jobs to process before restarting worker', '0' );

		// Combined options
		$this->addOption( 'no-scheduler', null, false, 'Run only the worker (disable scheduler)' );
		$this->addOption( 'no-worker', null, false, 'Run only the scheduler (disable worker)' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute(): int
	{
		// Register signal handlers for graceful shutdown
		$this->registerSignalHandlers();

		$runScheduler = !$this->input->hasOption( 'no-scheduler' );
		$runWorker = !$this->input->hasOption( 'no-worker' );

		if( !$runScheduler && !$runWorker )
		{
			$this->output->error( 'Cannot disable both scheduler and worker' );
			return 1;
		}

		$this->output->success( 'Starting Neuron Job System...' );
		$this->output->info( '' );

		// Display configuration
		if( $runScheduler )
		{
			$interval = $this->input->getOption( 'schedule-interval', '60' );
			$this->output->info( "Scheduler:" );
			$this->output->info( "  Polling interval: {$interval}s" );
			$this->output->info( '' );
		}

		if( $runWorker )
		{
			$queues = $this->input->getOption( 'queue', 'default' );
			$sleep = $this->input->getOption( 'worker-sleep', '3' );
			$timeout = $this->input->getOption( 'worker-timeout', '60' );

			$this->output->info( "Queue Worker:" );
			$this->output->info( "  Queue(s): {$queues}" );
			$this->output->info( "  Sleep: {$sleep}s" );
			$this->output->info( "  Timeout: {$timeout}s" );
			$this->output->info( '' );
		}

		$this->output->success( 'Press Ctrl+C to stop all processes' );
		$this->output->info( '' );

		// Start processes
		try
		{
			if( $runScheduler )
			{
				$this->startScheduler();
			}

			if( $runWorker )
			{
				$this->startWorker();
			}

			// Monitor processes
			$this->monitorProcesses();

			return 0;
		}
		catch( \Exception $e )
		{
			$this->output->error( 'Error: ' . $e->getMessage() );

			if( $this->input->hasOption( 'verbose' ) || $this->input->hasOption( 'v' ) )
			{
				$this->output->write( $e->getTraceAsString() );
			}

			$this->cleanup();
			return 1;
		}
	}

	/**
	 * Start the scheduler process.
	 *
	 * @return void
	 */
	private function startScheduler(): void
	{
		$cmd = $this->buildSchedulerCommand();

		$this->output->info( '[Scheduler] Starting...' );

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w']   // stderr
		];

		$process = proc_open( $cmd, $descriptors, $pipes );

		if( is_resource( $process ) )
		{
			// Set streams to non-blocking
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$this->childProcesses['scheduler'] = [
				'process' => $process,
				'pipes' => $pipes,
				'name' => 'Scheduler'
			];

			$this->output->success( '[Scheduler] Started' );
		}
		else
		{
			throw new \RuntimeException( 'Failed to start scheduler process' );
		}
	}

	/**
	 * Start the worker process.
	 *
	 * @return void
	 */
	private function startWorker(): void
	{
		$cmd = $this->buildWorkerCommand();

		$this->output->info( '[Worker] Starting...' );

		$descriptors = [
			0 => ['pipe', 'r'],  // stdin
			1 => ['pipe', 'w'],  // stdout
			2 => ['pipe', 'w']   // stderr
		];

		$process = proc_open( $cmd, $descriptors, $pipes );

		if( is_resource( $process ) )
		{
			// Set streams to non-blocking
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$this->childProcesses['worker'] = [
				'process' => $process,
				'pipes' => $pipes,
				'name' => 'Worker'
			];

			$this->output->success( '[Worker] Started' );
		}
		else
		{
			throw new \RuntimeException( 'Failed to start worker process' );
		}
	}

	/**
	 * Build the scheduler command.
	 *
	 * @return string
	 */
	private function buildSchedulerCommand(): string
	{
		$php = PHP_BINARY;
		$neuron = $_SERVER['argv'][0] ?? 'neuron';

		$cmd = "$php $neuron jobs:schedule";

		if( $this->input->hasOption( 'schedule-interval' ) )
		{
			$interval = $this->input->getOption( 'schedule-interval' );
			$cmd .= " --interval=$interval";
		}

		if( $this->input->hasOption( 'config' ) )
		{
			$config = $this->input->getOption( 'config' );
			$cmd .= " --config=" . escapeshellarg( $config );
		}

		if( $this->input->hasOption( 'config-file' ) )
		{
			$configFile = $this->input->getOption( 'config-file' );
			$cmd .= " --config-file=" . escapeshellarg( $configFile );
		}

		return $cmd;
	}

	/**
	 * Build the worker command.
	 *
	 * @return string
	 */
	private function buildWorkerCommand(): string
	{
		$php = PHP_BINARY;
		$neuron = $_SERVER['argv'][0] ?? 'neuron';

		$cmd = "$php $neuron jobs:work";

		if( $this->input->hasOption( 'queue' ) )
		{
			$queue = $this->input->getOption( 'queue' );
			$cmd .= " --queue=$queue";
		}

		if( $this->input->hasOption( 'worker-sleep' ) )
		{
			$sleep = $this->input->getOption( 'worker-sleep' );
			$cmd .= " --sleep=$sleep";
		}

		if( $this->input->hasOption( 'worker-timeout' ) )
		{
			$timeout = $this->input->getOption( 'worker-timeout' );
			$cmd .= " --timeout=$timeout";
		}

		if( $this->input->hasOption( 'max-jobs' ) )
		{
			$maxJobs = $this->input->getOption( 'max-jobs' );
			$cmd .= " --max-jobs=$maxJobs";
		}

		return $cmd;
	}

	/**
	 * Monitor child processes and handle their output.
	 *
	 * @return void
	 */
	private function monitorProcesses(): void
	{
		while( !$this->shutdown )
		{
			foreach( $this->childProcesses as $name => $proc )
			{
				// Check if process is still running
				$status = proc_get_status( $proc['process'] );

				if( !$status['running'] )
				{
					$this->output->warning( "[{$proc['name']}] Process exited with code {$status['exitcode']}" );

					// Close pipes
					fclose( $proc['pipes'][0] );
					fclose( $proc['pipes'][1] );
					fclose( $proc['pipes'][2] );

					// Close process
					proc_close( $proc['process'] );

					unset( $this->childProcesses[$name] );

					// If any critical process dies, shutdown
					$this->shutdown = true;
					break;
				}

				// Read output from stdout
				$stdout = stream_get_contents( $proc['pipes'][1] );
				if( $stdout )
				{
					$this->output->write( "[{$proc['name']}] " . rtrim( $stdout ) );
				}

				// Read output from stderr
				$stderr = stream_get_contents( $proc['pipes'][2] );
				if( $stderr )
				{
					$this->output->error( "[{$proc['name']}] " . rtrim( $stderr ) );
				}
			}

			// If all processes have stopped, exit
			if( empty( $this->childProcesses ) )
			{
				$this->output->info( '' );
				$this->output->warning( 'All processes have stopped' );
				break;
			}

			// Small sleep to prevent CPU spinning
			usleep( 100000 ); // 0.1 seconds
		}

		$this->cleanup();
	}

	/**
	 * Register signal handlers for graceful shutdown.
	 *
	 * @return void
	 */
	private function registerSignalHandlers(): void
	{
		if( !function_exists( 'pcntl_signal' ) )
		{
			$this->output->warning( 'PCNTL extension not available - graceful shutdown may not work' );
			return;
		}

		pcntl_signal( SIGTERM, [$this, 'handleSignal'] );
		pcntl_signal( SIGINT, [$this, 'handleSignal'] );
		pcntl_async_signals( true );
	}

	/**
	 * Handle shutdown signals.
	 *
	 * @param int $signal
	 * @return void
	 */
	public function handleSignal( int $signal ): void
	{
		$this->output->info( '' );
		$this->output->warning( 'Shutdown signal received...' );
		$this->shutdown = true;
	}

	/**
	 * Clean up child processes.
	 *
	 * @return void
	 */
	private function cleanup(): void
	{
		$this->output->info( 'Shutting down processes...' );

		foreach( $this->childProcesses as $name => $proc )
		{
			$this->output->info( "[{$proc['name']}] Stopping..." );

			// Get process status
			$status = proc_get_status( $proc['process'] );

			if( $status['running'] )
			{
				// Try to terminate gracefully
				proc_terminate( $proc['process'], SIGTERM );

				// Wait a bit for graceful shutdown
				$timeout = 5;
				$start = time();

				while( time() - $start < $timeout )
				{
					$status = proc_get_status( $proc['process'] );
					if( !$status['running'] )
					{
						break;
					}
					usleep( 100000 ); // 0.1 seconds
				}

				// Force kill if still running
				$status = proc_get_status( $proc['process'] );
				if( $status['running'] )
				{
					$this->output->warning( "[{$proc['name']}] Forcing shutdown..." );
					proc_terminate( $proc['process'], SIGKILL );
				}
			}

			// Close pipes
			if( is_resource( $proc['pipes'][0] ) ) fclose( $proc['pipes'][0] );
			if( is_resource( $proc['pipes'][1] ) ) fclose( $proc['pipes'][1] );
			if( is_resource( $proc['pipes'][2] ) ) fclose( $proc['pipes'][2] );

			// Close process
			proc_close( $proc['process'] );

			$this->output->success( "[{$proc['name']}] Stopped" );
		}

		$this->childProcesses = [];
		$this->output->success( 'All processes stopped' );
	}

	/**
	 * Get detailed help for the command.
	 *
	 * @return string
	 */
	public function getHelp(): string
	{
		$help = parent::getHelp();

		$help .= "\n\n";
		$help .= "This command runs both the scheduler and queue worker in a single process,\n";
		$help .= "making it easy to run the entire job system with one command.\n\n";
		$help .= "Examples:\n";
		$help .= "  # Run both scheduler and worker with defaults\n";
		$help .= "  neuron jobs:run\n\n";
		$help .= "  # Run with custom scheduler interval\n";
		$help .= "  neuron jobs:run --schedule-interval=30\n\n";
		$help .= "  # Run with specific queue\n";
		$help .= "  neuron jobs:run --queue=emails,default\n\n";
		$help .= "  # Run only the scheduler (disable worker)\n";
		$help .= "  neuron jobs:run --no-worker\n\n";
		$help .= "  # Run only the worker (disable scheduler)\n";
		$help .= "  neuron jobs:run --no-scheduler\n\n";
		$help .= "  # Customize worker behavior\n";
		$help .= "  neuron jobs:run --worker-sleep=5 --worker-timeout=120\n";

		return $help;
	}
}
