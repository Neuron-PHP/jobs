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
		$this->queueManager
			->method('countFailedJobs')
			->willReturn(0);

		$this->queueManager
			->method('listFailedJobs')
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

		$this->queueManager
			->method('countFailedJobs')
			->willReturn(count($failedJobs));

		$this->queueManager
			->method('listFailedJobs')
			->willReturn($failedJobs);

		$command = new FailedCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testPassesQueueLimitAndOffsetThrough(): void
	{
		$this->queueManager->expects($this->once())
			->method('countFailedJobs')
			->with('emails')
			->willReturn(5);

		$this->queueManager->expects($this->once())
			->method('listFailedJobs')
			->with(2, 2, 'emails')
			->willReturn([
				[ 'id' => 'job-3', 'queue' => 'emails', 'payload' => '{"class":"X"}', 'failed_at' => time(), 'exception' => 'e' ],
				[ 'id' => 'job-4', 'queue' => 'emails', 'payload' => '{"class":"X"}', 'failed_at' => time(), 'exception' => 'e' ],
			]);

		$command = new FailedCommand();
		$command->configure();
		$input = new Input([]);
		$input->setOption('queue', 'emails');
		$input->setOption('limit', '2');
		$input->setOption('offset', '2');
		$command->setInput($input);
		$command->setOutput($this->output);

		ob_start();
		$exitCode = $command->execute();
		$out = ob_get_clean();

		$this->assertEquals(0, $exitCode);
		$this->assertStringContainsString('Failed Jobs (5)', $out);
		$this->assertStringContainsString('Showing 3-4 of 5. Next: --offset=4', $out);
	}

	public function testNoFailedJobsOnNamedQueue(): void
	{
		$this->queueManager->method('countFailedJobs')->with('emails')->willReturn(0);
		$this->queueManager->expects($this->never())->method('listFailedJobs');

		$command = new FailedCommand();
		$command->configure();
		$input = new Input([]);
		$input->setOption('queue', 'emails');
		$command->setInput($input);
		$command->setOutput($this->output);

		ob_start();
		$exitCode = $command->execute();
		$out = ob_get_clean();

		$this->assertEquals(0, $exitCode);
		$this->assertStringContainsString("No failed jobs on 'emails'", $out);
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

		$this->queueManager
			->method('countFailedJobs')
			->willReturn(count($failedJobs));

		$this->queueManager
			->method('listFailedJobs')
			->willReturn($failedJobs);

		$command = new FailedCommand();
		$command->setInput(new Input([]));
		$command->setOutput($this->output);

		$exitCode = $command->execute();

		// Should still succeed even with malformed payload
		$this->assertEquals(0, $exitCode);
	}
}
