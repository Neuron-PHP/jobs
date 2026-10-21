<?php

namespace Tests\Jobs\Queue;

use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\History\ArrayJobHistory;
use Neuron\Jobs\Queue\History\DatabaseJobHistory;
use Neuron\Jobs\Queue\History\IJobHistory;
use Neuron\Jobs\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class HistorySuccessJob implements IJob
{
	public function getName(): string
	{
		return 'HistorySuccessJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

class HistoryFailJob implements IJob
{
	public function getName(): string
	{
		return 'HistoryFailJob';
	}

	public function run( array $argv = [] ): mixed
	{
		throw new \RuntimeException( 'history boom' );
	}
}

class QueueManagerHistoryTest extends TestCase
{
	public function testHistoryDisabledByDefault(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );

		$this->assertNull( $manager->getHistory() );
		$this->assertNull( $manager->getHeartbeat() );
	}

	public function testArrayHistoryRecordsCompletedAndFailedRuns(): void
	{
		$manager = $this->arrayManager();

		$manager->dispatch( new HistorySuccessJob(), [ 'n' => 1 ] );
		$manager->processNextJob();

		$manager->dispatch( new HistoryFailJob() );
		$manager->processNextJob();

		$history = $manager->getHistory();
		$this->assertInstanceOf( ArrayJobHistory::class, $history );
		$this->assertSame( 2, $history->countRuns() );

		$completed = $history->listRuns( [ 'status' => IJobHistory::STATUS_COMPLETED ] );
		$this->assertCount( 1, $completed );
		$this->assertSame( HistorySuccessJob::class, $completed[0]['job_class'] );
		$this->assertStringContainsString( '"n":1', $completed[0]['payload'] );

		$failed = $history->listRuns( [ 'status' => IJobHistory::STATUS_FAILED ] );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'history boom', $failed[0]['exception'] );
	}

	public function testDatabaseHistoryUsesInjectedPdo(): void
	{
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		$manager = new QueueManager( null, [
			'driver' => 'array',
			'history' => 'database',
		], $pdo );

		$this->assertInstanceOf( DatabaseJobHistory::class, $manager->getHistory() );

		$manager->dispatch( new HistorySuccessJob() );
		$manager->processNextJob();

		$this->assertSame( 1, $manager->getHistory()->countRuns() );
	}

	public function testDispatchFromHistoryQueuesANewJob(): void
	{
		$manager = $this->arrayManager();
		$manager->dispatch( new HistorySuccessJob(), [ 'again' => true ] );
		$manager->processNextJob();

		$run = $manager->getHistory()->listRuns()[0];
		$newId = $manager->dispatchFromHistory( $run['id'] );

		$this->assertNotNull( $newId );
		$this->assertNotSame( $run['job_id'], $newId );

		$waiting = $manager->listJobs( 'default', \Neuron\Jobs\Queue\JobState::Pending );
		$this->assertCount( 1, $waiting );
		$this->assertSame( [ 'again' => true ], $waiting[0]->getArguments() );

		$manager->processNextJob();
		$this->assertSame( 2, $manager->getHistory()->countRuns( [ 'status' => IJobHistory::STATUS_COMPLETED ] ) );
	}

	public function testDispatchFromHistoryReturnsNullWhenMissing(): void
	{
		$manager = $this->arrayManager();

		$this->assertNull( $manager->dispatchFromHistory( 'nope' ) );
	}

	public function testSetHistoryOverridesConfiguredStore(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );
		$history = new ArrayJobHistory();
		$manager->setHistory( $history );

		$manager->dispatch( new HistorySuccessJob() );
		$manager->processNextJob();

		$this->assertSame( 1, $history->countRuns() );
	}

	private function arrayManager(): QueueManager
	{
		return new QueueManager( null, [
			'driver' => 'array',
			'default_queue' => 'default',
			'history' => 'array',
			'max_attempts' => 1,
		] );
	}
}
