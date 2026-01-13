<?php

namespace Tests\Jobs\Cli;

use Neuron\Cli\Commands\Registry;
use Neuron\Jobs\Cli\Provider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CLI Provider.
 *
 * Tests command registration with the CLI registry.
 */
class ProviderTest extends TestCase
{
	public function testRegisterAddsAllCommands(): void
	{
		$registry = $this->createMock(Registry::class);

		// Expect register() to be called for each command
		$registry->expects($this->exactly(7))
			->method('register')
			->withConsecutive(
				['jobs:schedule', 'Neuron\\Jobs\\Cli\\Commands\\ScheduleCommand'],
				['jobs:work', 'Neuron\\Jobs\\Cli\\Commands\\WorkCommand'],
				['jobs:failed', 'Neuron\\Jobs\\Cli\\Commands\\FailedCommand'],
				['jobs:retry', 'Neuron\\Jobs\\Cli\\Commands\\RetryCommand'],
				['jobs:flush', 'Neuron\\Jobs\\Cli\\Commands\\FlushCommand'],
				['jobs:forget', 'Neuron\\Jobs\\Cli\\Commands\\ForgetCommand'],
				['jobs:stats', 'Neuron\\Jobs\\Cli\\Commands\\StatsCommand']
			);

		Provider::register($registry);
	}

	public function testRegisterCallsRegistrySevenTimes(): void
	{
		$registry = $this->createMock(Registry::class);

		$registry->expects($this->exactly(7))
			->method('register');

		Provider::register($registry);
	}

	public function testRegisterWithRealRegistry(): void
	{
		// Create a real registry to verify integration
		$registry = new Registry();

		// Should not throw exception
		Provider::register($registry);

		// Verify commands are accessible
		$this->assertTrue($registry->has('jobs:schedule'));
		$this->assertTrue($registry->has('jobs:work'));
		$this->assertTrue($registry->has('jobs:failed'));
		$this->assertTrue($registry->has('jobs:retry'));
		$this->assertTrue($registry->has('jobs:flush'));
		$this->assertTrue($registry->has('jobs:forget'));
		$this->assertTrue($registry->has('jobs:stats'));
	}
}
