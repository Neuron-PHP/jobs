<?php

namespace Neuron\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Log\Log;

/**
 * Redis-backed queue.
 *
 * Needs the phpredis extension (`ext-redis`); it is checked at runtime rather
 * than declared in composer.json so sites that never use Redis carry no
 * requirement. Select with `driver: redis` and configure under `queue.redis`:
 *
 *     queue:
 *       driver: redis
 *       redis:
 *         host: 127.0.0.1      # or url: redis://:pass@host:6379/2
 *         port: 6379
 *         password: ~
 *         database: 0
 *         prefix: neuron:queue
 *         timeout: 2.0
 *
 * Layout per queue (all keys under the prefix):
 *   {q}            list   ids ready to run, FIFO
 *   {q}:delayed    zset   id => available_at, for delayed pushes and backoff
 *   {q}:reserved   zset   id => expires_at (reserved_at + retry_after)
 *   {q}:queues     set    names of queues that have seen a push
 *   job:{id}       hash   queue, payload, attempts, reserved_at, available_at, created_at
 *   failed         zset   id => failed_at
 *   failed:{id}    hash   id, queue, payload, exception, failed_at
 *
 * pop() is a single Lua script: move due delayed/expired-reserved ids back to
 * the ready list, then LPOP and reserve atomically, so many workers can share
 * a queue without racing. Everything a job needs lives in its hash, not in
 * the list entries, so an id can move between structures cheaply.
 *
 * Durability is Redis's: with default RDB snapshots a crash can lose the last
 * seconds of pushes. Enable AOF (`appendonly yes`) on the queue's Redis when
 * that matters.
 *
 * @package Neuron\Jobs\Queue
 */
class RedisQueue implements IQueue, IQueueStats
{
	private \Redis $_redis;
	private string $_prefix;
	private int $_retryAfter;

	/**
	 * Lua: reclaim due delayed and expired reserved ids, then pop and reserve.
	 *
	 * KEYS[1] ready list, KEYS[2] delayed zset, KEYS[3] reserved zset,
	 * KEYS[4] job hash prefix ("prefix:job:"), ARGV[1] now, ARGV[2] expires_at.
	 * Returns the reserved job id or false.
	 */
	private const POP_SCRIPT = <<<'LUA'
local ready, delayed, reserved, jobPrefix = KEYS[1], KEYS[2], KEYS[3], KEYS[4]
local now, expires = tonumber(ARGV[1]), tonumber(ARGV[2])

local due = redis.call('ZRANGEBYSCORE', delayed, '-inf', now)
for _, id in ipairs(due) do
	redis.call('ZREM', delayed, id)
	redis.call('RPUSH', ready, id)
end

local stale = redis.call('ZRANGEBYSCORE', reserved, '-inf', now)
for _, id in ipairs(stale) do
	redis.call('ZREM', reserved, id)
	redis.call('HSET', jobPrefix .. id, 'reserved_at', '')
	redis.call('RPUSH', ready, id)
end

local id = redis.call('LPOP', ready)
if not id then
	return false
end

-- A job deleted while still listed: skip it and try the next.
while redis.call('EXISTS', jobPrefix .. id) == 0 do
	id = redis.call('LPOP', ready)
	if not id then
		return false
	end
end

redis.call('HINCRBY', jobPrefix .. id, 'attempts', 1)
redis.call('HSET', jobPrefix .. id, 'reserved_at', now)
redis.call('ZADD', reserved, expires, id)

return id
LUA;

	/**
	 * @param array $config See class docblock
	 * @param \Redis|null $redis Existing connection to reuse
	 * @throws \RuntimeException if ext-redis is missing or the connection fails
	 */
	public function __construct( array $config = [], ?\Redis $redis = null )
	{
		if( !self::isAvailable() )
		{
			throw new \RuntimeException( 'The redis queue driver requires the phpredis extension (ext-redis).' );
		}

		$this->_prefix = rtrim( (string)( $config['prefix'] ?? 'neuron:queue' ), ':' );
		$this->_retryAfter = (int)( $config['retry_after'] ?? 90 );
		$this->_redis = $redis ?? $this->connect( $config );
	}

	/**
	 * Whether the phpredis extension is loaded.
	 *
	 * @return bool
	 */
	public static function isAvailable(): bool
	{
		return extension_loaded( 'redis' ) && class_exists( \Redis::class );
	}

