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
		$inputProperty->setValue( $this->_command, $this->_input );

		$outputProperty = $reflection->getProperty( 'output' );
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
		$options = $optionsProperty->getValue( $this->_command );

		// Check that key options exist
		$this->assertArrayHasKey( 'schedule-interval', $options );
		$this->assertArrayHasKey( 'queue', $options );
		$this->assertArrayHasKey( 'worker-sleep', $options );
		$this->assertArrayHasKey( 'worker-timeout', $options );
		$this->assertArrayHasKey( 'config', $options );
		$this->assertArrayHasKey( 'max-jobs', $options );
		$this->assertArrayHasKey( 'max-restarts', $options );
		$this->assertArrayHasKey( 'max-restart-delay', $options );
		$this->assertArrayHasKey( 'no-restart', $options );
	}

	/**
	 * Invoke the private scheduleRestart() and return [result, pendingRestarts, failures].
	 */
	private function scheduleRestart( string $name, int $exitCode ): array
	{
		$reflection = new \ReflectionClass( $this->_command );

		$result = $reflection->getMethod( 'scheduleRestart' )->invoke( $this->_command, $name, $exitCode );

		return [
			$result,
			$reflection->getProperty( 'pendingRestarts' )->getValue( $this->_command ),
			$reflection->getProperty( 'failures' )->getValue( $this->_command ),
		];
	}

	public function testCleanExitRestartsImmediately(): void
	{
		$this->_command->configure();

		ob_start();
		[ $result, $pending, $failures ] = $this->scheduleRestart( 'worker', 0 );
		ob_end_clean();

		$this->assertTrue( $result );
		$this->assertLessThanOrEqual( time(), $pending['worker'] );
		$this->assertSame( 0, $failures['worker'] );
	}

	public function testCrashesBackOffExponentiallyUpToTheCap(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'max-restart-delay', '5' );

		ob_start();
		[ , $p1 ] = $this->scheduleRestart( 'worker', 1 );
		[ , $p2 ] = $this->scheduleRestart( 'worker', 1 );
		[ , $p3 ] = $this->scheduleRestart( 'worker', 1 );
		[ , $p4 ] = $this->scheduleRestart( 'worker', 1 );
		ob_end_clean();

		$now = time();
		// 1, 2, 4, then capped at 5 (allow a second of clock drift).
		$this->assertEqualsWithDelta( 1, $p1['worker'] - $now, 1 );
		$this->assertEqualsWithDelta( 2, $p2['worker'] - $now, 1 );
		$this->assertEqualsWithDelta( 4, $p3['worker'] - $now, 1 );
		$this->assertEqualsWithDelta( 5, $p4['worker'] - $now, 1 );
	}

	public function testGivesUpAfterMaxRestarts(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'max-restarts', '2' );

		ob_start();
		[ $r1 ] = $this->scheduleRestart( 'scheduler', 1 );
		[ $r2 ] = $this->scheduleRestart( 'scheduler', 1 );
		[ $r3 ] = $this->scheduleRestart( 'scheduler', 1 );
		ob_end_clean();

		$this->assertTrue( $r1 );
		$this->assertTrue( $r2 );
		$this->assertFalse( $r3, 'Third consecutive crash exceeds --max-restarts=2' );
	}

	public function testNoRestartOptionShutsDown(): void
	{
		$this->_command->configure();
		$this->_input->setOption( 'no-restart', true );

		ob_start();
		[ $result, $pending ] = $this->scheduleRestart( 'worker', 0 );
		ob_end_clean();

		$this->assertFalse( $result );
		$this->assertArrayNotHasKey( 'worker', $pending );
	}

	public function testStableUptimeResetsFailureCount(): void
	{
		$this->_command->configure();

		$reflection = new \ReflectionClass( $this->_command );
		$reflection->getProperty( 'failures' )->setValue( $this->_command, [ 'worker' => 5 ] );
		// Started long ago: the child was stable before this crash.
		$reflection->getProperty( 'startedAt' )->setValue( $this->_command, [ 'worker' => time() - 3600 ] );

		ob_start();
		[ , $pending, $failures ] = $this->scheduleRestart( 'worker', 1 );
		ob_end_clean();

		$this->assertSame( 1, $failures['worker'] );
		$this->assertEqualsWithDelta( 1, $pending['worker'] - time(), 1 );
	}

	/**
	 * Test building scheduler command with defaults.
	 */
	public function testBuildSchedulerCommandDefaults(): void
	{
		$this->_command->configure();

		$reflection = new \ReflectionClass( $this->_command );
		$method = $reflection->getMethod( 'buildSchedulerCommand' );

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

		$cmd = $method->invoke( $this->_command );

		$this->assertIsString( $cmd );
		$this->assertStringContainsString( 'jobs:work', $cmd );
		$this->assertStringContainsString( PHP_BINARY, $cmd );
		$this->assertStringContainsString( 'default,emails', $cmd );
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

		$cmd = $method->invoke( $this->_command );

		$this->assertStringContainsString( 'emails,notifications', $cmd );
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
		$this->assertStringContainsString( 'jobs:schedule', $help );
		$this->assertStringContainsString( 'jobs:work', $help );
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
