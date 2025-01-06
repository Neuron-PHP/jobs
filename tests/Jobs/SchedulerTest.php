<?php

namespace Jobs;

use Neuron\Data\Setting\Source\Ini;
use Neuron\Jobs\IJob;
use Neuron\Jobs\Scheduler;
use PHPUnit\Framework\TestCase;

class TestJob implements IJob
{
	public bool $Ran = false;
	public array $Args = [];

	public function getName() : string
	{
		return 'TestJob';
	}

	public function run( array $Argv = [] ) : mixed
	{
		$this->Ran = true;
		$this->Args = $Argv;
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

		$Ini = new Ini( './examples/config/config.ini' );
		$this->App = new Scheduler( "1.0.0", $Ini );
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

	public function testPoll()
	{
		$this->App->addJob( '* * * * *', new TestJob() );

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
}