	/**
	 * @return \Redis
	 */
	public function getRedis(): \Redis
	{
		return $this->_redis;
	}

	/**
	 * @return string
	 */
	public function getPrefix(): string
	{
		return $this->_prefix;
	}

	/**
	 * @return int
	 */
	public function getRetryAfter(): int
	{
		return $this->_retryAfter;
	}

	/**
	 * @inheritDoc
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string
	{
		$queuedJob = QueuedJob::fromJob( $job, $args, $queue, $delay );
		$id = $queuedJob->getId();

		$this->_redis->multi();
		$this->_redis->hMSet( $this->jobKey( $id ), $this->serializeJob( $queuedJob ) );
		$this->_redis->sAdd( $this->key( 'queues' ), $queue );

		if( $delay > 0 )
		{
			$this->_redis->zAdd( $this->key( $queue, 'delayed' ), $queuedJob->getAvailableAt(), $id );
		}
		else
		{
			$this->_redis->rPush( $this->key( $queue ), $id );
		}

		$this->_redis->exec();

		Log::debug( "Job pushed to redis queue: {$id} ({$queuedJob->getJobClass()})" );

		return $id;
	}

	/**
	 * @inheritDoc
	 */
	public function pop( string $queue = 'default' ): ?QueuedJob
	{
		$now = time();

		$id = $this->_redis->eval(
			self::POP_SCRIPT,
			[
				$this->key( $queue ),
				$this->key( $queue, 'delayed' ),
				$this->key( $queue, 'reserved' ),
				$this->key( 'job' ) . ':',
				$now,
				$now + $this->_retryAfter
			],
			4
		);

		if( !is_string( $id ) || $id === '' )
		{
			return null;
		}

		$data = $this->_redis->hGetAll( $this->jobKey( $id ) );

		if( !$data )
		{
			return null;
		}

		$job = $this->hydrate( $id, $data );

		Log::debug( "Job popped from redis queue: {$id} ({$job->getJobClass()})" );

		return $job;
	}

