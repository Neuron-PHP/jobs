<?php

namespace Neuron\Jobs\Queue;

use Neuron\Data\Database\ConnectionFactory;
use Neuron\Jobs\IJob;
use Neuron\Log\Log;

/**
 * Database-backed queue implementation.
 *
 * Uses PDO to store jobs in a relational database.
 * Supports SQLite, MySQL, and PostgreSQL.
 *
 * @package Neuron\Jobs\Queue
 */
class DatabaseQueue implements IQueue, IQueueStats, IUniqueQueue, IQueueInspector
{
	private \PDO $_connection;
	private string $_jobsTable = 'jobs';
	private string $_failedJobsTable = 'failed_jobs';
	private int $_retryAfter = 90; // seconds

	/**
	 * Maximum number of times pop() re-queries after losing a reservation race
	 * to another worker before giving up for this poll.
	 */
	private const MAX_POP_ATTEMPTS = 5;

	/**
	 * @param array $config Database configuration (see Neuron\Data\Database\ConnectionFactory)
	 *        plus optional `jobs_table`, `failed_jobs_table` and `retry_after`, and
	 *        `use_environment` - false to ignore DATABASE_URL, which otherwise
	 *        overrides the configuration (QueueManager sets it for a
	 *        `queue.connection` other than `database`).
	 * @param \PDO|null $connection Existing connection to reuse instead of opening one
	 */
	public function __construct( array $config, ?\PDO $connection = null )
	{
		$this->_connection = $connection ?? $this->createConnection( $config );
		$this->_jobsTable = $config['jobs_table'] ?? 'jobs';
		$this->_failedJobsTable = $config['failed_jobs_table'] ?? 'failed_jobs';
		$this->_retryAfter = (int)( $config['retry_after'] ?? 90 );
	}

	/**
	 * The underlying PDO connection.
	 *
	 * @return \PDO
	 */
	public function getConnection(): \PDO
	{
		return $this->_connection;
	}

	/**
	 * Seconds after which a reserved job is considered abandoned and released.
	 *
	 * @return int
	 */
	public function getRetryAfter(): int
	{
		return $this->_retryAfter;
	}

