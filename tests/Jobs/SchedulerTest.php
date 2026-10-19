<?php

namespace Jobs;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\Ini;
use Neuron\Data\Settings\Source\Yaml;
use Neuron\Jobs\IJob;
use Neuron\Jobs\Scheduler;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class TestJob implements IJob
{
	public bool $Ran = false;
	public int $Runs = 0;
	public bool $Crash = false;
	public array $Args = [];

	public function getName() : string
	{
		return 'TestJob';
	}

	public function run( array $argv = [] ) : mixed
	{
		$this->Ran = true;
		$this->Runs++;
		$this->Args = $argv;
		return true;
	}
}

class SchedulerTest extends TestCase
{
	public Scheduler $App;

	/**
	 * @throws \Exception
	 */
	protected function setUp() : void
	{
		parent::setUp();

		Registry::getInstance()->set( RegistryKeys::SETTINGS, null );

		$Ini = new Yaml( './examples/config/neuron.yaml' );
		$this->App = new Scheduler( "1.0.0", $Ini );
	}

	public function testGetDescription()
	{
		$this->assertNotEmpty(
			$this->App->getDescription()
		);
	}

	public function testMissingSchedule()
	{
		$this->App->setConfigFile( 'missing.yaml' );
		$this->assertEmpty( $this->App->loadSchedule() );
	}

	public function testBadSchedule()
	{
		$this->App->setConfigFile( 'bad-schedule.yaml' );
		$this->assertEmpty( $this->App->loadSchedule() );

		$this->App->run( [ '--poll' ] );
	}

	public function testInterval()
	{
		$this->assertEquals(
			60,
			$this->App->getInterval()
		);

		$this->App->setInterval( 30 );

		$this->assertEquals(
			30,
			$this->App->getInterval()
		);
	}

	public function testAddJobNoArgs()
	{
		$this->App->addJob(
			'TestJob',
			'* * * * *',
			new TestJob()
		);

		$this->assertCount(
			1,
			$this->App->getJobs()
		);
	}

	public function testAddJobWithArgs()
	{
		$this->App->addJob(
			'TestJob',
			'* * * * *',
			new TestJob(),
			[
				'arg1' => true,
				'arg2' => false
			]
		);

		$this->assertCount(
			1,
			$this->App->getJobs()
		);

		$this->assertEquals(
			[
				'arg1' => true,
				'arg2' => false
			],
			$this->App->getJobs()[0]['args']
		);
	}

	public function testAddJobWithQueue()
	{
		$this->App->addJob(
			'TestJob',
			'* * * * *',
			new TestJob(),
			[
				'arg1' => true
			],
			'test-queue'
		);

		$this->assertCount(
			1,
			$this->App->getJobs()
		);

		$jobs = $this->App->getJobs();
		$this->assertEquals( 'test-queue', $jobs[0]['queue'] );
		$this->assertEquals( ['arg1' => true], $jobs[0]['args'] );
	}

	public function testAddJobWithoutQueue()
	{
		$this->App->addJob(
			'TestJob',
			'* * * * *',
			new TestJob()
		);

		$jobs = $this->App->getJobs();
		$this->assertNull( $jobs[0]['queue'] );
	}

	public function testPoll()
	{
		$this->App->addJob(
			'TestJob',
			'* * * * *',
			new TestJob()
		);

		$this->App->poll();
		$this->assertTrue(
			$this->App->getJobs()[0]['job']->Ran
		);
	}

	public function testSchedule()
	{
		$this->App->run( [ '--poll' ] );
		$this->assertTrue(
			$this->App->getJobs()[0]['job']->Ran
		);
	}

	public function testBootstrapBoot()
	{
		$cwd = getcwd();
		$App = Boot( getcwd().'/examples/config' );

		$this->assertInstanceOf(
			Scheduler::class,
			$App
		);
	}

	public function testBootstrapBootConfigFail()
	{
		$App = Boot( getcwd().'/examples/config1' );

		$this->assertInstanceOf(
			Scheduler::class,
			$App
		);
	}

