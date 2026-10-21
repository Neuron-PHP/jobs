<?php

namespace Neuron\Jobs\Queue\History;

use Neuron\Log\Log;
use PDO;
use PDOException;

/**
 * Run history in a `job_runs` table on the queue (or application) connection.
 *
 * The table is created on first use. Rows older than `retention` seconds
 * are pruned opportunistically. Failures to write are logged and swallowed
 * so a broken history table never stops a worker.
 *
 * @package Neuron\Jobs\Queue\History
 */
class DatabaseJobHistory implements IJobHistory
{
	public const DEFAULT_TABLE = 'job_runs';
	public const DEFAULT_RETENTION = 7 * 86400;

	private PDO $_connection;
	private string $_table;
	private int $_retention;
	private bool $_tableChecked = false;
	private int $_writes = 0;

	public function __construct( PDO $connection, string $table = self::DEFAULT_TABLE, int $retention = self::DEFAULT_RETENTION )
	{
		$this->_connection = $connection;
		$this->_table = $table;
		$this->_retention = max( 60, $retention );
	}

	public function record( array $run ): void
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->prepare(
				"INSERT INTO {$this->_table}
					(id, job_id, job_class, queue, payload, status, exception, started_at, finished_at, duration_ms, attempts)
				 VALUES
					(:id, :job_id, :job_class, :queue, :payload, :status, :exception, :started_at, :finished_at, :duration_ms, :attempts)"
			);

