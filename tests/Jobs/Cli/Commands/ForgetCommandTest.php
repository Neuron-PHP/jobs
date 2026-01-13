<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Jobs\Cli\Commands\ForgetCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class ForgetCommandTest extends TestCase
{
	private ForgetCommand $command;
	private Output $output;
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		$this->command = new ForgetCommand();
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
		$this->assertEquals('jobs:forget', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('Delete a failed job', $this->command->getDescription());
	}

	public function testExecuteWithoutJobId(): void
	{
		$command = new ForgetCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(1, $exitCode);
	}

	public function testExecuteWithValidJobId(): void
	{
		$this->queueManager->expects($this->once())
			->method('forgetFailedJob')
			->with('job-123')
			->willReturn(true);

		$command = new ForgetCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute(['job-123']);

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithInvalidJobId(): void
	{
		$this->queueManager->expects($this->once())
			->method('forgetFailedJob')
			->with('job-999')
			->willReturn(false);

		$command = new ForgetCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute(['job-999']);

		$this->assertEquals(1, $exitCode);
	}
}
