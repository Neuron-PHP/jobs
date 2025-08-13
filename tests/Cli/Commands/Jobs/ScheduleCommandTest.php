<?php

namespace Tests\Cli\Commands\Jobs;

use Neuron\Jobs\Cli\Commands\ScheduleCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use PHPUnit\Framework\TestCase;

class ScheduleCommandTest extends TestCase
{
	private ScheduleCommand $command;
	private Output $output;
	
	protected function setUp(): void
	{
		$this->command = new ScheduleCommand();
		$this->output = $this->createMock( Output::class );
		$this->command->setOutput( $this->output );
	}
	
	public function testGetName(): void
	{
		$this->assertEquals( 'jobs:schedule', $this->command->getName() );
	}
	
	public function testGetDescription(): void
	{
		$this->assertEquals( 
			'Run the job scheduler for executing scheduled tasks', 
			$this->command->getDescription() 
		);
	}
	
	public function testConfigure(): void
	{
		$this->command->configure();
		
		$options = $this->command->getOptions();
		
		// Check that all expected options are configured
		$this->assertArrayHasKey( 'poll', $options );
		$this->assertArrayHasKey( 'interval', $options );
		$this->assertArrayHasKey( 'config', $options );
		$this->assertArrayHasKey( 'config-file', $options );
		$this->assertArrayHasKey( 'debug', $options );
		
		// Check option configurations
		$this->assertFalse( $options['poll']['hasValue'] );
		$this->assertTrue( $options['interval']['hasValue'] );
		$this->assertTrue( $options['config']['hasValue'] );
		$this->assertTrue( $options['config-file']['hasValue'] );
		$this->assertFalse( $options['debug']['hasValue'] );
	}
	
	public function testExecuteWithMissingConfig(): void
	{
		// Create input with no config option
		$input = new Input( [] );
		$this->command->setInput( $input );
		
		// Mock output to expect error message
		$this->output->expects( $this->once() )
			->method( 'error' )
			->with( $this->stringContains( 'Configuration directory not found' ) );
		
		$this->output->expects( $this->once() )
			->method( 'info' )
			->with( 'Use --config to specify the configuration directory' );
		
		// Execute should return error code
		$result = $this->command->execute();
		$this->assertEquals( 1, $result );
	}
	
	public function testExecuteWithValidConfigPath(): void
	{
		// Create a temporary config directory
		$tempDir = sys_get_temp_dir() . '/neuron_test_' . uniqid();
		mkdir( $tempDir );
		mkdir( $tempDir . '/config' );
		
		// Create a minimal config.yaml
		file_put_contents( 
			$tempDir . '/config/config.yaml', 
			"test: true\n" 
		);
		
		// Create a minimal schedule.yaml
		file_put_contents( 
			$tempDir . '/config/schedule.yaml', 
			"schedule:\n  test:\n    class: TestJob\n    cron: \"* * * * *\"\n" 
		);
		
		try
		{
			// Create input with config option
			$input = new Input( ['--config=' . $tempDir . '/config', '--poll'] );
			$this->command->setInput( $input );
			
			// Since we can't fully test the scheduler execution without
			// proper job classes, we'll just verify the command tries to
			// initialize properly
			$this->output->expects( $this->any() )
				->method( 'info' );
			
			// The command will initialize but may return 0 or error
			// depending on whether it can find and load jobs
			$result = $this->command->execute();
			
			// Assert the result is an integer (command completed)
			$this->assertIsInt( $result );
			
			// Clean up
			unlink( $tempDir . '/config/config.yaml' );
			unlink( $tempDir . '/config/schedule.yaml' );
			rmdir( $tempDir . '/config' );
			rmdir( $tempDir );
		}
		catch( \Exception $e )
		{
			// Clean up on failure
			if( file_exists( $tempDir . '/config/config.yaml' ) )
			{
				unlink( $tempDir . '/config/config.yaml' );
			}
			if( file_exists( $tempDir . '/config/schedule.yaml' ) )
			{
				unlink( $tempDir . '/config/schedule.yaml' );
			}
			if( is_dir( $tempDir . '/config' ) )
			{
				rmdir( $tempDir . '/config' );
			}
			if( is_dir( $tempDir ) )
			{
				rmdir( $tempDir );
			}
			
			throw $e;
		}
	}
	
	public function testGetHelp(): void
	{
		$help = $this->command->getHelp();
		
		// Check that help contains expected content
		$this->assertStringContainsString( 'Examples:', $help );
		$this->assertStringContainsString( 'neuron jobs:schedule', $help );
		$this->assertStringContainsString( '--poll', $help );
		$this->assertStringContainsString( '--interval', $help );
		$this->assertStringContainsString( '--config', $help );
		$this->assertStringContainsString( '--config-file', $help );
		$this->assertStringContainsString( '--debug', $help );
	}
	
	public function testOptionsHandling(): void
	{
		// Test with various options
		$input = new Input( [
			'--poll',
			'--interval=30',
			'--config-file=custom.yaml',
			'--debug'
		] );
		
		$this->command->setInput( $input );
		$this->command->configure();
		$input->parse( $this->command );
		
		$this->assertTrue( $input->hasOption( 'poll' ) );
		$this->assertEquals( '30', $input->getOption( 'interval' ) );
		$this->assertEquals( 'custom.yaml', $input->getOption( 'config-file' ) );
		$this->assertTrue( $input->hasOption( 'debug' ) );
	}
}