	public function testBootstrapScheduler()
	{
		Registry::getInstance()->set( RegistryKeys::SETTINGS, null );

		$App = Boot( getcwd().'/examples/config' );
		Scheduler( $App, [ '--poll' ] );

		$this->assertTrue(
			$App->getJobs()[0]['job']->Ran
		);
	}

	public function testInfinitePolling()
	{
		Registry::getInstance()->set( RegistryKeys::SETTINGS, null );

		$App = Boot( getcwd().'/examples/config' );
		$App->setDebug( true );
		Scheduler(
			$App,
			[
				'--interval',
				'0'
			]
		);

		$this->assertTrue(
			$App->getJobs()[0]['job']->Ran
		);
	}

	public function testBootstrapSchedulerIntervalCommand()
	{
		Registry::getInstance()->set( RegistryKeys::SETTINGS, null );

		$App = Boot( getcwd().'/examples/config' );

		Scheduler(
			$App,
			[
				'--interval',
				'30',
				'--poll'
			]
		);

		$this->assertTrue(
			$App->getJobs()[0]['job']->Ran
		);
	}

	/**
	 * A poll at the given time, in the default timezone.
	 */
	private function pollAt( string $time ): int
	{
		return $this->App->poll( new \DateTimeImmutable( $time ) );
	}

	/**
	 * Jobs run one after another. Checked against the clock, a job behind a slow
	 * one was checked after its minute had passed, and skipped. Every job is now
	 * checked against the poll's own time, whatever the clock says.
	 */
	public function testEveryJobIsCheckedAgainstThePollsTime()
	{
		$Job = new TestJob();

		$this->App->addJob( 'TestJob', '15 10 * * *', $Job );

		$this->pollAt( '2026-10-19 10:15:00' );

		$this->assertSame( 1, $Job->Runs );
	}

	/**
	 * A poll that runs long steps over the next one's minute. That time still
	 * runs, late, on the next poll.
	 */
	public function testAScheduledTimeAPollSteppedOverRunsLate()
	{
		$Job = new TestJob();

		$this->App->addJob( 'TestJob', '15 * * * *', $Job );

		$this->pollAt( '2026-10-19 10:14:00' );
		$this->assertSame( 0, $Job->Runs );

		$this->pollAt( '2026-10-19 10:16:30' );
		$this->assertSame( 1, $Job->Runs, 'run late, not skipped' );

		$this->pollAt( '2026-10-19 10:17:00' );
		$this->assertSame( 1, $Job->Runs, 'and only once' );
	}

	/**
	 * However many times were missed, the job runs once.
	 */
	public function testManyMissedTimesRunOnce()
	{
		$Job = new TestJob();

		$this->App->addJob( 'TestJob', '*/15 * * * *', $Job );

		$this->pollAt( '2026-10-19 10:01:00' );
		$this->assertSame( 1, $this->pollAt( '2026-10-19 11:02:00' ) );
		$this->assertSame( 1, $Job->Runs );
	}

	/**
	 * The first poll has no previous one to catch up from, so a restart does not
	 * replay the schedule.
	 */
	public function testTheFirstPollDoesNotCatchUp()
	{
		$Job = new TestJob();

		$this->App->addJob( 'TestJob', '15 * * * *', $Job );

		$this->pollAt( '2026-10-19 10:16:00' );

		$this->assertSame( 0, $Job->Runs );
	}

	/**
	 * Two polls in the same minute run a job once.
	 */
	public function testAJobRunsOncePerScheduledMinute()
	{
		$Job = new TestJob();

		$this->App->addJob( 'TestJob', '* * * * *', $Job );

		$this->pollAt( '2026-10-19 10:15:01' );
		$this->pollAt( '2026-10-19 10:15:40' );
		$this->assertSame( 1, $Job->Runs );

		$this->pollAt( '2026-10-19 10:16:02' );
		$this->assertSame( 2, $Job->Runs );
	}
}
