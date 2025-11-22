<?php

namespace Neuron\Jobs\Cli;

use Neuron\Cli\Commands\Registry;

/**
 * CLI provider for the Jobs component.
 * Registers all jobs-related CLI commands.
 */
class Provider
{
	/**
	 * Register jobs commands with the CLI registry
	 * 
	 * @param Registry $registry CLI Registry instance
	 * @return void
	 */
	public static function register( Registry $registry ): void
	{
		// Register the schedule command
		$registry->register(
			'jobs:schedule',
			'Neuron\\Jobs\\Cli\\Commands\\ScheduleCommand'
		);

		// Register queue commands
		$registry->register(
			'jobs:work',
			'Neuron\\Jobs\\Cli\\Commands\\WorkCommand'
		);

		$registry->register(
			'jobs:failed',
			'Neuron\\Jobs\\Cli\\Commands\\FailedCommand'
		);

		$registry->register(
			'jobs:retry',
			'Neuron\\Jobs\\Cli\\Commands\\RetryCommand'
		);

		$registry->register(
			'jobs:flush',
			'Neuron\\Jobs\\Cli\\Commands\\FlushCommand'
		);

		$registry->register(
			'jobs:forget',
			'Neuron\\Jobs\\Cli\\Commands\\ForgetCommand'
		);

		$registry->register(
			'jobs:stats',
			'Neuron\\Jobs\\Cli\\Commands\\StatsCommand'
		);

		// Register generator command
		$registry->register(
			'job:generate',
			'Neuron\\Jobs\\Cli\\Commands\\Generate\\JobCommand'
		);
	}
}
