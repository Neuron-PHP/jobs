<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Cli\Commands\FailedCommand;
use Neuron\Jobs\Cli\Commands\FlushCommand;
use Neuron\Jobs\Cli\Commands\ForgetCommand;
use Neuron\Jobs\Cli\Commands\RetryCommand;
use Neuron\Jobs\Cli\Commands\StatsCommand;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for CLI commands.
 *
 * Tests Failed, Flush, Forget, Retry, and Stats commands with mocked QueueManager.
 */
class CliCommandsTest extends TestCase
{
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		parent::setUp();

		// Create mock QueueManager
		$this->queueManager = $this->createMock(QueueManager::class);

		// Set up Registry with mocked QueueManager
		$this->registry = Registry::getInstance();
		$this->registry->set('queue.manager', $this->queueManager);
	}

	protected function tearDown(): void
	{
		// Registry is a singleton, no cleanup needed as setUp() will overwrite
		parent::tearDown();
	}

	/**
	 * Helper method to inject input/output into a command.
	 */
	private function injectInputOutput($command, Input $input, Output $output): void
	{
		$reflection = new \ReflectionClass($command);

		$inputProperty = $reflection->getProperty('input');
		$inputProperty->setValue($command, $input);

		$outputProperty = $reflection->getProperty('output');
		$outputProperty->setValue($command, $output);
	}

	// ========================================================================
	// FailedCommand Tests
	// ========================================================================

	public function testFailedCommandGetName(): void
	{
		$command = new FailedCommand();
		$this->assertEquals('jobs:failed', $command->getName());
	}

	public function testFailedCommandGetDescription(): void
	{
		$command = new FailedCommand();
		$description = $command->getDescription();
		$this->assertIsString($description);
		$this->assertNotEmpty($description);
	}

	public function testFailedCommandConfigure(): void
	{
		$command = new FailedCommand();
		$command->configure();
		$this->assertTrue(true); // No exception means configure() worked
	}

	public function testFailedCommandExecuteWithNoFailedJobs(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('getFailedJobs')
			->willReturn([]);

		$command = new FailedCommand();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testFailedCommandExecuteWithFailedJobs(): void
	{
		$failedJobs = [
			[
				'id' => 'job_123',
				'queue' => 'emails',
				'payload' => json_encode(['class' => 'App\\Jobs\\SendEmailJob']),
				'failed_at' => time(),
				'exception' => 'Test exception'
			]
		];

		$this->queueManager
			->expects($this->once())
			->method('getFailedJobs')
			->willReturn($failedJobs);

		$command = new FailedCommand();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testFailedCommandExecuteWithMultipleFailedJobs(): void
	{
		$failedJobs = [
			[
				'id' => 'job_1',
				'queue' => 'emails',
				'payload' => json_encode(['class' => 'SendEmailJob']),
				'failed_at' => time(),
				'exception' => 'Exception 1'
			],
			[
				'id' => 'job_2',
				'queue' => 'notifications',
				'payload' => json_encode(['class' => 'SendNotificationJob']),
				'failed_at' => time(),
				'exception' => 'Exception 2'
			]
		];

		$this->queueManager
			->expects($this->once())
			->method('getFailedJobs')
			->willReturn($failedJobs);

		$command = new FailedCommand();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	// ========================================================================
	// FlushCommand Tests
	// ========================================================================

	public function testFlushCommandGetName(): void
	{
		$command = new FlushCommand();
		$this->assertEquals('jobs:flush', $command->getName());
	}

	public function testFlushCommandGetDescription(): void
	{
		$command = new FlushCommand();
		$description = $command->getDescription();
		$this->assertIsString($description);
		$this->assertNotEmpty($description);
	}

	public function testFlushCommandConfigure(): void
	{
		$command = new FlushCommand();
		$command->configure();

		$reflection = new \ReflectionClass($command);
		$optionsProperty = $reflection->getProperty('options');
		$options = $optionsProperty->getValue($command);

		$this->assertArrayHasKey('queue', $options);
		$this->assertArrayHasKey('failed', $options);
	}

	public function testFlushCommandExecuteQueue(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('clear')
			->with('default')
			->willReturn(5);

		$command = new FlushCommand();
		$command->configure();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testFlushCommandExecuteCustomQueue(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('clear')
			->with('emails')
			->willReturn(10);

		$command = new FlushCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('queue', 'emails');
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testFlushCommandExecuteFailedJobs(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('clearFailedJobs')
			->willReturn(3);

		$command = new FlushCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('failed', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	// ========================================================================
	// ForgetCommand Tests
	// ========================================================================

	public function testForgetCommandGetName(): void
	{
		$command = new ForgetCommand();
		$this->assertEquals('jobs:forget', $command->getName());
	}

	public function testForgetCommandGetDescription(): void
	{
		$command = new ForgetCommand();
		$description = $command->getDescription();
		$this->assertIsString($description);
		$this->assertNotEmpty($description);
	}

	public function testForgetCommandConfigure(): void
	{
		$command = new ForgetCommand();
		$command->configure();
		$this->assertTrue(true); // No exception means configure() worked
	}

	public function testForgetCommandExecuteWithoutJobId(): void
	{
		$command = new ForgetCommand();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute([]);

		$this->assertEquals(1, $exitCode);
	}

	public function testForgetCommandExecuteSuccess(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('forgetFailedJob')
			->with('job_123')
			->willReturn(true);

		$command = new ForgetCommand();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute(['job_123']);

		$this->assertEquals(0, $exitCode);
	}

	public function testForgetCommandExecuteFailure(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('forgetFailedJob')
			->with('job_999')
			->willReturn(false);

		$command = new ForgetCommand();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute(['job_999']);

		$this->assertEquals(1, $exitCode);
	}

	// ========================================================================
	// RetryCommand Tests
	// ========================================================================

	public function testRetryCommandGetName(): void
	{
		$command = new RetryCommand();
		$this->assertEquals('jobs:retry', $command->getName());
	}

	public function testRetryCommandGetDescription(): void
	{
		$command = new RetryCommand();
		$description = $command->getDescription();
		$this->assertIsString($description);
		$this->assertNotEmpty($description);
	}

	public function testRetryCommandConfigure(): void
	{
		$command = new RetryCommand();
		$command->configure();

		$reflection = new \ReflectionClass($command);
		$optionsProperty = $reflection->getProperty('options');
		$options = $optionsProperty->getValue($command);

		$this->assertArrayHasKey('all', $options);
	}

	public function testRetryCommandExecuteWithoutParameters(): void
	{
		$command = new RetryCommand();
		$command->configure();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute([]);

		$this->assertEquals(1, $exitCode);
	}

	public function testRetryCommandExecuteAllJobs(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('retryAllFailedJobs')
			->willReturn(5);

		$command = new RetryCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('all', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute([]);

		$this->assertEquals(0, $exitCode);
	}

	public function testRetryCommandExecuteSpecificJobSuccess(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('retryFailedJob')
			->with('job_456')
			->willReturn(true);

		$command = new RetryCommand();
		$command->configure();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute(['job_456']);

		$this->assertEquals(0, $exitCode);
	}

	public function testRetryCommandExecuteSpecificJobFailure(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('retryFailedJob')
			->with('job_999')
			->willReturn(false);

		$command = new RetryCommand();
		$command->configure();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute(['job_999']);

		$this->assertEquals(1, $exitCode);
	}

	// ========================================================================
	// StatsCommand Tests
	// ========================================================================

	public function testStatsCommandGetName(): void
	{
		$command = new StatsCommand();
		$this->assertEquals('jobs:stats', $command->getName());
	}

	public function testStatsCommandGetDescription(): void
	{
		$command = new StatsCommand();
		$description = $command->getDescription();
		$this->assertIsString($description);
		$this->assertNotEmpty($description);
	}

	public function testStatsCommandConfigure(): void
	{
		$command = new StatsCommand();
		$command->configure();

		$reflection = new \ReflectionClass($command);
		$optionsProperty = $reflection->getProperty('options');
		$options = $optionsProperty->getValue($command);

		$this->assertArrayHasKey('queue', $options);
	}

	public function testStatsCommandExecuteDefaultQueue(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('size')
			->with('default')
			->willReturn(10);

		$this->queueManager
			->expects($this->once())
			->method('getFailedJobs')
			->willReturn([]);

		$command = new StatsCommand();
		$command->configure();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testStatsCommandExecuteMultipleQueues(): void
	{
		$this->queueManager
			->expects($this->exactly(3))
			->method('size')
			->willReturnCallback(function($queue) {
				return match($queue) {
					'high' => 5,
					'default' => 10,
					'low' => 2
				};
			});

		$this->queueManager
			->expects($this->once())
			->method('getFailedJobs')
			->willReturn([['id' => '1'], ['id' => '2']]);

		$command = new StatsCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('queue', 'high,default,low');
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testStatsCommandExecuteWithFailedJobs(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('size')
			->willReturn(5);

		$failedJobs = [
			['id' => 'job_1'],
			['id' => 'job_2'],
			['id' => 'job_3']
		];

		$this->queueManager
			->expects($this->once())
			->method('getFailedJobs')
			->willReturn($failedJobs);

		$command = new StatsCommand();
		$command->configure();
		$input = new Input();
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}
}
