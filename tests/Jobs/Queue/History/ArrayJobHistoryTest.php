<?php

namespace Tests\Jobs\Queue\History;

use Neuron\Jobs\Queue\History\ArrayJobHistory;
use Neuron\Jobs\Queue\History\IJobHistory;
use PHPUnit\Framework\TestCase;

class ArrayJobHistoryTest extends TestCase
{
	private ArrayJobHistory $_history;

	protected function setUp(): void
	{
		parent::setUp();
		$this->_history = new ArrayJobHistory();
	}

	public function testRecordAndFind(): void
	{
		$this->_history->record( $this->sample( 'r1', 'completed', 'default', 'App\\One' ) );

		$found = $this->_history->findRun( 'r1' );

		$this->assertNotNull( $found );
		$this->assertSame( 'r1', $found['id'] );
		$this->assertSame( IJobHistory::STATUS_COMPLETED, $found['status'] );
	}

	public function testListFiltersAndPages(): void
	{
		$this->_history->record( $this->sample( 'r1', 'completed', 'default', 'App\\One', time() - 10 ) );
		$this->_history->record( $this->sample( 'r2', 'failed', 'mail', 'App\\Two', time() - 5 ) );
		$this->_history->record( $this->sample( 'r3', 'completed', 'mail', 'App\\One', time() ) );

		$this->assertSame( 2, $this->_history->countRuns( [ 'status' => 'completed' ] ) );
		$this->assertSame( 2, $this->_history->countRuns( [ 'queue' => 'mail' ] ) );
		$this->assertSame( 2, $this->_history->countRuns( [ 'class' => 'App\\One' ] ) );

		$page = $this->_history->listRuns( [], 1, 0 );
		$this->assertCount( 1, $page );
		$this->assertSame( 'r3', $page[0]['id'] );

		$this->assertSame( [ 'App\\One', 'App\\Two' ], $this->_history->classes() );
		$this->assertSame( [ 'default', 'mail' ], $this->_history->queues() );
	}

	public function testCountsAndPercentiles(): void
	{
		foreach( [ 10, 20, 30, 40, 50 ] as $i => $ms )
		{
			$this->_history->record( $this->sample( "c{$i}", 'completed', 'default', 'App\\One', time(), $ms ) );
		}

		$this->_history->record( $this->sample( 'f1', 'failed', 'default', 'App\\One', time(), 999 ) );

		$counts = $this->_history->countsByStatus();
		$this->assertSame( 5, $counts['completed'] );
		$this->assertSame( 1, $counts['failed'] );

		$pct = $this->_history->durationPercentiles();
		$this->assertSame( 5, $pct['count'] );
		$this->assertSame( 30, $pct['p50'] );
		$this->assertSame( 50, $pct['p95'] );
	}

	public function testPercentileEmpty(): void
	{
		$this->assertNull( ArrayJobHistory::percentile( [], 50 ) );
		$this->assertSame( [ 'p50' => null, 'p95' => null, 'count' => 0 ], $this->_history->durationPercentiles() );
	}

	public function testPruneRemovesOldRuns(): void
	{
		$history = new ArrayJobHistory( 60 );
		$history->record( $this->sample( 'old', 'completed', 'default', 'App\\One', time() - 120 ) );
		$history->record( $this->sample( 'new', 'completed', 'default', 'App\\One', time() ) );

		$this->assertSame( 1, $history->prune() );
		$this->assertNull( $history->findRun( 'old' ) );
		$this->assertNotNull( $history->findRun( 'new' ) );
	}

	private function sample(
		string $id,
		string $status,
		string $queue,
		string $class,
		int $finishedAt = 0,
		int $duration = 10
	): array
	{
		return [
			'id' => $id,
			'job_id' => 'job_' . $id,
			'job_class' => $class,
			'queue' => $queue,
			'payload' => '{"class":"' . addslashes( $class ) . '","args":[]}',
			'status' => $status,
			'exception' => $status === 'failed' ? 'boom' : null,
			'started_at' => ( $finishedAt ?: time() ) - 1,
			'finished_at' => $finishedAt ?: time(),
			'duration_ms' => $duration,
			'attempts' => 1,
		];
	}
}