			$stmt->execute([
				'id' => (string)( $run['id'] ?? uniqid( 'run_', true ) ),
				'job_id' => (string)( $run['job_id'] ?? '' ),
				'job_class' => (string)( $run['job_class'] ?? '' ),
				'queue' => (string)( $run['queue'] ?? '' ),
				'payload' => (string)( $run['payload'] ?? '' ),
				'status' => (string)( $run['status'] ?? self::STATUS_FAILED ),
				'exception' => $run['exception'] ?? null,
				'started_at' => (int)( $run['started_at'] ?? time() ),
				'finished_at' => (int)( $run['finished_at'] ?? time() ),
				'duration_ms' => (int)( $run['duration_ms'] ?? 0 ),
				'attempts' => (int)( $run['attempts'] ?? 0 ),
			]);
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history write failed: {$e->getMessage()}" );
			return;
		}

		if( ++$this->_writes % 50 === 0 )
		{
			$this->prune();
		}
	}

	public function listRuns( array $filters = [], int $limit = 50, int $offset = 0 ): array
	{
		$this->ensureTable();

		[ $where, $params ] = $this->where( $filters );
		$limit = max( 1, $limit );
		$offset = max( 0, $offset );

		try
		{
			$stmt = $this->_connection->prepare(
				"SELECT * FROM {$this->_table} {$where} ORDER BY finished_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}"
			);
			$stmt->execute( $params );

			return array_map( [ $this, 'castRow' ], $stmt->fetchAll( PDO::FETCH_ASSOC ) ?: [] );
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history list failed: {$e->getMessage()}" );
			return [];
		}
	}

	public function countRuns( array $filters = [] ): int
	{
		$this->ensureTable();

		[ $where, $params ] = $this->where( $filters );

		try
		{
			$stmt = $this->_connection->prepare( "SELECT COUNT(*) FROM {$this->_table} {$where}" );
			$stmt->execute( $params );

			return (int)$stmt->fetchColumn();
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history count failed: {$e->getMessage()}" );
			return 0;
		}
	}

	public function findRun( string $id ): ?array
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->prepare( "SELECT * FROM {$this->_table} WHERE id = :id" );
			$stmt->execute( [ 'id' => $id ] );
			$row = $stmt->fetch( PDO::FETCH_ASSOC );

			return $row === false ? null : $this->castRow( $row );
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history read failed: {$e->getMessage()}" );
			return null;
		}
	}

	public function durationPercentiles( array $filters = [] ): array
	{
		$filters['status'] = self::STATUS_COMPLETED;
		$this->ensureTable();

		[ $where, $params ] = $this->where( $filters );

		try
		{
			$stmt = $this->_connection->prepare( "SELECT duration_ms FROM {$this->_table} {$where} ORDER BY duration_ms ASC" );
			$stmt->execute( $params );
			$durations = array_map( 'intval', $stmt->fetchAll( PDO::FETCH_COLUMN ) ?: [] );
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history percentiles failed: {$e->getMessage()}" );
			$durations = [];
		}

		return [
			'p50' => ArrayJobHistory::percentile( $durations, 50 ),
			'p95' => ArrayJobHistory::percentile( $durations, 95 ),
			'count' => count( $durations ),
		];
	}

	public function countsByStatus( array $filters = [] ): array
	{
		$this->ensureTable();

		[ $where, $params ] = $this->where( $filters );

		try
		{
			$stmt = $this->_connection->prepare(
				"SELECT status, COUNT(*) AS n FROM {$this->_table} {$where} GROUP BY status"
			);
			$stmt->execute( $params );

			$counts = [ 'completed' => 0, 'failed' => 0 ];

			foreach( $stmt->fetchAll( PDO::FETCH_ASSOC ) ?: [] as $row )
			{
				$status = (string)$row['status'];

				if( isset( $counts[ $status ] ) )
				{
					$counts[ $status ] = (int)$row['n'];
				}
			}

			return $counts;
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history status counts failed: {$e->getMessage()}" );
			return [ 'completed' => 0, 'failed' => 0 ];
		}
	}

	public function classes( array $filters = [] ): array
	{
		return $this->distinct( 'job_class', $filters );
	}

	public function queues( array $filters = [] ): array
	{
		return $this->distinct( 'queue', $filters );
	}

	public function prune(): int
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->prepare( "DELETE FROM {$this->_table} WHERE finished_at < :cutoff" );
			$stmt->execute( [ 'cutoff' => time() - $this->_retention ] );

			return $stmt->rowCount();
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history prune failed: {$e->getMessage()}" );
			return 0;
		}
	}

	public function getTable(): string
	{
		return $this->_table;
	}

	/**
	 * @return list<string>
	 */
	private function distinct( string $column, array $filters ): array
	{
		$this->ensureTable();

		[ $where, $params ] = $this->where( $filters );

		try
		{
			$stmt = $this->_connection->prepare( "SELECT DISTINCT {$column} FROM {$this->_table} {$where} ORDER BY {$column} ASC" );
			$stmt->execute( $params );

			return array_values( array_filter( array_map( 'strval', $stmt->fetchAll( PDO::FETCH_COLUMN ) ?: [] ) ) );
		}
		catch( PDOException $e )
		{
			Log::warning( "Job history distinct {$column} failed: {$e->getMessage()}" );
			return [];
		}
	}

	/**
	 * @return array{0:string,1:array}
	 */
	private function where( array $filters ): array
	{
		$clauses = [];
		$params = [];

		if( !empty( $filters['queue'] ) )
		{
			$clauses[] = 'queue = :queue';
			$params['queue'] = $filters['queue'];
		}

		if( !empty( $filters['class'] ) )
		{
			$clauses[] = 'job_class = :class';
			$params['class'] = $filters['class'];
		}

		if( !empty( $filters['status'] ) )
		{
			$clauses[] = 'status = :status';
			$params['status'] = $filters['status'];
		}

		if( isset( $filters['since'] ) && $filters['since'] !== null )
		{
			$clauses[] = 'finished_at >= :since';
			$params['since'] = (int)$filters['since'];
		}

		if( isset( $filters['until'] ) && $filters['until'] !== null )
		{
			$clauses[] = 'finished_at <= :until';
			$params['until'] = (int)$filters['until'];
		}

		return [ $clauses === [] ? '' : 'WHERE ' . implode( ' AND ', $clauses ), $params ];
	}

	private function castRow( array $row ): array
	{
		$row['started_at'] = (int)$row['started_at'];
		$row['finished_at'] = (int)$row['finished_at'];
		$row['duration_ms'] = (int)$row['duration_ms'];
		$row['attempts'] = (int)$row['attempts'];

		return $row;
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
					job_id VARCHAR(64) NOT NULL,
					job_class VARCHAR(255) NOT NULL,
					queue VARCHAR(255) NOT NULL,
					payload TEXT NOT NULL,
					status VARCHAR(16) NOT NULL,
					exception TEXT,
					started_at INTEGER NOT NULL,
					finished_at INTEGER NOT NULL,
					duration_ms INTEGER NOT NULL,
					attempts INTEGER NOT NULL
				)"
			);
			$this->_connection->exec( "CREATE INDEX IF NOT EXISTS {$this->_table}_finished ON {$this->_table} (finished_at)" );
			$this->_connection->exec( "CREATE INDEX IF NOT EXISTS {$this->_table}_class ON {$this->_table} (job_class, finished_at)" );
		}
		catch( PDOException $e )
		{
			Log::debug( "Job history table check: {$e->getMessage()}" );
		}
	}
}
