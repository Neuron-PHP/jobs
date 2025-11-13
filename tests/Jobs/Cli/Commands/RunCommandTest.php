<?php

namespace Neuron\Jobs\Tests\Cli\Commands;

use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Cli\Commands\RunCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RunCommand.
 *
 * @package Neuron\Jobs\Tests\Cli\Commands
 */
class RunCommandTest extends TestCase
{
	private RunCommand $_command;
	private Input $_input;
	private Output $_output;

	protected function setUp(): void
	{
		$this->_command = new RunCommand();
		$this->_input = new Input();
		$this->_output = new Output();

		// Use reflection to set input/output (they're protected)
		$reflection = new \ReflectionClass( $this->_command );

		$inputProperty = $reflection->getProperty( 'input' );
		$inputProperty->setAccessible( true );
		$inputProperty->setValue( $this->_command, $this->_input );

		$outputProperty = $reflection->getProperty( 'output' );
		$outputProperty->setAccessible( true );
		$outputProperty->setValue( $this->_command, $this->_output );
	}

	/**
	 * Test command name.
	 */
	public function testGetName(): void
	{
		$this->assertEquals( 'jobs:run', $this->_command->getName() );
	}

	/**
	 * Test command description.
	 */
	public function testGetDescription(): void
	{
		$description = $this->_command->getDescription();
		$this->assertIsString( $description );
		$this->assertNotEmpty( $description );
		$this->assertStringContainsString( 'scheduler', strtolower( $description ) );
		$this->assertStringContainsString( 'worker', strtolower( $description ) );
	}

	/**
	 * Test command configuration.
	 */
	public function testConfigure(): void
	{
		$this->_command->configure();

		$reflection = new \ReflectionClass( $this->_command );
		$optionsProperty = $reflection->getProperty( 'options' );
		$optionsProperty->setAccessible( true );
		$options = $optionsProperty->getValue( $this->_command );

		// Check that key options exist
		$this->assertArrayHasKey( 'schedule-interval', $options );
		$this->assertArrayHasKey( 'queue', $options );
		$this->assertArrayHasKey( 'worker-sleep', $options );
		$this->assertArrayHasKey( 'worker-timeout', $options );
		$this->assertArrayHasKey( 'no-scheduler', $options );
		$this->assertArrayHasKey( 'no-worker', $options );
		$this->assertArrayHasKey( 'config', $options );
		$this->assertArrayHasKey( 'max-jobs', $options );
	}

	/**
	 * Test building scheduler command with defaults.
	 */
	public function testBuildSchedulerCommandDefaults(): void
	{
		$this->_command->configure();

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildSchedulerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertIsString( $cmd );
		$this->assertStringContainsString( 'jobs:schedule', $cmd );
		$this->assertStringContainsString( PHP_BINARY, $cmd );
	}

	/**
	 * Test building scheduler command with custom interval.
	 */
	public function testBuildSchedulerCommandWithInterval(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'schedule-interval', '30' );

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildSchedulerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( '--interval=30', $cmd );
	}

	/**
	 * Test building scheduler command with config path.
	 */
	public function testBuildSchedulerCommandWithConfig(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'config', '/path/to/config' );

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildSchedulerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( '--config=', $cmd );
		$this->assertStringContainsString( '/path/to/config', $cmd );
	}

	/**
	 * Test building worker command with defaults.
	 */
	public function testBuildWorkerCommandDefaults(): void
	{
		$this->_command->configure();

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildWorkerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertIsString( $cmd );
		$this->assertStringContainsString( 'jobs:work', $cmd );
		$this->assertStringContainsString( PHP_BINARY, $cmd );
	}

	/**
	 * Test building worker command with custom queue.
	 */
	public function testBuildWorkerCommandWithQueue(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'queue', 'emails,notifications' );

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildWorkerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( '--queue=emails,notifications', $cmd );
	}

	/**
	 * Test building worker command with custom sleep.
	 */
	public function testBuildWorkerCommandWithSleep(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'worker-sleep', '5' );

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildWorkerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( '--sleep=5', $cmd );
	}

	/**
	 * Test building worker command with custom timeout.
	 */
	public function testBuildWorkerCommandWithTimeout(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'worker-timeout', '120' );

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildWorkerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( '--timeout=120', $cmd );
	}

	/**
	 * Test building worker command with max jobs.
	 */
	public function testBuildWorkerCommandWithMaxJobs(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'max-jobs', '100' );

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildWorkerCommand' );
		$method->setAccessible( true );

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( '--max-jobs=100', $cmd );
	}

	/**
	 * Test help output.
	 */
	public function testGetHelp(): void
	{
		$this->_command->configure();
		$help = $this->_command->getHelp();

		$this->assertIsString( $help );
		$this->assertNotEmpty( $help );
		$this->assertStringContainsString( 'jobs:run', $help );
		$this->assertStringContainsString( '--schedule-interval', $help );
		$this->assertStringContainsString( '--queue', $help );
		$this->assertStringContainsString( '--no-scheduler', $help );
		$this->assertStringContainsString( '--no-worker', $help );
	}

	/**
	 * Test that command includes examples in help.
	 */
	public function testGetHelpIncludesExamples(): void
	{
		$this->_command->configure();
		$help = $this->_command->getHelp();

		$this->assertStringContainsString( 'Examples:', $help );
		$this->assertStringContainsString( 'neuron jobs:run', $help );
	}
}
