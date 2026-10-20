<?php

namespace Tests\Jobs\Testing;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\ArrayQueue;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Testing\QueueAssertions;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class WelcomeEmailJob implements IJob
{
	public function getName(): string
	{
		return 'WelcomeEmailJob';
	}

	public function run( array $argv = [] ): mixed
	{
		throw new \LogicException( 'must never execute under the fake queue' );
	}
}

class ReportJob implements IJob
{
	public function getName(): string
	{
		return 'ReportJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

class QueueAssertionsTest extends TestCase
{
	use QueueAssertions;

	private mixed $sentinel;

	protected function setUp(): void
	{
		parent::setUp();

		// Something a previous test might have left behind; must be restored.
		$this->sentinel = new QueueManager();
		Registry::getInstance()->set( QueueManager::REGISTRY_KEY, $this->sentinel );

		$this->fakeQueue();
	}

	public function testFakeQueueInstallsArrayDriverInRegistry(): void
	{
		$manager = QueueManager::fromRegistry();

		$this->assertInstanceOf( ArrayQueue::class, $manager->getDriver() );
		$this->assertSame( $manager->getDriver(), $this->getFakeQueue() );
	}

	public function testDispatchHelperGoesToFakeQueueAndDoesNotExecute(): void
	{
		dispatch( new WelcomeEmailJob(), [ 'to' => 'a@b.c' ], 'emails' );

		$this->assertDispatched( WelcomeEmailJob::class );
		$this->assertDispatched( WelcomeEmailJob::class, fn( array $args ) => $args['to'] === 'a@b.c' );
		$this->assertDispatchedOn( 'emails', WelcomeEmailJob::class );
		$this->assertDispatchedTimes( WelcomeEmailJob::class, 1 );
		$this->assertNotDispatched( ReportJob::class );
		$this->assertNotDispatched( WelcomeEmailJob::class, fn( array $args ) => $args['to'] === 'x@y.z' );
	}

	public function testAssertDispatchedFailsWhenNothingMatches(): void
	{
		dispatch( new ReportJob() );

		try
		{
			$this->assertDispatched( WelcomeEmailJob::class );
			$this->fail( 'assertDispatched should have failed' );
		}
		catch( AssertionFailedError $e )
		{
			$this->assertStringContainsString( WelcomeEmailJob::class, $e->getMessage() );
			$this->assertStringContainsString( ReportJob::class, $e->getMessage(), 'Failure message lists what was dispatched' );
		}
	}

	public function testAssertNotDispatchedFailsWhenJobWasDispatched(): void
	{
		dispatch( new ReportJob() );

		$this->expectException( AssertionFailedError::class );
		$this->assertNotDispatched( ReportJob::class );
	}

	public function testAssertDispatchedOnFailsForWrongQueue(): void
	{
		dispatch( new ReportJob(), [], 'reports' );

		$this->expectException( AssertionFailedError::class );
		$this->assertDispatchedOn( 'emails', ReportJob::class );
	}

	public function testAssertNothingDispatched(): void
	{
		$this->assertNothingDispatched();

		dispatch( new ReportJob() );

		$this->expectException( AssertionFailedError::class );
		$this->assertNothingDispatched();
	}

	public function testFakeQueueAcceptsConfigOverrides(): void
	{
		$this->fakeQueue( [ 'default_queue' => 'custom' ] );

		dispatch( new ReportJob() );

		$this->assertDispatchedOn( 'custom', ReportJob::class );
	}

	public function testRestoreQueuePutsPreviousManagerBack(): void
	{
		$this->restoreQueue();

		$this->assertSame( $this->sentinel, Registry::getInstance()->get( QueueManager::REGISTRY_KEY ) );
	}

	public function testGetFakeQueueBeforeFakeQueueThrows(): void
	{
		$this->restoreQueue();

		$this->expectException( \LogicException::class );
		$this->getFakeQueue();
	}

	public function testFakeQueueCanStillBeProcessedForEndToEndTests(): void
	{
		dispatch( new ReportJob(), [ 'period' => 'monthly' ] );

		$this->assertTrue( QueueManager::fromRegistry()->processNextJob() );
		// Processing does not erase the dispatch record.
		$this->assertDispatched( ReportJob::class );
		$this->assertEquals( 0, $this->getFakeQueue()->size() );
	}
}
