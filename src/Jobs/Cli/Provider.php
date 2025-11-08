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
	}
}
