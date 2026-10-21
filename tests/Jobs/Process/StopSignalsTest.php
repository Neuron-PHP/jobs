<?php

namespace Tests\Jobs\Process;

use Neuron\Jobs\Process\StopSignals;
use PHPUnit\Framework\TestCase;

class StopSignalsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		if( !function_exists( 'pcntl_signal' ) )
		{
			$this->markTestSkipped( 'pcntl not available' );
		}
	}

	protected function tearDown(): void
	{
		StopSignals::assumeHeadless( null );

		// Restore defaults so later tests are not affected by our handlers.
		foreach( [ SIGTERM, SIGINT, SIGWINCH ] as $signal )
		{
			pcntl_signal( $signal, SIG_DFL );
		}

		parent::tearDown();
	}

	public function testTerminalProcessStopsOnTermAndIntOnly(): void
	{
		StopSignals::assumeHeadless( false );

		$this->assertSame( [ SIGTERM, SIGINT ], StopSignals::signals() );
	}

	public function testHeadlessProcessAlsoStopsOnWinch(): void
	{
		StopSignals::assumeHeadless( true );

		$this->assertSame( [ SIGTERM, SIGINT, SIGWINCH ], StopSignals::signals() );
	}

	public function testDetectionFollowsStdin(): void
	{
		StopSignals::assumeHeadless( null );

		$this->assertSame( !stream_isatty( STDIN ), StopSignals::isHeadless() );
	}

	public function testRegisterRoutesEachSignalToTheHandler(): void
	{
		StopSignals::assumeHeadless( true );

		$received = [];

		$this->assertTrue( StopSignals::register( function( int $signal ) use ( &$received ) {
			$received[] = $signal;
		} ) );

		posix_kill( posix_getpid(), SIGWINCH );
		posix_kill( posix_getpid(), SIGTERM );

		// Async signals are delivered at the next opportunity; give PHP one.
		usleep( 10000 );
		pcntl_signal_dispatch();

		sort( $received );

		$this->assertSame( [ SIGTERM, SIGWINCH ], $received );
	}

	public function testWinchIsIgnoredWithATerminal(): void
	{
		StopSignals::assumeHeadless( false );

		$received = [];

		StopSignals::register( function( int $signal ) use ( &$received ) {
			$received[] = $signal;
		} );

		// SIGWINCH's default action is to be ignored, so this is harmless.
		pcntl_signal( SIGWINCH, SIG_IGN );
		posix_kill( posix_getpid(), SIGWINCH );
		posix_kill( posix_getpid(), SIGINT );

		usleep( 10000 );
		pcntl_signal_dispatch();

		$this->assertSame( [ SIGINT ], $received );
	}

	public function testNameDescribesTheSignalForLogLines(): void
	{
		$this->assertSame( 'SIGTERM', StopSignals::name( SIGTERM ) );
		$this->assertSame( 'SIGINT', StopSignals::name( SIGINT ) );
		$this->assertSame( 'SIGWINCH', StopSignals::name( SIGWINCH ) );
		$this->assertSame( 'signal 64', StopSignals::name( 64 ) );
		$this->assertSame( 'stop request', StopSignals::name( null ) );
	}
}
