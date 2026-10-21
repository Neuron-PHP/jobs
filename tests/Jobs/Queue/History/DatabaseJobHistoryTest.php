<?php

namespace Tests\Jobs\Queue\History;

use Neuron\Jobs\Queue\History\DatabaseJobHistory;
use Neuron\Jobs\Queue\History\IJobHistory;
use PHPUnit\Framework\TestCase;

class DatabaseJobHistoryTest extends TestCase
{
	private DatabaseJobHistory $_history;

	protected function setUp(): void
	{
		parent::setUp();

		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->_history = new DatabaseJobHistory( $pdo, 'job_runs', 60 );
	}

	public function testCreatesTableAndRecords(): void
	{
		$this->_history->record( $this->sample( 'r1', 'completed' ) );

		$found = $this->_history->findRun( 'r1' );

		$this->assertNotNull( $found );
		$this->assertSame( 'App\\One', $found['job_class'] );
		$this->assertSame( IJobHistory::STATUS_COMPLETED, $found['status'] );
		$this->assertSame( 1, $found['attempts'] );
	}

	public function testListFiltersAndPercentiles(): void
	{
		$this->_history->record( $this->sample( 'r1', 'completed', 'default', 'App\\One', time() - 10, 10 ) );
		$this->_history->record( $this->sample( 'r2', 'failed', 'mail', 'App\\Two', time() - 5, 20 ) );
		$this->_history->record( $this->sample( 'r3', 'completed', 'mail', 'App\\One', time(), 30 ) );

		$this->assertSame( 2, $this->_history->countRuns( [ 'status' => 'completed' ] ) );
		$this->assertSame( [ 'App\\One', 'App\\Two' ], $this->_history->classes() );
		$this->assertSame( [ 'default', 'mail' ], $this->_history->queues() );

		$counts = $this->_history->countsByStatus( [ 'queue' => 'mail' ] );
		$this->assertSame( 1, $counts['completed'] );
		$this->assertSame( 1, $counts['failed'] );

		$pct = $this->_history->durationPercentiles( [ 'class' => 'App\\One' ] );
		$this->assertSame( 2, $pct['count'] );
		$this->assertSame( 10, $pct['p50'] );
		$this->assertSame( 30, $pct['p95'] );
	}

	public function testPruneRemovesOldRuns(): void
	{
		$this->_history->record( $this->sample( 'old', 'completed', 'default', 'App\\One', time() - 120 ) );
		$this->_history->record( $this->sample( 'new', 'completed', 'default', 'App\\One', time() ) );

		$this->assertSame( 1, $this->_history->prune() );
		$this->assertNull( $this->_history->findRun( 'old' ) );
		$this->assertNotNull( $this->_history->findRun( 'new' ) );
	}

	public function testMissingRunReturnsNull(): void
	{
		$this->assertNull( $this->_history->findRun( 'nope' ) );
	}

	private function sample(
		string $id,
		string $status,
		string $queue = 'default',
		string $class = 'App\\One',
		int $finishedAt = 0,
		int $duration = 10
	): array
	{
		return [
			'id' => $id,
			'job_id' => 'job_' . $id,
			'job_class' => $class,
			'queue' => $queue,
			'payload' => '{"class":"App\\\\One","args":[]}',
			'status' => $status,
			'exception' => $status === 'failed' ? 'boom' : null,
			'started_at' => ( $finishedAt ?: time() ) - 1,
			'finished_at' => $finishedAt ?: time(),
			'duration_ms' => $duration,
			'attempts' => 1,
		];
	}
}
