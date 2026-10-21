<?php

namespace Tests\Jobs\Queue\Inspector;

use Neuron\Jobs\Queue\IQueue;
use Neuron\Jobs\Queue\RedisQueue;

/**
 * IQueueInspector contract against RedisQueue. Skips without ext-redis or
 * a reachable server (REDIS_HOST / REDIS_PORT, default 127.0.0.1:6379).
 */
class RedisQueueInspectorTest extends QueueInspectorContractTestCase
{
	protected function createQueue(): IQueue
	{
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

		return new RedisQueue( [
			'host' => $host,
			'port' => $port,
			'prefix' => 'neuron:inspector-test:' . uniqid(),
			'retry_after' => 90
		] );
	}

	protected function tearDown(): void
	{
		if( isset( $this->queue ) && $this->queue instanceof RedisQueue )
		{
			$this->queue->flushAll();
		}

		parent::tearDown();
	}
}
