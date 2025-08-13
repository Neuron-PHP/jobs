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
		
		// Future commands can be added here:
		// $registry->register( 'jobs:list', 'Neuron\\Cli\\Commands\\Jobs\\ListCommand' );
		// $registry->register( 'jobs:run', 'Neuron\\Cli\\Commands\\Jobs\\RunCommand' );
		// $registry->register( 'jobs:status', 'Neuron\\Cli\\Commands\\Jobs\\StatusCommand' );
	}
}