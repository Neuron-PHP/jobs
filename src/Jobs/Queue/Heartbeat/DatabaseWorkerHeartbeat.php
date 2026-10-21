<?php

namespace Neuron\Jobs\Queue\Heartbeat;

use Neuron\Log\Log;
use PDO;
use PDOException;

/**
 * Worker heartbeats in a `worker_heartbeats` table, created on first use.
 *
 * @package Neuron\Jobs\Queue\Heartbeat
 */
class DatabaseWorkerHeartbeat implements IWorkerHeartbeat
{
	public const DEFAULT_TABLE = 'worker_heartbeats';

	private PDO $_connection;
	private string $_table;
	private bool $_tableChecked = false;
	private int $_touches = 0;

	public function __construct( PDO $connection, string $table = self::DEFAULT_TABLE )
	{
		$this->_connection = $connection;
		$this->_table = $table;
	}

	public function touch( string $id, array $queues, int $jobsProcessed, int $startedAt ): void
	{
		$this->ensureTable();

		$now = time();
		$payload = [
			'id' => $id,
			'queues' => implode( ',', $queues ),
			'jobs_processed' => $jobsProcessed,
			'started_at' => $startedAt,
			'last_seen' => $now,
			'pid' => getmypid() ?: null,
			'hostname' => gethostname() ?: null,
		];

		try
		{
			$stmt = $this->_connection->prepare(
				"UPDATE {$this->_table}
				    SET queues = :queues, jobs_processed = :jobs_processed, started_at = :started_at,
				        last_seen = :last_seen, pid = :pid, hostname = :hostname
				  WHERE id = :id"
			);
			$stmt->execute( $payload );

			if( $stmt->rowCount() === 0 )
			{
				$insert = $this->_connection->prepare(
					"INSERT INTO {$this->_table}
						(id, queues, jobs_processed, started_at, last_seen, pid, hostname)
					 VALUES
						(:id, :queues, :jobs_processed, :started_at, :last_seen, :pid, :hostname)"
				);
				$insert->execute( $payload );
			}
		}
		catch( PDOException $e )
		{
			Log::warning( "Worker heartbeat write failed: {$e->getMessage()}" );
			return;
		}

		if( ++$this->_touches % 50 === 0 )
		{
			$this->prune( $now - 86400 );
		}
	}

	public function listWorkers(): array
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->query( "SELECT * FROM {$this->_table} ORDER BY last_seen DESC" );

			return array_map( [ $this, 'castRow' ], $stmt->fetchAll( PDO::FETCH_ASSOC ) ?: [] );
		}
		catch( PDOException $e )
		{
			Log::warning( "Worker heartbeat list failed: {$e->getMessage()}" );
			return [];
		}
	}

	public function prune( int $olderThan ): int
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->prepare( "DELETE FROM {$this->_table} WHERE last_seen < :cutoff" );
			$stmt->execute( [ 'cutoff' => $olderThan ] );

			return $stmt->rowCount();
		}
		catch( PDOException $e )
		{
			Log::warning( "Worker heartbeat prune failed: {$e->getMessage()}" );
			return 0;
		}
	}

	public function getTable(): string
	{
		return $this->_table;
	}

	private function castRow( array $row ): array
	{
		$queues = (string)( $row['queues'] ?? '' );

		return [
			'id' => (string)$row['id'],
			'queues' => $queues === '' ? [] : explode( ',', $queues ),
			'jobs_processed' => (int)$row['jobs_processed'],
			'started_at' => (int)$row['started_at'],
			'last_seen' => (int)$row['last_seen'],
			'pid' => isset( $row['pid'] ) && $row['pid'] !== null && $row['pid'] !== '' ? (int)$row['pid'] : null,
			'hostname' => $row['hostname'] !== null && $row['hostname'] !== '' ? (string)$row['hostname'] : null,
		];
	}

	private function ensureTable(): void
	{
		if( $this->_tableChecked )
		{
			return;
		}

		$this->_tableChecked = true;

		try
		{
			$this->_connection->exec(
				"CREATE TABLE IF NOT EXISTS {$this->_table} (
					id VARCHAR(64) NOT NULL PRIMARY KEY,
					queues TEXT NOT NULL,
					jobs_processed INTEGER NOT NULL,
					started_at INTEGER NOT NULL,
					last_seen INTEGER NOT NULL,
					pid INTEGER,
					hostname VARCHAR(255)
				)"
			);
		}
		catch( PDOException $e )
		{
			Log::debug( "Worker heartbeat table check: {$e->getMessage()}" );
		}
	}
}
