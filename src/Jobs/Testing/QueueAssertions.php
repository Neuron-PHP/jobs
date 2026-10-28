<?php

namespace Neuron\Jobs\Testing;

use Neuron\Jobs\Queue\ArrayQueue;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\QueuedJob;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\Attributes\After;

/**
 * PHPUnit helpers for code that dispatches jobs.
 *
 * Call fakeQueue() in setUp() (or at the top of a test). It installs a
 * QueueManager backed by ArrayQueue in the Registry, so anything that goes
 * through dispatch(), getQueueManager() or QueueManager::fromRegistry() lands
 * in memory instead of a database or the filesystem, and nothing executes.
 * Then assert on what was dispatched:
 *
 *     use QueueAssertions;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->fakeQueue();
 *     }
 *
 *     public function testRegistrationSendsWelcomeEmail(): void
 *     {
 *         $this->service->register( $input );
 *
 *         $this->assertDispatched( SendEmailJob::class, fn( array $args ) => $args['to'] === 'a@b.c' );
 *         $this->assertDispatchedOn( 'emails', SendEmailJob::class );
 *     }
 *
 * The previous Registry entry is put back after each test by an After hook
 * (restoreQueue()), so other tests are not affected.
 *
 * The assertions delegate to the TestCase's own assert methods, so this trait
 * must be used inside a PHPUnit\Framework\TestCase.
 */
trait QueueAssertions
{
	private ?ArrayQueue $_fakeQueue = null;
	private mixed $_previousQueueManager = null;
	private bool $_queueFaked = false;

	/**
	 * Replace the shared QueueManager with one backed by ArrayQueue.
	 *
	 * @param array $config Extra QueueManager config (max_attempts, backoff, default_queue, ...)
	 * @return ArrayQueue
	 */
	protected function fakeQueue( array $config = [] ): ArrayQueue
	{
		$registry = Registry::getInstance();

		if( !$this->_queueFaked )
		{
			$this->_previousQueueManager = $registry->get( QueueManager::REGISTRY_KEY );
			$this->_queueFaked = true;
		}

		$manager = new QueueManager( null, $config + [ 'driver' => 'array', 'default_queue' => 'default', 'max_attempts' => 3, 'backoff' => 0 ] );

		/** @var ArrayQueue $driver */
		$driver = $manager->getDriver();

		$this->_fakeQueue = $driver;

		$registry->set( QueueManager::REGISTRY_KEY, $manager );

		return $driver;
	}

	/**
	 * Put back whatever QueueManager was in the Registry before fakeQueue().
	 *
	 * @return void
	 */
	protected function restoreQueue(): void
	{
		if( $this->_queueFaked )
		{
			Registry::getInstance()->set( QueueManager::REGISTRY_KEY, $this->_previousQueueManager );
		}

		$this->_queueFaked = false;
		$this->_fakeQueue = null;
		$this->_previousQueueManager = null;
	}

	/**
	 * PHPUnit calls this after every test; it undoes fakeQueue().
	 *
	 * @return void
	 */
	#[After]
	protected function tearDownQueueAssertions(): void
	{
		$this->restoreQueue();
	}

	/**
	 * The fake driver, for direct inspection or processing.
	 *
	 * @return ArrayQueue
	 */
	protected function getFakeQueue(): ArrayQueue
	{
		if( $this->_fakeQueue === null )
		{
			throw new \LogicException( 'Call fakeQueue() before asserting on the queue.' );
		}

		return $this->_fakeQueue;
	}

	/**
	 * Assert the job class was dispatched at least once.
	 *
	 * @param string $jobClass
	 * @param callable|null $filter fn( array $args, QueuedJob $job ): bool narrows the match
	 * @return void
	 */
	protected function assertDispatched( string $jobClass, ?callable $filter = null ): void
	{
		$matches = $this->dispatchedMatching( $jobClass, $filter );

		$this->assertNotEmpty(
			$matches,
			$filter === null
				? "Expected {$jobClass} to be dispatched, but it was not. " . $this->describeDispatched()
				: "Expected {$jobClass} to be dispatched with arguments matching the filter, but none did. " . $this->describeDispatched( $jobClass )
		);
	}

	/**
	 * Assert the job class was dispatched exactly $times times.
	 *
	 * @param string $jobClass
	 * @param int $times
	 * @param callable|null $filter
	 * @return void
	 */
	protected function assertDispatchedTimes( string $jobClass, int $times, ?callable $filter = null ): void
	{
		$count = count( $this->dispatchedMatching( $jobClass, $filter ) );

		$this->assertSame( $times, $count, "Expected {$jobClass} to be dispatched {$times} time(s), got {$count}." );
	}

	/**
	 * Assert the job class was never dispatched (optionally: never with
	 * arguments matching the filter).
	 *
	 * @param string $jobClass
	 * @param callable|null $filter
	 * @return void
	 */
	protected function assertNotDispatched( string $jobClass, ?callable $filter = null ): void
	{
		$matches = $this->dispatchedMatching( $jobClass, $filter );

		$this->assertEmpty( $matches, "Expected {$jobClass} not to be dispatched, but it was " . count( $matches ) . " time(s)." );
	}

	/**
	 * Assert the job class was dispatched onto the named queue.
	 *
	 * @param string $queue
	 * @param string $jobClass
	 * @param callable|null $filter
	 * @return void
	 */
	protected function assertDispatchedOn( string $queue, string $jobClass, ?callable $filter = null ): void
	{
		$matches = array_filter(
			$this->dispatchedMatching( $jobClass, $filter ),
			fn( QueuedJob $job ) => $job->getQueue() === $queue
		);

		$this->assertNotEmpty(
			$matches,
			"Expected {$jobClass} to be dispatched on queue '{$queue}'. " . $this->describeDispatched( $jobClass )
		);
	}

	/**
	 * Assert nothing at all was dispatched.
	 *
	 * @return void
	 */
	protected function assertNothingDispatched(): void
	{
		$dispatched = $this->getFakeQueue()->getDispatched();

		$this->assertEmpty( $dispatched, 'Expected no jobs to be dispatched. ' . $this->describeDispatched() );
	}

	/**
	 * @param string $jobClass
	 * @param callable|null $filter
	 * @return QueuedJob[]
	 */
	private function dispatchedMatching( string $jobClass, ?callable $filter ): array
	{
		$jobs = $this->getFakeQueue()->getDispatched( $jobClass );

		if( $filter === null )
		{
			return $jobs;
		}

		return array_values( array_filter(
			$jobs,
			fn( QueuedJob $job ) => (bool)$filter( $job->getArguments(), $job )
		) );
	}

	/**
	 * @param string|null $jobClass
	 * @return string
	 */
	private function describeDispatched( ?string $jobClass = null ): string
	{
		$jobs = $this->getFakeQueue()->getDispatched( $jobClass );

		if( empty( $jobs ) )
		{
			return $jobClass === null ? 'Nothing was dispatched.' : "No {$jobClass} was dispatched.";
		}

		$lines = array_map(
			fn( QueuedJob $job ) => sprintf( '%s on %s %s', $job->getJobClass(), $job->getQueue(), json_encode( $job->getArguments() ) ),
			$jobs
		);

		return "Dispatched:\n  " . implode( "\n  ", $lines );
	}
}
