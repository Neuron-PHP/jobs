<?php

namespace Neuron\Jobs\Bootstrap;

use Neuron\Application\Base;

/**
 * Fallback application for worker processes with no `queue.bootstrap`.
 *
 * Gives the process event listeners (so job lifecycle events reach
 * event-listeners.yaml) and nothing else. Sites that need a container, ORM
 * connection or view paths inside jobs should configure a real bootstrap.
 *
 * @package Neuron\Jobs\Bootstrap
 */
class MinimalApplication extends Base
{
	/**
	 * Events only; no initializers, since those are typically web wiring.
	 *
	 * @return bool
	 */
	protected function onStart(): bool
	{
		$this->initEvents();

		return true;
	}

	/**
	 * @return void
	 */
	protected function onRun(): void
	{
		// Never invoked: workers use start()/finish(), not run().
	}
}
