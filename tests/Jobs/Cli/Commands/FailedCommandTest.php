<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Jobs\Cli\Commands\FailedCommand;
use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

class FailedCommandTest extends TestCase
{
	private FailedCommand $command;
	private Output $output;
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		$this->command = new FailedCommand();
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
		$this->assertEquals('jobs:failed', $this->command->getName());
	}

	public function testGetDescription(): void
	{
		$this->assertEquals('List all failed jobs', $this->command->getDescription());
	}

	public function testExecuteWithNoFailedJobs(): void
	{
		$this->queueManager->expects($this->once())
			->method('getFailedJobs')
			->willReturn([]);

		$command = new FailedCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithFailedJobs(): void
	{
		// Create mock failed jobs
		$failedJobs = [
			[
				'id' => 'job-123',
				'queue' => 'default',
				'payload' => json_encode(['class' => 'App\\Jobs\\EmailJob']),
				'failed_at' => time(),
				'exception' => 'SMTP connection failed'
			],
			[
				'id' => 'job-456',
				'queue' => 'high',
				'payload' => json_encode(['class' => 'App\\Jobs\\ProcessPayment']),
				'failed_at' => time() - 3600,
				'exception' => 'Payment gateway timeout'
			]
		];

		$this->queueManager->expects($this->once())
			->method('getFailedJobs')
			->willReturn($failedJobs);

		$command = new FailedCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithMalformedPayload(): void
	{
		// Create mock failed job with malformed payload
		$failedJobs = [
			[
				'id' => 'job-789',
				'queue' => 'default',
				'payload' => 'invalid-json',
				'failed_at' => time(),
				'exception' => 'Job processing failed'
			]
		];

		$this->queueManager->expects($this->once())
			->method('getFailedJobs')
			->willReturn($failedJobs);

		$command = new FailedCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		// Should still succeed even with malformed payload
		$this->assertEquals(0, $exitCode);
	}
}
