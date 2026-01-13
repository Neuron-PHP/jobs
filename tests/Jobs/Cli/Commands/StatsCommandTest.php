<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Jobs\Cli\Commands\StatsCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class StatsCommandTest extends TestCase
{
	private StatsCommand $command;
	private Output $output;
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		$this->command = new StatsCommand();
		$this->output = new Output(false); // No colors in tests
		$this->command->setOutput($this->output);

		// Create mock QueueManager
		$this->queueManager = $this->createMock(QueueManager::class);

		// Set up Registry with mocked QueueManager
		$this->registry = Registry::getInstance();
		$this->registry->set('queue.manager', $this->queueManager);
	}


	public function testGetName(): void
	{
		$this->assertEquals('jobs:stats', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('Show queue statistics', $this->command->getDescription());
	}

	public function testConfigure(): void
	{
		$this->command->configure();

		$options = $this->command->getOptions();

		$this->assertArrayHasKey('queue', $options);
		$this->assertTrue($options['queue']['hasValue']);
		$this->assertEquals('Q', $options['queue']['shortcut']);
		$this->assertEquals('default', $options['queue']['default']);
	}

	public function testExecuteWithDefaultQueue(): void
	{
		$this->queueManager->expects($this->once())
			->method('size')
			->with('default')
			->willReturn(5);

		$this->queueManager->expects($this->once())
			->method('getFailedJobs')
			->willReturn([]);

		$command = new StatsCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithMultipleQueues(): void
	{
		$this->queueManager->expects($this->exactly(3))
			->method('size')
			->willReturnCallback(function($queue) {
				return match($queue) {
					'high' => 10,
					'default' => 5,
					'low' => 2,
					default => 0
				};
			});

		$this->queueManager->expects($this->once())
			->method('getFailedJobs')
			->willReturn(['job1', 'job2', 'job3']);

		$input = new Input(['--queue=high,default,low']);
		$command = new StatsCommand();
		$command->setInput($input);
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithFailedJobs(): void
	{
		$this->queueManager->expects($this->once())
			->method('size')
			->willReturn(0);

		$failedJobs = [
			['id' => 1, 'error' => 'Error 1'],
			['id' => 2, 'error' => 'Error 2']
		];

		$this->queueManager->expects($this->once())
			->method('getFailedJobs')
			->willReturn($failedJobs);

		$command = new StatsCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}
}
