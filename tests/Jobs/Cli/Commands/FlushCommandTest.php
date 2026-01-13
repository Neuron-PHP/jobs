<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Jobs\Cli\Commands\FlushCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class FlushCommandTest extends TestCase
{
	private FlushCommand $command;
	private Output $output;
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		$this->command = new FlushCommand();
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
		$this->assertEquals('jobs:flush', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('Flush queue or failed jobs', $this->command->getDescription());
	}

	public function testConfigure(): void
	{
		$this->command->configure();

		$options = $this->command->getOptions();

		$this->assertArrayHasKey('queue', $options);
		$this->assertTrue($options['queue']['hasValue']);
		$this->assertEquals('Q', $options['queue']['shortcut']);
		$this->assertEquals('default', $options['queue']['default']);

		$this->assertArrayHasKey('failed', $options);
		$this->assertFalse($options['failed']['hasValue']);
		$this->assertEquals('f', $options['failed']['shortcut']);
	}

	public function testExecuteWithFailedFlag(): void
	{
		$this->queueManager->expects($this->once())
			->method('clearFailedJobs')
			->willReturn(10);

		$command = new FlushCommand();
		$command->setInput(new Input(['--failed']));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithDefaultQueue(): void
	{
		$this->queueManager->expects($this->once())
			->method('clear')
			->with('default')
			->willReturn(5);

		$command = new FlushCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithCustomQueue(): void
	{
		$this->queueManager->expects($this->once())
			->method('clear')
			->with('high')
			->willReturn(3);

		$command = new FlushCommand();
		$command->setInput(new Input(['--queue=high']));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}
}
