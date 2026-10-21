<?php

namespace Neuron\Jobs\Schedule;

use Neuron\Log\Log;
use PDO;
use PDOException;

/**
 * Schedule lock backed by a unique row per (job, scheduled minute).
 *
 * The INSERT either succeeds, and the caller owns the trigger, or violates
 * the primary key because another scheduler got there first. This works on
 * SQLite, MySQL and PostgreSQL without vendor-specific locking, and shares
 * the queue's connection so no extra configuration is needed.
 *
 * The table is created on first use if it does not exist, so enabling
 * `queue.schedule_lock: database` needs no migration. Rows older than
 * `retention` seconds are pruned opportunistically.
 *
 * @package Neuron\Jobs\Schedule
 */
class DatabaseScheduleLock implements IScheduleLock
{
	public const DEFAULT_TABLE = 'schedule_locks';
	public const DEFAULT_RETENTION = 7 * 86400;

	private PDO $_connection;
	private string $_table;
	private int $_retention;
	private bool $_tableChecked = false;
	private int $_acquisitions = 0;

	/**
	 * @param PDO $connection
	 * @param string $table
	 * @param int $retention Seconds to keep claimed rows before pruning
	 */
	public function __construct( PDO $connection, string $table = self::DEFAULT_TABLE, int $retention = self::DEFAULT_RETENTION )
	{
		$this->_connection = $connection;
		$this->_table = $table;
		$this->_retention = max( 60, $retention );
	}

	/**
	 * @inheritDoc
	 */
	public function acquire( string $name, \DateTimeInterface $scheduled ): bool
	{
		$this->ensureTable();

		$dueMinute = $scheduled->format( 'YmdHi' );

		try
		{
			$stmt = $this->_connection->prepare(
				"INSERT INTO {$this->_table} (name, due_minute, claimed_at) VALUES (:name, :due_minute, :claimed_at)"
			);

			$stmt->execute([
				'name' => $name,
				'due_minute' => $dueMinute,
				'claimed_at' => time()
			]);
		}
		catch( PDOException $e )
		{
			if( $this->isDuplicateKey( $e ) )
			{
				Log::debug( "Schedule lock held elsewhere: {$name} @ {$dueMinute}" );
				return false;
			}

			// Any other database error: fail open so a broken lock table does
			// not silently stop the schedule, but say so.
			Log::error( "Schedule lock error for {$name}: {$e->getMessage()}; proceeding without lock" );
			return true;
		}

		// Prune every so often rather than on every claim.
		if( ++$this->_acquisitions % 50 === 1 )
		{
			$this->prune();
		}

		return true;
	}

	/**
	 * Remove claims older than the retention window.
	 *
	 * @return int Rows removed
	 */
	public function prune(): int
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->prepare( "DELETE FROM {$this->_table} WHERE claimed_at < :cutoff" );
			$stmt->execute([ 'cutoff' => time() - $this->_retention ]);

			return $stmt->rowCount();
		}
		catch( PDOException $e )
		{
			Log::warning( "Schedule lock prune failed: {$e->getMessage()}" );
			return 0;
		}
	}

	/**
	 * Most recent claim per schedule entry, for "last triggered" displays.
	 *
	 * Only entries that have been claimed within the retention window appear;
	 * a schedule that has never fired (or not since the last prune) is absent.
	 *
	 * @return array<string, int> entry name => unix timestamp of the latest claim
	 */
	public function getLastClaims(): array
	{
		$this->ensureTable();

		try
		{
			$stmt = $this->_connection->query(
				"SELECT name, MAX(claimed_at) AS claimed_at FROM {$this->_table} GROUP BY name"
			);

			$claims = [];

			foreach( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row )
			{
				$claims[ (string)$row['name'] ] = (int)$row['claimed_at'];
			}

			return $claims;
		}
		catch( PDOException $e )
		{
			Log::warning( "Schedule lock read failed: {$e->getMessage()}" );
			return [];
		}
	}

	/**
	 * @return string
	 */
	public function getTable(): string
	{
		return $this->_table;
	}

	/**
	 * Create the lock table if it is missing. Idempotent and portable.
	 *
	 * @return void
	 */
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
					name VARCHAR(255) NOT NULL,
					due_minute VARCHAR(12) NOT NULL,
					claimed_at INTEGER NOT NULL,
					PRIMARY KEY (name, due_minute)
				)"
			);
		}
		catch( PDOException $e )
		{
			// Concurrent creation or a permissions problem; acquire() will
			// surface a real failure on the INSERT.
			Log::debug( "Schedule lock table check: {$e->getMessage()}" );
		}
	}

	/**
	 * Whether the exception is a primary key / unique violation.
	 *
	 * SQLSTATE 23000 (MySQL, SQLite) and 23505 (PostgreSQL).
	 *
	 * @param PDOException $e
	 * @return bool
	 */
	private function isDuplicateKey( PDOException $e ): bool
	{
		$state = (string)( $e->errorInfo[0] ?? $e->getCode() );

		return $state === '23000' || $state === '23505';
	}
}
