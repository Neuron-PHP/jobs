<?php

namespace Tests\Jobs\Cli\Commands;

use Neuron\Cli\Console\Input;
use Neuron\Cli\Console\Output;
use Neuron\Jobs\Cli\Commands\WorkCommand;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for WorkCommand.
 *
 * Tests the queue worker CLI command with various configurations.
 */
class WorkCommandTest extends TestCase
{
	private QueueManager $queueManager;
	private Registry $registry;

	protected function setUp(): void
	{
		parent::setUp();

		// Create mock QueueManager that processes no jobs (returns false immediately)
		$this->queueManager = $this->createMock(QueueManager::class);
		$this->queueManager
			->method('processNextJob')
			->willReturn(false);

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

	public function testGetName(): void
	{
		$command = new WorkCommand();
		$this->assertEquals('jobs:work', $command->getName());
	}

	public function testGetDescription(): void
	{
		$command = new WorkCommand();
		$description = $command->getDescription();
		$this->assertIsString($description);
		$this->assertNotEmpty($description);
		$this->assertStringContainsString('queue', strtolower($description));
	}

	public function testConfigure(): void
	{
		$command = new WorkCommand();
		$command->configure();

		$reflection = new \ReflectionClass($command);
		$optionsProperty = $reflection->getProperty('options');
		$options = $optionsProperty->getValue($command);

		$this->assertArrayHasKey('queue', $options);
		$this->assertArrayHasKey('once', $options);
		$this->assertArrayHasKey('stop-when-empty', $options);
		$this->assertArrayHasKey('sleep', $options);
		$this->assertArrayHasKey('max-jobs', $options);
		$this->assertArrayHasKey('timeout', $options);
	}

	public function testExecuteWithDefaultOptions(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('once', true); // Use once mode to exit immediately
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithCustomQueue(): void
	{
		$this->queueManager
			->expects($this->once())
			->method('processNextJob')
			->with('emails')
			->willReturn(false);

		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('queue', 'emails');
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithMultipleQueues(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('queue', 'high,default,low');
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithCustomSleep(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('sleep', '5');
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithMaxJobs(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('max-jobs', '10');
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithCustomTimeout(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('timeout', '120');
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithStopWhenEmpty(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('stop-when-empty', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithOnceMode(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testExecuteWithAllCustomOptions(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$input = new Input();
		$input->setOption('queue', 'emails,notifications');
		$input->setOption('sleep', '10');
		$input->setOption('max-jobs', '50');
		$input->setOption('timeout', '180');
		$input->setOption('once', true);
		$output = new Output();
		$this->injectInputOutput($command, $input, $output);

		$exitCode = $command->execute();

		$this->assertEquals(0, $exitCode);
	}

	public function testGetHelp(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$help = $command->getHelp();

		$this->assertIsString($help);
		$this->assertNotEmpty($help);
		$this->assertStringContainsString('Examples:', $help);
		$this->assertStringContainsString('jobs:work', $help);
		$this->assertStringContainsString('--once', $help);
		$this->assertStringContainsString('--queue', $help);
	}

	public function testGetHelpIncludesAllOptions(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$help = $command->getHelp();

		$this->assertStringContainsString('--sleep', $help);
		$this->assertStringContainsString('--max-jobs', $help);
		$this->assertStringContainsString('--stop-when-empty', $help);
	}

	public function testGetHelpIncludesMultipleExamples(): void
	{
		$command = new WorkCommand();
		$command->configure();
		$help = $command->getHelp();

		// Should have multiple example usages
		$this->assertGreaterThan(5, substr_count($help, 'neuron jobs:work'));
	}
}
