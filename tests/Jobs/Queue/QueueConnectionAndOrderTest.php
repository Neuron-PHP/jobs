<?php

namespace Tests\Jobs\Queue;

use Neuron\Data\Settings\Source\Memory;
use Neuron\Jobs\IJob;
use Neuron\Jobs\Queue\ArrayQueue;
use Neuron\Jobs\Queue\DatabaseQueue;
use Neuron\Jobs\Queue\IUniqueJob;
use Neuron\Jobs\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class OrderTestJob implements IJob
{
	public function getName(): string
	{
		return 'OrderTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

class UniqueTestJob implements IJob, IUniqueJob
{
	public function getName(): string
	{
		return 'UniqueTestJob';
	}

	public function run( array $argv = [] ): mixed
	{
		return true;
	}
}

/**
 * queue.connection and the table names, FIFO order within a second, and unique
 * jobs.
 */
class QueueConnectionAndOrderTest extends TestCase
{
	private function tables( \PDO $pdo, string $jobs = 'jobs', string $failed = 'failed_jobs' ): void
	{
		$pdo->exec( "CREATE TABLE $jobs (id TEXT PRIMARY KEY, queue TEXT NOT NULL, payload TEXT NOT NULL,
			attempts INTEGER DEFAULT 0, reserved_at INTEGER NULL, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)" );
		$pdo->exec( "CREATE TABLE $failed (id TEXT PRIMARY KEY, queue TEXT NOT NULL, payload TEXT NOT NULL,
			exception TEXT NOT NULL, failed_at INTEGER NOT NULL)" );
	}

	private function sqlite(): \PDO
	{
		$pdo = new \PDO( 'sqlite::memory:' );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );

		return $pdo;
	}

	public function testQueueConnectionNamesTheSectionTheDatabaseDriverUses(): void
	{
		$manager = new QueueManager( new Memory( [
			'queue'          => [
				'driver'            => 'database',
				'connection'        => 'state',
				'jobs_table'        => 'worker_jobs',
				'failed_jobs_table' => 'worker_failed_jobs',
			],
			// Would throw if it were used.
			'database'       => [ 'adapter' => 'oracle' ],
			'state'          => [ 'url' => 'sqlite::memory:' ],
		] ) );

		$config = $manager->getConfig();

		$this->assertSame( 'sqlite::memory:', $config['database']['url'] );
		$this->assertSame( 'worker_jobs', $config['database']['jobs_table'] );
		$this->assertSame( 'worker_failed_jobs', $config['database']['failed_jobs_table'] );
		$this->assertInstanceOf( DatabaseQueue::class, $manager->getDriver() );
	}

	/**
	 * DATABASE_URL is the `database` section's. A queue on a named connection
	 * must not follow it there.
	 */
	public function testANamedConnectionIgnoresDatabaseUrl(): void
	{
		$file = tempnam( sys_get_temp_dir(), 'queue' );
		$pdo  = new \PDO( 'sqlite:' . $file );
		$this->tables( $pdo );

		putenv( 'DATABASE_URL=mysql://nobody:nothing@127.0.0.1:1/platform' );

		try
		{
			$manager = new QueueManager( new Memory( [
				'queue' => [ 'driver' => 'database', 'connection' => 'state' ],
				'state' => [ 'url' => 'sqlite:' . $file ],
			] ) );

			$manager->dispatch( new OrderTestJob() );
		}
		finally
		{
			putenv( 'DATABASE_URL' );
		}

		$this->assertSame( 1, (int)$pdo->query( 'SELECT COUNT(*) FROM jobs' )->fetchColumn() );

		unlink( $file );
	}

	public function testWithoutQueueConnectionTheDatabaseSectionIsUsed(): void
	{
		$manager = new QueueManager( new Memory( [
			'queue'    => [ 'driver' => 'database' ],
			'database' => [ 'url' => 'sqlite::memory:' ],
		] ) );

		$this->assertSame( 'sqlite::memory:', $manager->getConfig()['database']['url'] );
		$this->assertArrayNotHasKey( 'jobs_table', $manager->getConfig()['database'] );
	}

	public function testTheConfiguredTablesAreTheOnesWrittenTo(): void
	{
		$pdo = $this->sqlite();
		$this->tables( $pdo, 'worker_jobs', 'worker_failed_jobs' );

		$queue = new DatabaseQueue( [ 'jobs_table' => 'worker_jobs', 'failed_jobs_table' => 'worker_failed_jobs' ], $pdo );
		$queue->push( new OrderTestJob() );

		$this->assertSame( 1, (int)$pdo->query( 'SELECT COUNT(*) FROM worker_jobs' )->fetchColumn() );
	}

	/**
	 * available_at has one-second resolution, so jobs pushed within the same
	 * second used to come out in whatever order the database chose.
	 */
	public function testJobsPushedInTheSameSecondComeOutInPushOrder(): void
	{
		$pdo = $this->sqlite();
		$this->tables( $pdo );

		$queue = new DatabaseQueue( [], $pdo );

		foreach( range( 1, 20 ) as $n )
		{
			$queue->push( new OrderTestJob(), [ 'n' => $n ] );
		}

		// Same second for all, and stored in reverse, so neither the clock nor
		// insertion order can be what puts them right.
		$pdo->exec( 'UPDATE jobs SET available_at = 1000, created_at = 1000' );
		$rows = $pdo->query( 'SELECT * FROM jobs ORDER BY id DESC' )->fetchAll( \PDO::FETCH_ASSOC );
		$pdo->exec( 'DELETE FROM jobs' );

		$insert = $pdo->prepare( 'INSERT INTO jobs VALUES (:id, :queue, :payload, :attempts, :reserved_at, :available_at, :created_at)' );

		foreach( $rows as $row )
		{
			$insert->execute( $row );
		}

		$order = [];

		while( $job = $queue->pop() )
		{
			$order[] = $job->getArguments()['n'];
		}

		$this->assertSame( range( 1, 20 ), $order );
	}

	public function testAUniqueJobAlreadyPendingIsNotQueuedAgain(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );

		$first  = $manager->dispatch( new UniqueTestJob(), [ 'group' => 'payout' ], 'monitors' );
		$second = $manager->dispatch( new UniqueTestJob(), [ 'group' => 'payout' ], 'monitors' );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $manager->size( 'monitors' ) );
	}

	public function testOtherArgumentsOrAnotherQueueAreNotTheSameJob(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );

		$manager->dispatch( new UniqueTestJob(), [ 'group' => 'payout' ], 'monitors' );
		$manager->dispatch( new UniqueTestJob(), [ 'group' => 'settlement' ], 'monitors' );
		$manager->dispatch( new UniqueTestJob(), [ 'group' => 'payout' ], 'other' );

		$this->assertSame( 2, $manager->size( 'monitors' ) );
		$this->assertSame( 1, $manager->size( 'other' ) );
	}

	/**
	 * A run that is already under way does not stand in for one asked for after
	 * it started.
	 */
	public function testAUniqueJobThatIsRunningDoesNotBlockTheNextOne(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );

		$first = $manager->dispatch( new UniqueTestJob(), [], 'monitors' );
		$manager->getDriver()->pop( 'monitors' );

		$this->assertNotSame( $first, $manager->dispatch( new UniqueTestJob(), [], 'monitors' ) );
	}

	public function testOrdinaryJobsAreQueuedEveryTime(): void
	{
		$manager = new QueueManager( null, [ 'driver' => 'array' ] );

		$manager->dispatch( new OrderTestJob(), [], 'monitors' );
		$manager->dispatch( new OrderTestJob(), [], 'monitors' );

		$this->assertSame( 2, $manager->size( 'monitors' ) );
	}

	public function testTheDatabaseDriverFindsAPendingJobByClassArgumentsAndQueue(): void
	{
		$pdo = $this->sqlite();
		$this->tables( $pdo );

		$queue = new DatabaseQueue( [], $pdo );
		$id    = $queue->push( new UniqueTestJob(), [ 'group' => 'payout', 'n' => 1 ], 'monitors' );

		$this->assertSame( $id, $queue->findPending( UniqueTestJob::class, [ 'group' => 'payout', 'n' => 1 ], 'monitors' ) );
		$this->assertNull( $queue->findPending( UniqueTestJob::class, [ 'group' => 'payout', 'n' => '1' ], 'monitors' ), 'a string is not the int it looks like' );
		$this->assertNull( $queue->findPending( UniqueTestJob::class, [ 'group' => 'payout', 'n' => 1 ], 'default' ) );
		$this->assertNull( $queue->findPending( OrderTestJob::class, [ 'group' => 'payout', 'n' => 1 ], 'monitors' ) );

		$queue->pop( 'monitors' );

		$this->assertNull( $queue->findPending( UniqueTestJob::class, [ 'group' => 'payout', 'n' => 1 ], 'monitors' ), 'reserved is not pending' );
	}
}