	/**
	 * Create PDO connection from config.
	 *
	 * Delegates to the framework-wide factory so the queue honours `url`
	 * configuration and receives the same adapter initialisation (SQLite WAL
	 * and busy timeout, UTC sessions) as the application's own connections.
	 *
	 * @param array $config
	 * @return \PDO
	 * @throws \RuntimeException if the configuration is invalid
	 */
	private function createConnection( array $config ): \PDO
	{
		$useEnvironment = (bool)( $config['use_environment'] ?? true );

		unset( $config['jobs_table'], $config['failed_jobs_table'], $config['retry_after'], $config['use_environment'] );

		try
		{
			if( $useEnvironment )
			{
				return ConnectionFactory::createFromConfig( $config );
			}

			// createFromConfig() always applies DATABASE_URL, so the same steps
			// without it.
			$config = ConnectionFactory::normalize( $config, false );

			$pdo = new \PDO(
				ConnectionFactory::buildDsn( $config ),
				$config['user'] ?? null,
				$config['pass'] ?? null,
				[
					\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
					\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
				]
			);

			ConnectionFactory::initializeConnection( $pdo, $config['adapter'] );

			return $pdo;
		}
		catch( \InvalidArgumentException $e )
		{
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string
	{
		$queuedJob = QueuedJob::fromJob( $job, $args, $queue, $delay );

		$sql = "INSERT INTO {$this->_jobsTable}
			(id, queue, payload, attempts, reserved_at, available_at, created_at)
			VALUES (:id, :queue, :payload, :attempts, :reserved_at, :available_at, :created_at)";

		$stmt = $this->_connection->prepare( $sql );

		$stmt->execute([
			'id' => $queuedJob->getId(),
			'queue' => $queuedJob->getQueue(),
			'payload' => $queuedJob->getPayload(),
			'attempts' => $queuedJob->getAttempts(),
			'reserved_at' => $queuedJob->getReservedAt(),
			'available_at' => $queuedJob->getAvailableAt(),
			'created_at' => $queuedJob->getCreatedAt()
		]);

		Log::debug( "Job pushed to queue: {$queuedJob->getId()} ({$queuedJob->getJobClass()})" );

		return $queuedJob->getId();
	}

	/**
	 * @inheritDoc
	 */
	public function findPending( string $jobClass, array $args, string $queue ): ?string
	{
		$stmt = $this->_connection->prepare(
			"SELECT id, payload FROM {$this->_jobsTable}
			  WHERE queue = :queue AND reserved_at IS NULL
			  ORDER BY available_at ASC, id ASC"
		);

		$stmt->execute( [ 'queue' => $queue ] );

		// Compared as they come back out of the payload, so an int argument
		// matches the int it was stored as and not a numeric string.
		$args = json_decode( json_encode( $args, JSON_THROW_ON_ERROR ), true );

		while( $row = $stmt->fetch( \PDO::FETCH_ASSOC ) )
		{
			$data = json_decode( (string)$row['payload'], true );

			if( is_array( $data ) && ( $data['class'] ?? null ) === $jobClass && ( $data['args'] ?? null ) === $args )
			{
				$stmt->closeCursor();

				return (string)$row['id'];
			}
		}

		return null;
	}

	/**
	 * @inheritDoc
	 */
	public function pop( string $queue = 'default' ): ?QueuedJob
	{
		$this->releaseExpiredJobs( $queue );

		if( $this->supportsSkipLocked() )
		{
			return $this->popWithRowLock( $queue );
		}

		return $this->popWithCompareAndSet( $queue );
	}

	/**
	 * Whether the connected database supports `FOR UPDATE SKIP LOCKED`.
	 *
	 * MySQL 8+, MariaDB 10.6+ and PostgreSQL 9.5+ do. SQLite has no row
	 * locks, and older MySQL rejects the syntax; those use compare-and-set.
	 *
	 * @return bool
	 */
	private function supportsSkipLocked(): bool
	{
		$driver = $this->_connection->getAttribute( \PDO::ATTR_DRIVER_NAME );

		if( $driver === 'pgsql' )
		{
			return true;
		}

		if( $driver !== 'mysql' )
		{
			return false;
		}

		$version = (string)$this->_connection->getAttribute( \PDO::ATTR_SERVER_VERSION );

		if( stripos( $version, 'mariadb' ) !== false )
		{
			// "5.5.5-10.6.12-MariaDB..." or "10.6.12-MariaDB"
			preg_match( '/(\d+\.\d+)\.\d+-MariaDB/i', $version, $m );
			return isset( $m[1] ) && version_compare( $m[1], '10.6', '>=' );
		}

		return version_compare( $version, '8.0', '>=' );
	}

	/**
	 * Reserve the next job under a row lock, skipping rows other workers hold.
	 *
	 * With many workers on one queue this avoids the SELECT/UPDATE race
	 * entirely: each worker locks a different row, so there is nothing to
	 * retry and no wasted round trips.
	 *
	 * @param string $queue
	 * @return QueuedJob|null
	 */
	private function popWithRowLock( string $queue ): ?QueuedJob
	{
		$selectSql = "SELECT * FROM {$this->_jobsTable}
			WHERE queue = :queue
			AND available_at <= :now
			AND reserved_at IS NULL
			ORDER BY available_at ASC, id ASC
			LIMIT 1
			FOR UPDATE SKIP LOCKED";

		$updateSql = "UPDATE {$this->_jobsTable}
			SET reserved_at = :reserved_at, attempts = attempts + 1
			WHERE id = :id";

		$this->_connection->beginTransaction();

		try
		{
			$selectStmt = $this->_connection->prepare( $selectSql );
			$selectStmt->execute([ 'queue' => $queue, 'now' => time() ]);

			$row = $selectStmt->fetch();

			if( !$row )
			{
				$this->_connection->commit();
				return null;
			}

			$reservedAt = time();

			$updateStmt = $this->_connection->prepare( $updateSql );
			$updateStmt->execute([ 'reserved_at' => $reservedAt, 'id' => $row['id'] ]);

			$this->_connection->commit();
		}
		catch( \Throwable $e )
		{
			if( $this->_connection->inTransaction() )
			{
				$this->_connection->rollBack();
			}

			throw $e;
		}

		$job = QueuedJob::fromPayload(
			$row['id'],
			$row['queue'],
			$row['payload'],
			(int)$row['attempts'] + 1,
			$reservedAt,
			(int)$row['available_at'],
			(int)$row['created_at']
		);

		Log::debug( "Job popped from queue: {$job->getId()} ({$job->getJobClass()})" );

		return $job;
	}

	/**
	 * Reserve the next job with an optimistic compare-and-set on reserved_at.
	 *
	 * @param string $queue
	 * @return QueuedJob|null
	 */
	private function popWithCompareAndSet( string $queue ): ?QueuedJob
	{
		$selectSql = "SELECT * FROM {$this->_jobsTable}
			WHERE queue = :queue
			AND available_at <= :now
			AND reserved_at IS NULL
			ORDER BY available_at ASC, id ASC
			LIMIT 1";

		// Reservation is a compare-and-set on reserved_at IS NULL, so two
		// workers selecting the same row cannot both reserve it.
		$updateSql = "UPDATE {$this->_jobsTable}
			SET reserved_at = :reserved_at, attempts = attempts + 1
			WHERE id = :id AND reserved_at IS NULL";

		$selectStmt = $this->_connection->prepare( $selectSql );
		$updateStmt = $this->_connection->prepare( $updateSql );

		for( $attempt = 0; $attempt < self::MAX_POP_ATTEMPTS; $attempt++ )
		{
			$selectStmt->execute([
				'queue' => $queue,
				'now' => time()
			]);

			$row = $selectStmt->fetch();

			if( !$row )
			{
				return null;
			}

			$reservedAt = time();

			$updateStmt->execute([
				'reserved_at' => $reservedAt,
				'id' => $row['id']
			]);

			// Another worker reserved it between our SELECT and UPDATE.
			if( $updateStmt->rowCount() === 0 )
			{
				continue;
			}

			$job = QueuedJob::fromPayload(
				$row['id'],
				$row['queue'],
				$row['payload'],
				(int)$row['attempts'] + 1, // Increment was done in UPDATE
				$reservedAt,
				(int)$row['available_at'],
				(int)$row['created_at']
			);

			Log::debug( "Job popped from queue: {$job->getId()} ({$job->getJobClass()})" );

			return $job;
		}

		Log::debug( "Lost " . self::MAX_POP_ATTEMPTS . " reservation races on queue '{$queue}'; yielding until next poll" );

		return null;
	}

	/**
	 * Release expired reserved jobs back to the queue
	 *
	 * @param string $queue
	 * @return void
	 */
	private function releaseExpiredJobs( string $queue ): void
	{
		$expiredTime = time() - $this->_retryAfter;

		$sql = "UPDATE {$this->_jobsTable}
			SET reserved_at = NULL, available_at = :available_at
			WHERE queue = :queue
			AND reserved_at IS NOT NULL
			AND reserved_at < :expired_time";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([
			'available_at' => time(),
			'queue' => $queue,
			'expired_time' => $expiredTime
		]);

		if( $stmt->rowCount() > 0 )
		{
			Log::debug( "Released {$stmt->rowCount()} expired jobs" );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function release( QueuedJob $job, int $delay = 0 ): void
	{
		$sql = "UPDATE {$this->_jobsTable}
			SET reserved_at = NULL, available_at = :available_at
			WHERE id = :id";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([
			'available_at' => time() + $delay,
			'id' => $job->getId()
		]);

		Log::debug( "Job released back to queue: {$job->getId()}" );
	}

