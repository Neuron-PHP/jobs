<?php

namespace Tests\Jobs\Queue\Inspector;

use Neuron\Jobs\Queue\DatabaseQueue;
use Neuron\Jobs\Queue\IQueue;

/**
 * IQueueInspector contract against DatabaseQueue on in-memory SQLite.
 */
class DatabaseQueueInspectorTest extends QueueInspectorContractTestCase
{
	protected function createQueue(): IQueue
	{
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );

		$pdo->exec( "
			CREATE TABLE jobs (
				id TEXT PRIMARY KEY,
				queue TEXT NOT NULL,
				payload TEXT NOT NULL,
				attempts INTEGER DEFAULT 0,
				reserved_at INTEGER NULL,
				available_at INTEGER NOT NULL,
				created_at INTEGER NOT NULL
			)
		" );

		$pdo->exec( "
			CREATE TABLE failed_jobs (
				id TEXT PRIMARY KEY,
				queue TEXT NOT NULL,
				payload TEXT NOT NULL,
				exception TEXT NOT NULL,
				failed_at INTEGER NOT NULL
			)
		" );

		return new DatabaseQueue( [ 'retry_after' => 90 ], $pdo );
	}

	public function testUnreadablePayloadRowsAreSkippedNotFatal(): void
	{
		$pdo = $this->queue->getConnection();

		$pdo->exec( "INSERT INTO jobs (id, queue, payload, attempts, reserved_at, available_at, created_at)
			VALUES ('bad', 'default', 'not json', 0, NULL, 0, 0)" );

		$good = $this->queue->push( new InspectorTestJob(), [] );

		$this->assertSame( 2, $this->queue->countJobs( 'default', \Neuron\Jobs\Queue\JobState::Pending ) );
		$this->assertSame( [ $good ], $this->ids( $this->queue->listJobs( 'default', \Neuron\Jobs\Queue\JobState::Pending ) ) );
		$this->assertNull( $this->queue->findJob( 'bad' ) );
	}
}
