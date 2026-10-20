<?php

namespace Tests\Jobs\Cli\Traits;

use Neuron\Core\Registry\RegistryKeys;
use Neuron\Data\Settings\Source\Memory;
use Neuron\Jobs\Cli\Traits\HasQueueManager;
use Neuron\Jobs\Queue\DatabaseQueue;
use Neuron\Jobs\Queue\QueueManager;
use Neuron\Jobs\Queue\SyncQueue;
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
 * The trait must resolve the QueueManager from the same Registry key the
 * application stores its settings under (RegistryKeys::SETTINGS), otherwise
 * every CLI queue command silently falls back to the sync driver and the
 * worker never sees jobs the web process enqueued.
 */
class HasQueueManagerTest extends TestCase
{
	private Registry $registry;

	protected function setUp(): void
	{
		parent::setUp();
		$this->registry = Registry::getInstance();
		$this->registry->set( QueueManager::REGISTRY_KEY, null );
		$this->registry->set( RegistryKeys::SETTINGS, null );
	}

	protected function tearDown(): void
	{
		$this->registry->set( QueueManager::REGISTRY_KEY, null );
		$this->registry->set( RegistryKeys::SETTINGS, null );
		parent::tearDown();
	}

	public function testGetQueueManagerReturnsExistingInstance(): void
	{
		$mockQueueManager = $this->createMock( QueueManager::class );
		$this->registry->set( QueueManager::REGISTRY_KEY, $mockQueueManager );

		$testClass = new TestClassWithQueueManager();
		$result = $testClass->exposeGetQueueManager();

		$this->assertSame( $mockQueueManager, $result );
	}

	public function testGetQueueManagerCreatesNewInstanceWhenNotInRegistry(): void
	{
		$testClass = new TestClassWithQueueManager();
		$result = $testClass->exposeGetQueueManager();

		$this->assertInstanceOf( QueueManager::class, $result );
		$this->assertSame( $result, $this->registry->get( QueueManager::REGISTRY_KEY ) );
	}

	public function testGetQueueManagerReturnsSameInstanceOnMultipleCalls(): void
	{
		$testClass = new TestClassWithQueueManager();

		$result1 = $testClass->exposeGetQueueManager();
		$result2 = $testClass->exposeGetQueueManager();

		$this->assertSame( $result1, $result2 );
	}

	public function testGetQueueManagerDefaultsToSyncWithoutSettings(): void
	{
		$testClass = new TestClassWithQueueManager();

		$this->assertInstanceOf( SyncQueue::class, $testClass->exposeGetQueueManager()->getDriver() );
	}

	public function testGetQueueManagerUsesApplicationSettings(): void
	{
		$this->registry->set( RegistryKeys::SETTINGS, new Memory( [
			'queue' => [
				'driver' => 'database',
				'retry_after' => 45,
			],
			'database' => [
				'adapter' => 'sqlite',
				'name' => ':memory:',
			],
		] ) );

		$testClass = new TestClassWithQueueManager();
		$manager = $testClass->exposeGetQueueManager();

		$driver = $manager->getDriver();

		$this->assertInstanceOf( DatabaseQueue::class, $driver );
		$this->assertSame( 45, $driver->getRetryAfter() );
	}

	public function testGetQueueManagerIgnoresNonSettingObjectsInRegistry(): void
	{
		$this->registry->set( RegistryKeys::SETTINGS, new \stdClass() );

		$testClass = new TestClassWithQueueManager();

		$this->assertInstanceOf( SyncQueue::class, $testClass->exposeGetQueueManager()->getDriver() );
	}
}
