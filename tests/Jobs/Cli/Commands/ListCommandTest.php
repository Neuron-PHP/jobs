<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Cli\Commands\ListCommand;
use Neuron\Jobs\Queue\JobState;
use Neuron\Jobs\Queue\QueuedJob;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class ListCommandTest extends TestCase
{
	private QueueManager $queueManager;

	protected function setUp(): void
	{
		parent::setUp();

		$this->queueManager = $this->createMock( QueueManager::class );
		$this->queueManager->method( 'getDefaultQueue' )->willReturn( 'default' );
		$this->queueManager->method( 'getDriverName' )->willReturn( 'database' );

		Registry::getInstance()->set( QueueManager::REGISTRY_KEY, $this->queueManager );
	}

	private function runCommand( array $options = [] ): array
	{
		$command = new ListCommand();
		$command->configure();

		$input = new Input( [] );

		foreach( $options as $name => $value )
		{
			$input->setOption( $name, $value );
		}

		$command->setInput( $input );
		$command->setOutput( new Output( false ) );

		ob_start();
		$exit = $command->execute();
		$out = ob_get_clean();

		return [ $exit, $out ];
	}

	public function testNameAndDescription(): void
	{
		$command = new ListCommand();

		$this->assertSame( 'jobs:list', $command->getName() );
		$this->assertNotEmpty( $command->getDescription() );
	}

	public function testFailsClearlyWhenDriverCannotInspect(): void
	{
		$this->queueManager->method( 'supportsInspection' )->willReturn( false );
		$this->queueManager->expects( $this->never() )->method( 'listJobs' );

		[ $exit, $out ] = $this->runCommand();

		$this->assertSame( 1, $exit );
		$this->assertStringContainsString( "cannot list jobs", $out );
	}

	public function testRejectsUnknownState(): void
	{
		$this->queueManager->method( 'supportsInspection' )->willReturn( true );

		[ $exit, $out ] = $this->runCommand( [ 'state' => 'running' ] );

		$this->assertSame( 1, $exit );
		$this->assertStringContainsString( "Unknown state 'running'", $out );
	}

	public function testListsPendingOnDefaultQueueByDefault(): void
	{
		$this->queueManager->method( 'supportsInspection' )->willReturn( true );

		$job = new QueuedJob( 'job_1', 'default', 'App\\Jobs\\SendEmail', [ 'to' => 'a@example.com' ], 0, null, time() - 30, time() - 30 );

		$this->queueManager->expects( $this->once() )
			->method( 'countJobs' )
			->with( 'default', JobState::Pending )
			->willReturn( 1 );

		$this->queueManager->expects( $this->once() )
			->method( 'listJobs' )
			->with( 'default', JobState::Pending, 50, 0 )
			->willReturn( [ $job ] );

		[ $exit, $out ] = $this->runCommand();

		$this->assertSame( 0, $exit );
		$this->assertStringContainsString( "Pending jobs on 'default': 1", $out );
		$this->assertStringContainsString( 'job_1', $out );
		$this->assertStringContainsString( 'App\\Jobs\\SendEmail', $out );
		$this->assertStringContainsString( '"to":"a@example.com"', $out );
		$this->assertStringContainsString( 'waiting', $out );
	}

	public function testHonoursQueueStateLimitAndOffsetAndShowsPagingHint(): void
	{
		$this->queueManager->method( 'supportsInspection' )->willReturn( true );

		$jobs = [
			new QueuedJob( 'job_a', 'emails', 'A', [], 0, null, time() + 300, time() ),
			new QueuedJob( 'job_b', 'emails', 'B', [], 0, null, time() + 600, time() ),
		];

		$this->queueManager->method( 'countJobs' )->with( 'emails', JobState::Delayed )->willReturn( 7 );

		$this->queueManager->expects( $this->once() )
			->method( 'listJobs' )
			->with( 'emails', JobState::Delayed, 2, 2 )
			->willReturn( $jobs );

		[ $exit, $out ] = $this->runCommand( [ 'queue' => 'emails', 'state' => 'Delayed', 'limit' => '2', 'offset' => '2' ] );

		$this->assertSame( 0, $exit );
		$this->assertStringContainsString( "Delayed jobs on 'emails': 7", $out );
		$this->assertStringContainsString( 'runs in', $out );
		$this->assertStringContainsString( 'Showing 3-4 of 7. Next: --offset=4', $out );
	}

	public function testReservedJobsShowHowLongTheyHaveBeenHeld(): void
	{
		$this->queueManager->method( 'supportsInspection' )->willReturn( true );

		$job = new QueuedJob( 'job_r', 'default', 'R', [], 1, time() - 120, time() - 200, time() - 200 );

		$this->queueManager->method( 'countJobs' )->willReturn( 1 );
		$this->queueManager->method( 'listJobs' )->willReturn( [ $job ] );

		[ $exit, $out ] = $this->runCommand( [ 'state' => 'reserved' ] );

		$this->assertSame( 0, $exit );
		$this->assertStringContainsString( 'reserved for 2m 00s', $out );
		$this->assertStringContainsString( 'Attempts: 1', $out );
	}
}
