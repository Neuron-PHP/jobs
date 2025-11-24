<?php

namespace Jobs;

use Neuron\Data\Setting\Source\Ini;
use Neuron\Data\Setting\Source\Yaml;
use Neuron\Jobs\IJob;
use Neuron\Jobs\Scheduler;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class TestJob implements IJob
{
	public bool $Ran = false;
	public bool $Crash = false;
	public array $Args = [];

	public function getName() : string
	{
		return 'TestJob';
	}

	public function run( array $argv = [] ) : mixed
	{
		$this->Ran = true;
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

		Registry::getInstance()->set( 'Settings', null );

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
		Registry::getInstance()->set( 'Settings', null );

		$App = Boot( getcwd().'/examples/config' );
		Scheduler( $App, [ '--poll' ] );

		$this->assertTrue(
			$App->getJobs()[0]['job']->Ran
		);
	}

	public function testInfinitePolling()
	{
		Registry::getInstance()->set( 'Settings', null );

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
		Registry::getInstance()->set( 'Settings', null );

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
}