	/**
	 * @inheritDoc
	 */
	public function delete( QueuedJob $job ): void
	{
		$sql = "DELETE FROM {$this->_jobsTable} WHERE id = :id";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([ 'id' => $job->getId() ]);

		Log::debug( "Job deleted from queue: {$job->getId()}" );
	}

	/**
	 * @inheritDoc
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void
	{
		// Insert into failed jobs table
		$sql = "INSERT INTO {$this->_failedJobsTable}
			(id, queue, payload, exception, failed_at)
			VALUES (:id, :queue, :payload, :exception, :failed_at)";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([
			'id' => $job->getId(),
			'queue' => $job->getQueue(),
			'payload' => $job->getPayload(),
			'exception' => $this->formatException( $exception ),
			'failed_at' => time()
		]);

		// Delete from jobs table
		$this->delete( $job );

		Log::error( "Job failed: {$job->getId()} - {$exception->getMessage()}" );
	}

	/**
	 * Format exception for storage
	 *
	 * @param \Throwable $exception
	 * @return string
	 */
	private function formatException( \Throwable $exception ): string
	{
		return sprintf(
			"%s: %s\n%s",
			get_class( $exception ),
			$exception->getMessage(),
			$exception->getTraceAsString()
		);
	}

	/**
	 * @inheritDoc
	 */
	public function size( string $queue = 'default' ): int
	{
		$sql = "SELECT COUNT(*) as count FROM {$this->_jobsTable}
			WHERE queue = :queue AND reserved_at IS NULL";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([ 'queue' => $queue ]);

		$row = $stmt->fetch();

		return (int)( $row['count'] ?? 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function getStats( string $queue = 'default' ): array
	{
		$now = time();

		$sql = "SELECT
				SUM(CASE WHEN reserved_at IS NULL AND available_at <= :now1 THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN reserved_at IS NULL AND available_at > :now2 THEN 1 ELSE 0 END) AS delayed,
				SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved,
				MIN(CASE WHEN reserved_at IS NULL AND available_at <= :now3 THEN available_at END) AS oldest_available_at
			FROM {$this->_jobsTable}
			WHERE queue = :queue";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([ 'queue' => $queue, 'now1' => $now, 'now2' => $now, 'now3' => $now ]);

		$row = $stmt->fetch() ?: [];

		$oldest = $row['oldest_available_at'] ?? null;

		return [
			'pending' => (int)( $row['pending'] ?? 0 ),
			'delayed' => (int)( $row['delayed'] ?? 0 ),
			'reserved' => (int)( $row['reserved'] ?? 0 ),
			'oldest_pending_age' => $oldest === null ? null : max( 0, $now - (int)$oldest ),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getQueueNames(): array
	{
		$stmt = $this->_connection->query( "SELECT DISTINCT queue FROM {$this->_jobsTable} ORDER BY queue" );

		return array_map( 'strval', $stmt->fetchAll( \PDO::FETCH_COLUMN ) ?: [] );
	}

	/**
	 * @inheritDoc
	 */
	public function listJobs( string $queue, JobState $state, int $limit = 50, int $offset = 0 ): array
	{
		$limit = max( 1, $limit );
		$offset = max( 0, $offset );

		$order = $state === JobState::Reserved ? 'reserved_at ASC, id ASC' : 'available_at ASC, id ASC';

		$sql = "SELECT * FROM {$this->_jobsTable}
			WHERE queue = :queue AND {$this->statePredicate( $state )}
			ORDER BY {$order}
			LIMIT {$limit} OFFSET {$offset}";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute( $this->stateParams( $state, [ 'queue' => $queue ] ) );

		$jobs = [];

		while( $row = $stmt->fetch( \PDO::FETCH_ASSOC ) )
		{
			$job = $this->hydrateRow( $row );

			if( $job !== null )
			{
				$jobs[] = $job;
			}
		}

		return $jobs;
	}

	/**
	 * @inheritDoc
	 */
	public function countJobs( string $queue, JobState $state ): int
	{
		$sql = "SELECT COUNT(*) AS count FROM {$this->_jobsTable}
			WHERE queue = :queue AND {$this->statePredicate( $state )}";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute( $this->stateParams( $state, [ 'queue' => $queue ] ) );

		$row = $stmt->fetch( \PDO::FETCH_ASSOC );

		return (int)( $row['count'] ?? 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function findJob( string $id ): ?QueuedJob
	{
		$stmt = $this->_connection->prepare( "SELECT * FROM {$this->_jobsTable} WHERE id = :id" );
		$stmt->execute( [ 'id' => $id ] );

		$row = $stmt->fetch( \PDO::FETCH_ASSOC );

		return $row ? $this->hydrateRow( $row ) : null;
	}

	/**
	 * @inheritDoc
	 */
	public function deleteJob( string $id ): bool
	{
		$stmt = $this->_connection->prepare(
			"DELETE FROM {$this->_jobsTable} WHERE id = :id AND reserved_at IS NULL"
		);
		$stmt->execute( [ 'id' => $id ] );

		if( $stmt->rowCount() > 0 )
		{
			Log::info( "Job removed from queue: {$id}" );
			return true;
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function releaseNow( string $id ): bool
	{
		$job = $this->findJob( $id );

		if( $job === null || $job->getReservedAt() !== null )
		{
			return false;
		}

		$now = time();

		if( $job->getAvailableAt() <= $now )
		{
			return true;
		}

		$stmt = $this->_connection->prepare(
			"UPDATE {$this->_jobsTable} SET available_at = :now WHERE id = :id AND reserved_at IS NULL"
		);
		$stmt->execute( [ 'now' => $now, 'id' => $id ] );

		Log::info( "Job released to run now: {$id}" );

		return $stmt->rowCount() > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function listFailedJobs( int $limit = 50, int $offset = 0, ?string $queue = null ): array
	{
		$limit = max( 1, $limit );
		$offset = max( 0, $offset );

		$where = $queue === null ? '' : 'WHERE queue = :queue';

		$sql = "SELECT * FROM {$this->_failedJobsTable} {$where}
			ORDER BY failed_at DESC, id DESC
			LIMIT {$limit} OFFSET {$offset}";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute( $queue === null ? [] : [ 'queue' => $queue ] );

		return $stmt->fetchAll( \PDO::FETCH_ASSOC ) ?: [];
	}

	/**
	 * @inheritDoc
	 */
	public function countFailedJobs( ?string $queue = null ): int
	{
		$where = $queue === null ? '' : 'WHERE queue = :queue';

		$stmt = $this->_connection->prepare( "SELECT COUNT(*) AS count FROM {$this->_failedJobsTable} {$where}" );
		$stmt->execute( $queue === null ? [] : [ 'queue' => $queue ] );

		$row = $stmt->fetch( \PDO::FETCH_ASSOC );

		return (int)( $row['count'] ?? 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function findFailedJob( string $id ): ?array
	{
		$stmt = $this->_connection->prepare( "SELECT * FROM {$this->_failedJobsTable} WHERE id = :id" );
		$stmt->execute( [ 'id' => $id ] );

		$row = $stmt->fetch( \PDO::FETCH_ASSOC );

		return $row ?: null;
	}

	/**
	 * SQL predicate selecting one job state. Pairs with stateParams().
	 *
	 * @param JobState $state
	 * @return string
	 */
	private function statePredicate( JobState $state ): string
	{
		return match( $state )
		{
			JobState::Pending  => 'reserved_at IS NULL AND available_at <= :now',
			JobState::Delayed  => 'reserved_at IS NULL AND available_at > :now',
			JobState::Reserved => 'reserved_at IS NOT NULL',
		};
	}

	/**
	 * Bound parameters for statePredicate(), merged with the caller's own.
	 *
	 * @param JobState $state
	 * @param array $params
	 * @return array
	 */
	private function stateParams( JobState $state, array $params ): array
	{
		if( $state !== JobState::Reserved )
		{
			$params['now'] = time();
		}

		return $params;
	}

	/**
	 * A jobs-table row as a QueuedJob, or null (logged) when its payload is
	 * unreadable, so one corrupt row cannot blank an inspection listing.
	 *
	 * @param array $row
	 * @return QueuedJob|null
	 */
	private function hydrateRow( array $row ): ?QueuedJob
	{
		try
		{
			return QueuedJob::fromPayload(
				(string)$row['id'],
				(string)$row['queue'],
				(string)$row['payload'],
				(int)$row['attempts'],
				$row['reserved_at'] === null ? null : (int)$row['reserved_at'],
				(int)$row['available_at'],
				(int)$row['created_at']
			);
		}
		catch( \RuntimeException $e )
		{
			Log::warning( "Skipping job {$row['id']} with unreadable payload: {$e->getMessage()}" );

			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function clear( string $queue = 'default' ): int
	{
		$sql = "DELETE FROM {$this->_jobsTable} WHERE queue = :queue";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([ 'queue' => $queue ]);

		$count = $stmt->rowCount();

		Log::info( "Cleared {$count} jobs from queue: {$queue}" );

		return $count;
	}

	/**
	 * @inheritDoc
	 */
	public function getFailedJobs(): array
	{
		$sql = "SELECT * FROM {$this->_failedJobsTable} ORDER BY failed_at DESC, id DESC";

		$stmt = $this->_connection->query( $sql );

		return $stmt->fetchAll();
	}

	/**
	 * @inheritDoc
	 */
	public function retryFailedJob( string $id ): bool
	{
		// Get failed job
		$sql = "SELECT * FROM {$this->_failedJobsTable} WHERE id = :id";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([ 'id' => $id ]);

		$row = $stmt->fetch();

		if( !$row )
		{
			return false;
		}

		// Insert back into jobs table
		$insertSql = "INSERT INTO {$this->_jobsTable}
			(id, queue, payload, attempts, reserved_at, available_at, created_at)
			VALUES (:id, :queue, :payload, 0, NULL, :available_at, :created_at)";

		$insertStmt = $this->_connection->prepare( $insertSql );
		$insertStmt->execute([
			'id' => uniqid( 'job_', true ), // New ID for retry
			'queue' => $row['queue'],
			'payload' => $row['payload'],
			'available_at' => time(),
			'created_at' => time()
		]);

		// Delete from failed jobs
		$deleteSql = "DELETE FROM {$this->_failedJobsTable} WHERE id = :id";

		$deleteStmt = $this->_connection->prepare( $deleteSql );
		$deleteStmt->execute([ 'id' => $id ]);

		Log::info( "Failed job retried: {$id}" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function forgetFailedJob( string $id ): bool
	{
		$sql = "DELETE FROM {$this->_failedJobsTable} WHERE id = :id";

		$stmt = $this->_connection->prepare( $sql );
		$stmt->execute([ 'id' => $id ]);

		if( $stmt->rowCount() > 0 )
		{
			Log::info( "Failed job deleted: {$id}" );
			return true;
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function clearFailedJobs(): int
	{
		$sql = "DELETE FROM {$this->_failedJobsTable}";

		$stmt = $this->_connection->query( $sql );
		$count = $stmt->rowCount();

		Log::info( "Cleared {$count} failed jobs" );

		return $count;
	}
}
