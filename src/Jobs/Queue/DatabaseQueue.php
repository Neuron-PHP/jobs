<?php

namespace Neuron\Jobs\Queue;

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
class DatabaseQueue implements IQueue
{
	private \PDO $_Connection;
	private string $_JobsTable = 'jobs';
	private string $_FailedJobsTable = 'failed_jobs';
	private int $_RetryAfter = 90; // seconds

	/**
	 * @param array $config Database configuration
	 */
	public function __construct( array $config )
	{
		$this->_Connection = $this->createConnection( $config );
		$this->_JobsTable = $config['jobs_table'] ?? 'jobs';
		$this->_FailedJobsTable = $config['failed_jobs_table'] ?? 'failed_jobs';
		$this->_RetryAfter = $config['retry_after'] ?? 90;
	}

	/**
	 * Create PDO connection from config
	 *
	 * @param array $config
	 * @return \PDO
	 */
	private function createConnection( array $config ): \PDO
	{
		$adapter = $config['adapter'] ?? 'mysql';

		$dsn = match( $adapter )
		{
			'sqlite' => 'sqlite:' . $config['name'],
			'mysql' => sprintf(
				'mysql:host=%s;port=%d;dbname=%s;charset=%s',
				$config['host'] ?? 'localhost',
				$config['port'] ?? 3306,
				$config['name'],
				$config['charset'] ?? 'utf8mb4'
			),
			'pgsql' => sprintf(
				'pgsql:host=%s;port=%d;dbname=%s',
				$config['host'] ?? 'localhost',
				$config['port'] ?? 5432,
				$config['name']
			),
			default => throw new \RuntimeException( "Unsupported database adapter: {$adapter}" )
		};

		$options = [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
		];

		$username = $config['user'] ?? null;
		$password = $config['pass'] ?? null;

		return new \PDO( $dsn, $username, $password, $options );
	}

	/**
	 * @inheritDoc
	 */
	public function push( IJob $job, array $args = [], string $queue = 'default', int $delay = 0 ): string
	{
		$queuedJob = QueuedJob::fromJob( $job, $args, $queue, $delay );

		$sql = "INSERT INTO {$this->_JobsTable}
			(id, queue, payload, attempts, reserved_at, available_at, created_at)
			VALUES (:id, :queue, :payload, :attempts, :reserved_at, :available_at, :created_at)";

		$stmt = $this->_Connection->prepare( $sql );

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
	public function pop( string $queue = 'default' ): ?QueuedJob
	{
		$this->releaseExpiredJobs( $queue );

		// Get the next available job
		$sql = "SELECT * FROM {$this->_JobsTable}
			WHERE queue = :queue
			AND available_at <= :now
			AND reserved_at IS NULL
			ORDER BY available_at ASC
			LIMIT 1";

		$stmt = $this->_Connection->prepare( $sql );
		$stmt->execute([
			'queue' => $queue,
			'now' => time()
		]);

		$row = $stmt->fetch();

		if( !$row )
		{
			return null;
		}

		// Reserve the job
		$updateSql = "UPDATE {$this->_JobsTable}
			SET reserved_at = :reserved_at, attempts = attempts + 1
			WHERE id = :id AND reserved_at IS NULL";

		$updateStmt = $this->_Connection->prepare( $updateSql );
		$reservedAt = time();

		$updateStmt->execute([
			'reserved_at' => $reservedAt,
			'id' => $row['id']
		]);

		// If update didn't affect any rows, another worker got it
		if( $updateStmt->rowCount() === 0 )
		{
			return $this->pop( $queue ); // Try again
		}

		// Create QueuedJob from row
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

	/**
	 * Release expired reserved jobs back to the queue
	 *
	 * @param string $queue
	 * @return void
	 */
	private function releaseExpiredJobs( string $queue ): void
	{
		$expiredTime = time() - $this->_RetryAfter;

		$sql = "UPDATE {$this->_JobsTable}
			SET reserved_at = NULL, available_at = :available_at
			WHERE queue = :queue
			AND reserved_at IS NOT NULL
			AND reserved_at < :expired_time";

		$stmt = $this->_Connection->prepare( $sql );
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
		$sql = "UPDATE {$this->_JobsTable}
			SET reserved_at = NULL, available_at = :available_at
			WHERE id = :id";

		$stmt = $this->_Connection->prepare( $sql );
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
		$sql = "DELETE FROM {$this->_JobsTable} WHERE id = :id";

		$stmt = $this->_Connection->prepare( $sql );
		$stmt->execute([ 'id' => $job->getId() ]);

		Log::debug( "Job deleted from queue: {$job->getId()}" );
	}

	/**
	 * @inheritDoc
	 */
	public function failed( QueuedJob $job, \Throwable $exception ): void
	{
		// Insert into failed jobs table
		$sql = "INSERT INTO {$this->_FailedJobsTable}
			(id, queue, payload, exception, failed_at)
			VALUES (:id, :queue, :payload, :exception, :failed_at)";

		$stmt = $this->_Connection->prepare( $sql );
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
		$sql = "SELECT COUNT(*) as count FROM {$this->_JobsTable}
			WHERE queue = :queue AND reserved_at IS NULL";

		$stmt = $this->_Connection->prepare( $sql );
		$stmt->execute([ 'queue' => $queue ]);

		$row = $stmt->fetch();

		return (int)( $row['count'] ?? 0 );
	}

	/**
	 * @inheritDoc
	 */
	public function clear( string $queue = 'default' ): int
	{
		$sql = "DELETE FROM {$this->_JobsTable} WHERE queue = :queue";

		$stmt = $this->_Connection->prepare( $sql );
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
		$sql = "SELECT * FROM {$this->_FailedJobsTable} ORDER BY failed_at DESC";

		$stmt = $this->_Connection->query( $sql );

		return $stmt->fetchAll();
	}

	/**
	 * @inheritDoc
	 */
	public function retryFailedJob( string $id ): bool
	{
		// Get failed job
		$sql = "SELECT * FROM {$this->_FailedJobsTable} WHERE id = :id";

		$stmt = $this->_Connection->prepare( $sql );
		$stmt->execute([ 'id' => $id ]);

		$row = $stmt->fetch();

		if( !$row )
		{
			return false;
		}

		// Insert back into jobs table
		$insertSql = "INSERT INTO {$this->_JobsTable}
			(id, queue, payload, attempts, reserved_at, available_at, created_at)
			VALUES (:id, :queue, :payload, 0, NULL, :available_at, :created_at)";

		$insertStmt = $this->_Connection->prepare( $insertSql );
		$insertStmt->execute([
			'id' => uniqid( 'job_', true ), // New ID for retry
			'queue' => $row['queue'],
			'payload' => $row['payload'],
			'available_at' => time(),
			'created_at' => time()
		]);

		// Delete from failed jobs
		$deleteSql = "DELETE FROM {$this->_FailedJobsTable} WHERE id = :id";

		$deleteStmt = $this->_Connection->prepare( $deleteSql );
		$deleteStmt->execute([ 'id' => $id ]);

		Log::info( "Failed job retried: {$id}" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function forgetFailedJob( string $id ): bool
	{
		$sql = "DELETE FROM {$this->_FailedJobsTable} WHERE id = :id";

		$stmt = $this->_Connection->prepare( $sql );
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
		$sql = "DELETE FROM {$this->_FailedJobsTable}";

		$stmt = $this->_Connection->query( $sql );
		$count = $stmt->rowCount();

		Log::info( "Cleared {$count} failed jobs" );

		return $count;
	}
}
