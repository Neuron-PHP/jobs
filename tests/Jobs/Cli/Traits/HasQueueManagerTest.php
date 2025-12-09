<?php

namespace Tests\Jobs\Cli\Traits;

use Neuron\Jobs\Cli\Traits\HasQueueManager;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Patterns\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Test class that uses the HasQueueManager trait.
 */
class TestClassWithQueueManager
{
	use HasQueueManager;

	public function exposeGetQueueManager(): ?QueueManager
	{
		return $this->getQueueManager();
	}
}

/**
 * Tests for HasQueueManager trait.
 *
 * Tests getting or initializing the QueueManager from registry.
 */
class HasQueueManagerTest extends TestCase
{
	private Registry $registry;

	protected function setUp(): void
	{
		parent::setUp();
		$this->registry = Registry::getInstance();
	}

	protected function tearDown(): void
	{
		// Registry is a singleton, no cleanup needed
		parent::tearDown();
	}

	public function testGetQueueManagerReturnsExistingInstance(): void
	{
		$mockQueueManager = $this->createMock(QueueManager::class);
		$this->registry->set('queue.manager', $mockQueueManager);

		$testClass = new TestClassWithQueueManager();
		$result = $testClass->exposeGetQueueManager();

		$this->assertSame($mockQueueManager, $result);
	}

	public function testGetQueueManagerCreatesNewInstanceWhenNotInRegistry(): void
	{
		// Make sure there's no existing queue manager
		$this->registry->set('queue.manager', null);
		$this->registry->set('settings', null);

		$testClass = new TestClassWithQueueManager();
		$result = $testClass->exposeGetQueueManager();

		$this->assertInstanceOf(QueueManager::class, $result);

		// Verify it was stored in the registry
		$storedManager = $this->registry->get('queue.manager');
		$this->assertSame($result, $storedManager);
	}

	public function testGetQueueManagerReturnsSameInstanceOnMultipleCalls(): void
	{
		$this->registry->set('queue.manager', null);
		$this->registry->set('settings', null);

		$testClass = new TestClassWithQueueManager();

		$result1 = $testClass->exposeGetQueueManager();
		$result2 = $testClass->exposeGetQueueManager();

		$this->assertSame($result1, $result2);
	}
}
