<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Jobs\Cli\Commands\RetryCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class RetryCommandTest extends TestCase
{
	private RetryCommand $command;
	private Output $output;
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		$this->command = new RetryCommand();
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
		$this->assertEquals('jobs:retry', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('Retry one or more failed jobs', $this->command->getDescription());
	}

	public function testConfigure(): void
	{
		$this->command->configure();

		$options = $this->command->getOptions();

		$this->assertArrayHasKey('all', $options);
		$this->assertFalse($options['all']['hasValue']);
		$this->assertEquals('a', $options['all']['shortcut']);
	}

	public function testExecuteWithAllFlag(): void
	{
		$this->queueManager->expects($this->once())
			->method('retryAllFailedJobs')
			->willReturn(5);

		$command = new RetryCommand();
		$command->setInput(new Input(['--all']));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithSpecificJobIdSuccess(): void
	{
		$this->queueManager->expects($this->once())
			->method('retryFailedJob')
			->with('job-123')
			->willReturn(true);

		$command = new RetryCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute(['job-123']);

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithSpecificJobIdNotFound(): void
	{
		$this->queueManager->expects($this->once())
			->method('retryFailedJob')
			->with('job-999')
			->willReturn(false);

		$command = new RetryCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute(['job-999']);

		$this->assertEquals(1, $exitCode);
	}

	public function testExecuteWithoutJobIdOrAllFlag(): void
	{
		$command = new RetryCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(1, $exitCode);
	}
}
