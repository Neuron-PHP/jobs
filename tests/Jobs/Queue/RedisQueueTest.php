<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\IQueueStats;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\RedisQueue;
use PHPUnit\Framework\TestCase;

class RedisQueueTestJob implements IJob
{
	public function getName(): string
	{
		return 'RedisQueueTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

/**
 * Runs against a real Redis when one is reachable (REDIS_HOST / REDIS_PORT,
 * default 127.0.0.1:6379) and ext-redis is loaded; skipped otherwise.
 */
class RedisQueueTest extends TestCase
{
	private RedisQueue $queue;
	private string $prefix;

	protected function setUp(): void
	{
		parent::setUp();

		if( !RedisQueue::isAvailable() )
		{
			$this->markTestSkipped( 'ext-redis not loaded' );
		}

		$host = getenv( 'REDIS_HOST' ) ?: '127.0.0.1';
		$port = (int)( getenv( 'REDIS_PORT' ) ?: 6379 );

		$probe = new \Redis();

		try
		{
			if( !@$probe->connect( $host, $port, 0.5 ) )
			{
				$this->markTestSkipped( "No redis at {$host}:{$port}" );
			}
		}
		catch( \RedisException $e )
		{
			$this->markTestSkipped( "No redis at {$host}:{$port}: {$e->getMessage()}" );
		}

		$this->prefix = 'neuron:test:' . uniqid();
		$this->queue = new RedisQueue( [ 'host' => $host, 'port' => $port, 'prefix' => $this->prefix, 'retry_after' => 90 ] );
	}

	protected function tearDown(): void
	{
		if( isset( $this->queue ) )
		{
			$this->queue->flushAll();
		}

		parent::tearDown();
	}

	public function testPushAndPopRoundTrip(): void
	{
		$id = $this->queue->push( new RedisQueueTestJob(), [ 'a' => 1 ], 'emails' );

		$this->assertNotEmpty( $id );
		$this->assertEquals( 1, $this->queue->size( 'emails' ) );
		$this->assertEquals( 0, $this->queue->size( 'default' ) );

		$job = $this->queue->pop( 'emails' );

		$this->assertNotNull( $job );
		$this->assertEquals( $id, $job->getId() );
		$this->assertEquals( RedisQueueTestJob::class, $job->getJobClass() );
		$this->assertEquals( [ 'a' => 1 ], $job->getArguments() );
		$this->assertEquals( 'emails', $job->getQueue() );
		$this->assertEquals( 1, $job->getAttempts() );
		$this->assertTrue( $job->isReserved() );

		$this->assertEquals( 0, $this->queue->size( 'emails' ), 'reserved jobs are not pending' );
		$this->assertNull( $this->queue->pop( 'emails' ), 'a reserved job is not handed out twice' );
	}

	public function testPopIsFifo(): void
	{
		$first = $this->queue->push( new RedisQueueTestJob(), [ 'n' => 1 ] );
		$second = $this->queue->push( new RedisQueueTestJob(), [ 'n' => 2 ] );

		$this->assertEquals( $first, $this->queue->pop()->getId() );
		$this->assertEquals( $second, $this->queue->pop()->getId() );
		$this->assertNull( $this->queue->pop() );
	}

	public function testDelayedJobBecomesAvailableLater(): void
	{
		$id = $this->queue->push( new RedisQueueTestJob(), [], 'default', 60 );

		$this->assertNull( $this->queue->pop() );
		$this->assertEquals( 0, $this->queue->size() );
		$this->assertEquals( 1, $this->queue->getStats()['delayed'] );

		// Make it due by rewinding its score.
		$this->queue->getRedis()->zAdd( $this->prefix . ':default:delayed', time() - 1, $id );

		$this->assertEquals( 1, $this->queue->size() );
		$this->assertEquals( $id, $this->queue->pop()->getId() );
	}

	public function testStaleReservationIsReclaimed(): void
	{
		$queue = new RedisQueue( [ 'prefix' => $this->prefix, 'retry_after' => 0 ], $this->queue->getRedis() );

		$id = $queue->push( new RedisQueueTestJob() );
		$first = $queue->pop();
		$this->assertNotNull( $first );

		// retry_after 0: the reservation expires at pop time; rewind one second
		// so the Lua script's "<= now" reclaim sees it.
		$queue->getRedis()->zAdd( $this->prefix . ':default:reserved', time() - 1, $id );

		$second = $queue->pop();

		$this->assertNotNull( $second );
		$this->assertEquals( $id, $second->getId() );
		$this->assertEquals( 2, $second->getAttempts() );
	}

	public function testReleasePutsJobBackImmediatelyOrDelayed(): void
	{
		$id = $this->queue->push( new RedisQueueTestJob() );
		$job = $this->queue->pop();

		$this->queue->release( $job );
		$this->assertEquals( 1, $this->queue->size() );

		$again = $this->queue->pop();
		$this->assertEquals( $id, $again->getId() );
		$this->assertEquals( 2, $again->getAttempts() );

		$this->queue->release( $again, 300 );
		$this->assertEquals( 0, $this->queue->size() );
		$this->assertEquals( 1, $this->queue->getStats()['delayed'] );
		$this->assertNull( $this->queue->pop() );
	}

	public function testDeleteRemovesEverything(): void
	{
		$id = $this->queue->push( new RedisQueueTestJob() );
		$job = $this->queue->pop();

		$this->queue->delete( $job );

		$this->assertEquals( 0, $this->queue->getRedis()->exists( $this->prefix . ':job:' . $id ) );
		$this->assertEquals( [ 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age' => null ], $this->queue->getStats() );
	}

	public function testFailedRetryForgetAndClear(): void
	{
		$id = $this->queue->push( new RedisQueueTestJob(), [ 'x' => 1 ], 'work' );
		$job = $this->queue->pop( 'work' );

		$this->queue->failed( $job, new \RuntimeException( 'boom' ) );

		$this->assertEquals( 0, $this->queue->size( 'work' ) );

		$failed = $this->queue->getFailedJobs();
		$this->assertCount( 1, $failed );
		$this->assertEquals( $id, $failed[0]['id'] );
		$this->assertEquals( 'work', $failed[0]['queue'] );
		$this->assertStringContainsString( 'boom', $failed[0]['exception'] );
		$this->assertIsInt( $failed[0]['failed_at'] );

		$this->assertTrue( $this->queue->retryFailedJob( $id ) );
		$this->assertFalse( $this->queue->retryFailedJob( $id ) );
		$this->assertEmpty( $this->queue->getFailedJobs() );

		$retried = $this->queue->pop( 'work' );
		$this->assertEquals( $id, $retried->getId() );
		$this->assertEquals( 1, $retried->getAttempts(), 'retry resets attempts' );
		$this->assertEquals( [ 'x' => 1 ], $retried->getArguments() );

		$this->queue->failed( $retried, new \RuntimeException( 'again' ) );
		$this->assertTrue( $this->queue->forgetFailedJob( $id ) );
		$this->assertFalse( $this->queue->forgetFailedJob( $id ) );

		$this->queue->push( new RedisQueueTestJob(), [], 'work' );
		$this->queue->failed( $this->queue->pop( 'work' ), new \RuntimeException( 'z' ) );
		$this->assertEquals( 1, $this->queue->clearFailedJobs() );
		$this->assertEquals( 0, $this->queue->clearFailedJobs() );
	}

	public function testClearEmptiesOneQueueIncludingDelayedAndReserved(): void
	{
		$this->queue->push( new RedisQueueTestJob(), [], 'a' );
		$this->queue->push( new RedisQueueTestJob(), [], 'a', 600 );
		$this->queue->push( new RedisQueueTestJob(), [], 'a' );
		$this->queue->push( new RedisQueueTestJob(), [], 'b' );
		$this->queue->pop( 'a' ); // reserve one

		$this->assertEquals( 3, $this->queue->clear( 'a' ) );
		$this->assertEquals( [ 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age' => null ], $this->queue->getStats( 'a' ) );
		$this->assertEquals( 1, $this->queue->size( 'b' ) );
	}

	public function testGetStats(): void
	{
		$this->assertInstanceOf( IQueueStats::class, $this->queue );

		$this->queue->push( new RedisQueueTestJob() );
		$this->queue->push( new RedisQueueTestJob() );
		$this->queue->push( new RedisQueueTestJob(), [], 'default', 600 );
		$this->queue->pop();

		$stats = $this->queue->getStats();

		$this->assertEquals( 1, $stats['pending'] );
		$this->assertEquals( 1, $stats['delayed'] );
		$this->assertEquals( 1, $stats['reserved'] );
		$this->assertIsInt( $stats['oldest_pending_age'] );
	}

	public function testConcurrentPopsNeverHandOutTheSameJob(): void
	{
		$ids = [];
		for( $i = 0; $i < 20; $i++ )
		{
			$ids[] = $this->queue->push( new RedisQueueTestJob(), [ 'i' => $i ] );
		}

		// Two "workers" on separate connections, interleaved.
		$other = new RedisQueue( [ 'prefix' => $this->prefix ] );
		$seen = [];

		for( $i = 0; $i < 10; $i++ )
		{
			$seen[] = $this->queue->pop()->getId();
			$seen[] = $other->pop()->getId();
		}

		sort( $ids );
		sort( $seen );

		$this->assertSame( $ids, $seen );
		$this->assertNull( $this->queue->pop() );
	}

	public function testQueueManagerSelectsRedisDriverFromConfigAndSettings(): void
	{
		$manager = new QueueManager( null, [
			'driver' => 'redis',
			'retry_after' => 45,
			'redis' => [ 'prefix' => $this->prefix . ':mgr' ]
		] );

		/** @var RedisQueue $driver */
		$driver = $manager->getDriver();

		$this->assertInstanceOf( RedisQueue::class, $driver );
		$this->assertEquals( 45, $driver->getRetryAfter() );
		$this->assertEquals( $this->prefix . ':mgr', $driver->getPrefix() );

		$settings = new \Neuron\Data\Settings\Source\Memory( [
			'queue' => [ 'driver' => 'redis', 'redis' => [ 'prefix' => $this->prefix . ':set' ] ]
		] );

		$fromSettings = new QueueManager( $settings );
		$this->assertInstanceOf( RedisQueue::class, $fromSettings->getDriver() );
		$this->assertEquals( $this->prefix . ':set', $fromSettings->getDriver()->getPrefix() );

		$driver->flushAll();
		$fromSettings->getDriver()->flushAll();
	}

	public function testUrlConfiguration(): void
	{
		$host = getenv( 'REDIS_HOST' ) ?: '127.0.0.1';
		$port = (int)( getenv( 'REDIS_PORT' ) ?: 6379 );

		$queue = new RedisQueue( [ 'url' => "redis://{$host}:{$port}/0", 'prefix' => $this->prefix . ':url' ] );

		$this->assertTrue( $queue->getRedis()->isConnected() );
		$this->assertEquals( $this->prefix . ':url', $queue->getPrefix() );
		$queue->flushAll();
	}

	public function testInvalidUrlThrows(): void
	{
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid redis url' );

		new RedisQueue( [ 'url' => 'not a url' ] );
	}

	public function testUnreachableHostThrowsRuntimeException(): void
	{
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Redis connection failed' );

		new RedisQueue( [ 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2 ] );
	}

	public function testEndToEndThroughQueueManager(): void
	{
		$manager = new QueueManager( null, [
			'driver' => 'redis',
			'max_attempts' => 1,
			'backoff' => 0,
			'redis' => [ 'prefix' => $this->prefix . ':e2e' ]
		] );

		$manager->dispatch( new RedisQueueTestJob(), [ 'k' => 'v' ] );

		$this->assertTrue( $manager->processNextJob() );
		$this->assertFalse( $manager->processNextJob() );
		$this->assertEquals( 0, $manager->size() );

		/** @var RedisQueue $driver */
		$driver = $manager->getDriver();
		$driver->flushAll();
	}
}