	/**
	 * @inheritDoc
	 */
	public function release( QueuedJob $job, int $delay = 0 ): void
	{
		$id = $job->getId();
		$queue = $job->getQueue();
		$availableAt = time() + $delay;

		$this->_redis->multi();
		$this->_redis->zRem( $this->key( $queue, 'reserved' ), $id );
		$this->_redis->hMSet( $this->jobKey( $id ), [ 'reserved_at' => '', 'available_at' => $availableAt ] );

		if( $delay > 0 )
		{
			$this->_redis->zAdd( $this->key( $queue, 'delayed' ), $availableAt, $id );
		}
		else
		{
			$this->_redis->rPush( $this->key( $queue ), $id );
		}

		$this->_redis->exec();

		Log::debug( "Job released to redis queue: {$id}" );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( QueuedJob $job ): void
	{
		$id = $job->getId();
		$queue = $job->getQueue();

		$this->_redis->multi();
		$this->_redis->zRem( $this->key( $queue, 'reserved' ), $id );
		$this->_redis->zRem( $this->key( $queue, 'delayed' ), $id );
		$this->_redis->lRem( $this->key( $queue ), $id, 0 );
		$this->_redis->del( $this->jobKey( $id ) );
		$this->_redis->exec();
	}

	/**
	 * @inheritDoc
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void
	{
		$id = $job->getId();
		$failedAt = time();

		$this->_redis->multi();
		$this->_redis->hMSet( $this->key( 'failed', $id ), [
			'id' => $id,
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'exception' => sprintf( "%s: %s\n%s", get_class( $exception ), $exception->getMessage(), $exception->getTraceAsString() ),
			'failed_at' => $failedAt
		] );
		$this->_redis->zAdd( $this->key( 'failed' ), $failedAt, $id );
		$this->_redis->exec();

		$this->delete( $job );

		Log::error( "Job failed: {$id} - {$exception->getMessage()}" );
	}

	/**
	 * @inheritDoc
	 * Ready jobs only; delayed and reserved are excluded like the other drivers.
	 */
	public function size( string $queue = 'default' ): int
	{
		$now = time();

		// Ready list plus delayed jobs whose time has come but have not been
		// moved yet (that happens lazily in pop).
		return (int)$this->_redis->lLen( $this->key( $queue ) )
			+ (int)$this->_redis->zCount( $this->key( $queue, 'delayed' ), '-inf', (string)$now )
			+ (int)$this->_redis->zCount( $this->key( $queue, 'reserved' ), '-inf', (string)$now );
	}

	/**
	 * @inheritDoc
	 *
	 * The `queues` set records every queue ever pushed to; names whose keys
	 * have since emptied are filtered out here rather than maintained on every
	 * pop.
	 */
	public function getQueueNames(): array
	{
		$names = [];

		foreach( $this->_redis->sMembers( $this->key( 'queues' ) ) ?: [] as $queue )
		{
			$stats = $this->getStats( (string)$queue );

			if( $stats['pending'] + $stats['delayed'] + $stats['reserved'] > 0 )
			{
				$names[] = (string)$queue;
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * @inheritDoc
	 */
	public function getStats( string $queue = 'default' ): array
	{
		$now = time();

		$pending = $this->size( $queue );
		$delayed = (int)$this->_redis->zCount( $this->key( $queue, 'delayed' ), '(' . $now, '+inf' );
		$reserved = (int)$this->_redis->zCount( $this->key( $queue, 'reserved' ), '(' . $now, '+inf' );

		$oldest = null;

		$headId = $this->_redis->lIndex( $this->key( $queue ), 0 );

		if( is_string( $headId ) && $headId !== '' )
		{
			$availableAt = $this->_redis->hGet( $this->jobKey( $headId ), 'available_at' );

			if( $availableAt !== false && $availableAt !== '' )
			{
				$oldest = max( 0, $now - (int)$availableAt );
			}
		}

		return [
			'pending' => $pending,
			'delayed' => $delayed,
			'reserved' => $reserved,
			'oldest_pending_age' => $oldest,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function clear( string $queue = 'default' ): int
	{
		$ids = array_merge(
			$this->_redis->lRange( $this->key( $queue ), 0, -1 ) ?: [],
			$this->_redis->zRange( $this->key( $queue, 'delayed' ), 0, -1 ) ?: [],
			$this->_redis->zRange( $this->key( $queue, 'reserved' ), 0, -1 ) ?: []
		);

		$ids = array_values( array_unique( $ids ) );

		$this->_redis->multi();

		foreach( $ids as $id )
		{
			$this->_redis->del( $this->jobKey( $id ) );
		}

		$this->_redis->del( $this->key( $queue ), $this->key( $queue, 'delayed' ), $this->key( $queue, 'reserved' ) );
		$this->_redis->exec();

		return count( $ids );
	}

	/**
	 * @inheritDoc
	 */
	public function getFailedJobs(): array
	{
		$ids = $this->_redis->zRevRange( $this->key( 'failed' ), 0, -1 ) ?: [];
		$jobs = [];

		foreach( $ids as $id )
		{
			$data = $this->_redis->hGetAll( $this->key( 'failed', $id ) );

			if( $data )
			{
				$data['failed_at'] = (int)( $data['failed_at'] ?? 0 );
				$jobs[] = $data;
			}
		}

		return $jobs;
	}

	/**
	 * @inheritDoc
	 */
	public function retryFailedJob( string $id ): bool
	{
		$data = $this->_redis->hGetAll( $this->key( 'failed', $id ) );

		if( !$data )
		{
			return false;
		}

		$now = time();
		$queue = (string)$data['queue'];

		$this->_redis->multi();
		$this->_redis->hMSet( $this->jobKey( $id ), [
			'queue' => $queue,
			'payload' => (string)$data['payload'],
			'attempts' => 0,
			'reserved_at' => '',
			'available_at' => $now,
			'created_at' => $now
		] );
		$this->_redis->sAdd( $this->key( 'queues' ), $queue );
		$this->_redis->rPush( $this->key( $queue ), $id );
		$this->_redis->zRem( $this->key( 'failed' ), $id );
		$this->_redis->del( $this->key( 'failed', $id ) );
		$this->_redis->exec();

		Log::info( "Failed job retried: {$id}" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function forgetFailedJob( string $id ): bool
	{
		if( !$this->_redis->exists( $this->key( 'failed', $id ) ) )
		{
			return false;
		}

		$this->_redis->multi();
		$this->_redis->zRem( $this->key( 'failed' ), $id );
		$this->_redis->del( $this->key( 'failed', $id ) );
		$this->_redis->exec();

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function clearFailedJobs(): int
	{
		$ids = $this->_redis->zRange( $this->key( 'failed' ), 0, -1 ) ?: [];

		$this->_redis->multi();

		foreach( $ids as $id )
		{
			$this->_redis->del( $this->key( 'failed', $id ) );
		}

		$this->_redis->del( $this->key( 'failed' ) );
		$this->_redis->exec();

		return count( $ids );
	}

	/**
	 * Remove every key under this prefix. For tests and `jobs:flush --all`.
	 *
	 * @return int Keys removed
	 */
	public function flushAll(): int
	{
		$count = 0;
		$iterator = null;

		do
		{
			$keys = $this->_redis->scan( $iterator, $this->_prefix . ':*', 500 );

			if( $keys )
			{
				$count += (int)$this->_redis->del( $keys );
			}
		}
		while( $iterator > 0 );

		return $count;
	}

	/**
	 * Open a connection from config, accepting `url` or host/port/etc.
	 *
	 * @param array $config
	 * @return \Redis
	 * @throws \RuntimeException
	 */
	private function connect( array $config ): \Redis
	{
		$host = (string)( $config['host'] ?? '127.0.0.1' );
		$port = (int)( $config['port'] ?? 6379 );
		$password = $config['password'] ?? null;
		$database = (int)( $config['database'] ?? 0 );
		$timeout = (float)( $config['timeout'] ?? 2.0 );

		if( !empty( $config['url'] ) )
		{
			$parts = parse_url( (string)$config['url'] );

			if( $parts === false || !isset( $parts['host'] ) )
			{
				throw new \RuntimeException( 'Invalid redis url: ' . $config['url'] );
			}

			$host = $parts['host'];
			$port = (int)( $parts['port'] ?? 6379 );
			$password = isset( $parts['pass'] ) ? rawurldecode( $parts['pass'] ) : $password;

			if( isset( $parts['path'] ) && $parts['path'] !== '/' )
			{
				$database = (int)ltrim( $parts['path'], '/' );
			}

			if( ( $parts['scheme'] ?? '' ) === 'rediss' )
			{
				$host = 'tls://' . $host;
			}
		}

		$redis = new \Redis();

		try
		{
			if( !$redis->connect( $host, $port, $timeout ) )
			{
				throw new \RuntimeException( "Could not connect to redis at {$host}:{$port}" );
			}

			if( $password !== null && $password !== '' )
			{
				$redis->auth( $password );
			}

			if( $database > 0 )
			{
				$redis->select( $database );
			}
		}
		catch( \RedisException $e )
		{
			throw new \RuntimeException( "Redis connection failed ({$host}:{$port}): {$e->getMessage()}", 0, $e );
		}

		return $redis;
	}

	/**
	 * @param string ...$parts
	 * @return string
	 */
	private function key( string ...$parts ): string
	{
		return $this->_prefix . ':' . implode( ':', $parts );
	}

	/**
	 * @param string $id
	 * @return string
	 */
	private function jobKey( string $id ): string
	{
		return $this->key( 'job', $id );
	}

	/**
	 * @param QueuedJob $job
	 * @return array<string, string|int>
	 */
	private function serializeJob( QueuedJob $job ): array
	{
		return [
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'attempts' => $job->getAttempts(),
			'reserved_at' => $job->getReservedAt() ?? '',
			'available_at' => $job->getAvailableAt(),
			'created_at' => $job->getCreatedAt()
		];
	}

	/**
	 * @param string $id
	 * @param array $data
	 * @return QueuedJob
	 */
	private function hydrate( string $id, array $data ): QueuedJob
	{
		$reservedAt = $data['reserved_at'] ?? '';

		return QueuedJob::fromPayload(
			$id,
			(string)$data['queue'],
			(string)$data['payload'],
			(int)( $data['attempts'] ?? 0 ),
			$reservedAt === '' ? null : (int)$reservedAt,
			(int)( $data['available_at'] ?? 0 ),
			(int)( $data['created_at'] ?? 0 )
		);
	}
}
